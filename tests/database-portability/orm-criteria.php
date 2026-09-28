<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/orm-criteria.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
$DB->beginTransaction();
try {
    $stamp = 'Mapped criteria ' . bin2hex(random_bytes(4));
    $writer = new \itsmng\Database\MappedStorage($DB);
    $ids = [];
    foreach (["O'Reilly C:\\new\\file %_ 日本語", 'Alpha', 'alpha second', 'Beta'] as $index => $name) {
        $ids[] = $writer->insert('glpi_computers', [
            'name' => $DB->escape($name), 'entities_id' => 0, 'is_deleted' => $index === 3,
            'comment' => $index % 2 ? $stamp : null, 'date_creation' => '2025-01-0' . ($index + 1) . ' 12:34:56',
        ]);
    }
    $criteria = [
        [], ['is_deleted' => false], ['is_deleted' => [false, true]],
        ['name' => $DB->escape("O'Reilly C:\\new\\file %_ 日本語")],
        ['name' => ['LIKE', 'aLpHa%']], ['name' => ['NOT LIKE', 'Alpha%']],
        ['comment' => null], ['comment' => 'NULL'], ['NOT' => ['comment' => null]],
        ['OR' => [['name' => 'Alpha'], ['is_deleted' => true]]],
        ['NOT' => ['OR' => [['name' => 'Alpha'], ['is_deleted' => true]]]],
        ['AND' => [['date_creation' => ['>=', '2025-01-02 12:34:56']], ['date_creation' => ['<', '2025-01-04 12:34:56']]]],
        ['glpi_computers.is_deleted' => 0, 'glpi_computers.entities_id' => 0],
        ['name' => ['<>', 'Alpha']], ['id' => ['>', $ids[0]]],
        ['id' => ['=', null]],
        ['comment' => ['IS', 'NULL']], // These are list values in the legacy criteria grammar.
    ];
    $computer = new Computer();
    foreach ($criteria as $number => $condition) {
        $condition = $condition ? ['AND' => [['id' => $ids], $condition]] : ['id' => $ids];
        $native = iterator_to_array($DB->request(['FROM' => 'glpi_computers', 'WHERE' => $condition, 'ORDER' => 'id DESC']));
        $mapped = $computer->find($condition, 'glpi_computers.id DESC');
        verify(array_column($native, 'id') === array_keys($mapped), 'Mapped predicate result/order parity: ' . $number);
    }
    verify(array_keys($computer->find(['id' => $ids], 'id DESC', 2)) === [$ids[3], $ids[2]], 'Database-side result limit');
    verify($computer->getFromDBByRequest(['WHERE' => ['id' => $ids], 'ORDER' => 'id', 'LIMIT' => 1, 'START' => 2]), 'Mapped request offset');
    verify($computer->fields['id'] === $ids[2] && $computer->fields['date_creation'] === '2025-01-03 12:34:56', 'Typed request hydration');
    verify($computer->getFromDBByCrit(['id' => $ids[1]]), 'Mapped criteria lookup');
    verify(!$computer->getFromDBByCrit(['id' => -1]), 'Absent mapped lookup');

    $group = $writer->insert('glpi_groups', ['name' => $stamp]);
    $membership = $writer->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => 2]);
    $memberships = (new Group_User())->find(['groups_id' => $group, 'users_id' => [2]], 'groups_id, id DESC');
    verify(array_keys($memberships) === [$membership] && $memberships[$membership]['groups_id'] === $group, 'Mapped association identity comparison and ordering');
    $right = $writer->insert('glpi_profilerights', ['profiles_id' => 2, 'name' => $stamp, 'rights' => 3]);
    verify(array_keys((new ProfileRight())->find(['id' => $right, 'rights' => ['&', 2]])) === [$right], 'Mapped bitmask predicate');
    verify((new ProfileRight())->find(['id' => $right, 'rights' => ['&', 4]]) === [], 'Unmatched bitmask predicate');

    $em = \itsmng\Database\Orm::create($DB);
    $repository = new \itsmng\Database\Repository\RecordRepository($em);
    verify(count($repository->matching('glpi_computers', ['id' => $ids, 'name' => "O'Reilly C:\\new\\file %_ 日本語"], legacyValues: false)) === 1, 'New repositories bind raw values without unescaping');
    verify(count($repository->matching('glpi_computers', ['id' => $ids, 'date_creation' => new DateTimeImmutable('2025-01-02 12:34:56')], legacyValues: false)) === 1, 'New repositories accept typed date values');
    $rawWriter = new \itsmng\Database\Repository\RecordWriter($em);
    $rawId = $rawWriter->insert('glpi_locations', ['name' => 'NULL']);
    verify(count($repository->matching('glpi_locations', ['id' => $rawId, 'name' => 'NULL'], legacyValues: false)) === 1, 'Raw literal NULL is distinct from SQL NULL');
    $rawWriter->update('glpi_computers', $ids[0], ['date_creation' => new DateTimeImmutable('2025-02-03 12:34:56')]);
    verify($repository->find('glpi_computers', 'id', $ids[0])['date_creation'] === '2025-02-03 12:34:56', 'Immutable date writes use the mapped mutable Doctrine type');
    foreach ([['name' => ['REGEXP', '^Alpha$']], [new QueryExpression('1 = 1')]] as $unsupported) {
        try {
            $repository->matching('glpi_computers', $unsupported);
            throw new RuntimeException('Unsupported SQL construct was silently interpreted');
        } catch (\itsmng\Database\UnsupportedCriteria $expected) {
        }
    }
    verify(array_keys($computer->find(['id' => $ids, 'name' => ['REGEXP', '^Alpha$']], 'id')) === [$ids[1]], 'Legacy regex path remains explicit and functional');

    // Prove the shared model operations execute ORM reads, not adapter SQL requests.
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    verify(count($computer->find(['id' => $ids], 'id', 2)) === 2, 'Mapped model find');
    verify($computer->getFromDBByCrit(['id' => $ids[0]]), 'Mapped model criteria lookup');
    verify($computer->getFromDBByRequest(['WHERE' => ['id' => $ids[1]]]), 'Mapped model request');
    verify($SQL_TOTAL_REQUEST === 0, 'Shared mapped reads bypass the legacy SQL adapter');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped criteria, typed fields, association identities, pagination and ORM execution passed.\n";
