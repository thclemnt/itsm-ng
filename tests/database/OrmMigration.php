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
use itsmng\Database\Migration\GraphicCardParents;
use itsmng\Database\Migration\ComponentParents;
use itsmng\Database\Migration\GraphicCardParents\Definition as GraphicCardParentDefinition;
use itsmng\Database\Migration\IPAddressParents;
use itsmng\Database\Migration\IPAddressParents\Definition as IPAddressParentDefinition;
use itsmng\Database\Migration\NetworkNameParents;
use itsmng\Database\Migration\NetworkNameParents\Definition as NetworkNameParentDefinition;
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
        $this->array(History::versions())->isIdenticalTo(['2.2.0', SensorSubjects::VERSION, PhysicalReferenceIndexes::VERSION, NetworkNameParents::VERSION, IPAddressParents::VERSION, GraphicCardParents::VERSION, ComponentParents::VERSION]);
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
            $this->boolean(Ledger::state($connection, NetworkNameParents::VERSION)['complete'])->isTrue();
            $this->boolean(Ledger::state($connection, IPAddressParents::VERSION)['complete'])->isTrue();
            $this->boolean(Ledger::state($connection, GraphicCardParents::VERSION)['complete'])->isTrue();
            $this->boolean(Ledger::state($connection, ComponentParents::VERSION)['complete'])->isTrue();
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
        $this->assertCurrentRootAndCalendarNativeVerification($connection);
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


    /** Valid rows cannot substitute for exact installed root/calendar CHECK ownership. */
    private function assertCurrentRootAndCalendarNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $owner = new BaselineSchema();
        $owner->build($platform);
        $policies = array_map(static fn (array $fields): array => array_filter($fields, static fn (array $policy): bool => isset($policy['kind'])), $owner->referencePolicies());
        $this->integer(count(array_filter($policies)))->isIdenticalTo(2);
        $quote = $platform->quoteIdentifier(...);
        $ledger = Ledger::states($connection);
        $rows = [];
        foreach (['glpi_entities', 'glpi_slms', 'glpi_calendars'] as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id');
            $this->variable($connection->fetchOne('SELECT id FROM ' . $quote($table) . ' WHERE id = 2147483000'))->isIdenticalTo(false);
        }
        $connection->insert('glpi_entities', ['id' => 2147483000, 'name' => 'Native root policy child', 'entities_id' => 0]);
        $connection->insert('glpi_calendars', ['id' => 2147483000, 'name' => 'Actual selected calendar', 'entities_id' => 0]);
        $connection->insert('glpi_slms', ['id' => 2147483000, 'name' => 'Actual selection policy', 'entities_id' => 0,
            'calendars_id' => 2147483000, 'use_ticket_calendar' => 0]);
        try {
            // Existing real FK targets isolate correlation/self-parent CHECK violations.
            foreach ([['glpi_entities', ['entities_id' => 0], 0],
                ['glpi_entities', ['entities_id' => 2147483000], 2147483000],
                ['glpi_entities', ['entities_id' => null], 2147483000],
                ['glpi_slms', ['use_ticket_calendar' => 1], 2147483000]] as [$table, $change, $id]) {
                $this->exception(static fn () => $connection->transactional(static fn () =>
                    $connection->update($table, $change, ['id' => $id])))->isInstanceOf(DbalException::class);
            }
            $connection->update('glpi_slms', ['calendars_id' => null, 'use_ticket_calendar' => 1], ['id' => 2147483000]);
            $this->variable($connection->fetchOne('SELECT calendars_id FROM glpi_slms WHERE id = 2147483000'))->isNull();
            $connection->update('glpi_slms', ['calendars_id' => 2147483000, 'use_ticket_calendar' => 0], ['id' => 2147483000]);
            foreach ($policies as $table => $fields) {
                foreach ($fields as $property => $policy) {
                    $selected = [$table => [$property => $policy]];
                    $inspect = static fn (): array => NativeReferenceSchema::compare($selected, NativeCheckCatalog::snapshot($connection, $table));
                    $this->array($inspect())->isEmpty();
                    $diagnostic = 'Changed, missing or unenforced native reference CHECK: ' . $table . '.' . $policy['constraint'];
                    $drop = 'ALTER TABLE ' . $quote($table) . ' DROP '
                        . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
                    $add = static fn (string $clause, string $suffix): string => 'ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT '
                        . $quote($policy['constraint']) . ' CHECK (' . $clause . ')' . $suffix;
                    $suffix = $platform instanceof MySQLPlatform ? ' ENFORCED' : '';
                    $weakened = $table === 'glpi_entities'
                        ? str_replace(' AND ' . $quote($policy['selected_column']) . ' <> ' . $quote('id'), '', $policy['check'])
                        : str_replace(' AND NOT ' . $quote($policy['boolean_columns'][0]), '', $policy['check']);
                    $this->string($weakened)->isNotEqualTo($policy['check']);
                    $cases = [[null, ''], ['1 = 1', $suffix], [$weakened, $suffix]];
                    if ($platform instanceof PostgreSQLPlatform) {
                        $cases[] = [$policy['check'], ' NOT VALID'];
                        if ((int)$connection->fetchOne("SELECT current_setting('server_version_num')") >= 180000) {
                            $cases[] = [$policy['check'], ' NOT ENFORCED'];
                        }
                    } elseif ($platform instanceof MySQLPlatform) {
                        $cases[] = [$policy['check'], ' NOT ENFORCED'];
                    }
                    foreach ($cases as [$clause, $caseSuffix]) {
                        $transactional = $platform instanceof PostgreSQLPlatform;
                        $nativeBefore = $transactional ? NativeCheckCatalog::snapshot($connection, $table) : null;
                        $tableRows = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id');
                        $dropped = $replacement = false;
                        if ($transactional) {
                            $connection->beginTransaction();
                        }
                        try {
                            $connection->executeStatement($drop);
                            $dropped = true;
                            if ($clause !== null) {
                                $connection->executeStatement($add($clause, $caseSuffix));
                                $replacement = true;
                            }
                            $this->array($inspect())->isIdenticalTo([$diagnostic]);
                            $this->array((new SchemaCheck())->differences($connection))->contains($diagnostic);
                            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id'))->isIdenticalTo($tableRows);
                            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
                        } finally {
                            if ($transactional) {
                                $connection->rollBack();
                            } elseif ($dropped) {
                                if ($replacement) {
                                    $connection->executeStatement($drop);
                                }
                                $connection->executeStatement($add($policy['check'], $suffix));
                            }
                        }
                        $this->array($inspect())->isEmpty();
                        if ($transactional) {
                            $this->array(NativeCheckCatalog::snapshot($connection, $table))->isIdenticalTo($nativeBefore);
                        }
                        $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id'))->isIdenticalTo($tableRows);
                    }
                }
            }
        } finally {
            $connection->delete('glpi_slms', ['id' => 2147483000]);
            $connection->delete('glpi_calendars', ['id' => 2147483000]);
            $connection->delete('glpi_entities', ['id' => 2147483000]);
        }
        foreach ($rows as $table => $before) {
            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY id'))->isIdenticalTo($before);
        }
        $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        $this->array((new SchemaCheck())->differences($connection))->isEmpty();
    }

    /** Fallback authentication still needs its installed native CHECK after release completion. */
    private function assertCurrentInheritedNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $owner = new BaselineSchema();
        $owner->build($platform);
        $policies = array_map(static fn (array $fields): array => array_filter($fields, static fn (array $policy): bool => !isset($policy['kind'])), $owner->referencePolicies());
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

    public function testOrderedOpenParentStagesResume(): void
    {
        $connection = $this->emptyFixture()->getDoctrineConnection();
        $predecessor = new Version220();
        $predecessor->baseline($connection);
        $predecessor->apply($connection);
        $predecessor->verify($connection);
        (new SensorSubjects())->apply($connection);
        (new PhysicalReferenceIndexes())->apply($connection);
        (new PhysicalReferenceIndexes())->verify($connection);
        // One genuine predecessor; each release resumes its own journal in history order.
        $this->assertNetworkNameParentRetry($connection);
        $this->assertIPAddressParentRetry($connection);
        $this->assertGraphicCardParentRetry($connection);
        $this->assertRemainingComponentParentRetry($connection);
        $this->fixtureCompleted = true;
    }

    private function assertIPAddressParentRetry(Connection $connection): void
    {
        $connection->insert('glpi_networknames', ['id' => 2000, 'name' => 'Actual owning name', 'itemtype' => '', 'opaque_parent_id' => 0, 'entities_id' => 0]);
        foreach ([2000 => ['NetworkName', 2000], 2001 => ['NetworkName', 0], 2002 => ['', 0],
            2003 => ['PluginOpaqueParent', -9], 2004 => ['networkname', 2000]] as $id => [$kind, $owner]) {
            $connection->insert('glpi_ipaddresses', ['id' => $id, 'name' => '192.0.2.' . ($id - 1999), 'itemtype' => $kind, 'items_id' => $owner,
                'entities_id' => 0, 'mainitemtype' => 'HistoricalContext', 'mainitems_id' => -7]);
        }
        $release = new IPAddressParents();
        $ledger = Ledger::states($connection);
        foreach ([-1, 2001] as $invalid) {
            $connection->update('glpi_ipaddresses', ['items_id' => $invalid], ['id' => 2001]);
            $rows = $connection->fetchAllAssociative('SELECT * FROM glpi_ipaddresses ORDER BY id');
            $this->exception(static fn () => $release->plan($connection))->isInstanceOf(RuntimeException::class);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_ipaddresses ORDER BY id'))->isIdenticalTo($rows);
            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        }
        $connection->update('glpi_ipaddresses', ['items_id' => 0], ['id' => 2001]);
        $source = $connection->fetchAllAssociative('SELECT id, itemtype, items_id, mainitemtype, mainitems_id, version, binary_0, binary_1, binary_2, binary_3 FROM glpi_ipaddresses ORDER BY id');
        $oldChecks = NativeCheckCatalog::snapshot($connection, 'glpi_ipaddresses')['checks']['glpi_ipaddresses'] ?? [];
        $mainIndexName = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'glpi_ipaddresses_mainitem' : 'mainitem';
        $mainIndex = $connection->createSchemaManager()->introspectTable('glpi_ipaddresses')->getIndex($mainIndexName)->getUnquotedColumns();
        $checksBeforeRetry = NativeCheckCatalog::snapshot($connection, 'glpi_ipaddresses')['checks']['glpi_ipaddresses'] ?? [];
        $retrySourceHash = null;
        foreach (['columns', 'copy', 'constraints', 'projection'] as $phase) {
            $message = 'Interrupted IP address ' . $phase;
            $this->exception(static fn () => $release->apply($connection, static function (string $actual) use ($phase, $message): void {
                if ($actual === $phase) {
                    throw new RuntimeException($message);
                }
            }))->isInstanceOf(RuntimeException::class)->hasMessage($message);
            $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id, mainitemtype, mainitems_id, version, binary_0, binary_1, binary_2, binary_3 FROM glpi_ipaddresses ORDER BY id'))->isIdenticalTo($source);
            foreach ($ledger as $checkpoint => $priorReceipt) {
                $this->array(Ledger::state($connection, $checkpoint))->isIdenticalTo($priorReceipt);
            }
            $state = Ledger::state($connection, IPAddressParentDefinition::PHASE);
            $this->boolean($state['complete'] ?? false)->isFalse();
            $this->boolean($connection->createSchemaManager()->introspectTable('glpi_ipaddresses')->hasColumn('networknames_id'))
                ->isIdenticalTo(!($connection->getDatabasePlatform() instanceof PostgreSQLPlatform));
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $this->variable($state)->isNull();
            } else {
                $this->string($state['source_hash'])->isNotEmpty();
                $retrySourceHash ??= $state['source_hash'];
                $this->string($state['source_hash'])->isIdenticalTo($retrySourceHash);
                $this->string($state['phase'])->isIdenticalTo($phase === 'columns' ? 'audited' : ($phase === 'projection' ? 'projected' : 'copied'));
            }
            $checksAtStop = NativeCheckCatalog::snapshot($connection, 'glpi_ipaddresses')['checks']['glpi_ipaddresses'] ?? [];
            foreach ($checksBeforeRetry as $checkName => $check) {
                $this->array($checksAtStop[$checkName])->isIdenticalTo($check);
            }
        }
        $release->apply($connection);
        $release->verify($connection);
        $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id, mainitemtype, mainitems_id, version, binary_0, binary_1, binary_2, binary_3 FROM glpi_ipaddresses ORDER BY id'))->isIdenticalTo($source);
        $checks = NativeCheckCatalog::snapshot($connection, 'glpi_ipaddresses')['checks']['glpi_ipaddresses'];
        foreach ($oldChecks as $name => $check) {
            $this->array($checks[$name])->isIdenticalTo($check);
        }
        $this->array($connection->createSchemaManager()->introspectTable('glpi_ipaddresses')->getIndex($mainIndexName)->getUnquotedColumns())->isIdenticalTo($mainIndex);
        $receipt = Ledger::state($connection, IPAddressParentDefinition::PHASE);
        $this->boolean($receipt['complete'])->isTrue();
        $release->apply($connection, static fn () => throw new LogicException('Completed address adoption replayed'));
        $this->array(Ledger::state($connection, IPAddressParentDefinition::PHASE))->isIdenticalTo($receipt);
        foreach ([['networknames_id' => 2001], ['networknames_id' => 0], ['itemtype' => 'PluginOpaqueParent'], ['opaque_parent_id' => 2000], ['items_id' => null]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () => $connection->update('glpi_ipaddresses', $invalid, ['id' => 2000])))->isInstanceOf(DbalException::class);
        }
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->insert('glpi_ipaddresses', ['id' => 2006, 'name' => '192.0.2.106', 'itemtype' => 'NetworkName',
                'networknames_id' => 2001, 'opaque_parent_id' => null, 'entities_id' => 0])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->update('glpi_ipaddresses', ['opaque_parent_id' => null], ['id' => 2003])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () => $connection->delete('glpi_networknames', ['id' => 2000])))->isInstanceOf(DbalException::class);
        $connection->update('glpi_ipaddresses', ['networknames_id' => null], ['id' => 2000]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_ipaddresses WHERE id=2000'))->isIdenticalTo(0);
        $connection->update('glpi_ipaddresses', ['opaque_parent_id' => -99], ['id' => 2003]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_ipaddresses WHERE id=2003'))->isIdenticalTo(-99);
        $connection->insert('glpi_ipaddresses', ['id' => 2005, 'name' => '192.0.2.105', 'itemtype' => '', 'opaque_parent_id' => 0, 'entities_id' => 0]);
        $release->verify($connection);
        $connection->delete('glpi_ipaddresses', ['id' => 2005]);
        $release->verify($connection);
    }

    private function assertNetworkNameParentRetry(Connection $connection): void
    {
        $connection->insert('glpi_networkports', ['id' => 1000, 'name' => 'Adopted port', 'itemtype' => 'Computer', 'items_id' => 0, 'entities_id' => 0]);
        foreach ([1000 => ['NetworkPort', 1000], 1001 => ['NetworkPort', 0], 1002 => ['', 0],
            1003 => ['PluginOpaqueParent', -9], 1004 => ['networkport', 1000]] as $id => [$kind, $owner]) {
            $connection->insert('glpi_networknames', ['id' => $id, 'name' => 'adopt-' . $id, 'itemtype' => $kind, 'items_id' => $owner, 'entities_id' => 0]);
        }
        $release = new NetworkNameParents();
        $ledger = Ledger::states($connection);
        foreach ([-1, 1001] as $invalid) {
            $connection->update('glpi_networknames', ['items_id' => $invalid], ['id' => 1001]);
            $rows = $connection->fetchAllAssociative('SELECT id, itemtype, items_id FROM glpi_networknames ORDER BY id');
            $this->exception(static fn () => $release->plan($connection))->isInstanceOf(RuntimeException::class);
            $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id FROM glpi_networknames ORDER BY id'))->isIdenticalTo($rows);
            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        }
        $connection->update('glpi_networknames', ['items_id' => 0], ['id' => 1001]);
        $source = $connection->fetchAllAssociative('SELECT id, itemtype, items_id FROM glpi_networknames ORDER BY id');
        $checksBeforeRetry = NativeCheckCatalog::snapshot($connection, 'glpi_networknames')['checks']['glpi_networknames'] ?? [];
        $retrySourceHash = null;
        foreach (['columns', 'copy', 'constraints', 'projection'] as $phase) {
            $message = 'Interrupted network name ' . $phase;
            $this->exception(static fn () => $release->apply($connection, static function (string $actual) use ($phase, $message): void {
                if ($actual === $phase) {
                    throw new RuntimeException($message);
                }
            }))->isInstanceOf(RuntimeException::class)->hasMessage($message);
            $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id FROM glpi_networknames ORDER BY id'))->isIdenticalTo($source);
            foreach ($ledger as $checkpoint => $priorReceipt) {
                $this->array(Ledger::state($connection, $checkpoint))->isIdenticalTo($priorReceipt);
            }
            $state = Ledger::state($connection, NetworkNameParentDefinition::PHASE);
            $this->boolean($state['complete'] ?? false)->isFalse();
            $this->boolean($connection->createSchemaManager()->introspectTable('glpi_networknames')->hasColumn('networkports_id'))
                ->isIdenticalTo(!($connection->getDatabasePlatform() instanceof PostgreSQLPlatform));
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $this->variable($state)->isNull();
            } else {
                $this->string($state['source_hash'])->isNotEmpty();
                $retrySourceHash ??= $state['source_hash'];
                $this->string($state['source_hash'])->isIdenticalTo($retrySourceHash);
                $this->string($state['phase'])->isIdenticalTo($phase === 'columns' ? 'audited' : ($phase === 'projection' ? 'projected' : 'copied'));
            }
            $checksAtStop = NativeCheckCatalog::snapshot($connection, 'glpi_networknames')['checks']['glpi_networknames'] ?? [];
            foreach ($checksBeforeRetry as $checkName => $check) {
                $this->array($checksAtStop[$checkName])->isIdenticalTo($check);
            }
        }
        $release->apply($connection);
        $release->verify($connection);
        $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id FROM glpi_networknames ORDER BY id'))->isIdenticalTo($source);
        $receipt = Ledger::state($connection, NetworkNameParentDefinition::PHASE);
        $this->boolean($receipt['complete'])->isTrue();
        $release->apply($connection, static fn () => throw new LogicException('Completed name adoption replayed'));
        $this->array(Ledger::state($connection, NetworkNameParentDefinition::PHASE))->isIdenticalTo($receipt);
        foreach ([['networkports_id' => 1001], ['networkports_id' => 0], ['itemtype' => 'PluginOpaqueParent'],
            ['opaque_parent_id' => 1000], ['items_id' => null]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->update('glpi_networknames', $invalid, ['id' => 1000])))->isInstanceOf(DbalException::class);
        }
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->insert('glpi_networknames', ['id' => 1006, 'name' => 'orphan-insert', 'itemtype' => 'NetworkPort',
                'networkports_id' => 1001, 'opaque_parent_id' => null, 'entities_id' => 0])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->delete('glpi_networkports', ['id' => 1000])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->update('glpi_networknames', ['opaque_parent_id' => null], ['id' => 1003])))->isInstanceOf(DbalException::class);
        $connection->update('glpi_networknames', ['networkports_id' => null], ['id' => 1000]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_networknames WHERE id=1000'))->isIdenticalTo(0);
        $connection->update('glpi_networknames', ['opaque_parent_id' => -99], ['id' => 1003]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_networknames WHERE id=1003'))->isIdenticalTo(-99);
        // Completion proof must continue to admit ordinary subsequent writes.
        $release->verify($connection);
        $ledgerBeforeDamage = Ledger::states($connection);
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach (['NOT VALID', 'DEFERRABLE INITIALLY IMMEDIATE'] as $damage) {
                $connection->beginTransaction();
                try {
                    $connection->executeStatement('ALTER TABLE glpi_networknames DROP CONSTRAINT fk_networknames_networkports_id');
                    $connection->executeStatement('ALTER TABLE glpi_networknames ADD CONSTRAINT fk_networknames_networkports_id '
                        . 'FOREIGN KEY (networkports_id) REFERENCES glpi_networkports(id) ON DELETE RESTRICT ' . $damage);
                    $this->exception(static fn () => $release->verify($connection))->isInstanceOf(RuntimeException::class);
                    $this->array(Ledger::states($connection))->isIdenticalTo($ledgerBeforeDamage);
                } finally {
                    $connection->rollBack();
                }
                $release->verify($connection);
            }
        } else {
            $checks = (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks');
            try {
                $connection->executeStatement('SET SESSION foreign_key_checks=0');
                $this->exception(static fn () => $release->verify($connection))->isInstanceOf(RuntimeException::class);
                $this->array(Ledger::states($connection))->isIdenticalTo($ledgerBeforeDamage);
            } finally {
                $connection->executeStatement('SET SESSION foreign_key_checks=' . $checks);
            }
            $release->verify($connection);
        }
        $connection->insert('glpi_networknames', ['id' => 1005, 'name' => 'fresh-free', 'itemtype' => '', 'opaque_parent_id' => 0, 'entities_id' => 0]);
        $release->verify($connection);
        $connection->delete('glpi_networknames', ['id' => 1005]);
        $release->verify($connection);
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
    private function assertGraphicCardParentRetry(Connection $connection): void
    {
        $connection->insert('glpi_computers', ['id' => 3000, 'name' => 'Actual graphic owner', 'entities_id' => 0]);
        $connection->insert('glpi_devicegraphiccards', ['id' => 3000, 'designation' => 'Actual device', 'entities_id' => 0]);
        foreach ([3000 => ['Computer', 3000], 3001 => ['Computer', 0], 3002 => ['', 0],
            3003 => [null, 0], 3004 => ['PluginOpaqueParent', -9], 3005 => ['computer', 3000],
            3006 => [null, -7]] as $id => [$kind, $owner]) {
            $connection->insert('glpi_items_devicegraphiccards', ['id' => $id, 'devicegraphiccards_id' => 3000,
                'itemtype' => $kind, 'items_id' => $owner, 'entities_id' => 0, 'memory' => $id,
                'serial' => 'retained-' . $id, 'is_deleted' => $id === 3005 ? 1 : 0]);
        }
        $release = new GraphicCardParents();
        $ledger = Ledger::states($connection);
        foreach ([-1, 3001] as $invalid) {
            $connection->update('glpi_items_devicegraphiccards', ['items_id' => $invalid], ['id' => 3001]);
            $rows = $connection->fetchAllAssociative('SELECT * FROM glpi_items_devicegraphiccards ORDER BY id');
            $this->exception(static fn () => $release->plan($connection))->isInstanceOf(RuntimeException::class);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_items_devicegraphiccards ORDER BY id'))->isIdenticalTo($rows);
            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
        }
        $connection->update('glpi_items_devicegraphiccards', ['items_id' => 0], ['id' => 3001]);
        $source = $connection->fetchAllAssociative('SELECT id, itemtype, items_id, devicegraphiccards_id, memory, serial, is_deleted FROM glpi_items_devicegraphiccards ORDER BY id');
        $oldIndexes = $connection->createSchemaManager()->listTableIndexes('glpi_items_devicegraphiccards');
        $checksBeforeRetry = NativeCheckCatalog::snapshot($connection, 'glpi_items_devicegraphiccards')['checks']['glpi_items_devicegraphiccards'] ?? [];
        $retrySourceHash = null;
        foreach (['columns', 'copy', 'constraints', 'projection'] as $phase) {
            $message = 'Interrupted graphic card ' . $phase;
            $this->exception(static fn () => $release->apply($connection, static function (string $actual) use ($phase, $message): void {
                if ($actual === $phase) {
                    throw new RuntimeException($message);
                }
            }))->isInstanceOf(RuntimeException::class)->hasMessage($message);
            $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id, devicegraphiccards_id, memory, serial, is_deleted FROM glpi_items_devicegraphiccards ORDER BY id'))->isIdenticalTo($source);
            foreach ($ledger as $checkpoint => $priorReceipt) {
                $this->array(Ledger::state($connection, $checkpoint))->isIdenticalTo($priorReceipt);
            }
            $state = Ledger::state($connection, GraphicCardParentDefinition::PHASE);
            $this->boolean($state['complete'] ?? false)->isFalse();
            $this->boolean($connection->createSchemaManager()->introspectTable('glpi_items_devicegraphiccards')->hasColumn('computers_id'))
                ->isIdenticalTo(!($connection->getDatabasePlatform() instanceof PostgreSQLPlatform));
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $this->variable($state)->isNull();
            } else {
                $this->string($state['source_hash'])->isNotEmpty();
                $retrySourceHash ??= $state['source_hash'];
                $this->string($state['source_hash'])->isIdenticalTo($retrySourceHash);
                $this->string($state['phase'])->isIdenticalTo($phase === 'columns' ? 'audited' : ($phase === 'projection' ? 'projected' : 'copied'));
            }
            $checksAtStop = NativeCheckCatalog::snapshot($connection, 'glpi_items_devicegraphiccards')['checks']['glpi_items_devicegraphiccards'] ?? [];
            foreach ($checksBeforeRetry as $checkName => $check) {
                $this->array($checksAtStop[$checkName])->isIdenticalTo($check);
            }
        }
        $release->apply($connection);
        $release->verify($connection);
        $this->array($connection->fetchAllAssociative('SELECT id, itemtype, items_id, devicegraphiccards_id, memory, serial, is_deleted FROM glpi_items_devicegraphiccards ORDER BY id'))->isIdenticalTo($source);
        $afterIndexes = $connection->createSchemaManager()->listTableIndexes('glpi_items_devicegraphiccards');
        foreach ($oldIndexes as $name => $index) {
            $this->array($afterIndexes[$name]->getUnquotedColumns())->isIdenticalTo($index->getUnquotedColumns());
            $this->boolean($afterIndexes[$name]->isUnique())->isIdenticalTo($index->isUnique());
            $this->array($afterIndexes[$name]->getFlags())->isIdenticalTo($index->getFlags());
            $this->array($afterIndexes[$name]->getOptions())->isIdenticalTo($index->getOptions());
        }
        $receipt = Ledger::state($connection, GraphicCardParentDefinition::PHASE);
        $this->boolean($receipt['complete'])->isTrue();
        $release->apply($connection, static fn () => throw new LogicException('Completed graphic adoption replayed'));
        $this->array(Ledger::state($connection, GraphicCardParentDefinition::PHASE))->isIdenticalTo($receipt);
        foreach ([['computers_id' => 3001], ['computers_id' => 0], ['itemtype' => null],
            ['itemtype' => 'PluginOpaqueParent'], ['opaque_parent_id' => 3000], ['items_id' => null]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->update('glpi_items_devicegraphiccards', $invalid, ['id' => 3000])))->isInstanceOf(DbalException::class);
        }
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->insert('glpi_items_devicegraphiccards', ['id' => 3008, 'devicegraphiccards_id' => 3000,
                'itemtype' => null, 'computers_id' => 3000, 'opaque_parent_id' => null, 'entities_id' => 0])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->update('glpi_items_devicegraphiccards', ['opaque_parent_id' => null], ['id' => 3003])))->isInstanceOf(DbalException::class);
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->delete('glpi_computers', ['id' => 3000])))->isInstanceOf(DbalException::class);
        $connection->update('glpi_items_devicegraphiccards', ['computers_id' => null], ['id' => 3000]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_items_devicegraphiccards WHERE id=3000'))->isIdenticalTo(0);
        $connection->update('glpi_items_devicegraphiccards', ['opaque_parent_id' => -99], ['id' => 3006]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_items_devicegraphiccards WHERE id=3006'))->isIdenticalTo(-99);
        $connection->insert('glpi_items_devicegraphiccards', ['id' => 3007, 'devicegraphiccards_id' => 3000,
            'itemtype' => '', 'opaque_parent_id' => 0, 'entities_id' => 0]);
        $release->verify($connection);
        $connection->delete('glpi_items_devicegraphiccards', ['id' => 3007]);
        $release->verify($connection);
    }
    private function assertRemainingComponentParentRetry(Connection $connection): void
    {
        $connection->insert('glpi_computers', ['id' => 4000, 'name' => 'Actual component owner', 'entities_id' => 0]);
        $sources = [];
        $indexes = [];
        $deviceColumns = [];
        foreach (ComponentParents::DEFINITIONS as $table => $class) {
            $deviceTable = str_replace('glpi_items_', 'glpi_', $table);
            $deviceColumn = substr($deviceTable, 5) . '_id';
            $deviceColumns[$table] = $deviceColumn;
            $connection->insert($deviceTable, ['id' => 4000, 'designation' => 'Actual ' . $table, 'entities_id' => 0]);
            $kinds = [4000 => ['Computer', 4000], 4001 => ['Computer', 0], 4002 => ['', 0],
                4004 => ['PluginOpaqueParent', -9], 4005 => ['computer', 4000], 4007 => ['Computer', 4000]];
            if ($table !== 'glpi_items_devicesimcards') {
                $kinds[4003] = [null, 0];
                $kinds[4006] = [null, -7];
            }
            foreach ($kinds as $id => [$kind, $owner]) {
                $connection->insert($table, ['id' => $id, $deviceColumn => 4000, 'itemtype' => $kind,
                    'items_id' => $owner, 'entities_id' => 0, 'serial' => 'retained-' . $id, 'is_deleted' => $id === 4005 ? 1 : 0]);
            }
            $sources[$table] = $connection->fetchAllAssociative("SELECT id,itemtype,items_id,$deviceColumn,serial,is_deleted FROM $table ORDER BY id");
            $indexes[$table] = $connection->createSchemaManager()->listTableIndexes($table);
        }
        $release = new ComponentParents();
        $ledger = Ledger::states($connection);
        // Last-table invalid data must be diagnosed before any earlier adoption DDL.
        foreach ([-1, 4001] as $invalid) {
            $connection->update('glpi_items_devicepcis', ['items_id' => $invalid], ['id' => 4001]);
            $rows = $connection->fetchAllAssociative('SELECT * FROM glpi_items_devicepcis ORDER BY id');
            $this->exception(static fn () => $release->apply($connection))->isInstanceOf(RuntimeException::class);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_items_devicepcis ORDER BY id'))->isIdenticalTo($rows);
            $this->array(Ledger::states($connection))->isIdenticalTo($ledger);
            $this->boolean($connection->createSchemaManager()->introspectTable('glpi_items_devicenetworkcards')->hasColumn('computers_id'))->isFalse();
        }
        $connection->update('glpi_items_devicepcis', ['items_id' => 0], ['id' => 4001]);
        $checksBeforeRetry = NativeCheckCatalog::snapshot($connection, 'glpi_items_devicepcis')['checks']['glpi_items_devicepcis'] ?? [];
        $retrySourceHash = null;
        $completed = [];
        foreach (['columns', 'copy', 'constraints', 'projection'] as $phase) {
            $message = 'Interrupted final component ' . $phase;
            $this->exception(static fn () => $release->apply($connection, static function (string $actual) use ($phase, $message): void {
                if ($actual === 'glpi_items_devicepcis.' . $phase) {
                    throw new RuntimeException($message);
                }
            }))->isInstanceOf(RuntimeException::class)->hasMessage($message);
            foreach (ComponentParents::DEFINITIONS as $table => $class) {
                if ($table !== 'glpi_items_devicepcis') {
                    $current = Ledger::state($connection, $class::PHASE);
                    if (isset($completed[$class::PHASE])) {
                        $this->array($current)->isIdenticalTo($completed[$class::PHASE]);
                    } else {
                        $completed[$class::PHASE] = $current;
                    }
                    $this->boolean($completed[$class::PHASE]['complete'])->isTrue();
                }
            }
            foreach (ComponentParents::DEFINITIONS as $table => $class) {
                $column = $deviceColumns[$table];
                $this->array($connection->fetchAllAssociative("SELECT id,itemtype,items_id,$column,serial,is_deleted FROM $table ORDER BY id"))->isIdenticalTo($sources[$table]);
            }
            foreach ($ledger as $checkpoint => $priorReceipt) {
                $this->array(Ledger::state($connection, $checkpoint))->isIdenticalTo($priorReceipt);
            }
            $lastClass = ComponentParents::DEFINITIONS['glpi_items_devicepcis'];
            $state = Ledger::state($connection, $lastClass::PHASE);
            $this->boolean($state['complete'] ?? false)->isFalse();
            $this->boolean($connection->createSchemaManager()->introspectTable('glpi_items_devicepcis')->hasColumn('computers_id'))
                ->isIdenticalTo(!($connection->getDatabasePlatform() instanceof PostgreSQLPlatform));
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $this->variable($state)->isNull();
            } else {
                $this->string($state['source_hash'])->isNotEmpty();
                $retrySourceHash ??= $state['source_hash'];
                $this->string($state['source_hash'])->isIdenticalTo($retrySourceHash);
                $this->string($state['phase'])->isIdenticalTo($phase === 'columns' ? 'audited' : ($phase === 'projection' ? 'projected' : 'copied'));
            }
            $checksAtStop = NativeCheckCatalog::snapshot($connection, 'glpi_items_devicepcis')['checks']['glpi_items_devicepcis'] ?? [];
            foreach ($checksBeforeRetry as $checkName => $check) {
                $this->array($checksAtStop[$checkName])->isIdenticalTo($check);
            }
        }
        $release->apply($connection);
        $release->verify($connection);
        foreach ($completed as $checkpoint => $receipt) {
            $this->array(Ledger::state($connection, $checkpoint))->isIdenticalTo($receipt);
        }
        foreach (ComponentParents::DEFINITIONS as $table => $class) {
            $column = $deviceColumns[$table];
            $this->array($connection->fetchAllAssociative("SELECT id,itemtype,items_id,$column,serial,is_deleted FROM $table ORDER BY id"))->isIdenticalTo($sources[$table]);
            $after = $connection->createSchemaManager()->listTableIndexes($table);
            foreach ($indexes[$table] as $name => $index) {
                $this->array($after[$name]->getUnquotedColumns())->isIdenticalTo($index->getUnquotedColumns());
                $this->boolean($after[$name]->isUnique())->isIdenticalTo($index->isUnique());
                $this->array($after[$name]->getFlags())->isIdenticalTo($index->getFlags());
                $this->array($after[$name]->getOptions())->isIdenticalTo($index->getOptions());
            }
            foreach ([['computers_id' => 4001], ['computers_id' => 0], ['opaque_parent_id' => 4000],
                ['itemtype' => null, 'computers_id' => 4000, 'opaque_parent_id' => null]] as $invalid) {
                $this->exception(static fn () => $connection->transactional(static fn () => $connection->update($table, $invalid, ['id' => 4000])))->isInstanceOf(DbalException::class);
            }
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->insert($table, ['id' => 4008, $column => 4000, 'itemtype' => null,
                    'computers_id' => 4000, 'opaque_parent_id' => null, 'entities_id' => 0])))->isInstanceOf(DbalException::class);
            if ($table !== 'glpi_items_devicesimcards') {
                $this->exception(static fn () => $connection->transactional(static fn () => $connection->update($table, ['opaque_parent_id' => null], ['id' => 4003])))->isInstanceOf(DbalException::class);
                $connection->update($table, ['opaque_parent_id' => -99], ['id' => 4006]);
                $this->integer((int)$connection->fetchOne("SELECT items_id FROM $table WHERE id=4006"))->isIdenticalTo(-99);
            }
            $connection->update($table, ['computers_id' => null], ['id' => 4000]);
            $this->integer((int)$connection->fetchOne("SELECT items_id FROM $table WHERE id=4000"))->isIdenticalTo(0);
            $connection->insert($table, ['id' => 4009, $column => 4000, 'itemtype' => '', 'opaque_parent_id' => 0, 'entities_id' => 0]);
            $connection->delete($table, ['id' => 4009]);
        }
        $this->exception(static fn () => $connection->transactional(static fn () => $connection->delete('glpi_computers', ['id' => 4000])))->isInstanceOf(DbalException::class);
        $states = Ledger::states($connection);
        $release->apply($connection, static fn () => throw new LogicException('A completed component adoption replayed'));
        $this->array(Ledger::states($connection))->isIdenticalTo($states);
        $release->verify($connection);
    }

}
