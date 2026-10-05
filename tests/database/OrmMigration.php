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
        $this->array((new SchemaCheck())->differences($connection))->isEmpty();
        $this->array(History::pendingVersions($connection))->isEmpty();
        $this->boolean(History::isInstalling($connection))->isFalse();
        $this->boolean(Ledger::state($connection, Baseline::PHASE)['installation_complete'])->isTrue();
        $this->array(History::versions())->isIdenticalTo(['2.2.0']);
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
        $process = proc_open([PHP_BINARY, GLPI_ROOT . '/bin/console', '--config-dir=' . $directory, '--no-interaction', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, GLPI_ROOT);
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
