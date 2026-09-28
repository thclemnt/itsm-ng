<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/orm.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
class GlpitestSQLError extends RuntimeException
{
}
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$em = \itsmng\Database\Orm::create($DB);
verify((new \Doctrine\ORM\Tools\SchemaValidator($em))->validateMapping() === [], 'Doctrine validates all mappings');
$schema = (new \itsmng\Database\BaselineSchema())->build($DB->getDoctrineConnection()->getDatabasePlatform());
$metadata = $em->getMetadataFactory()->getAllMetadata();
verify(count($metadata) === 23, 'Mapped identity, asset, contract and reservation tables');
foreach ($metadata as $meta) {
    $columns = array_map(fn ($field) => $field->columnName, $meta->fieldMappings);
    foreach ($meta->associationMappings as $association) {
        foreach ($association->joinColumns as $join) {
            $columns[] = $join->name;
        }
    }
    sort($columns);
    $expected = array_keys($schema->getTable($meta->getTableName())->getColumns());
    sort($expected);
    verify($columns === $expected, 'Complete mapping for ' . $meta->getTableName());
    // Hydrate whole entities, including typed flags and dates, not partial objects.
    $em->createQueryBuilder()->select('e')->from($meta->name, 'e')->setMaxResults(1)->getQuery()->getResult();
}
verify(count((new \Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata)) >= 23, 'Mappings generate portable scoped schema DDL');
$em->clear();
$DB->beginTransaction();
try {
    $parents = [];
    $parent = static function (string $table) use (&$parents, $DB): int {
        if (!isset($parents[$table])) {
            $DB->insertOrDie($table, $DB->fieldExists($table, 'name') ? ['name' => 'ORM fixture'] : ['comment' => 'Foreign key fixture']);
            $parents[$table] = $DB->insertId();
        }
        return $parents[$table];
    };
    foreach (\itsmng\Database\MappedStorage::TABLES as $table => $class) {
        $values = [];
        foreach (\itsmng\Database\ForeignKeys::RELATIONS[$table] as $column => $target) {
            $values[$column] = $parent($target);
        }
        $storage = new \itsmng\Database\MappedStorage($DB);
        $id = $storage->insert($table, $values);
        verify($id > 0, 'ORM generated id for ' . $table);
        $field = match ($table) {
            'glpi_groups_users' => 'is_manager', 'glpi_useremails' => 'email', 'glpi_profilerights' => 'rights',
            'glpi_contractcosts' => 'name', 'glpi_reservations' => 'comment',
            default => array_key_first($values),
        };
        $value = match ($field) {
            'is_manager' => 1, 'rights' => 42, 'email', 'name', 'comment' => "O'Reilly C:\\new\\file %_ 日本語", default => $parent(\itsmng\Database\ForeignKeys::RELATIONS[$table][$field])
        };
        $storage->update($table, $id, [$field => is_string($value) ? $DB->escape($value) : $value]);
        $row = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $id]])->next();
        verify((string)$row[$field] === (string)$value, 'Typed and escaped ORM update round-trip for ' . $table);
        if ($field === 'comment' || $field === 'name') {
            $storage->update($table, $id, [$field => 'NULL']);
            verify($DB->request(['FROM' => $table, 'WHERE' => ['id' => $id]])->next()[$field] === null, 'Nullable update');
        }
        verify($storage->delete($table, $id), 'ORM deletion');
        verify(!$DB->request(['FROM' => $table, 'WHERE' => ['id' => $id]])->count(), 'Deletion visible through legacy connection');
    }
    // Native writes remain visible to a subsequent ORM unit of work.
    $email = new UserEmail();
    $id = $email->add(['users_id' => $parent('glpi_users'), 'email' => 'first@example.invalid']);
    verify((bool)$id, 'CommonDBTM add');
    $DB->update('glpi_useremails', ['email' => 'external@example.invalid'], ['id' => $id]);
    $email->fields['is_default'] = 0;
    verify($email->updateInDB(['is_default']), 'CommonDBTM persistence update');
    verify($email->getFromDB($id) && $email->fields['email'] === 'external@example.invalid', 'No stale identity map overwrites legacy changes');
    verify($email->delete(['id' => $id], true), 'CommonDBTM purge');

    // Every parent purge must clean every new required association, with real FKs enabled.
    foreach (['glpi_contracts', 'glpi_suppliers', 'glpi_contacts', 'glpi_reservationitems', 'glpi_changes', 'glpi_problems', 'glpi_tickets', 'glpi_groups'] as $target) {
        $parentId = $parent($target);
        foreach (\itsmng\Database\ForeignKeys::RELATIONS as $table => $relations) {
            if (!in_array($target, $relations, true)) {
                continue;
            }
            $values = [];
            foreach ($relations as $column => $reference) {
                $values[$column] = $reference === 'glpi_entities' ? 0 : $parent($reference);
            }
            $DB->insertOrDie($table, $values);
        }
        $item = getItemForItemtype(getItemTypeForTable($target));
        verify($item->delete(['id' => $parentId], true), 'Parent lifecycle purge: ' . $target);
        unset($parents[$target]);
        foreach (\itsmng\Database\ForeignKeys::RELATIONS as $table => $relations) {
            foreach ($relations as $column => $reference) {
                if ($reference === $target) {
                    verify(!$DB->request(['FROM' => $table, 'WHERE' => [$column => $parentId]])->count(), 'Purge cleaned ' . $table . '.' . $column);
                }
            }
        }
    }
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphans after lifecycle purges');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": complete mappings, typed ORM CRUD, shared transactions and parent purge coverage passed.\n";
