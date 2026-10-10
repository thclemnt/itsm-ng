<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\integration;

use ArrayObject;
use DBAdapter;
use DBConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use GLPITestCase;
use LogicException;
use RuntimeException;
use Toolbox;
use itsmng\Database\BaselineSchema;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Installer;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\PhysicalReferenceIndexes;
use itsmng\Database\Migration\ReleaseMigration;
use itsmng\Database\Migration\SensorSubjects;
use itsmng\Database\Migration\SensorSubjects\Definition as SensorSubjectDefinition;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\ExactDiscriminators;
use itsmng\Database\Migration\V220\NetworkPortAggregateOrigins;
use itsmng\Database\Migration\V220\PlanningEventGuests;
use itsmng\Database\Migration\V220\Seeds;
use itsmng\Database\Migration\Version220;
use itsmng\Database\NativeCheckCatalog;
use itsmng\Database\NativeNonNegativeSchema;
use itsmng\Database\NativeSubjectSchema;
use itsmng\Database\NativeReferenceSchema;
use itsmng\Database\Orm;
use itsmng\Database\PhysicalIndexSchema;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;
use itsmng\Database\Upgrade;

/** Native release boundaries; the historical 0.72.3 database tests remain separate. */
class OrmMigration extends GLPITestCase
{
    private ?DBAdapter $fixture = null;
    private bool $fixtureCompleted = false;

    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
        $this->setTestedClassName(History::class);
    }

    private function emptyFixture(): DBAdapter
    {
        global $DB;
        $this->fixtureCompleted = false;
        $name = getenv('ITSM_TEST_MIGRATION_DB');
        $this->boolean(is_string($name) && preg_match('/^itsm_test_[a-z0-9_]+_migration$/D', $name) === 1
            && $name !== $DB->dbdefault)->isTrue('An explicit separate disposable migration database is required');
        $database = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $name);
        $this->boolean($database->connected)->isTrue();
        $this->array($database->getDoctrineConnection()->createSchemaManager()->listTableNames())
            ->isEmpty('Never replace an existing migration fixture or failed-run evidence');
        return $this->fixture = $database;
    }

    public function afterTestMethod($method)
    {
        try {
            if ($this->fixture !== null) {
                // Atoum records uncaught exceptions only after this callback.
                // Delete only fixtures whose test body reached its normal end.
                $score = $this->getScore();
                if (!$this->fixtureCompleted || $score->getFailNumber() > 0 || $score->getErrorNumber() > 0
                    || $score->getExceptionNumber() > 0 || $score->getRuntimeExceptionNumber() > 0) {
                    return;
                }
                $connection = $this->fixture->getDoctrineConnection();
                $platform = $connection->getDatabasePlatform();
                $manager = $connection->createSchemaManager();
                $owned = [...array_map(static fn ($table) => $table->getName(), (new Baseline())->build($platform)->getTables()),
                    Ledger::TABLE, NetworkPortAggregateOrigins::TABLE,
                    PlanningEventGuests::TABLE];
                $this->array(array_diff($manager->listTableNames(), $owned))->isEmpty('Cleanup owns only release fixture tables');
                if ($platform instanceof PostgreSQLPlatform) {
                    foreach ($manager->listTableNames() as $table) {
                        $connection->executeStatement('DROP TABLE ' . $platform->quoteIdentifier($table) . ' CASCADE');
                    }
                } else {
                    Installer::resetMysqlCore($connection);
                }
            }
        } finally {
            $this->fixture?->close();
            $this->fixture = null;
            parent::afterTestMethod($method);
        }
    }

    public function testPublicInstallationPublishesConvergedHistory(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $this->integer((int)$connection->fetchOne(
            'SELECT COUNT(*) FROM glpi_configs WHERE context = ? AND name = ?',
            ['phpunit', 'dataset']
        ))->isIdenticalTo(0, 'Migration bootstrap must preserve the public installation without ordinary fixtures');
        $this->integer((int)$connection->fetchOne(
            'SELECT COUNT(*) FROM glpi_plugins WHERE directory = ?',
            ['tester']
        ))->isIdenticalTo(0, 'Migration bootstrap must not register the ordinary test plugin');
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            && !$connection->getDatabasePlatform() instanceof MariaDBPlatform) {
            // Exercise native metadata with the connection collation involved
            // in the MySQL 8.4 public-install illegal-mix failure.
            $collation = $connection->fetchOne('SELECT @@session.collation_connection');
            try {
                $connection->executeStatement("SET SESSION collation_connection = 'utf8mb3_unicode_ci'");
                $this->string($connection->fetchOne('SELECT @@session.collation_connection'))->isIdenticalTo('utf8mb3_unicode_ci');
                $this->array((new SchemaCheck())->differences($connection))->isEmpty();
            } finally {
                $connection->executeStatement('SET SESSION collation_connection = ?', [$collation]);
            }
            $this->string($connection->fetchOne('SELECT @@session.collation_connection'))->isIdenticalTo($collation);
        } else {
            $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        }
        $this->array(History::pendingVersions($connection))->isEmpty();
        $this->boolean(History::isInstalling($connection))->isFalse();
        $this->boolean(Ledger::state($connection, Baseline::PHASE)['installation_complete'])->isTrue();
        $this->array(History::versions())->isIdenticalTo(['2.2.0', SensorSubjects::VERSION, PhysicalReferenceIndexes::VERSION]);
        $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE context = ? AND name = ?', ['core', 'itsmdbversion']))->isIdenticalTo(ITSM_SCHEMA_VERSION);
        // Readiness must bootstrap its own adapter in a fresh process, without
        // relying on this test runner's already-loaded database functions.
        $process = proc_open(
            [PHP_BINARY, GLPI_ROOT . '/tests/e2e/check_installed_history.php',
            GLPI_CONFIG_DIR, '--without-application-fixtures'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            GLPI_ROOT
        );
        $this->boolean(is_resource($process))->isTrue();
        fclose($pipes[0]);
        try {
            $output = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
        }
        $this->integer(proc_close($process))->isIdenticalTo(0, $output);
        $this->string($output)->contains('Installed canonical history, core schema and release publication verified.');
    }

    public function testInterruptedBaselineAndSeedsCanResume(): void
    {
        $connection = $this->emptyFixture()->getDoctrineConnection();
        $history = new History();
        $created = null;
        $this->exception(static function () use ($history, $connection, &$created): void {
            $history->baseline($connection, static function (string $step) use (&$created): void {
                $created = substr($step, strlen('Created table: '));
                throw new RuntimeException('Interrupted baseline');
            });
        })->isInstanceOf(RuntimeException::class)->hasMessage('Interrupted baseline');
        $manager = $connection->createSchemaManager();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->array($manager->listTableNames())->isEmpty();
            $this->boolean(History::isInstalling($connection))->isFalse();
        } else {
            $this->boolean($manager->tablesExist([$created]))->isTrue();
            $this->integer(Ledger::state($connection, Baseline::PHASE)['next'])->isIdenticalTo(0);
            $this->boolean(History::isInstalling($connection))->isTrue();
        }
        $history->baseline($connection);
        $this->integer(count($manager->listTableNames()))->isIdenticalTo(count((new Baseline())->build($connection->getDatabasePlatform())->getTables()) + 1);
        $rows = 0;
        $this->exception(static fn () => (new Seeds())->apply($connection, progress: static function () use (&$rows): void {
            if (++$rows === 20) {
                throw new RuntimeException('Interrupted seeds');
            }
        }))->isInstanceOf(RuntimeException::class)->hasMessage('Interrupted seeds');
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_apiclients'))->isIdenticalTo(0);
        $this->boolean(Ledger::state($connection, Seeds::PHASE)['complete'])->isFalse();
        (new Seeds())->apply($connection);
        $connection->update('glpi_rulerightparameters', ['comment' => 'Retained seed edit'], ['id' => 1]);
        $receipt = Ledger::state($connection, Seeds::PHASE);
        (new Seeds())->apply($connection, progress: static fn () => throw new LogicException('Completed seeds replayed'));
        $this->string($connection->fetchOne('SELECT comment FROM glpi_rulerightparameters WHERE id = 1'))->isIdenticalTo('Retained seed edit');
        $this->array(Ledger::state($connection, Seeds::PHASE))->isIdenticalTo($receipt);

        // Establish the real predecessor (including bigint target IDs) before testing forward DDL.
        $predecessor = new Version220();
        $predecessor->apply($connection);
        $predecessor->verify($connection);

        // The forward phase has its own retained proof in the same ledger.
        $connection->insert('glpi_devicesensors', ['id' => 100, 'designation' => 'Retry sensor', 'entities_id' => 0]);
        $connection->insert('glpi_computers', ['id' => 100, 'name' => 'Retry subject', 'entities_id' => 0]);
        $connection->insert('glpi_items_devicesensors', ['id' => 100, 'devicesensors_id' => 100,
            'itemtype' => 'computer', 'items_id' => 100, 'entities_id' => 0]);
        $forward = new SensorSubjects();
        $before = $this->rowBags($connection);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(RuntimeException::class);
        $this->array($this->rowBags($connection))->isIdenticalTo($before);
        $connection->update('glpi_items_devicesensors', ['itemtype' => 'Computer', 'items_id' => 101], ['id' => 100]);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(RuntimeException::class);
        $connection->update('glpi_items_devicesensors', ['items_id' => 100], ['id' => 100]);
        $this->exception(static fn () => $forward->apply($connection, static function (string $phase): void {
            if ($phase === 'projection') {
                throw new RuntimeException('Interrupted Sensor projection');
            }
        }))->isInstanceOf(RuntimeException::class)->hasMessage('Interrupted Sensor projection');
        $forward->apply($connection);
        $forward->verify($connection);
        $retained = Ledger::state($connection, SensorSubjectDefinition::PHASE);
        $this->boolean($retained['complete'])->isTrue();
        $this->array($retained['policy'])->hasKeys(['projection', 'check']);
        $forward->apply($connection, static fn () => throw new LogicException('Completed Sensor DDL replayed'));
        $forward->verify($connection);
        $this->array(Ledger::state($connection, SensorSubjectDefinition::PHASE))->isIdenticalTo($retained);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_items_devicesensors WHERE id = 100'))->isIdenticalTo(100);
        $connection->insert('glpi_peripherals', ['id' => 100, 'name' => 'Other retry subject', 'entities_id' => 0]);
        foreach ([['itemtype' => 'computer'], ['computers_id' => 0], ['peripherals_id' => 100], ['computers_id' => 101]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->update('glpi_items_devicesensors', $invalid, ['id' => 100])))
                ->isInstanceOf(DbalException::class);
        }
        $forward->verify($connection);
        $phase = SensorSubjectDefinition::PHASE;
        $connection->delete(Ledger::TABLE, ['version' => $phase]);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(RuntimeException::class);
        Ledger::save($connection, $phase, $retained);
        $forward->verify($connection);

        // The next release adds physical support only; interrupted PostgreSQL
        // CREATEs and already-supported MySQL FKs converge without touching rows.
        $indexes = new PhysicalReferenceIndexes();
        $beforeRows = $this->rowBags($connection);
        $beforeIndexes = PhysicalIndexSchema::catalog($connection, array_keys($indexes::declarations()));
        $plan = $indexes->plan($connection);
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->integer(count($plan['sql']))->isIdenticalTo(68);
            $this->exception(static fn () => $indexes->apply($connection, static fn () => throw new RuntimeException('Interrupted physical index creation')))
                ->isInstanceOf(RuntimeException::class)->hasMessage('Interrupted physical index creation');
            $this->integer(count($indexes->plan($connection)['sql']))->isIdenticalTo(67);
        } else {
            $this->array($plan['sql'])->isEmpty('Existing InnoDB supporting indexes already provide physical coverage');
        }
        $indexes->apply($connection);
        $indexes->verify($connection);
        $this->array($indexes->plan($connection)['sql'])->isEmpty();
        $indexes->apply($connection, static fn () => throw new LogicException('Completed physical index DDL replayed'));
        $this->array($this->rowBags($connection))->isIdenticalTo($beforeRows);
        $afterIndexes = PhysicalIndexSchema::catalog($connection, array_keys($indexes::declarations()));
        foreach ($beforeIndexes as $table => $physical) {
            foreach ($physical as $name => $definition) {
                $this->array($afterIndexes[$table][$name])->isIdenticalTo($definition, 'Every pre-existing physical index is retained');
            }
        }
        $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $name = 'glpi_items_devicesensors_computers_id_typed';
            $connection->executeStatement('DROP INDEX ' . $connection->getDatabasePlatform()->quoteIdentifier($name));
            $this->array((new SchemaCheck())->differences($connection))
                ->contains('Missing physical index coverage: glpi_items_devicesensors.' . $name);
            $this->exception(static fn () => $indexes->verify($connection))->isInstanceOf(RuntimeException::class);
            $indexes->apply($connection);
            $indexes->verify($connection);
            $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        }
        $this->fixtureCompleted = true;
    }

    public function testPublicUpgradeRefusesOldProvenanceAndPreservesPopulatedData(): void
    {
        global $DB;
        $database = $this->emptyFixture();
        $connection = $database->getDoctrineConnection();
        $history = new History();
        $history->baseline($connection);
        (new Seeds())->apply($connection);
        $manager = $connection->createSchemaManager();
        $manager->dropTable(Ledger::TABLE);
        $legacyId = 2147483647;
        $audit = "Historical O'Reilly \\path 日本語";
        $connection->insert('glpi_computers', ['id' => $legacyId, 'name' => 'Imported computer', 'entities_id' => 0, 'computermodels_id' => 0]);
        $connection->insert('glpi_peripherals', ['id' => 100, 'name' => 'Imported peripheral', 'entities_id' => 0]);
        $connection->insert('glpi_devicesensors', ['id' => 100, 'designation' => 'Imported sensor', 'entities_id' => 0]);
        foreach ([[101, 'Computer', $legacyId], [102, 'Peripheral', 100], [103, '', 0]] as [$id, $kind, $subject]) {
            $connection->insert('glpi_items_devicesensors', ['id' => $id, 'devicesensors_id' => 100,
                'itemtype' => $kind, 'items_id' => $subject, 'entities_id' => 0, 'serial' => $audit]);
        }
        $connection->insert('glpi_certificates', ['id' => 100, 'name' => 'Imported certificate']);
        $connection->insert('glpi_certificates_items', ['id' => 101, 'certificates_id' => 100, 'itemtype' => 'Computer', 'items_id' => $legacyId]);
        $connection->insert('glpi_logs', ['id' => 2147483646, 'itemtype' => 'Computer', 'items_id' => $legacyId, 'user_name' => 'Original administrator', 'old_value' => $audit]);
        $ciphertext = Toolbox::sodiumEncrypt('Original encrypted configuration');
        $connection->update('glpi_configs', ['value' => $ciphertext], ['context' => 'core', 'name' => 'smtp_passwd']);
        $key = (new Upgrade($DB))->expectedSecurityKeyPath();
        $this->boolean(is_string($key) && is_file($key))->isTrue();
        $hash = hash_file('sha256', $key);
        $directory = sys_get_temp_dir() . '/itsm-upgrade-test-' . bin2hex(random_bytes(6));
        $this->boolean(mkdir($directory, 0700))->isTrue();
        try {
            $class = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'DBpgsql' : 'DBmysql';
            $config = '<?php class DB extends ' . $class . ' {';
            foreach (['dbhost', 'dbuser', 'dbpassword', 'dbdefault'] as $property) {
                $config .= ' public $' . $property . ' = ' . var_export($database->$property, true) . ';';
            }
            file_put_contents($directory . '/config_db.php', $config . '}');
            chmod($directory . '/config_db.php', 0600);
            $this->boolean(copy($key, $directory . '/glpicrypt.key'))->isTrue();
            chmod($directory . '/glpicrypt.key', 0600);
            foreach (['version', 'itsmversion', 'dbversion', 'itsmdbversion'] as $name) {
                $connection->update('glpi_configs', ['value' => '2.1.2'], ['context' => 'core', 'name' => $name]);
            }
            $rows = $this->rowBags($connection);
            $schema = $manager->introspectSchema();
            $policies = BooleanDomainSchema::catalog($connection);
            foreach ([['db:update', '--dry-run'], ['db:update']] as $arguments) {
                [$status, $output] = $this->console($directory, $arguments);
                $this->integer($status)->isNotEqualTo(0);
                $this->string($output)->contains('Historical ITSM-NG adoption provenance');
                $this->array($this->rowBags($connection))->isIdenticalTo($rows);
                $this->boolean($manager->tablesExist([Ledger::TABLE]))->isFalse();
                $this->boolean($manager->createComparator()->compareSchemas($schema, $manager->introspectSchema())->isEmpty())->isTrue();
                $this->array(BooleanDomainSchema::catalog($connection))->isIdenticalTo($policies);
            }
            foreach (['version', 'itsmversion', 'dbversion', 'itsmdbversion'] as $name) {
                $connection->update('glpi_configs', ['value' => '2.1.3'], ['context' => 'core', 'name' => $name]);
            }
            [$status, $output] = $this->console($directory, ['db:update']);
            $this->integer($status)->isIdenticalTo(0, $output);
            $this->array((new SchemaCheck())->differences($connection))->isEmpty();
            $this->array(History::pendingVersions($connection))->isEmpty();
            $this->boolean(History::isInstalling($connection))->isFalse();
            $link = $connection->fetchAssociative('SELECT id, certificates_id, computers_id, items_id FROM glpi_certificates_items WHERE id = 101');
            $this->array(array_map('intval', $link))->isIdenticalTo(['id' => 101, 'certificates_id' => 100, 'computers_id' => $legacyId, 'items_id' => $legacyId]);
            $this->variable($connection->fetchOne('SELECT computermodels_id FROM glpi_computers WHERE id = ?', [$legacyId]))->isNull();
            $this->string($connection->fetchOne('SELECT old_value FROM glpi_logs WHERE id = 2147483646'))->isIdenticalTo($audit);
            $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE context = ? AND name = ?', ['core', 'smtp_passwd']))->isIdenticalTo($ciphertext);
            $this->string(Toolbox::sodiumDecrypt($ciphertext))->isIdenticalTo('Original encrypted configuration');
            $sensorRows = $connection->fetchAllAssociative('SELECT id, itemtype, items_id, computers_id, peripherals_id, serial FROM glpi_items_devicesensors ORDER BY id');
            $this->array(array_map('intval', array_column($sensorRows, 'items_id')))->isIdenticalTo([$legacyId, 100, 0]);
            $this->array(array_column($sensorRows, 'itemtype'))->isIdenticalTo(['Computer', 'Peripheral', null]);
            $this->array(array_column($sensorRows, 'serial'))->isIdenticalTo([$audit, $audit, $audit]);
            $this->variable($sensorRows[0]['peripherals_id'])->isNull();
            $this->variable($sensorRows[1]['computers_id'])->isNull();
            $this->variable($sensorRows[2]['computers_id'])->isNull();
            $this->variable($sensorRows[2]['peripherals_id'])->isNull();
            $this->boolean(Ledger::state($connection, SensorSubjects::VERSION)['complete'])->isTrue();
            $this->boolean(Ledger::state($connection, PhysicalReferenceIndexes::VERSION)['complete'])->isTrue();
            $this->array((new PhysicalReferenceIndexes())->plan($connection)['sql'])->isEmpty();
            $this->array(Ledger::state($connection, SensorSubjectDefinition::PHASE)['policy'])->hasKeys(['projection', 'check']);
            $this->assertCurrentPrefixNativeVerification($connection);
            $this->assertCurrentSubjectNativeVerification($connection);
            $this->assertTerminalSensorVerification($connection);
            $this->assertTerminalReleaseOrder($connection);
            $after = $this->rowBags($connection);
            [$status, $output] = $this->console($directory, ['db:update']);
            $this->integer($status)->isIdenticalTo(0, $output);
            $this->array($this->rowBags($connection))->isIdenticalTo($after);
            $this->string(hash_file('sha256', $key))->isIdenticalTo($hash);
            $this->string(hash_file('sha256', $directory . '/glpicrypt.key'))->isIdenticalTo($hash);
            $id = (new RecordWriter(Orm::create($database)))->insert('glpi_computers', ['name' => 'After upgrade']);
            $this->integer($id)->isGreaterThan($legacyId);
            $row = (new RecordRepository(Orm::create($database)))->find('glpi_computers', 'id', $id);
            $this->integer($row['id'])->isIdenticalTo($id);
        } finally {
            foreach (['config_db.php', 'glpicrypt.key'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
        $this->fixtureCompleted = true;
    }

    /** The entity-owned native tuple remains mandatory after historical migration receipts. */
    private function assertCurrentPrefixNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform) {
            return;
        }
        $owner = new BaselineSchema();
        $schema = $owner->build($platform);
        $policies = $owner->nativeIndexPolicies();
        $this->integer(count($policies))->isIdenticalTo(2);
        $this->integer(array_sum(array_map('count', $policies)))->isIdenticalTo(3);
        $rows = $this->rowBags($connection);
        $ledger = Ledger::states($connection);
        $quote = $platform->quoteIdentifier(...);
        foreach ($policies as $table => $indexes) {
            $selected = new Schema([clone $schema->getTable($table)]);
            $inspect = static fn (): array => PhysicalIndexSchema::differences($connection, $selected, [$table => $indexes]);
            $this->array($inspect())->isEmpty();
            foreach ($indexes as $name => $policy) {
                $diagnostic = 'Missing or changed native prefix index: ' . $table . '.' . $name;
                $keys = array_map(static fn (string $column, int $length): string => 'pg_catalog.left('
                    . $quote($column) . ', ' . $length . ')', $policy['columns'], $policy['lengths']);
                foreach (['missing', 'shorter', 'descending', 'predicate', 'included', 'operator_class'] as $variant) {
                    $connection->beginTransaction();
                    try {
                        $connection->executeStatement('DROP INDEX ' . $quote($name));
                        if ($variant !== 'missing') {
                            $altered = $keys;
                            if ($variant === 'shorter') {
                                $altered[0] = 'pg_catalog.left(' . $quote($policy['columns'][0]) . ', ' . ($policy['lengths'][0] - 1) . ')';
                            } elseif ($variant === 'descending') {
                                $altered[0] .= ' DESC';
                            } elseif ($variant === 'operator_class') {
                                $altered[0] .= ' text_pattern_ops';
                            }
                            $sql = 'CREATE INDEX ' . $quote($name) . ' ON ' . $quote($table) . ' (' . implode(', ', $altered) . ')';
                            if ($variant === 'predicate') {
                                $sql .= ' WHERE ' . $quote($policy['columns'][0]) . ' IS NOT NULL';
                            } elseif ($variant === 'included') {
                                $sql .= ' INCLUDE (' . $quote($policy['columns'][0]) . ')';
                            }
                            $connection->executeStatement($sql);
                        }
                        $this->array($inspect())->isIdenticalTo([$diagnostic], $variant);
                        $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
                        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
                    } finally {
                        // Transactional PostgreSQL DDL restores the exact historical
                        // native expression, dependencies and every original row.
                        $connection->rollBack();
                    }
                    $this->array($inspect())->isEmpty();
                }
            }
        }
        $this->array($this->rowBags($connection))->isIdenticalTo($rows);
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
    }

    /** Existing installed domains remain mandatory after every migration receipt is complete. */
    private function assertCurrentNonnegativeNativeVerification(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        $platform = $connection->getDatabasePlatform();
        $owner = new BaselineSchema();
        $owner->build($platform);
        $policies = $owner->nonNegativePolicies();
        $quote = $platform->quoteIdentifier(...);
        $catalog = static fn (?string $table = null): array => NativeCheckCatalog::snapshot($connection, $table)['checks'];
        $this->array(NativeNonNegativeSchema::compare($policies, $catalog()))->isEmpty();
        $ledger = Ledger::states($connection);
        $rows = [];
        foreach (array_keys($policies) as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id');
        }
        $publishedDiagnostic = false;
        foreach ($policies as $table => $fields) {
            foreach ($fields as $column => $policy) {
                $selected = [$table => [$column => $policy]];
                $diagnostic = 'Changed, missing or unenforced native nonnegative CHECK: ' . $table . '.' . $policy['constraint'];
                $drop = 'ALTER TABLE ' . $quote($table) . ' DROP CONSTRAINT ' . $quote($policy['constraint']);
                foreach ([[null, false], [$quote($column) . ' >= -1', false], [$policy['check'], true]] as [$replacement, $notValid]) {
                    $connection->beginTransaction();
                    try {
                        $connection->executeStatement($drop);
                        if ($replacement !== null) {
                            // NOT VALID changes native enforcement evidence even when its expression is exact.
                            $clause = $replacement;
                            $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT '
                                . $quote($policy['constraint']) . ' CHECK (' . $clause . ')' . ($notValid ? ' NOT VALID' : ''));
                        }
                        $this->array(NativeNonNegativeSchema::compare($selected, $catalog($table)))->isIdenticalTo([$diagnostic]);
                        if (!$publishedDiagnostic) {
                            $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
                            $publishedDiagnostic = true;
                        }
                        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
                    } finally {
                        // Native DDL, definitions, dependencies and rows return to the original snapshot.
                        $connection->rollBack();
                    }
                    $this->array(NativeNonNegativeSchema::compare($selected, $catalog($table)))->isEmpty();
                }
            }
        }
        foreach ($rows as $table => $original) {
            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id'))->isIdenticalTo($original);
        }
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        $this->array((new SchemaCheck())->differences($connection))->isEmpty();
    }

    /** Nonterminal subjects still belong to current native policy after later releases complete. */
    private function assertCurrentSubjectNativeVerification(Connection $connection): void
    {
        $this->assertCurrentInheritedNativeVerification($connection);
        $this->assertCurrentNotificationRecipientNativeVerification($connection);
        $this->assertCurrentNonnegativeNativeVerification($connection);
        $this->assertCurrentUserAuthenticationNativeVerification($connection);
        $platform = $connection->getDatabasePlatform();
        $builder = new BaselineSchema();
        $schema = $builder->build($platform);
        $policies = $builder->subjectPolicies();
        $table = 'glpi_certificates_items';
        $policy = $policies[$table]['items_id'];
        $selected = [$table => ['items_id' => $policy]];
        $inspect = static fn (): array => NativeSubjectSchema::differences($connection, $selected);
        $this->array($inspect())->isEmpty();
        $quote = $platform->quoteIdentifier(...);
        $drop = 'ALTER TABLE ' . $quote($table) . ' DROP '
            . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
        $restore = 'ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT ' . $quote($policy['constraint'])
            . ' CHECK (' . $policy['check'] . ')'
            . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        $ledger = Ledger::states($connection);
        $connection->executeStatement($drop);
        try {
            $diagnostic = 'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'];
            $this->array($inspect())->isIdenticalTo([$diagnostic]);
            $published = false;
            $this->exception(static function () use ($connection, &$published): void {
                (new History())->upgrade($connection, onComplete: static function () use (&$published): void {
                    $published = true;
                });
            })->isInstanceOf(RuntimeException::class)->hasMessage("Migration history did not converge:\n" . $diagnostic);
            $this->boolean($published)->isFalse();
            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        } finally {
            $connection->executeStatement($restore);
        }
        $this->array($inspect())->isEmpty();
        $diagnostic = 'Changed or missing native subject projection: ' . $table . '.items_id';
        if ($platform instanceof PostgreSQLPlatform) {
            // PostgreSQL 14 cannot replace a generation expression in place.
            // A rollback restores the original column and every dependent index.
            $connection->beginTransaction();
            try {
                $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ALTER COLUMN items_id DROP EXPRESSION');
                $this->array($inspect())->isIdenticalTo([$diagnostic]);
            } finally {
                $connection->rollBack();
            }
        } else {
            $comment = $schema->getTable($table)->getColumn('items_id')->getComment();
            $alter = static fn (string $expression): string => 'ALTER TABLE ' . $quote($table)
                . ' MODIFY COLUMN items_id BIGINT GENERATED ALWAYS AS (' . $expression . ') STORED'
                . ($comment === '' ? '' : ' ' . $platform->getInlineColumnCommentSQL($comment));
            $connection->executeStatement($alter('0'));
            try {
                $this->array($inspect())->isIdenticalTo([$diagnostic]);
            } finally {
                // The strict inspection above proved this current declaration matches.
                // INFORMATION_SCHEMA may escape literal delimiters; its text is not DDL.
                $connection->executeStatement($alter($policy['projection']));
            }
        }
        $this->array($inspect())->isEmpty();
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
    }


    /** Fallback authentication still needs its installed native CHECK after release completion. */
    private function assertCurrentInheritedNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $owner = new BaselineSchema();
        $owner->build($platform);
        $policies = $owner->referencePolicies();
        $this->integer(count($policies['glpi_entities']))->isIdenticalTo(6);
        $quote = $platform->quoteIdentifier(...);
        $ledger = Ledger::states($connection);
        $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_entities') . ' ORDER BY id');
        foreach ($policies as $table => $fields) {
            foreach ($fields as $property => $policy) {
                $selected = [$table => [$property => $policy]];
                $inspect = static fn (): array => NativeReferenceSchema::compare($selected, NativeCheckCatalog::snapshot($connection, $table));
                $this->array($inspect())->isEmpty();
                $diagnostic = 'Changed, missing or unenforced native inherited reference CHECK: ' . $table . '.' . $policy['constraint'];
                $drop = 'ALTER TABLE ' . $quote($table) . ' DROP '
                    . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
                $add = static fn (string $check): string => 'ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT '
                    . $quote($policy['constraint']) . ' CHECK (' . $check . ')'
                    . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
                $dropped = $weakened = false;
                $transactional = $platform instanceof PostgreSQLPlatform;
                $nativeBefore = $transactional ? NativeCheckCatalog::snapshot($connection, $table) : null;
                $constraintOid = static fn () => $connection->fetchOne(
                    'SELECT c.oid::text FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class t ON t.oid = c.conrelid '
                    . 'WHERE t.relname = ? AND c.conname = ? AND pg_catalog.pg_table_is_visible(t.oid)',
                    [$table, $policy['constraint']]
                );
                $oidBefore = $transactional ? $constraintOid() : null;
                if ($transactional) {
                    $connection->beginTransaction();
                }
                try {
                    $connection->executeStatement($drop);
                    $dropped = true;
                    $this->array($inspect())->isIdenticalTo([$diagnostic]);
                    if ($property === array_key_first($fields)) {
                        $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
                    }
                    $connection->executeStatement($add('1 = 1'));
                    $weakened = true;
                    $this->array($inspect())->isIdenticalTo([$diagnostic]);
                    $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
                } finally {
                    if ($transactional) {
                        // PostgreSQL rollback restores the exact original CHECK,
                        // including its OID, native definition and every row.
                        $connection->rollBack();
                    } elseif ($dropped) {
                        if ($weakened) {
                            $connection->executeStatement($drop);
                        }
                        $connection->executeStatement($add($policy['check']));
                    }
                }
                $this->array($inspect())->isEmpty();
                if ($transactional) {
                    $this->array(NativeCheckCatalog::snapshot($connection, $table))->isIdenticalTo($nativeBefore);
                    $this->variable($constraintOid())->isIdenticalTo($oidBefore);
                }
                $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_entities') . ' ORDER BY id'))->isIdenticalTo($rows);
                $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
            }
        }
        $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_entities') . ' ORDER BY id'))->isIdenticalTo($rows);
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
    }

    private function assertCurrentNotificationRecipientNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $builder = new BaselineSchema();
        $builder->build($platform);
        $policy = $builder->subjectPolicies()['glpi_notificationtargets']['items_id'];
        $selected = ['glpi_notificationtargets' => ['items_id' => $policy]];
        $inspect = static fn (): array => NativeSubjectSchema::differences($connection, $selected);
        $this->array($inspect())->isEmpty('The actual installed CHECK and generated fallback projection match current property policy');
        $quote = $platform->quoteIdentifier(...);
        $drop = 'ALTER TABLE ' . $quote('glpi_notificationtargets') . ' DROP '
            . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
        $add = static fn (string $check): string => 'ALTER TABLE ' . $quote('glpi_notificationtargets') . ' ADD CONSTRAINT '
            . $quote($policy['constraint']) . ' CHECK (' . $check . ')'
            . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        $diagnostic = 'Changed, missing or unenforced native subject CHECK: glpi_notificationtargets.' . $policy['constraint'];
        $ledger = Ledger::states($connection);
        $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_notificationtargets') . ' ORDER BY id');
        $constraintDropped = $weakenedInstalled = false;
        try {
            $connection->executeStatement($drop);
            $constraintDropped = true;
            $this->array($inspect())->isIdenticalTo([$diagnostic]);
            $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
            $connection->executeStatement($add('1 = 1'));
            $weakenedInstalled = true;
            $this->array($inspect())->isIdenticalTo([$diagnostic]);
            $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
        } finally {
            if ($constraintDropped) {
                if ($weakenedInstalled) {
                    $connection->executeStatement($drop);
                }
                $connection->executeStatement($add($policy['check']));
            }
        }
        $this->array($inspect())->isEmpty();
        $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_notificationtargets') . ' ORDER BY id'))->isIdenticalTo($rows);
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
    }

    private function assertCurrentUserAuthenticationNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $builder = new BaselineSchema();
        $builder->build($platform);
        $policy = $builder->subjectPolicies()['glpi_users']['auths_id'];
        $selected = ['glpi_users' => ['auths_id' => $policy]];
        $inspect = static fn (): array => NativeSubjectSchema::differences($connection, $selected);
        $this->array($inspect())->isEmpty('The actual installed CHECK and generated fallback projection match current property policy');
        $quote = $platform->quoteIdentifier(...);
        $drop = 'ALTER TABLE ' . $quote('glpi_users') . ' DROP '
            . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
        $add = static fn (string $check): string => 'ALTER TABLE ' . $quote('glpi_users') . ' ADD CONSTRAINT '
            . $quote($policy['constraint']) . ' CHECK (' . $check . ')'
            . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        $diagnostic = 'Changed, missing or unenforced native subject CHECK: glpi_users.' . $policy['constraint'];
        $ledger = Ledger::states($connection);
        $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_users') . ' ORDER BY id');
        $constraintDropped = $weakenedInstalled = false;
        try {
            $connection->executeStatement($drop);
            $constraintDropped = true;
            $this->array($inspect())->isIdenticalTo([$diagnostic]);
            $connection->executeStatement($add('1 = 1'));
            $weakenedInstalled = true;
            $this->array($inspect())->isIdenticalTo([$diagnostic]);
        } finally {
            if ($constraintDropped) {
                if ($weakenedInstalled) {
                    $connection->executeStatement($drop);
                }
                $connection->executeStatement($add($policy['check']));
            }
        }
        $this->array($inspect())->isEmpty();
        $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_users') . ' ORDER BY id'))->isIdenticalTo($rows);
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
    }

    private function assertTerminalSensorVerification(Connection $connection): void
    {
        $version = SensorSubjects::VERSION;
        $phase = SensorSubjectDefinition::PHASE;
        $receipt = Ledger::state($connection, $version);
        $proof = Ledger::state($connection, $phase);
        $table = 'glpi_items_devicesensors';
        $constraint = $table . '_typed_item_kind';
        $platform = $connection->getDatabasePlatform();
        $drop = 'ALTER TABLE ' . $table . ' DROP '
            . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $constraint;
        $policy = static fn () => ExactDiscriminators::nativePolicy(
            $connection,
            $table,
            ['column' => 'items_id', 'constraint' => $constraint]
        );
        foreach ([['complete' => true], ['complete' => false, 'applied' => true]] as $state) {
            Ledger::save($connection, $version, $state);
            foreach (['proof', 'missing-check', 'changed-check'] as $damage) {
                if ($damage === 'proof') {
                    $connection->delete(Ledger::TABLE, ['version' => $phase]);
                    $diagnostic = 'Experimental typed-subject receipt lacks retained post-DDL native policy: ' . $table
                        . '. Restore the genuine 2.1.3 source and apply the supported transition; no receipt or data was rewritten.';
                } else {
                    $connection->executeStatement($drop);
                    if ($damage === 'changed-check') {
                        $connection->executeStatement('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $constraint . ' CHECK (1 = 1)');
                    }
                    $diagnostic = $damage === 'missing-check'
                        ? 'Frozen typed subject conversion did not converge: ' . $table
                        : 'Frozen subject native policy changed after authoritative DDL: ' . $table;
                }
                $before = Ledger::states($connection);
                $native = $policy();
                $published = false;
                $this->exception(static function () use ($connection, &$published): void {
                    (new History())->upgrade($connection, onComplete: static function () use (&$published): void {
                        $published = true;
                    });
                })->isInstanceOf(RuntimeException::class)->hasMessage($diagnostic);
                $this->boolean($published)->isFalse();
                $this->array(Ledger::states($connection))->isIdenticalTo($before, 'Neither predecessor nor terminal receipts are rewritten');
                $this->array($policy())->isIdenticalTo($native, 'Failed verification does not repair native policy');
                if ($damage === 'proof') {
                    Ledger::save($connection, $phase, $proof);
                } else {
                    if ($damage === 'changed-check') {
                        $connection->executeStatement($drop);
                    }
                    $connection->executeStatement(SensorSubjectDefinition::checkSql($table, $platform)
                        . ($platform instanceof MySQLPlatform ? ' ENFORCED' : ''));
                }
                (new SensorSubjects())->verify($connection);
            }
        }
        Ledger::save($connection, $version, $receipt);
    }

    private function assertTerminalReleaseOrder(Connection $connection): void
    {
        $calls = new ArrayObject();
        $release = static fn (string $name) => new class ($name, $calls) implements ReleaseMigration {
            public function __construct(private string $name, private ArrayObject $calls)
            {
            }
            public function version(): string
            {
                return $this->name;
            }
            public function plan(Connection $connection): array
            {
                throw new LogicException('Replay cannot preview a release');
            }
            public function apply(Connection $connection, ?callable $progress = null): void
            {
                $this->calls[] = $this->name . '.apply';
            }
            public function verify(Connection $connection): void
            {
                $this->calls[] = $this->name . '.verify';
            }
        };
        $history = new History([$release('fixture-predecessor'), $release('fixture-terminal')]);
        foreach ([null, ['complete' => false, 'applied' => true], ['complete' => true]] as $state) {
            Ledger::save($connection, 'fixture-predecessor', $state === null ? ['complete' => false, 'applied' => true] : ['complete' => true]);
            if ($state !== null) {
                Ledger::save($connection, 'fixture-terminal', $state);
            }
            $calls->exchangeArray([]);
            $history->upgrade($connection, onComplete: static function () use ($calls): void {
                $calls[] = 'publish';
            });
            $this->array($calls->getArrayCopy())->isIdenticalTo($state === null
                ? ['fixture-terminal.apply', 'fixture-terminal.verify', 'publish']
                : ['fixture-terminal.verify', 'publish']);
        }
        $connection->delete(Ledger::TABLE, ['version' => 'fixture-predecessor']);
        $connection->delete(Ledger::TABLE, ['version' => 'fixture-terminal']);
        $before = Ledger::states($connection);
        $calls->exchangeArray([]);
        (new History([]))->upgrade($connection, onComplete: static function () use ($calls): void {
            $calls[] = 'publish';
        });
        $this->array($calls->getArrayCopy())->isIdenticalTo(['publish']);
        $this->array(Ledger::states($connection))->isIdenticalTo($before);
    }

    private function rowBags(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $rows[$table] = array_map(serialize(...), $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table)));
            sort($rows[$table], SORT_STRING);
        }
        ksort($rows);
        return $rows;
    }

    private function console(string $directory, array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, GLPI_ROOT . '/bin/console', '--config-dir=' . $directory, '--no-interaction', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            GLPI_ROOT
        );
        $this->boolean(is_resource($process))->isTrue();
        fclose($pipes[0]);
        try {
            $output = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
        }
        return [proc_close($process), $output];
    }
}
