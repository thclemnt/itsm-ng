<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Migration\History as Releases;
use itsmng\Database\Migration\Version220;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Orm;

class History extends \atoum\atoum\test
{
    public function testMysqlFreshReplacementDropsOnlyExistingOwnedTablesInOneStatement(): void
    {
        foreach ([new \Doctrine\DBAL\Platforms\MySQLPlatform(), new \Doctrine\DBAL\Platforms\MariaDBPlatform()] as $platform) {
            foreach ([0, 1] as $checks) {
                $connection = new FreshReplacementFixtureConnection($platform, $checks);
                $connection->tables = ['plugin_keep', 'itsmng_migrations', 'glpi_users', 'glpi_computers',
                    'glpi_networkportaggregateorigins', 'glpi_planningexternaleventguests'];
                \itsmng\Database\Installer::resetMysqlCore($connection);
                $this->array($connection->inspected)->isIdenticalTo(['plugin_keep']);
                $this->integer($connection->checkReads)->isIdenticalTo(1);
                $this->integer(count($connection->statements))->isIdenticalTo(3);
                $this->string($connection->statements[0])->isIdenticalTo('SET FOREIGN_KEY_CHECKS = 0');
                $this->string($connection->statements[2])->isIdenticalTo('SET FOREIGN_KEY_CHECKS = ' . $checks);
                $this->string($connection->statements[1])->startWith('DROP TABLE `itsmng_migrations`, ');
                $targets = explode(', ', substr($connection->statements[1], strlen('DROP TABLE ')));
                sort($targets);
                $this->array($targets)->isIdenticalTo(['`glpi_computers`', '`glpi_networkportaggregateorigins`',
                    '`glpi_planningexternaleventguests`', '`glpi_users`', '`itsmng_migrations`']);

                // Empty/custom-only databases must not produce invalid empty DROP SQL.
                foreach ([[], ['plugin_keep'], ['itsmng_migrations']] as $tables) {
                    $empty = new FreshReplacementFixtureConnection($platform, $checks);
                    $empty->tables = $tables;
                    \itsmng\Database\Installer::resetMysqlCore($empty);
                    $expected = ['SET FOREIGN_KEY_CHECKS = 0'];
                    if ($tables === ['itsmng_migrations']) {
                        $expected[] = 'DROP TABLE `itsmng_migrations`';
                    }
                    $expected[] = 'SET FOREIGN_KEY_CHECKS = ' . $checks;
                    $this->array($empty->statements)->isIdenticalTo($expected);
                }
            }
        }
    }

    public function testMysqlFreshReplacementRefusesCustomReferencesAndRestoresChecksAfterFailure(): void
    {
        foreach ([new \Doctrine\DBAL\Platforms\MySQLPlatform(), new \Doctrine\DBAL\Platforms\MariaDBPlatform()] as $platform) {
            $connection = new FreshReplacementFixtureConnection($platform, 1);
            $connection->tables = ['plugin_keep', 'plugin_refers_to_core', 'glpi_users'];
            $connection->foreignKeys['plugin_refers_to_core'] = [new \Doctrine\DBAL\Schema\ForeignKeyConstraint(['users_id'], 'glpi_users', ['id'])];
            $this->exception(static fn () => \itsmng\Database\Installer::resetMysqlCore($connection))
                ->hasMessage('Cannot replace core schema referenced by custom table: plugin_refers_to_core. Use the validated upgrade path.');
            $this->array($connection->inspected)->isIdenticalTo(['plugin_keep', 'plugin_refers_to_core']);
            $this->array($connection->statements)->isEmpty();
            $this->integer($connection->checkReads)->isIdenticalTo(0);

            foreach ([0, 1] as $checks) {
                $failed = new FreshReplacementFixtureConnection($platform, $checks);
                $failed->tables = ['glpi_users'];
                $failed->dropFailure = new \RuntimeException('Native DROP failed');
                $this->exception(static fn () => \itsmng\Database\Installer::resetMysqlCore($failed))
                    ->isIdenticalTo($failed->dropFailure);
                $this->array($failed->statements)->isIdenticalTo([
                    'SET FOREIGN_KEY_CHECKS = 0', 'DROP TABLE `glpi_users`', 'SET FOREIGN_KEY_CHECKS = ' . $checks,
                ]);
            }
        }
    }

