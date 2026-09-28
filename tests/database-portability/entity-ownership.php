<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/entity-ownership.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $storage = new \itsmng\Database\MappedStorage($DB);
    $default = $storage->insert('glpi_computers', ['name' => 'Implicit root owner']);
    $computer = new Computer();
    verify($computer->getFromDB($default) && (int)$computer->fields['entities_id'] === 0, 'Omitted owner defaults to real root');
    verify(count($computer->find(['id' => $default, 'entities_id' => 0])) === 1, 'Root zero criteria does not become NULL');

    foreach (['insert', 'update'] as $operation) {
        $rejected = false;
        try {
            if ($operation === 'insert') {
                $storage->insert('glpi_computers', ['entities_id' => null]);
            } else {
                $storage->update('glpi_computers', $default, ['entities_id' => null]);
            }
        } catch (InvalidArgumentException $error) {
            $rejected = str_contains($error->getMessage(), 'glpi_computers.entities_id');
        }
        verify($rejected, 'Required ownership rejects explicit NULL on ' . $operation);
    }
    $owner = (new Entity())->add(['name' => 'Ownership source', 'entities_id' => 0]);
    $other = (new Entity())->add(['name' => 'Ownership unrelated', 'entities_id' => 0]);
    $records = [];
    $others = [];
    $em = \itsmng\Database\Orm::create($DB);
    foreach (\itsmng\Database\EntityOwnership::RELATIONS as $table => $relations) {
        $metadata = $em->getClassMetadata(\itsmng\Database\EntityRegistry::TABLES[$table]);
        $values = ['entities_id' => $owner];
        $otherValues = ['entities_id' => $other];
        if ($metadata->hasField('name')) {
            $values['name'] = 'Owned ' . $table;
            $otherValues['name'] = 'Other ' . $table;
        }
        if ($table === 'glpi_rules') {
            $values['sub_type'] = $otherValues['sub_type'] = 'RuleTicket';
        }
        if ($metadata->hasField('itemtype') && $metadata->hasField('items_id')) {
            $values += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers', ['entities_id' => $owner])];
            $otherValues += ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers', ['entities_id' => $other])];
        }
        if ($table === 'glpi_ipnetworks') {
            $records[$table] = (new IPNetwork())->add($values + ['network' => '10.246.1.0/24']);
            $others[$table] = (new IPNetwork())->add($otherValues + ['network' => '10.246.2.0/24']);
            verify($records[$table] > 0 && $others[$table] > 0, 'Valid network fixtures');
            continue;
        }
        $records[$table] = $fixtures->create($table, $values);
        $others[$table] = $fixtures->create($table, $otherValues);
    }
    $replacement = (new Entity())->add(['name' => 'Ownership replacement', 'entities_id' => 0]);
    $parentNetwork = (new IPNetwork())->add(['name' => 'Destination network parent', 'network' => '10.246.0.0/16', 'entities_id' => $replacement]);
    verify($parentNetwork > 0, 'Destination network fixture');
    $fixtures->create('glpi_profiles_users', ['users_id' => $records['glpi_users'], 'entities_id' => $replacement, 'profiles_id' => 4]);
    $nonmember = $fixtures->create('glpi_users', ['name' => 'Default without replacement access', 'entities_id' => $owner]);
    $fixtures->create('glpi_profiles_users', ['users_id' => $nonmember, 'entities_id' => $owner, 'profiles_id' => 4]);
    $entity = new Entity();
    verify(!$entity->delete(['id' => 0], true), 'Root entity is protected');
    verify($entity->delete(['id' => $owner, '_replace_by' => $replacement], true), 'Replace entity under ownership enforcement');
    foreach ($records as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && (int)$item->fields['entities_id'] === (int)$replacement, 'Replacement ownership: ' . $table);
    }
    $movedNetwork = new IPNetwork();
    verify($movedNetwork->getFromDB($records['glpi_ipnetworks']) && (int)$movedNetwork->fields['ipnetworks_id'] === (int)$parentNetwork, 'Network transfer uses destination ancestry');
    $user = new User();
    verify($user->getFromDB($nonmember) && (int)$user->fields['entities_id'] === 0, 'Inaccessible replacement clears the user default');
    verify(Profile_User::getUserEntities($nonmember) === [], 'Clearing a default does not grant root or replacement access');
    verify($entity->delete(['id' => $replacement], true), 'Delete entity under ownership enforcement');
    foreach ($records as $table => $id) {
        $item = getItemForItemtype(getItemTypeForTable($table));
        verify($item->getFromDB($id) && (int)$item->fields['entities_id'] === 0, 'Ownership falls back to the real root: ' . $table);
        verify($item->getFromDB($others[$table]) && (int)$item->fields['entities_id'] === (int)$other, 'Other ownership preserved: ' . $table);
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Ownership graph remains valid');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": required entity ownership, root defaults and entity purge passed.\n";
