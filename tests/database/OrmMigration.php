<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Installer;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\Seeds;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;
use itsmng\Database\Upgrade;

/** Native release boundaries; the historical 0.72.3 database tests remain separate. */
class OrmMigration extends \GLPITestCase
{
    private ?\DBAdapter $fixture = null;

    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
        $this->setTestedClassName(History::class);
    }

    private function emptyFixture(): \DBAdapter
    {
        global $DB;
        $name = getenv('ITSM_TEST_MIGRATION_DB');
        $this->boolean(is_string($name) && preg_match('/^itsm_test_[a-z0-9_]+_migration$/D', $name) === 1
            && $name !== $DB->dbdefault)->isTrue('An explicit separate disposable migration database is required');
        $database = \DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $name);
        $this->boolean($database->connected)->isTrue();
        $this->array($database->getDoctrineConnection()->createSchemaManager()->listTableNames())
            ->isEmpty('Never replace an existing migration fixture or failed-run evidence');
        return $this->fixture = $database;
    }

    public function afterTestMethod($method)
    {
        try {
            if ($this->fixture !== null) {
                $connection = $this->fixture->getDoctrineConnection();
                $platform = $connection->getDatabasePlatform();
                $manager = $connection->createSchemaManager();
                $owned = [...array_map(static fn ($table) => $table->getName(), (new Baseline())->build($platform)->getTables()),
                    Ledger::TABLE, \itsmng\Database\Migration\V220\NetworkPortAggregateOrigins::TABLE,
                    \itsmng\Database\Migration\V220\PlanningEventGuests::TABLE];
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
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform
            && !$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
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
        $this->array(History::versions())->isIdenticalTo(['2.2.0', \itsmng\Database\Migration\SensorSubjects::VERSION, \itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION]);
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
                throw new \RuntimeException('Interrupted baseline');
            });
        })->isInstanceOf(\RuntimeException::class)->hasMessage('Interrupted baseline');
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
                throw new \RuntimeException('Interrupted seeds');
            }
        }))->isInstanceOf(\RuntimeException::class)->hasMessage('Interrupted seeds');
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_apiclients'))->isIdenticalTo(0);
        $this->boolean(Ledger::state($connection, Seeds::PHASE)['complete'])->isFalse();
        (new Seeds())->apply($connection);
        $connection->update('glpi_rulerightparameters', ['comment' => 'Retained seed edit'], ['id' => 1]);
        $receipt = Ledger::state($connection, Seeds::PHASE);
        (new Seeds())->apply($connection, progress: static fn () => throw new \LogicException('Completed seeds replayed'));
        $this->string($connection->fetchOne('SELECT comment FROM glpi_rulerightparameters WHERE id = 1'))->isIdenticalTo('Retained seed edit');
        $this->array(Ledger::state($connection, Seeds::PHASE))->isIdenticalTo($receipt);

        // Establish the real predecessor (including bigint target IDs) before testing forward DDL.
        $predecessor = new \itsmng\Database\Migration\Version220();
        $predecessor->apply($connection);
        $predecessor->verify($connection);

        // The forward phase has its own retained proof in the same ledger.
        $connection->insert('glpi_devicesensors', ['id' => 100, 'designation' => 'Retry sensor', 'entities_id' => 0]);
        $connection->insert('glpi_computers', ['id' => 100, 'name' => 'Retry subject', 'entities_id' => 0]);
        $connection->insert('glpi_items_devicesensors', ['id' => 100, 'devicesensors_id' => 100,
            'itemtype' => 'computer', 'items_id' => 100, 'entities_id' => 0]);
        $forward = new \itsmng\Database\Migration\SensorSubjects();
        $before = $this->rowBags($connection);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(\RuntimeException::class);
        $this->array($this->rowBags($connection))->isIdenticalTo($before);
        $connection->update('glpi_items_devicesensors', ['itemtype' => 'Computer', 'items_id' => 101], ['id' => 100]);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(\RuntimeException::class);
        $connection->update('glpi_items_devicesensors', ['items_id' => 100], ['id' => 100]);
        $this->exception(static fn () => $forward->apply($connection, static function (string $phase): void {
            if ($phase === 'projection') {
                throw new \RuntimeException('Interrupted Sensor projection');
            }
        }))->isInstanceOf(\RuntimeException::class)->hasMessage('Interrupted Sensor projection');
        $forward->apply($connection);
        $forward->verify($connection);
        $retained = Ledger::state($connection, \itsmng\Database\Migration\SensorSubjects\Definition::PHASE);
        $this->boolean($retained['complete'])->isTrue();
        $this->array($retained['policy'])->hasKeys(['projection', 'check']);
        $forward->apply($connection, static fn () => throw new \LogicException('Completed Sensor DDL replayed'));
        $forward->verify($connection);
        $this->array(Ledger::state($connection, \itsmng\Database\Migration\SensorSubjects\Definition::PHASE))->isIdenticalTo($retained);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_items_devicesensors WHERE id = 100'))->isIdenticalTo(100);
        $connection->insert('glpi_peripherals', ['id' => 100, 'name' => 'Other retry subject', 'entities_id' => 0]);
        foreach ([['itemtype' => 'computer'], ['computers_id' => 0], ['peripherals_id' => 100], ['computers_id' => 101]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->update('glpi_items_devicesensors', $invalid, ['id' => 100])))
                ->isInstanceOf(\Doctrine\DBAL\Exception::class);
        }
        $forward->verify($connection);
        $phase = \itsmng\Database\Migration\SensorSubjects\Definition::PHASE;
        $connection->delete(Ledger::TABLE, ['version' => $phase]);
        $this->exception(static fn () => $forward->plan($connection))->isInstanceOf(\RuntimeException::class);
        Ledger::save($connection, $phase, $retained);
        $forward->verify($connection);

        // The next release adds physical support only; interrupted PostgreSQL
        // CREATEs and already-supported MySQL FKs converge without touching rows.
        $indexes = new \itsmng\Database\Migration\PhysicalReferenceIndexes();
        $beforeRows = $this->rowBags($connection);
        $beforeIndexes = \itsmng\Database\PhysicalIndexSchema::catalog($connection, array_keys($indexes::declarations()));
        $plan = $indexes->plan($connection);
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->integer(count($plan['sql']))->isIdenticalTo(68);
            $this->exception(static fn () => $indexes->apply($connection, static fn () => throw new \RuntimeException('Interrupted physical index creation')))
                ->isInstanceOf(\RuntimeException::class)->hasMessage('Interrupted physical index creation');
            $this->integer(count($indexes->plan($connection)['sql']))->isIdenticalTo(67);
        } else {
            $this->array($plan['sql'])->isEmpty('Existing InnoDB supporting indexes already provide physical coverage');
        }
        $indexes->apply($connection);
        $indexes->verify($connection);
        $this->array($indexes->plan($connection)['sql'])->isEmpty();
        $indexes->apply($connection, static fn () => throw new \LogicException('Completed physical index DDL replayed'));
        $this->array($this->rowBags($connection))->isIdenticalTo($beforeRows);
        $afterIndexes = \itsmng\Database\PhysicalIndexSchema::catalog($connection, array_keys($indexes::declarations()));
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
            $this->exception(static fn () => $indexes->verify($connection))->isInstanceOf(\RuntimeException::class);
            $indexes->apply($connection);
            $indexes->verify($connection);
            $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        }
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
        $ciphertext = \Toolbox::sodiumEncrypt('Original encrypted configuration');
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
            $this->string(\Toolbox::sodiumDecrypt($ciphertext))->isIdenticalTo('Original encrypted configuration');
            $sensorRows = $connection->fetchAllAssociative('SELECT id, itemtype, items_id, computers_id, peripherals_id, serial FROM glpi_items_devicesensors ORDER BY id');
            $this->array(array_map('intval', array_column($sensorRows, 'items_id')))->isIdenticalTo([$legacyId, 100, 0]);
            $this->array(array_column($sensorRows, 'itemtype'))->isIdenticalTo(['Computer', 'Peripheral', null]);
            $this->array(array_column($sensorRows, 'serial'))->isIdenticalTo([$audit, $audit, $audit]);
            $this->variable($sensorRows[0]['peripherals_id'])->isNull();
            $this->variable($sensorRows[1]['computers_id'])->isNull();
            $this->variable($sensorRows[2]['computers_id'])->isNull();
            $this->variable($sensorRows[2]['peripherals_id'])->isNull();
            $this->boolean(Ledger::state($connection, \itsmng\Database\Migration\SensorSubjects::VERSION)['complete'])->isTrue();
            $this->boolean(Ledger::state($connection, \itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION)['complete'])->isTrue();
            $this->array((new \itsmng\Database\Migration\PhysicalReferenceIndexes())->plan($connection)['sql'])->isEmpty();
            $this->array(Ledger::state($connection, \itsmng\Database\Migration\SensorSubjects\Definition::PHASE)['policy'])->hasKeys(['projection', 'check']);
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
    }

    /** Nonterminal subjects still belong to current native policy after later releases complete. */
    private function assertCurrentSubjectNativeVerification(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $builder = new \itsmng\Database\BaselineSchema();
        $schema = $builder->build($platform);
        $policies = $builder->subjectPolicies();
        $table = 'glpi_certificates_items';
        $policy = $policies[$table]['items_id'];
        $selected = [$table => ['items_id' => $policy]];
        $inspect = static fn (): array => \itsmng\Database\NativeSubjectSchema::differences($connection, $selected);
        $this->array($inspect())->isEmpty();
        $quote = $platform->quoteIdentifier(...);
        $drop = 'ALTER TABLE ' . $quote($table) . ' DROP '
            . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($policy['constraint']);
        $restore = 'ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT ' . $quote($policy['constraint'])
            . ' CHECK (' . $policy['check'] . ')'
            . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? ' ENFORCED' : '');
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
            })->isInstanceOf(\RuntimeException::class)->hasMessage("Migration history did not converge:\n" . $diagnostic);
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

    private function assertTerminalSensorVerification(Connection $connection): void
    {
        $version = \itsmng\Database\Migration\SensorSubjects::VERSION;
        $phase = \itsmng\Database\Migration\SensorSubjects\Definition::PHASE;
        $receipt = Ledger::state($connection, $version);
        $proof = Ledger::state($connection, $phase);
        $table = 'glpi_items_devicesensors';
        $constraint = $table . '_typed_item_kind';
        $platform = $connection->getDatabasePlatform();
        $drop = 'ALTER TABLE ' . $table . ' DROP '
            . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $constraint;
        $policy = static fn () => \itsmng\Database\Migration\V220\ExactDiscriminators::nativePolicy(
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
                })->isInstanceOf(\RuntimeException::class)->hasMessage($diagnostic);
                $this->boolean($published)->isFalse();
                $this->array(Ledger::states($connection))->isIdenticalTo($before, 'Neither predecessor nor terminal receipts are rewritten');
                $this->array($policy())->isIdenticalTo($native, 'Failed verification does not repair native policy');
                if ($damage === 'proof') {
                    Ledger::save($connection, $phase, $proof);
                } else {
                    if ($damage === 'changed-check') {
                        $connection->executeStatement($drop);
                    }
                    $connection->executeStatement(\itsmng\Database\Migration\SensorSubjects\Definition::checkSql($table, $platform)
                        . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? ' ENFORCED' : ''));
                }
                (new \itsmng\Database\Migration\SensorSubjects())->verify($connection);
            }
        }
        Ledger::save($connection, $version, $receipt);
    }

    private function assertTerminalReleaseOrder(Connection $connection): void
    {
        $calls = new \ArrayObject();
        $release = static fn (string $name) => new class ($name, $calls) implements \itsmng\Database\Migration\ReleaseMigration {
            public function __construct(private string $name, private \ArrayObject $calls)
            {
            }
            public function version(): string
            {
                return $this->name;
            }
            public function plan(Connection $connection): array
            {
                throw new \LogicException('Replay cannot preview a release');
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