    public function testFrozenBaselineIsIndependentOfMutableCurrentMetadata(): void
    {
        foreach ([['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0'],
            ['driver' => 'pdo_pgsql', 'serverVersion' => '15.0']] as $parameters) {
            $connection = DriverManager::getConnection($parameters);
            try {
                $platform = $connection->getDatabasePlatform();
                $baseline = new Baseline();
                $frozen = $baseline->toSql($platform);
                $manager = new EntityManager($connection, Orm::configuration($platform));
                $metadata = $manager->getClassMetadata(Computer::class);
                $this->string($metadata->fieldMappings['is_deleted']->type)->isIdenticalTo('boolean');
                $metadata->fieldMappings['is_deleted']->type = 'integer';
                $this->array($baseline->toSql($platform))->isIdenticalTo($frozen, 'Current entity metadata cannot rewrite historical DDL');
                $freshManager = new EntityManager($connection, Orm::configuration($platform));
                $this->string($freshManager->getClassMetadata(Computer::class)->fieldMappings['is_deleted']->type)
                    ->isIdenticalTo('boolean', 'Historical inspection does not contaminate later entity managers');
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
                unset($manager, $freshManager, $metadata);
            }
        }
    }

    public function testSensorForwardDeclarationIsFrozenAndUsesTheExistingLedger(): void
    {
        $definition = \itsmng\Database\Migration\SensorSubjects\Definition::class;
        $release = new \itsmng\Database\Migration\SensorSubjects();
        $this->string($release->version())->isNotIdenticalTo(Version220::VERSION);
        $this->string($release->version())->isNotIdenticalTo($definition::PHASE);
        $this->array(Releases::versions())->isIdenticalTo([Version220::VERSION, $release->version(), \itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION]);
        foreach ([new \Doctrine\DBAL\Platforms\MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
            $table = new \Doctrine\DBAL\Schema\Table('glpi_items_devicesensors');
            $table->addColumn('itemtype', 'string');
            $table->addColumn('items_id', 'bigint');
            $definition::configureTable($table, $platform);
            $this->array(array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(), $table->getColumns()))
                ->isIdenticalTo(['itemtype', 'items_id', 'computers_id', 'peripherals_id']);
            $this->boolean($table->getColumn('items_id')->getNotnull())->isFalse();
            $sql = $definition::checkSql($table->getName(), $platform);
            $this->string($sql)->contains('computers_id >= 1')->contains('peripherals_id >= 1')
                ->contains('itemtype IS NULL')->contains('computers_id IS NULL')->contains('peripherals_id IS NULL');
            $this->string($table->getColumn('items_id')->getColumnDefinition())->contains('ELSE 0 END');
            if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
                $this->string($sql)->contains('CAST(itemtype AS BINARY)');
            }
        }
    }

    public function testPreviewDefersDependentReleasesAndSkipsAppliedTargets(): void
    {
        $connection = new ReleaseJournalFixtureConnection();
        $calls = new \ArrayObject();
        $first = $this->release('fixture-first', $calls);
        $second = $this->release('fixture-second', $calls);
        $history = new Releases([$first, $second]);
        $plan = $history->plan($connection);
        $this->array($plan['pending'])->isIdenticalTo(['fixture-first', 'fixture-second']);
        $this->array($plan['deferred_releases'])->isIdenticalTo(['fixture-second']);
        $this->array($calls->getArrayCopy())->isIdenticalTo(['fixture-first']);
        $connection->states['fixture-first'] = ['complete' => false, 'applied' => true];
        $calls->exchangeArray([]);
        $plan = $history->plan($connection);
        $this->string($plan['planned_release'])->isIdenticalTo('fixture-second');
        $this->array($plan['pending'])->isIdenticalTo(['fixture-first', 'fixture-second']);
        $this->array($calls->getArrayCopy())->isIdenticalTo(['fixture-second']);
        $connection->states['fixture-first'] = $connection->states['fixture-second'] = ['complete' => true];
        $calls->exchangeArray([]);
        $this->boolean($history->plan($connection)['complete'])->isTrue();
        $this->array($calls->getArrayCopy())->isEmpty();
    }

    public function testPhysicalReferenceReleaseHasFrozenFiniteDeclarations(): void
    {
        $release = new \itsmng\Database\Migration\PhysicalReferenceIndexes();
        $required = $release::declarations();
        $this->integer(array_sum(array_map('count', $required)))->isIdenticalTo(68);
        foreach ([new \Doctrine\DBAL\Platforms\MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
            foreach ($required as $table => $indexes) {
                foreach ($indexes as $index) {
                    $this->integer(count($index->getColumns()))->isIdenticalTo(1);
                    $this->boolean($index->isUnique())->isFalse();
                    $this->string($platform->getCreateIndexSQL($index, $platform->quoteIdentifier($table)))->startWith('CREATE INDEX ');
                }
            }
        }
        $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']), Orm::configuration(new PostgreSQLPlatform()));
        $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\ItemDeviceSensor::class);
        $metadata->associationMappings['computer']->joinColumns[0]->name = 'future_computer';
        $this->array(array_map(static fn ($index): array => $index->getColumns(), $release::declarations()['glpi_items_devicesensors']))
            ->isIdenticalTo([['computers_id'], ['locations_id'], ['states_id']]);
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    private function release(string $version, \ArrayObject $calls): \itsmng\Database\Migration\ReleaseMigration
    {
        return new class ($version, $calls) implements \itsmng\Database\Migration\ReleaseMigration {
            public function __construct(private string $name, private \ArrayObject $calls)
            {
            }
            public function version(): string
            {
                return $this->name;
            }
            public function plan(Connection $connection): array
            {
                $this->calls[] = $this->name;
                return [];
            }
            public function apply(Connection $connection, ?callable $progress = null): void
            {
                throw new \LogicException('Preview wrote a release.');
            }
            public function verify(Connection $connection): void
            {
                throw new \LogicException('Preview inspected an unapplied target.');
            }
        };
    }

    public function testExperimentalCheckpointsDoNotPublishAnOrmRelease(): void
    {
        $connection = new ReleaseJournalFixtureConnection();
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0', \itsmng\Database\Migration\SensorSubjects::VERSION, \itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION]);
        $connection->states = array_fill_keys(Version220::PHASES, ['complete' => true]);
        $original = $connection->states;
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0', \itsmng\Database\Migration\SensorSubjects::VERSION, \itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION]);
        $this->array($connection->states)->isIdenticalTo($original);
        $connection->states[Version220::VERSION] = ['complete' => true];
        $connection->states[\itsmng\Database\Migration\SensorSubjects::VERSION] = ['complete' => true];
        $connection->states[\itsmng\Database\Migration\PhysicalReferenceIndexes::VERSION] = ['complete' => true];
        $this->array(Releases::pendingVersions($connection))->isEmpty();
        $connection->states[Baseline::PHASE] = ['complete' => false, 'origin' => 'installed', 'next' => 1];
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0']);
        $this->integer($connection->catalogueReads)->isIdenticalTo(4);
        $this->integer($connection->journalReads)->isIdenticalTo(4);
    }
}

