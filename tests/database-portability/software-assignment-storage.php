<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-storage.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** Observe actual lifecycle boundaries; every implementation delegates its write. */
trait SoftwareStorageMutationProbe
{
    public static int $prepared = 0;
    public static int $writes = 0;

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        ++self::$prepared;
        return parent::executePreparedUpdate($operation, $storedFields);
    }

    public function updateInDB($updates, $oldvalues = [])
    {
        ++self::$writes;
        return parent::updateInDB($updates, $oldvalues);
    }
}

class SoftwareStorageParent extends Software
{
    use SoftwareStorageMutationProbe;

    public static function getTable($classname = null)
    {
        return Software::getTable();
    }

    public static function getType()
    {
        return Software::getType();
    }
}

class SoftwareStorageAllocation extends Item_SoftwareLicense
{
    use SoftwareStorageMutationProbe;

    public static function getTable($classname = null)
    {
        return Item_SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareLicense::getType();
    }
}

class SoftwareStorageMonitor extends Monitor
{
    use SoftwareStorageMutationProbe;

    public static function getTable($classname = null)
    {
        return Monitor::getTable();
    }

    public static function getType()
    {
        return Monitor::getType();
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Native storage fixture starts outside a caller frame');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before storage fixtures');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveentities_string'] = '0';
$_SESSION['glpishowallentities'] = false;
$fixtures = new FixtureRecords($DB);
$created = [];
$prefix = 'Software storage ' . bin2hex(random_bytes(5));
$record = static function (string $table, array $values = []) use ($fixtures, &$created): int {
    $id = $fixtures->create($table, $values);
    $created[] = [$table, $id];
    return $id;
};
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$rows = static fn (string $table): array => $records()->matching($table, [], 'id ASC');
$quote = static fn (string $name): string => $connection->quoteIdentifier($name);
$nativeRows = static fn (string $table): array => $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id');
$engine = static fn (string $table): mixed => $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
$definition = static function (string $table) use ($connection, $quote): string {
    $row = $connection->fetchAssociative('SHOW CREATE TABLE ' . $quote($table));
    verify(is_array($row) && isset($row['Create Table']), 'Native table definition is available before or after the engine fixture');
    return $row['Create Table'];
};
$foreignKeys = static fn (): array => [
    $connection->fetchAllAssociative('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'),
    $connection->fetchAllAssociative('SELECT TABLE_NAME, CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME'),
];
$originalApplicationRows = [];
foreach (['glpi_softwares', 'glpi_softwarelicenses', 'glpi_monitors', 'glpi_items_softwarelicenses'] as $table) {
    $originalApplicationRows[$table] = $nativeRows($table);
}
$backupTables = [];

try {
    $software = $record('glpi_softwares', ['name' => $prefix, 'entities_id' => 0]);
    $license = $record('glpi_softwarelicenses', ['name' => $prefix, 'softwares_id' => $software, 'entities_id' => 0, 'number' => 0]);
    $monitor = $record('glpi_monitors', ['name' => $prefix, 'entities_id' => 0]);
    $allocation = (new Item_SoftwareLicense())->add(['itemtype' => 'Monitor', 'items_id' => $monitor, 'softwarelicenses_id' => $license]);
    verify(is_numeric($allocation) && $allocation > 0, 'Actual allocation establishes finite-zero licence and Software invalidity');
    $allocation = (int)$allocation;
    $created[] = ['glpi_items_softwarelicenses', $allocation];
    $snapshot = static fn (): array => [
        $read('glpi_softwares', $software), $read('glpi_softwarelicenses', $license),
        $read('glpi_monitors', $monitor), $read('glpi_items_softwarelicenses', $allocation),
        $rows('glpi_logs'), $rows('glpi_queuednotifications'),
    ];
    $cases = [
        ['glpi_softwares', SoftwareStorageParent::class, $software, ['comment' => 'Actual parent persistence'], 0],
        ['glpi_softwarelicenses', SoftwareStorageAllocation::class, $allocation, ['is_deleted' => 1], 3],
        ['glpi_monitors', SoftwareStorageMonitor::class, $monitor, ['is_template' => 1], 2],
    ];
    $acceptedControl = static function (array $case) use ($connection, $snapshot, $read, $software, $license): void {
        [$table, $kind, $id, $change, $slot] = $case;
        $before = $snapshot();
        verify(!$before[0]['is_valid'] && !$before[1]['is_valid'], 'Control starts with actual eligible allocation and required invalid aggregates');
        $connection->beginTransaction();
        try {
            $kind::$prepared = $kind::$writes = 0;
            $model = new $kind();
            verify($model->update(['id' => $id] + $change) === true && $kind::$prepared === 1 && $kind::$writes === 1, 'Actual public transactional control reaches its prepared boundary and real writer: ' . $table);
            foreach ($change as $field => $value) {
                verify($snapshot()[$slot][$field] == $value, 'Accepted control physically persists the requested field: ' . $field);
            }
            if ($table !== 'glpi_softwares') {
                verify($read('glpi_softwarelicenses', $license)['is_valid'] && $read('glpi_softwares', $software)['is_valid'], 'Actual relation or subject control writes both required validity aggregates');
            }
            verify($connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1, 'Accepted lifecycle retains the usable caller transaction');
        } finally {
            $connection->rollBack();
        }
        verify($snapshot() === $before, 'Caller rollback restores the accepted control, required aggregates, history and queue');
    };

    foreach ($cases as $case) {
        $acceptedControl($case);
        if ($DB->getProvider() === 'pgsql') {
            // PostgreSQL has no MyISAM engine. These are real transactional
            // public-path controls, not evidence of MySQL storage admission.
            continue;
        }
        [$table, $kind, $id, $change, $slot] = $case;
        verify($connection->getTransactionNestingLevel() === 0 && strcasecmp((string)$engine($table), 'InnoDB') === 0, 'Native fixture begins with the original canonical InnoDB table outside any transaction');
        $originalRows = $nativeRows($table);
        $originalDefinition = $definition($table);
        $originalForeignKeys = $foreignKeys();
        $backup = 'itsm_storage_' . bin2hex(random_bytes(5)) . '_' . $table;
        verify($connection->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$backup]) == 0, 'Unique backup does not replace any existing table');
        try {
            // This fixture proves bounded storage admission, not a canonical FK
            // graph or populated-upgrade behavior while the clone is installed.
            // Incoming FKs follow the unchanged original to its backup name.
            // CREATE LIKE copies columns/indexes but not FKs. Only that clone is
            // converted to MyISAM; original rows and constraints stay in InnoDB.
            $connection->executeStatement('RENAME TABLE ' . $quote($table) . ' TO ' . $quote($backup));
            $backupTables[$table] = $backup;
            $connection->executeStatement('CREATE TABLE ' . $quote($table) . ' LIKE ' . $quote($backup));
            $columns = $connection->fetchAllAssociative('SELECT COLUMN_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$backup]);
            $insertable = [];
            foreach ($columns as $column) {
                if ($column['GENERATION_EXPRESSION'] === null || $column['GENERATION_EXPRESSION'] === '') {
                    $insertable[] = $quote($column['COLUMN_NAME']);
                }
            }
            verify($insertable !== [], 'Physical schema supplies insertable columns without writing generated projections');
            $columnSql = implode(', ', $insertable);
            $connection->executeStatement('INSERT INTO ' . $quote($table) . ' (' . $columnSql . ') SELECT ' . $columnSql . ' FROM ' . $quote($backup));
            $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ENGINE=MyISAM');
            $DB->clearSchemaCache();
            verify(strcasecmp((string)$engine($table), 'MyISAM') === 0 && strcasecmp((string)$engine($backup), 'InnoDB') === 0
                && $nativeRows($table) === $originalRows && $nativeRows($backup) === $originalRows, 'Native nontransactional clone exactly preserves rows and leaves the original transactional backup intact');

            foreach (['standalone', 'caller'] as $context) {
                $before = $snapshot();
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Prior storage feedback']];
                $beforeSession = $_SESSION;
                if ($context === 'caller') {
                    $connection->beginTransaction();
                }
                try {
                    $level = $connection->getTransactionNestingLevel();
                    $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' storage caller marker']) : null;
                    $model = new $kind();
                    verify($model->getFromDB($id), 'Load actual stored public model before storage refusal');
                    $storedFields = $model->fields;
                    $kind::$prepared = $kind::$writes = 0;
                    verify($model->update(['id' => $id] + $change) === false && $kind::$prepared === 1 && $kind::$writes === 0, 'Actual ' . $context . ' public mutation refuses participating MyISAM storage after preparation and before persistence: ' . $table);
                    verify($snapshot() === $before && $nativeRows($table) === $originalRows && $nativeRows($backup) === $originalRows, 'Storage admission leaves parent, licence, subject, allocation, audit, queue and original backup untouched without relying on MyISAM rollback');
                    verify($model->fields === $storedFields && $model->updates === [] && $model->oldvalues === [], 'Storage refusal restores stored public fields and clears attempted persistence arrays');
                    foreach (['id' => $id] + $change as $field => $value) {
                        verify(($model->input[$field] ?? null) == $value, 'Storage refusal retains the actual attempted public input: ' . $field);
                    }
                    verify($_SESSION === $beforeSession, 'Storage refusal preserves caller scope, feedback and session state');
                    verify($connection->getTransactionNestingLevel() === $level && (int)$connection->fetchOne('SELECT 1') === 1
                        && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Storage refusal leaves the native caller frame and independent marker usable');
                } finally {
                    if ($context === 'caller') {
                        $connection->rollBack();
                    }
                }
                verify($marker === null || $read('glpi_suppliers', $marker) === null, 'Caller rollback removes its marker after storage admission refusal');
            }
        } finally {
            while ($connection->getTransactionNestingLevel() > 0) {
                $connection->rollBack();
            }
            if (isset($backupTables[$table])) {
                if ($connection->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) == 1) {
                    $connection->executeStatement('DROP TABLE ' . $quote($table));
                }
                $connection->executeStatement('RENAME TABLE ' . $quote($backup) . ' TO ' . $quote($table));
                unset($backupTables[$table]);
                $DB->clearSchemaCache();
            }
            verify(strcasecmp((string)$engine($table), 'InnoDB') === 0 && $nativeRows($table) === $originalRows
                && $definition($table) === $originalDefinition && $foreignKeys() === $originalForeignKeys, 'Restoration retains the exact native original data, engine, column/index/FK definition and all incoming constraint names/targets');
            verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema fully restored after each native engine fixture');
        }
        $acceptedControl($case);
    }
} finally {
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
    try {
        // Recover any backup whose inner restoration failed before ordinary purge.
        foreach ($backupTables as $table => $backup) {
            if ($connection->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) == 1) {
                $connection->executeStatement('DROP TABLE ' . $quote($table));
            }
            $connection->executeStatement('RENAME TABLE ' . $quote($backup) . ' TO ' . $quote($table));
            $DB->clearSchemaCache();
        }
        foreach (array_reverse($created) as [$table, $id]) {
            $kind = getItemTypeForTable($table);
            $model = new $kind();
            if ($model->getFromDB($id)) {
                verify((bool)$model->delete(['id' => $id, '_no_history' => true, '_disablenotif' => true], true), 'Owned fixture purge: ' . $table);
            }
        }
    } finally {
        $_SESSION = $savedSession;
        $CFG_GLPI = $savedConfig;
    }
}
foreach ($originalApplicationRows as $table => $originalRows) {
    verify($nativeRows($table) === $originalRows, 'All pre-existing application rows remain unchanged after owned fixture cleanup: ' . $table);
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after storage contract cleanup');
$facility = $DB->getProvider() === 'pgsql' ? 'transactional public-path controls; PostgreSQL has no MyISAM facility' : 'native MyISAM admission and restored InnoDB public-path controls';
echo $DB->getProvider() . ": $assertions $facility passed.\n";
