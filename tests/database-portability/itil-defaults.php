<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-defaults.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$DB->beginTransaction();
try {
    foreach (ReferenceHistory::get('optional', 'ITIL_DEFAULTS') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'ITIL default parent ' . $table . '.' . $column]);
            $replacement = $fixtures->create($target, ['name' => 'ITIL default replacement ' . $table . '.' . $column]);
            $other = $fixtures->create($target, ['name' => 'ITIL default other ' . $table . '.' . $column]);
            $child = $fixtures->create($table, [$column => $parent]);
            $unrelated = $fixtures->create($table, [$column => $other]);
            $empty = $fixtures->create($table, [$column => null]);
            (new \itsmng\Database\MappedStorage($DB))->update($table, $empty, [$column => 0]);
            verify($read($table, $empty)[$column] === null, 'Empty default remains NULL');
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace default target ' . $target);
            verify((int)$read($table, $child)[$column] === $replacement, 'Reassign ' . $table . '.' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge default target ' . $target);
            verify($read($table, $child)[$column] === null, 'Clear ' . $table . '.' . $column);
            verify((int)$read($table, $unrelated)[$column] === $other, 'Unrelated default retained');
        }
    }
    $now = new DateTimeImmutable('2030-05-01 12:00:00');
    $template = $fixtures->create('glpi_tickettemplates', ['name' => 'Mapped recurrence template']);
    $calendar = $fixtures->create('glpi_calendars', ['name' => 'Mapped recurrence calendar']);
    $base = ['is_active' => true, 'next_creation_date' => '2030-05-01 11:00:00', 'begin_date' => '2030-01-01 00:00:00', 'periodicity' => '86400', 'tickettemplates_id' => $template, 'calendars_id' => $calendar];
    $expected = [];
    $all = [];
    foreach ([
        'first' => ['next_creation_date' => '2030-05-01 10:00:00'],
        'second' => [],
        'unconfigured' => ['tickettemplates_id' => null, 'calendars_id' => null],
        'inactive' => ['is_active' => false],
        'future' => ['next_creation_date' => '2030-05-01 13:00:00'],
        'boundary' => ['next_creation_date' => '2030-05-01 12:00:00'],
        'no-next-date' => ['next_creation_date' => null],
        'ended' => ['end_date' => '2030-05-01 11:59:59'],
        'end-boundary' => ['end_date' => '2030-05-01 12:00:00'],
        'live-end' => ['end_date' => '2030-05-01 12:00:01'],
    ] as $name => $values) {
        $id = $fixtures->create('glpi_ticketrecurrents', $values + ['name' => $name] + $base);
        $all[] = $id;
        if (in_array($name, ['first', 'second', 'unconfigured', 'live-end'], true)) {
            $expected[] = $id;
        }
    }
    $repo = new \itsmng\Database\Repository\TicketRecurrentRepository(Orm::create($DB));
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $rows = array_values(array_filter($repo->due($now), static fn ($row) => in_array($row['id'], $all, true)));
    verify(array_column($rows, 'id') === $expected, 'Due selection preserves strict time boundaries, active flags, NULL dates and deterministic order');
    verify($rows[0]['next_creation_date'] === '2030-05-01 10:00:00' && $rows[0]['is_active'] === 1, 'Legacy row contract retains dates and numeric flags');
    verify($rows[2]['tickettemplates_id'] === null && $rows[2]['calendars_id'] === null, 'Unconfigured recurrence remains visible for existing failure handling');
    verify($SQL_TOTAL_REQUEST === 0, 'Due selection uses ORM');
    $record = $read('glpi_ticketrecurrents', $expected[0]);
    verify($record['next_creation_date'] === $rows[0]['next_creation_date'], 'Selection does not advance or execute schedules');
    $recurrent = new TicketRecurrent();
    verify($recurrent->getFromDB($expected[2]), 'Load unconfigured recurrence');
    $fields = $recurrent->getAdditionalFields();
    verify(is_array($fields) && count($fields) > 0, 'Recurrence form accepts nullable metadata');
    verify((new ForeignKeys())->audit($connection) === [], 'ITIL defaults graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NullableReferences(ReferenceHistory::get('optional', 'ITIL_DEFAULTS'), 'ITIL default');
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'ITIL_DEFAULTS') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_ticketrecurrents');
    $connection->insert('glpi_ticketrecurrents', ['id' => $legacy, 'name' => 'legacy-name']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy ITIL default migration has a plan');
    verify((int)$connection->fetchOne('SELECT tickettemplates_id FROM glpi_ticketrecurrents WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_ticketrecurrents', ['tickettemplates_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ITIL default');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_ticketrecurrents')['tickettemplates_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_ticketrecurrents', ['tickettemplates_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT tickettemplates_id FROM glpi_ticketrecurrents WHERE id = ?', [$legacy]) === null, 'Legacy template default becomes NULL');
    verify($migration->apply($connection) === [], 'ITIL default migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_ticketrecurrents', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": ITIL defaults, nullable references, recurring selection and migration passed.\n";