/** Observe installer SQL without opening a native connection or executing DDL. */
final class FreshReplacementFixtureConnection extends Connection
{
    public array $tables = [];
    public array $foreignKeys = [];
    public array $inspected = [];
    public array $statements = [];
    public int $checkReads = 0;
    public ?\RuntimeException $dropFailure = null;

    public function __construct(private AbstractPlatform $platform, private int $checks)
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->platform;
    }

    public function createSchemaManager(): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return new class ($this, $this->platform) extends \Doctrine\DBAL\Schema\MySQLSchemaManager {
            public function listTableNames(): array
            {
                return $this->connection->tables;
            }

            public function listTableForeignKeys(string $table): array
            {
                $this->connection->inspected[] = $table;
                return $this->connection->foreignKeys[$table] ?? [];
            }
        };
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if ($query !== 'SELECT @@FOREIGN_KEY_CHECKS') {
            throw new \LogicException('Unexpected fresh replacement read: ' . $query);
        }
        ++$this->checkReads;
        return $this->checks;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->statements[] = $sql;
        if (str_starts_with($sql, 'DROP TABLE ') && $this->dropFailure !== null) {
            throw $this->dropFailure;
        }
        return 0;
    }
}

/** Read-only readiness double; no native driver, DDL or metadata schema exists. */
final class ReleaseJournalFixtureConnection extends Connection
{
    public array $states = [];
    public int $catalogueReads = 0;
    public int $journalReads = 0;

    public function __construct()
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new PostgreSQLPlatform();
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if ($query !== 'SELECT to_regclass(?)') {
            throw new \LogicException('Unexpected readiness query: ' . $query);
        }
        ++$this->catalogueReads;
        return 'itsmng_migrations';
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        if ($query !== 'SELECT version, state FROM itsmng_migrations') {
            throw new \LogicException('Unexpected readiness query: ' . $query);
        }
        ++$this->journalReads;
        $rows = [];
        foreach ($this->states as $version => $state) {
            $rows[] = ['version' => $version, 'state' => json_encode($state, JSON_THROW_ON_ERROR)];
        }
        return $rows;
    }
}
