<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
*/

namespace tests\units;

use atoum\atoum\php\mocker\funktion;
use Closure;
use CommonDBTM;
use Config as ConfigModel;
use DBAdapter;
use DBmysql;
use DBpgsql;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\SqlOutputWalker;
use Glpi\Console\Config\SetCommand;
use Glpi\Console\Database\InstallCommand;
use Glpi\Console\System\ClearCacheCommand;
use GLPIKey;
use Group;
use Impact as ImpactModel;
use Infocom;
use Item_Devices;
use itsmng\Cache\SessionAdapter;
use itsmng\Database\BaselineSchema;
use itsmng\Database\ComponentCountReadOperation;
use itsmng\Database\Entity\Computer as ComputerRecord;
use itsmng\Database\Entity\Config as ConfigRecord;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\EntityRegistry;
use itsmng\Database\MappedReads;
use itsmng\Database\MySQLConnection;
use itsmng\Database\OidcRefreshReadOperation;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\PostgresConnection;
use itsmng\Database\ReadQueryOwner;
use itsmng\Database\RecordCriteria;
use itsmng\Database\RecordReadOperation;
use itsmng\Database\Repository\ConfigurationRepository;
use itsmng\Database\Repository\OidcRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;
use itsmng\Database\UnsupportedCriteria;
use JsonException;
use Log;
use LogicException;
use mock\DBmysql as ConfigurationAdapter;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Cache\CacheItemInterface;
use Psr\Log\AbstractLogger;
use QuerySubQuery;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Session;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException as CacheConfigurationException;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use tests\fixtures\ScalarReadProbe;
use Throwable;
use Toolbox;
use User;

use function exportArrayToDB;
use function importArrayFromDB;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/config.class.php */

class Config extends DbTestCase
{
    public function testOidcPersistenceKeepsSingletonsAndRejectsInvalidUserStates(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $manager = Orm::forConnection($connection);
        $repository = new OidcRepository($manager);
        $writer = new RecordWriter($manager);
        try {
            // The ordinary outer test transaction restores these singleton rows.
            foreach (['glpi_oidc_config', 'glpi_oidc_mapping'] as $table) {
                $writer->delete($table, 0);
            }
            $manager->clear();
            foreach ([false, true] as $enabled) {
                $configuration = ['Provider' => "https://issuer.invalid/O'Reilly", 'ClientID' => 'native-client',
                    'is_activate' => $enabled, 'is_forced' => !$enabled, 'sso_link_users' => $enabled];
                $mapping = ['name' => $enabled ? 'preferred_username' : 'name', 'given_name' => '',
                    'email' => null, 'date_mod' => $enabled ? '2002-03-04 05:06:07' : '2001-02-03 04:05:06'];
                $repository->saveConfiguration(['id' => 991] + $configuration);
                $repository->saveMapping(['id' => 992] + $mapping);
                foreach (['is_activate', 'is_forced', 'sso_link_users'] as $flag) {
                    $configuration[$flag] = (int)$configuration[$flag];
                }
                foreach ([[$repository->configuration(), ['id' => 0] + $configuration],
                    [$repository->mapping(), ['id' => 0] + $mapping]] as [$actual, $expected]) {
                    foreach ($expected as $field => $value) {
                        $this->variable($actual[$field])->isIdenticalTo($value, $field);
                    }
                }
            }
            foreach (['glpi_oidc_config' => 991, 'glpi_oidc_mapping' => 992] as $table => $ignored) {
                $this->variable((new RecordRepository($manager))->find($table, 'id', $ignored))->isNull();
            }
            $user = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
            $id = (int)$user->getID();
            $state = $writer->insert('glpi_oidc_users', ['user_id' => $id, 'update' => false]);
            $before = $connection->fetchAssociative('SELECT * FROM glpi_oidc_users WHERE id = ?', [$state]);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE id = ?', [-1]))->isIdenticalTo(0);
            $pending = $connection->getDatabasePlatform()->quoteSingleIdentifier('update');
            foreach ([[$id, UniqueConstraintViolationException::class], [-1, ForeignKeyConstraintViolationException::class]] as [$reference, $exception]) {
                // Probe physical constraints without poisoning the ORM or the outer PG transaction.
                $this->exception(static fn () => OwnedMutationFrame::run($connection, static fn () =>
                    $connection->insert(
                        'glpi_oidc_users',
                        ['user_id' => $reference, $pending => true],
                        ['user_id' => Types::BIGINT, $pending => Types::BOOLEAN]
                    )))
                    ->isInstanceOf($exception);
                $this->array($connection->fetchAssociative('SELECT * FROM glpi_oidc_users WHERE id = ?', [$state]))->isIdenticalTo($before);
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_oidc_users WHERE user_id IN (?, ?)', [$id, -1]))->isIdenticalTo(1);
                $this->boolean($repository->needsRefresh($id))->isTrue();
            }
            $writer->update('glpi_oidc_users', $state, ['update' => true]);
            $this->boolean($repository->needsRefresh($id))->isFalse();
        } finally {
            $manager->clear();
        }
    }

    public function testOidcRefreshReadUsesCurrentTypedStateWithoutLoadingUserGraph(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $pendingColumn = $connection->getDatabasePlatform()->quoteSingleIdentifier('update');
        $user = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        $id = (int)$user->getID();
        $manager = Orm::forConnection($connection);
        $ordinary = new OidcRepository($manager);
        $probe = new ScalarReadProbe($connection);
        $reader = new OidcRefreshReadOperation($probe);
        $bigint = Type::getType('bigint');
        $integer = Type::getType('integer');
        $boolean = Type::getType('boolean');
        try {
            foreach ([0, -1] as $missing) {
                $this->boolean($reader->needsRefresh($missing))->isIdenticalTo($ordinary->needsRefresh($missing));
            }
            $this->integer($probe->builders)->isIdenticalTo(0);
            $this->boolean($reader->needsRefresh($id))->isFalse();
            $this->boolean($ordinary->needsRefresh($id))->isFalse();
            $this->integer($probe->builders)->isIdenticalTo(1);
            $this->array($probe->queries[0]['params'])->isIdenticalTo(['user' => $id, 'pending' => false]);
            $this->array($probe->queries[0]['types'])->isIdenticalTo(['user' => 'integer', 'pending' => 'boolean']);
            $connection->insert('glpi_oidc_users', ['user_id' => $id, $pendingColumn => false], ['user_id' => 'bigint', $pendingColumn => 'boolean']);
            $this->boolean($reader->needsRefresh($id))->isTrue();
            $this->boolean($reader->needsRefresh($id))->isIdenticalTo($ordinary->needsRefresh($id));
            $connection->update('glpi_oidc_users', [$pendingColumn => true], ['user_id' => $id], [$pendingColumn => 'boolean', 'user_id' => 'bigint']);
            $this->boolean($reader->needsRefresh($id))->isFalse();
            $this->boolean($reader->needsRefresh($id))->isIdenticalTo($ordinary->needsRefresh($id));
            $connection->update('glpi_oidc_users', [$pendingColumn => false], ['user_id' => $id], [$pendingColumn => 'boolean', 'user_id' => 'bigint']);
            $hadSessionId = array_key_exists('glpiID', $_SESSION);
            $sessionId = $_SESSION['glpiID'] ?? null;
            $scopedManager = null;
            $scopedRead = static function (Connection $selected) use (&$scopedManager): bool {
                return Orm::withReadConnection($selected, static function (?EntityManager $manager) use ($selected, &$scopedManager): bool {
                    $scopedManager = $manager;
                    return (new OidcRefreshReadOperation($selected, $manager))->needsRefresh((int)$_SESSION['glpiID']);
                });
            };
            try {
                $_SESSION['glpiID'] = $id;
                $this->boolean($scopedRead($connection))->isTrue();
                $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
                $beforeFactories = $factories->getValue();
                for ($repeat = 0; $repeat < 3; ++$repeat) {
                    $this->boolean($scopedRead($connection))->isTrue();
                    $this->boolean($connection->ownsApplicationEntityManager($scopedManager))->isFalse();
                }
                $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
                $connection->update('glpi_oidc_users', [$pendingColumn => true], ['user_id' => $id], [$pendingColumn => 'boolean', 'user_id' => 'bigint']);
                $this->boolean($scopedRead($connection))->isFalse();
                $connection->update('glpi_oidc_users', [$pendingColumn => false], ['user_id' => $id], [$pendingColumn => 'boolean', 'user_id' => 'bigint']);
                $this->boolean($scopedRead($connection))->isTrue();
                Orm::withReadConnection($connection, function (?EntityManager $outer) use ($connection, $scopedRead, $factories): void {
                    $this->boolean($connection->ownsApplicationEntityManager($outer))->isTrue();
                    $beforeNested = $factories->getValue();
                    $this->boolean($scopedRead($connection))->isTrue();
                    $this->integer($factories->getValue() - $beforeNested)->isIdenticalTo(1);
                    $this->boolean($connection->ownsApplicationEntityManager($outer))->isTrue();
                });

                $custom = new class ($connection) extends ScalarReadProbe {
                    public ?Closure $beforePlatform = null;
                    private ?EventManager $events = null;

                    public function getDatabasePlatform(): AbstractPlatform
                    {
                        if ($this->beforePlatform !== null) {
                            $callback = $this->beforePlatform;
                            $this->beforePlatform = null;
                            $callback();
                        }
                        return parent::getDatabasePlatform();
                    }

                    public function getEventManager(): EventManager
                    {
                        return $this->events ??= new EventManager();
                    }
                };
                $clears = new class () {
                    public int $count = 0;
                    public ?RuntimeException $failure = null;

                    public function onClear(): void
                    {
                        ++$this->count;
                        if ($this->failure !== null) {
                            throw $this->failure;
                        }
                    }
                };
                $custom->getEventManager()->addEventListener(['onClear'], $clears);
                $_SESSION['glpiID'] = 0;
                $custom->beforePlatform = static function () use ($id): void {
                    $_SESSION['glpiID'] = $id;
                };
                $beforeFactories = $factories->getValue();
                $this->boolean($scopedRead($custom))->isTrue();
                $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(1);
                $this->array($custom->queries)->hasSize(1);
                $this->integer($custom->builders)->isIdenticalTo(0);
                $this->integer($clears->count)->isIdenticalTo(1);

                $this->mockGenerator()->orphanize('__construct');
                $adapter = new ConfigurationAdapter();
                $this->calling($adapter)->getDoctrineConnection = $custom;
                $beforeClears = $clears->count;
                $this->integer(MappedReads::countMatching($adapter, 'glpi_configs', ['context' => 'core']))->isGreaterThan(0);
                $this->integer($clears->count - $beforeClears)->isIdenticalTo(1, 'The public finally and destructor close one private owner');

                $private = new OidcRefreshReadOperation($custom);
                $this->boolean($private->needsRefresh($id))->isTrue();
                $beforeClears = $clears->count;
                $private->close();
                $private->close();
                unset($private);
                $this->integer($clears->count - $beforeClears)->isIdenticalTo(1);

                $supplied = Orm::forConnection($custom);
                $pending = new ConfigRecord();
                $pending->context = 'private-owner';
                $pending->name = 'pending';
                $pending->value = 'retained';
                $supplied->persist($pending);
                try {
                    $borrowed = new OidcRefreshReadOperation($custom, $supplied);
                    $beforeClears = $clears->count;
                    $borrowed->close();
                    $borrowed->close();
                    unset($borrowed);
                    $this->integer($clears->count - $beforeClears)->isIdenticalTo(0);
                    $this->boolean($supplied->contains($pending))->isTrue();
                    $this->string($pending->value)->isIdenticalTo('retained');
                } finally {
                    $supplied->clear();
                }

                $beforeFactories = $factories->getValue();
                $counts = new ComponentCountReadOperation($connection);
                $counts->close();
                $counts->close();
                unset($counts);
                $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0, 'An unused lazy component owner creates no manager during cleanup');
                $counts = new ComponentCountReadOperation($custom);
                $beforeClears = $clears->count;
                $counts->close();
                $counts->close();
                unset($counts);
                $this->integer($clears->count - $beforeClears)->isIdenticalTo(1);

                $private = new OidcRefreshReadOperation($custom);
                $clears->failure = new RuntimeException('Private owner cleanup failed');
                $beforeClears = $clears->count;
                try {
                    $this->exception(static fn () => $private->close())
                        ->isInstanceOf(RuntimeException::class)->hasMessage('Private owner cleanup failed');
                    $private->close();
                    unset($private);
                    $this->integer($clears->count - $beforeClears)->isIdenticalTo(1, 'Cleanup failure is propagated once and is not retried by destruction');
                } finally {
                    $clears->failure = null;
                    unset($private);
                }
            } finally {
                if ($hadSessionId) {
                    $_SESSION['glpiID'] = $sessionId;
                } else {
                    unset($_SESSION['glpiID']);
                }
            }
            $observed = new class () extends BigIntType {
                public int $conversions = 0;
                public int $sql = 0;
                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    ++$this->sql;
                    return '(' . $sqlExpr . ' + 0)';
                }
                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): int|string|null
                {
                    ++$this->conversions;
                    return parent::convertToPHPValue($value, $platform);
                }
            };
            Type::overrideType('bigint', $observed);
            $this->boolean($reader->needsRefresh($id))->isTrue();
            $this->integer($observed->conversions)->isIdenticalTo(1);
            $this->boolean($ordinary->needsRefresh($id))->isTrue();
            $this->integer($observed->conversions)->isIdenticalTo(2);
            $this->integer($observed->sql)->isIdenticalTo(2);
            Type::overrideType('bigint', new class () extends BigIntType {
                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): int|string|null
                {
                    throw new LogicException('OIDC scalar PHP conversion remains observable');
                }
            });
            $this->exception(static fn () => $reader->needsRefresh($id))->isInstanceOf(LogicException::class)
                ->hasMessage('OIDC scalar PHP conversion remains observable');
            $this->exception(static fn () => $ordinary->needsRefresh($id))->isInstanceOf(LogicException::class)
                ->hasMessage('OIDC scalar PHP conversion remains observable');
            Type::overrideType('bigint', new class () extends BigIntType {
                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): int|string|null
                {
                    throw new NoResultException();
                }
            });
            $this->boolean($reader->needsRefresh($id))->isFalse();
            $this->boolean($ordinary->needsRefresh($id))->isFalse();
            Type::overrideType('bigint', $bigint);
            Type::overrideType('integer', new class () extends IntegerType {
                public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return '(' . $sqlExpr . ' * 0 - 1)';
                }
            });
            $this->boolean($reader->needsRefresh($id))->isFalse();
            $this->boolean($ordinary->needsRefresh($id))->isFalse();
            Type::overrideType('integer', $integer);
            Type::overrideType('boolean', new class () extends BooleanType {
                public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return '(NOT ' . $sqlExpr . ')';
                }
            });
            $this->boolean($reader->needsRefresh($id))->isFalse();
            $this->boolean($ordinary->needsRefresh($id))->isFalse();
            Type::overrideType('boolean', $boolean);
            $extension = new class ($connection) extends ScalarReadProbe {
                private ?EventManager $events = null;
                public function getEventManager(): EventManager
                {
                    return $this->events ??= new EventManager();
                }
            };
            $local = new OidcRefreshReadOperation($extension);
            $listener = new class () {
                public int $loads = 0;
                public bool $absent = false;
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    ++$this->loads;
                    if ($this->absent) {
                        throw new NoResultException();
                    }
                }
            };
            $extension->getEventManager()->addEventListener([Events::loadClassMetadata], $listener);
            $this->boolean($local->needsRefresh($id))->isTrue();
            $this->integer($listener->loads)->isGreaterThan(0);
            $this->integer($extension->builders)->isIdenticalTo(0);
            $local->close();
            $listener->absent = true;
            $absent = new OidcRefreshReadOperation($extension);
            $ordinaryManager = Orm::forConnection($extension);
            $this->boolean($absent->needsRefresh($id))->isFalse();
            $this->boolean((new OidcRepository($ordinaryManager))->needsRefresh($id))->isFalse();
            $absent->close();
            $ordinaryManager->clear();
            $connection->delete('glpi_oidc_users', ['user_id' => $id]);
            $this->boolean($reader->needsRefresh($id))->isFalse();
        } finally {
            Type::overrideType('bigint', $bigint);
            Type::overrideType('integer', $integer);
            Type::overrideType('boolean', $boolean);
            $reader->close();
            $manager->clear();
        }
    }

    public function testLegacyConfigurationInspectsFreshPhysicalTablesOnSelectedConnection(): void
    {
        global $DB, $CFG_GLPI;
        $original = $DB;
        $originalConfig = $CFG_GLPI;
        $parameters = $original->getDoctrineConnection()->getParams();
        $postgres = $original->getProvider() === 'pgsql';
        $factory = $postgres ? PostgresConnection::class : MySQLConnection::class;
        $admin = $factory::create($parameters);
        $namespace = 'itsm_test_config_catalog_' . bin2hex(random_bytes(6));
        $quotedNamespace = $admin->quoteIdentifier($namespace);
        $connection = null;
        $created = false;
        $roleCreated = false;
        $logger = new class () extends AbstractLogger {
            public array $queries = [];
            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    // Capture actual driver SQL only, never connection or row data.
                    $this->queries[] = strtolower(str_replace(['`', '"'], '', $context['sql']));
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        try {
            // A private physical namespace keeps DDL out of the suite's transaction.
            // CI grants MySQL DDL only on itsm_test_config_catalog_* databases.
            // PostgreSQL fixtures need CREATE SCHEMA, CREATEROLE and SET ROLE.
            $admin->executeStatement(($postgres ? 'CREATE SCHEMA ' : 'CREATE DATABASE ') . $quotedNamespace);
            $created = true;
            if ($postgres) {
                $parameters['search_path'] = $quotedNamespace;
            } else {
                $parameters['dbname'] = $namespace;
            }
            $connection = $factory::create($parameters, $configuration);
            $probe = clone $original;
            (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
            $probe->clearSchemaCache();
            if ($postgres) {
                $probe->dbschema = $namespace;
            } else {
                $probe->dbdefault = $namespace;
            }
            // Global DB already represents the configured reader or writer. The
            // loader must use its connection, never acquire a default writer.
            $probe->slave = true;
            $DB = $probe;
            $load = function (bool $olderFirst, ?string $marker) use (&$CFG_GLPI): void {
                $CFG_GLPI = [];
                $this->boolean(ConfigModel::loadLegacyConfiguration($olderFirst, false))->isIdenticalTo($marker !== null);
                $this->variable($CFG_GLPI['catalog_probe'] ?? null)->isIdenticalTo($marker);
            };
            $load(false, null);
            $connection->executeStatement('CREATE TABLE glpi_config (id INTEGER NOT NULL, catalog_probe VARCHAR(40))');
            $connection->insert('glpi_config', ['id' => 1, 'catalog_probe' => 'old']);
            $load(false, 'old');
            $connection->executeStatement('CREATE TABLE glpi_configs (id INTEGER NOT NULL, context VARCHAR(40), name VARCHAR(40), value VARCHAR(80))');
            $connection->insert('glpi_configs', ['id' => 1, 'context' => 'core', 'name' => 'catalog_probe', 'value' => 'current']);
            $connection->insert('glpi_configs', ['id' => 2, 'context' => 'other', 'name' => 'catalog_probe', 'value' => 'wrong-context']);
            $load(true, 'old');
            $load(false, 'current');

            // Establish the actual full-catalog SQL baseline, then require that
            // the loader's catalog query constrains the two bootstrap names.
            $logger->queries = [];
            $this->array($connection->createSchemaManager()->listTableNames())->contains('glpi_configs');
            $catalogQueries = static fn (array $queries): array => array_values(array_filter(
                $queries,
                static fn (string $sql): bool => str_contains($sql, 'information_schema.tables') || str_contains($sql, 'from pg_class')
            ));
            $this->array($catalogQueries($logger->queries))->hasSize(1);
            $logger->queries = [];
            $load(false, 'current');
            $catalog = $catalogQueries($logger->queries);
            $this->array($catalog)->hasSize(1);
            $targetedCatalog = array_values(array_filter($catalog, static fn (string $sql): bool =>
                preg_match('/table_name\s+in\s*\(\s*(?:\?|\$[0-9]+)\s*,\s*(?:\?|\$[0-9]+)\s*\)/', $sql) === 1));
            $this->array($targetedCatalog)->hasSize(1);
            $this->array((new ReflectionProperty(DBAdapter::class, 'table_cache'))->getValue($probe))->isEmpty();

            if ($postgres) {
                // Empty search_path must not discover a same-named table in
                // another schema (including the application's real schema).
                $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($namespace . '_missing'));
                $load(false, null);
                $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($namespace . '_missing') . ', ' . $quotedNamespace);
                $load(false, 'current');
                $connection->executeStatement('SET search_path TO ' . $quotedNamespace);

                // The historic information_schema scan only exposes tables
                // visible through ownership, table grants, or column grants.
                $admin->executeStatement('CREATE ROLE ' . $quotedNamespace);
                $roleCreated = true;
                $admin->executeStatement('GRANT USAGE ON SCHEMA ' . $quotedNamespace . ' TO ' . $quotedNamespace);
                $admin->executeStatement('GRANT SELECT ON ' . $quotedNamespace . '.glpi_configs TO ' . $quotedNamespace);
                $connection->executeStatement('SET ROLE ' . $quotedNamespace);
                $visible = $connection->createSchemaManager()->listTableNames();
                $this->array($visible)->contains('glpi_configs')->notContains('glpi_config');
                $load(true, 'current'); // Hidden old table must not shadow current.
                $connection->executeStatement('RESET ROLE');
                $admin->executeStatement('GRANT SELECT (id) ON ' . $quotedNamespace . '.glpi_config TO ' . $quotedNamespace);
                $connection->executeStatement('SET ROLE ' . $quotedNamespace);
                $this->array($connection->createSchemaManager()->listTableNames())->contains('glpi_config');
                // A column grant exposes the table, but SELECT * still fails;
                // the loader must not mistake partial access for absence.
                $this->exception(static fn () => ConfigModel::loadLegacyConfiguration(true, false))
                    ->isInstanceOf(DriverException::class);
                $connection->executeStatement('RESET ROLE');
            }

            $manager = $connection->createSchemaManager();
            $manager->renameTable('glpi_config', 'saved_config');
            $load(true, 'current');
            $manager->renameTable('glpi_configs', 'saved_configs');
            $load(false, null);
            $connection->executeStatement('CREATE VIEW glpi_configs AS SELECT * FROM saved_configs');
            $load(false, null); // A view is not a physical configuration table.
            $connection->executeStatement('DROP VIEW glpi_configs');
            $manager->renameTable('saved_configs', 'glpi_configs');
            $load(false, 'current');
            $manager->renameTable('glpi_configs', $connection->quoteIdentifier('GLPI_CONFIGS'));
            $exactNameExists = in_array('glpi_configs', $manager->listTableNames(), true);
            $load(false, $exactNameExists ? 'current' : null);
            $manager->renameTable($connection->quoteIdentifier('GLPI_CONFIGS'), 'glpi_configs');
            $connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => $name !== 'glpi_configs');
            $load(false, null);
            $connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => true);
            $load(false, 'current');

            // Unsignaled DDL between loading configuration and cache bootstrap
            // must remain visible to the adapter's formerly fresh first scan.
            $manager->renameTable('glpi_configs', 'saved_configs');
            $this->object(ConfigModel::getCache('cache_db', 'core', false))
                ->isInstanceOf(FilesystemAdapter::class);
            $this->boolean($probe->tableExists('glpi_configs', false))->isFalse();
            $connection->executeStatement('CREATE TABLE glpi_configs (id INTEGER NOT NULL, catalog_probe VARCHAR(40))');
            $connection->insert('glpi_configs', ['id' => 1, 'catalog_probe' => 'wide']);
            $load(false, 'wide');
            $manager->dropTable('glpi_configs');
            $load(false, null);
            $manager->renameTable('saved_config', 'glpi_config');
            $load(false, 'old');
            $this->object($original->getDoctrineConnection())->isNotIdenticalTo($connection);
        } finally {
            $DB = $original;
            $CFG_GLPI = $originalConfig;
            $connection?->close();
            if ($created) {
                $admin->executeStatement(($postgres ? 'DROP SCHEMA ' : 'DROP DATABASE ') . $quotedNamespace . ($postgres ? ' CASCADE' : ''));
            }
            if ($roleCreated) {
                $admin->executeStatement('DROP ROLE ' . $quotedNamespace);
            }
            $admin->close();
        }
    }

    public function testSchemaInspectionDetectsCurrentConfigEditsWithoutChangingStorage(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $platform = $connection->getDatabasePlatform();
        $schemaManager = $connection->createSchemaManager();
        $before = $schemaManager->introspectTable('glpi_configs');
        $rowsHash = static fn (): string => hash('sha256', serialize($connection->fetchAllAssociative(
            'SELECT id, context, name, value FROM glpi_configs ORDER BY id'
        )));
        // Compare digests so a failed read-only check cannot print configuration secrets.
        $beforeRows = $rowsHash();
        $level = $connection->getTransactionNestingLevel();
        $manager = Orm::create($DB);
        try {
            $metadata = $manager->getClassMetadata(ConfigRecord::class);
            $metadata->fieldMappings['context']->length = 173;
            $expected = (new BaselineSchema($manager))->build($platform)->getTable('glpi_configs');
            $this->array((new SchemaCheck())->differences(
                $connection,
                new Schema([clone $expected])
            ))->isIdenticalTo(['Changed column: glpi_configs.context']);
            $after = $schemaManager->introspectTable('glpi_configs');
            $this->boolean($schemaManager->createComparator()->compareTables($before, $after)->isEmpty())->isTrue();
            $this->array($after->getOptions())->isIdenticalTo($before->getOptions());
            $this->string($rowsHash())->isIdenticalTo($beforeRows);
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $manager->clear();
        }
    }

    public function testGetTypeName()
    {
        $this->string(ConfigModel::getTypeName())->isIdenticalTo('Setup');
    }

    public function testAcls()
    {
        //check ACLs when not logged
        $this->boolean(ConfigModel::canView())->isFalse();
        $this->boolean(ConfigModel::canCreate())->isFalse();

        $conf = new ConfigModel();
        $this->boolean($conf->canViewItem())->isFalse();

        //check ACLs from superadmin profile
        $this->login();
        $this->boolean((bool)ConfigModel::canView())->isTrue();
        $this->boolean(ConfigModel::canCreate())->isFalse();
        $this->boolean($conf->canViewItem())->isFalse();

        $this->boolean($conf->getFromDB(1))->isTrue();
        $this->boolean($conf->canViewItem())->isTrue();

        //check ACLs from tech profile
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
        $this->boolean((bool)ConfigModel::canView())->isFalse();
        $this->boolean(ConfigModel::canCreate())->isFalse();
        $this->boolean($conf->canViewItem())->isTrue();
    }

    public function testGetMenuContent()
    {
        $this->boolean(ConfigModel::getMenuContent())->isFalse();

        $this->login();
        $this->array(ConfigModel::getMenuContent())
           ->hasSize(4)
           ->hasKeys(['title', 'page', 'options', 'icon']);
    }

    public function testDefineTabs()
    {
        $expected = [
           'Config$1'      => 'General setup',
           'Config$2'      => 'Default values',
           'Config$3'      => 'Assets',
           'Config$4'      => 'Assistance',
           'Log$1'         => 'Historical',
        ];
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);

        //Standards users do not have extra tabs
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);

        //check extra tabs from superadmin profile
        $this->login();
        $expected = [
           'Config$1'      => 'General setup',
           'Config$2'      => 'Default values',
           'Config$3'      => 'Assets',
           'Config$4'      => 'Assistance',
           'Config$9'      => 'Logs purge',
           'Config$5'      => 'System',
           'Config$10'     => 'Security',
           'Config$7'      => 'Performance',
           'Config$8'      => 'API',
           'Config$11'      => \Impact::getTypeName(),
           'Log$1'         => 'Historical',
        ];
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);
    }

    public function testPrepareInputForUpdate()
    {
        global $DB, $CFG_GLPI;

        $this->login();
        $this->boolean((bool)ConfigModel::canUpdate())->isTrue();
        $rows = static fn (string $table, array $criteria): array => (new RecordRepository(Orm::create($DB)))->matching($table, $criteria, ['id ASC']);
        ConfigModel::setConfigurationValues('core', ['is_ids_visible' => 0]);
        $before = $rows('glpi_configs', ['context' => 'core']);
        $setting = $rows('glpi_configs', ['context' => 'core', 'name' => 'is_ids_visible']);
        $this->array($setting)->hasSize(1);
        $this->string($setting[0]['value'])->isIdenticalTo('0');
        $historyCriteria = ['itemtype' => ConfigModel::getType(), 'old_value' => ['LIKE', 'is_ids_visible %']];
        $historyBefore = $rows('glpi_logs', $historyCriteria);

        // The actual default-values form stores configuration during preparation
        // and deliberately returns false to stop the outer record update.
        $config = new ConfigModel();
        $this->boolean($config->prepareInputForUpdate([
            'id' => $setting[0]['id'],
            'is_ids_visible' => 1,
            'update' => 'Save',
            '_glpi_csrf_token' => $_SESSION['_glpi_csrf_token'],
            '_no_history' => 1,
        ]))->isFalse();
        $this->array(ConfigModel::getConfigurationValues('core', ['is_ids_visible']))->isIdenticalTo(['is_ids_visible' => '1']);

        $expected = $before;
        foreach ($expected as &$row) {
            if ($row['id'] === $setting[0]['id']) {
                $row['value'] = '1';
            }
        }
        unset($row);
        // Exact ORM rows also prove that id/update/CSRF/_no_history became no
        // accidental core settings, and that unrelated configuration is intact.
        $this->array($rows('glpi_configs', ['context' => 'core']))->isIdenticalTo($expected);
        $history = array_slice($rows('glpi_logs', $historyCriteria), count($historyBefore));
        $this->array($history)->hasSize(1);
        $this->string($history[0]['old_value'])->isIdenticalTo('is_ids_visible 0');
        $this->string($history[0]['new_value'])->isIdenticalTo('1');
        $actor = Session::getLoginUserID(false);
        $this->string($history[0]['user_name'])->isIdenticalTo(sprintf(__('%1$s (%2$s)'), getUserName($actor), $actor));

        $allowed = $CFG_GLPI['impact_asset_types'];
        $itemtypes = ['Computer', 'GlpiPlugin\\ConfigFixture\\Device', 'GlpiPlugin\\ConfigFixture\\Équipement'];
        try {
            foreach ($itemtypes as $itemtype) {
                $CFG_GLPI['impact_asset_types'][$itemtype] = true;
            }
            foreach ([$itemtypes, array_reverse($itemtypes), []] as $selected) {
                // inc/includes.php sanitizes the form before config.form.php calls update().
                $this->boolean($config->update(Toolbox::sanitize([
                    'id' => 1,
                    ImpactModel::CONF_ENABLED => $selected,
                    'update' => 'Save',
                    '_glpi_csrf_token' => $_SESSION['_glpi_csrf_token'],
                ])))->isFalse();
                $stored = ConfigModel::getConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
                $this->string($stored[ImpactModel::CONF_ENABLED])->isIdenticalTo(exportArrayToDB($selected));
                $this->array(importArrayFromDB($stored[ImpactModel::CONF_ENABLED]))->isIdenticalTo($selected);
                $this->array(ImpactModel::getEnabledItemtypes())->isIdenticalTo($selected);
            }
        } finally {
            $CFG_GLPI['impact_asset_types'] = $allowed;
            // DbTestCase rolls back the form's configuration and audit writes.
        }
    }

    public function testUnsetUndisclosedFields()
    {
        $input = [
           'context'   => 'core',
           'name'      => 'name',
           'value'     => 'value'
        ];
        $expected = $input;

        ConfigModel::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);

        $input = [
           'context'   => 'core',
           'name'      => 'proxy_passwd',
           'value'     => 'value'
        ];
        $expected = $input;
        unset($expected['value']);

        ConfigModel::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);

        $input = [
           'context'   => 'core',
           'name'      => 'smtp_passwd',
           'value'     => 'value'
        ];
        $expected = $input;
        unset($expected['value']);

        ConfigModel::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);
    }

    public function testValidatePassword()
    {
        global $CFG_GLPI;
        $this->boolean((bool)$CFG_GLPI['use_password_security'])->isFalse();

        $this->boolean(ConfigModel::validatePassword('mypass'))->isTrue();

        $CFG_GLPI['use_password_security'] = 1;
        $this->integer((int)$CFG_GLPI['password_min_length'])->isIdenticalTo(8);
        $this->integer((int)$CFG_GLPI['password_need_number'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_letter'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_caps'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_symbol'])->isIdenticalTo(1);
        $this->boolean(ConfigModel::validatePassword(''))->isFalse();

        $expected = [
           'Password too short!',
           'Password must include at least a digit!',
           'Password must include at least a lowercase letter!',
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->hasSessionMessages(ERROR, $expected);
        $expected = [
           'Password must include at least a digit!',
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->boolean(ConfigModel::validatePassword('mypassword'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_min_length'] = strlen('mypass');
        $this->boolean(ConfigModel::validatePassword('mypass'))->isFalse();
        $CFG_GLPI['password_min_length'] = 8; //reset

        $this->hasSessionMessages(ERROR, $expected);

        $expected = [
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->boolean(ConfigModel::validatePassword('my1password'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_need_number'] = 0;
        $this->boolean(ConfigModel::validatePassword('mypassword'))->isFalse();
        $CFG_GLPI['password_need_number'] = 1; //reset
        $this->hasSessionMessages(ERROR, $expected);

        $expected = [
           'Password must include at least a symbol!'
        ];
        $this->boolean(ConfigModel::validatePassword('my1paSsword'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_need_caps'] = 0;
        $this->boolean(ConfigModel::validatePassword('my1password'))->isFalse();
        $CFG_GLPI['password_need_caps'] = 1; //reset
        $this->hasSessionMessages(ERROR, $expected);

        $this->boolean(ConfigModel::validatePassword('my1paSsw@rd'))->isTrue();
        $this->hasNoSessionMessage(ERROR);

        $CFG_GLPI['password_need_symbol'] = 0;
        $this->boolean(ConfigModel::validatePassword('my1paSsword'))->isTrue();
        $CFG_GLPI['password_need_symbol'] = 1; //reset
        $this->hasNoSessionMessage(ERROR);
    }

    public function testGetLibraries()
    {
        $actual = $expected = [];
        $deps = ConfigModel::getLibraries(true);
        foreach ($deps as $dep) {
            // composer names only (skip htmlLawed)
            if (strpos((string) $dep['name'], '/')) {
                $actual[] = $dep['name'];
            }
        }
        sort($actual);
        $this->array($actual)->isNotEmpty();
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        foreach (array_keys($composer['require']) as $dep) {
            // composer names only (skip php, ext-*, ...)
            if (strpos((string) $dep, '/')) {
                $expected[] = $dep;
            }
        }
        sort($expected);
        $this->array($expected)->isNotEmpty();
        $this->array(array_values(array_diff($actual, $expected)))->isEmpty();
    }

    public function testGetLibraryDir()
    {
        $this->boolean(ConfigModel::getLibraryDir(''))->isFalse();
        $this->boolean(ConfigModel::getLibraryDir('abcde'))->isFalse();

        $expected = realpath(__DIR__ . '/../../vendor/phpmailer/phpmailer/src');
        if (is_dir($expected)) { // skip when system library is used
            $this->string(ConfigModel::getLibraryDir('PHPMailer\PHPMailer\PHPMailer'))->isIdenticalTo($expected);

            $mailer = new PHPMailer();
            $this->string(ConfigModel::getLibraryDir($mailer))->isIdenticalTo($expected);
        }

        $expected = realpath(__DIR__ . '/../');
        $this->string(ConfigModel::getLibraryDir('getItemByTypeName'))->isIdenticalTo($expected);
    }

    public function testDatabaseConfigurationRequiresSelectedPdoDriver(): void
    {
        $command = new InstallCommand();
        $validate = new ReflectionMethod($command, 'validateConfigInput');
        $functions = new funktion('Glpi\\Console\\Database');
        try {
            foreach (['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'] as $provider => $extension) {
                $input = new ArrayInput([
                    '--db-type' => $provider, '--db-name' => 'requirements_only', '--db-user' => 'test',
                ], $command->getDefinition());
                $functions->extension_loaded = static fn (string $name): bool => $name === $extension;
                $this->variable($validate->invoke($command, $input))->isNull();
                // MySQLi cannot substitute for either actual PDO transport.
                $functions->extension_loaded = static fn (string $name): bool => $name === 'mysqli';
                $this->exception(static fn () => $validate->invoke($command, $input))
                    ->isInstanceOf(ConsoleInvalidArgumentException::class)
                    ->hasMessage('The ' . $extension . ' PHP extension is required for this database provider.');
            }
        } finally {
            unset($functions->extension_loaded);
        }
    }

    public function testCoreExtensionsFollowConfiguredPdoProvider(): void
    {
        global $DB;
        $database = $DB;
        try {
            foreach ([DBmysql::class => 'pdo_mysql', DBpgsql::class => 'pdo_pgsql'] as $adapter => $extension) {
                // Select the configured provider without opening either transport.
                $DB = (new ReflectionClass($adapter))->newInstanceWithoutConstructor();
                $report = ConfigModel::checkExtensions();
                $required = $report['good'] + $report['missing'];
                $this->array($required)->hasKey($extension)->notHasKey('mysqli');
                $this->array($required)->notHasKey($extension === 'pdo_mysql' ? 'pdo_pgsql' : 'pdo_mysql');
            }
        } finally {
            $DB = $database;
        }
    }

    public function testCheckExtensions()
    {
        $this->array(ConfigModel::checkExtensions())
           ->hasKeys(['error', 'good', 'missing', 'may']);

        $expected = [
           'error'     => 0,
           'good'      => [
              'json' => 'json extension is installed',
           ],
           'missing'   => [],
           'may'       => []
        ];

        //check extension from class name
        $list = [
           'json' => [
              'required'  => true,
              'class'     => JsonException::class
           ]
        ];
        $report = ConfigModel::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //check extension from method name
        $list = [
           'json' => [
              'required'  => true,
              'function'  => 'json_encode'
           ]
        ];
        $report = ConfigModel::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //check extension from its name
        $list = [
           'json' => [
              'required'  => true
           ]
        ];
        $report = ConfigModel::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //required, missing extension
        $list['notantext'] = [
           'required'  => true
        ];
        $report = ConfigModel::checkExtensions($list);
        $expected = [
           'error'     => 2,
           'good'      => [
              'json' => 'json extension is installed',
           ],
           'missing'   => [
              'notantext' => 'notantext extension is missing'
           ],
           'may'       => []
        ];
        $this->array($report)->isIdenticalTo($expected);

        //not required, missing extension
        unset($list['notantext']);
        $list['totally_optionnal'] = ['required' => false];
        $report = ConfigModel::checkExtensions($list);
        $expected = [
           'error'     => 1,
           'good'      => [
              'json' => 'json extension is installed',
           ],
           'missing'   => [],
           'may'       => [
              'totally_optionnal' => 'totally_optionnal extension is not present'
           ]
        ];
        $this->array($report)->isIdenticalTo($expected);
    }

    public function testCacheBackendBootstrapReadsFreshRawConfiguration(): void
    {
        global $DB, $PHP_LOG_HANDLER;
        $connection = $DB->getDoctrineConnection();
        $context = "cache-bootstrap-'" . bin2hex(random_bytes(6));
        $otherContext = $context . '-other';
        $name = 'cache_db';
        $table = $connection->quoteIdentifier(ConfigModel::getTable());
        $hadCache = array_key_exists('GLPI_CACHE', $GLOBALS);
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $settings = static fn (string $namespace, int $ttl): string => json_encode([
            'adapter' => 'memory',
            'options' => ['namespace' => $namespace, 'ttl' => $ttl],
        ], JSON_THROW_ON_ERROR);
        $encrypted = Toolbox::sodiumEncrypt($settings('must-not-decrypt', 99));
        $memory = new ArrayAdapter(storeSerialized: false);
        try {
            $connection->insert($table, ['context' => $context, 'name' => $name, 'value' => $settings('first', 17)]);
            $connection->insert($table, ['context' => $otherContext, 'name' => $name, 'value' => $settings('wrong-context', 31)]);
            $connection->insert($table, ['context' => $context, 'name' => 'other-cache', 'value' => $settings('wrong-name', 43)]);
            unset($GLOBALS['GLPI_CACHE']);
            $first = ConfigModel::getCache($name, $context, false);
            $this->object($first)->isInstanceOf(ArrayAdapter::class);
            $firstCache = new Psr16Cache($first);
            $this->boolean($firstCache->set('retained', 'first'))->isTrue();
            $this->integer((new ReflectionProperty(ArrayAdapter::class, 'defaultLifetime'))->getValue($first))->isIdenticalTo(17);

            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $connection->update($table, ['value' => $settings('second', 29)], ['context' => $context, 'name' => $name]);
            $second = ConfigModel::getCache($name, $context, false);
            $this->object($second)->isNotIdenticalTo($first);
            $secondCache = new Psr16Cache($second);
            $this->boolean($secondCache->has('retained'))->isFalse();
            $this->integer((new ReflectionProperty(ArrayAdapter::class, 'defaultLifetime'))->getValue($second))->isIdenticalTo(29);
            $this->string($firstCache->get('retained'))->isIdenticalTo('first');

            $sessionSettings = ['adapter' => 'session', 'options' => ['namespace' => $context, 'ttl' => 23]];
            $connection->update($table, ['value' => json_encode($sessionSettings, JSON_THROW_ON_ERROR)], ['context' => $context, 'name' => $name]);
            $session = ConfigModel::getCache($name, $context, false);
            $this->object($session)->isInstanceOf(SessionAdapter::class);
            $this->boolean($session->set('explicit-ttl', 'value', 23))->isTrue();
            $this->string($session->get('explicit-ttl'))->isIdenticalTo('value');
            $session->delete('explicit-ttl');
            $this->boolean($session->set('configuration', ['native' => true]))->isTrue();
            $this->array($session->get('configuration'))->isIdenticalTo(['native' => true]);
            $session->delete('configuration');

            // Neither SQL NULL, JSON null nor ciphertext is an adapter declaration.
            foreach ([null, 'null', $encrypted] as $value) {
                $connection->update($table, ['value' => $value], ['context' => $context, 'name' => $name]);
                $fallback = ConfigModel::getCache($name, $context, false);
                $this->object($fallback)->isInstanceOf(FilesystemAdapter::class);
                $this->integer((new ReflectionProperty(AbstractAdapter::class, 'defaultLifetime'))->getValue($fallback))->isIdenticalTo(600);
            }
            $connection->delete($table, ['context' => $context, 'name' => $name]);
            $this->object(ConfigModel::getCache($name, $context, false))->isInstanceOf(FilesystemAdapter::class);
            $this->array($memory->getValues())->isEmpty('Cache backend construction does not populate the ORM metadata cache');

            // Invalid default-backend settings keep the ordinary request fallback,
            // while an explicit cache-clear operation must receive the original error.
            $unavailable = ['options' => ['ttl' => -1]];
            $connection->insert($table, ['context' => $context, 'name' => $name,
                'value' => json_encode($unavailable, JSON_THROW_ON_ERROR)]);
            $this->object(ConfigModel::getCache($name, $context, false))->isInstanceOf(ArrayAdapter::class);
            $this->exception(static fn () => ConfigModel::getCache($name, $context, false, allowFallback: false))
                ->isInstanceOf(CacheConfigurationException::class)
                ->hasMessage('Cache namespace must be a string and TTL a nonnegative integer.');

            // A disconnected configured adapter cannot reveal a stored custom backend.
            $connected = $DB->connected;
            try {
                $DB->connected = false;
                $this->object(ConfigModel::getCache($name, $context, false))->isInstanceOf(FilesystemAdapter::class);
                $this->exception(static fn () => ConfigModel::getCache($name, $context, false, allowFallback: false))
                    ->isInstanceOf(RuntimeException::class)
                    ->hasMessage('The configured cache cannot be read until the database is available.');
            } finally {
                $DB->connected = $connected;
            }

            // Consume only the seven deliberate getCache debug messages, after
            // checking their complete decoded payloads and order.
            $expectedPayloads = [];
            foreach ([['first', 17], ['second', 29]] as [$namespace, $ttl]) {
                $expectedPayloads[] = 'CACHE CONFIG  cache_db ' . str_replace("\n", "\n  ", print_r([
                    'adapter' => 'memory',
                    'options' => ['namespace' => $namespace, 'ttl' => $ttl],
                ], true));
            }
            $expectedPayloads[] = 'CACHE CONFIG  cache_db ' . str_replace("\n", "\n  ", print_r($sessionSettings, true));
            $expectedPayloads[] = 'CACHE CONFIG  cache_db NULL ';
            $expectedPayloads[] = 'CACHE CONFIG  cache_db NULL ';
            $unavailablePayload = 'CACHE CONFIG  cache_db ' . str_replace("\n", "\n  ", print_r($unavailable, true));
            $expectedPayloads[] = $unavailablePayload;
            $expectedPayloads[] = $unavailablePayload;
            $records = $PHP_LOG_HANDLER->getRecords();
            $this->array($records)->hasSize(7);
            foreach ($records as $index => $record) {
                $this->string($record['level_name'])->isIdenticalTo('DEBUG');
                [$caller, $payload] = explode("\n", $record['message'], 2);
                $this->integer(preg_match(
                    '~^' . preg_quote('Config::getCache() in ' . realpath(GLPI_ROOT . '/inc/config.class.php') . ' line ', '~') . '[0-9]+$~D',
                    $caller
                ))->isIdenticalTo(1);
                $this->string($payload)->isIdenticalTo($expectedPayloads[$index]);
            }
            $PHP_LOG_HANDLER->clear();
        } finally {
            $connection->delete($table, ['context' => $context]);
            $connection->delete($table, ['context' => $otherContext]);
            if ($hadCache) {
                $GLOBALS['GLPI_CACHE'] = $previous;
            } else {
                unset($GLOBALS['GLPI_CACHE']);
            }
        }
    }

    public function testCacheClearCommandUsesConfiguredBackendAndReportsFailure(): void
    {
        global $DB, $GLPI_CACHE, $PHP_LOG_HANDLER;
        $connection = $DB->getDoctrineConnection();
        $where = ['context' => 'core', 'name' => 'cache_db'];
        $original = $connection->fetchAssociative('SELECT value FROM glpi_configs WHERE context = ? AND name = ?', array_values($where));
        $previous = $GLPI_CACHE;
        $directory = 'cache-clear-' . bin2hex(random_bytes(6));
        $unavailable = ['options' => ['ttl' => -1]];
        $settings = ['adapter' => 'filesystem', 'options' => ['namespace' => 'custom-deployment', 'cache_dir' => $directory]];
        $blocked = GLPI_CACHE_DIR . '/' . $directory . '/custom-deployment/A/B/blocked';
        $GLPI_CACHE = new Psr16Cache(new ArrayAdapter());
        $GLPI_CACHE->set('borrowed', 'retained');
        try {
            $this->string($GLPI_CACHE->get('borrowed'))->isIdenticalTo('retained');
            if ($original === false) {
                $connection->insert('glpi_configs', $where + ['value' => json_encode($unavailable, JSON_THROW_ON_ERROR)]);
            } else {
                $connection->update('glpi_configs', ['value' => json_encode($unavailable, JSON_THROW_ON_ERROR)], $where);
            }
            $command = new CommandTester(new ClearCacheCommand());
            $this->exception(static fn () => $command->execute([]))
                ->isInstanceOf(CacheConfigurationException::class)
                ->hasMessage('Cache namespace must be a string and TTL a nonnegative integer.');
            $this->string($GLPI_CACHE->get('borrowed'))->isIdenticalTo('retained');

            $connection->update('glpi_configs', ['value' => json_encode($settings, JSON_THROW_ON_ERROR)], $where);
            $cache = ConfigModel::getCache('cache_db');
            // A real filesystem clear cannot unlink this nonempty directory.
            $this->boolean(mkdir($blocked, 0700, true))->isTrue();
            file_put_contents($blocked . '/retained', 'uncleared');
            $this->integer($command->execute([]))->isIdenticalTo(1);
            $this->string($command->getDisplay())->contains('The application cache could not be cleared.');
            $this->string($command->getDisplay())->notContains('Cache reset successful');
            $this->string(file_get_contents($blocked . '/retained'))->isIdenticalTo('uncleared');
            $this->string($GLPI_CACHE->get('borrowed'))->isIdenticalTo('retained');

            unlink($blocked . '/retained');
            rmdir($blocked);
            $this->boolean($cache->set('mapping', 'previous deployment'))->isTrue();
            $this->string($cache->get('mapping'))->isIdenticalTo('previous deployment');
            $this->integer($command->execute([]))->isIdenticalTo(0);
            $this->string($command->getDisplay())->contains('Cache reset successful');
            $this->boolean($cache->has('mapping'))->isFalse();
            $this->string($GLPI_CACHE->get('borrowed'))->isIdenticalTo('retained');

            $records = $PHP_LOG_HANDLER->getRecords();
            $this->array($records)->hasSize(4);
            foreach ([$unavailable, $settings, $settings, $settings] as $index => $configuration) {
                $this->string($records[$index]['level_name'])->isIdenticalTo('DEBUG');
                $this->string(explode("\n", $records[$index]['message'], 2)[1])->isIdenticalTo(
                    'CACHE CONFIG  cache_db ' . str_replace("\n", "\n  ", print_r($configuration, true))
                );
            }
            $PHP_LOG_HANDLER->clear();
        } finally {
            if ($original === false) {
                $connection->delete('glpi_configs', $where);
            } else {
                $connection->update('glpi_configs', $original, $where);
            }
            $GLPI_CACHE = $previous;
            (new Filesystem())->remove(GLPI_CACHE_DIR . '/' . $directory);
        }
    }

    public function testPublicMetadataRemainsLocalAcrossManagersAndApplicationPools(): void
    {
        global $DB;
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ArrayAdapter(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $listener = new class () {
            public int $loads = 0;
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                if ($event->getClassMetadata()->name === ConfigRecord::class) {
                    ++$this->loads;
                }
            }
        };
        $owned = static function () use ($DB, $listener): EntityManager {
            $manager = Orm::create($DB);
            $manager->getEventManager()->addEventListener(Events::loadClassMetadata, $listener);
            return $manager;
        };
        try {
            unset($GLOBALS['GLPI_CACHE']);
            $bootstrap = Orm::create($DB);
            $this->object($bootstrap->getConfiguration()->getMetadataCache())->isInstanceOf(ArrayAdapter::class);
            $bootstrap->getClassMetadata(ConfigRecord::class);
            $GLOBALS['GLPI_CACHE'] = $pool;
            $first = $owned();
            $original = $first->getClassMetadata(ConfigRecord::class)->generatorType;
            $this->integer($listener->loads)->isIdenticalTo(1);
            $first->getClassMetadata(ConfigRecord::class)->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            $second = $owned();
            $this->object($second)->isNotIdenticalTo($first);
            $this->object($second->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
            $this->integer($second->getClassMetadata(ConfigRecord::class)->generatorType)->isIdenticalTo($original);
            $this->integer($listener->loads)->isIdenticalTo(2, 'Every mutable public manager dispatches its own mapping listeners');
            $this->array($memory->getValues())->isEmpty();
            $public = Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform());
            $this->object($public->getMetadataCache())->isInstanceOf(ArrayAdapter::class);
            $this->variable($public->getQueryCache())->isNull();
            $pool->clear(); // The ordinary application cache-clear boundary.
            $owned()->getClassMetadata(ConfigRecord::class);
            $this->integer($listener->loads)->isIdenticalTo(3);
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache(new ArrayAdapter(storeSerialized: false));
            $owned()->getClassMetadata(ConfigRecord::class);
            $this->integer($listener->loads)->isIdenticalTo(4, 'Public managers do not depend on the application cache pool');
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testOwnedMetadataCacheKeepsProviderDeclarationsSeparate(): void
    {
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $GLOBALS['GLPI_CACHE'] = new Psr16Cache(new ArrayAdapter(storeSerialized: false));
        try {
            foreach ([['pdo_mysql', '8.0.0'], ['pdo_pgsql', '15.0'], ['pdo_mysql', '8.0.0']] as [$driver, $version]) {
                $connection = DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $version]);
                $this->mockGenerator->orphanize('__construct');
                $adapter = new ConfigurationAdapter();
                $this->calling($adapter)->getDoctrineConnection = $connection;
                try {
                    $manager = Orm::create($adapter);
                    $metadata = $manager->getClassMetadata(ComputerRecord::class);
                    $declaration = $metadata->fieldMappings['date_creation']->columnDefinition;
                    if ($driver === 'pdo_mysql') {
                        $this->string($declaration)->contains('TIMESTAMP');
                    } else {
                        $this->variable($declaration)->isNull();
                    }
                    $this->object($manager->getConnection())->isIdenticalTo($connection);
                    $this->boolean($connection->isConnected())->isFalse();
                } finally {
                    $connection->close();
                }
            }
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testPublicQueriesKeepMutableConfigurationAndLiveValues(): void
    {
        global $DB;
        $context = 'query-cache-' . bin2hex(random_bytes(6));
        ConfigModel::setConfigurationValues($context, ['first' => 'before', 'second' => 'other']);
        ConfigQueryCacheWalker::$compilations = 0;
        $first = Orm::create($DB);
        $second = Orm::create($DB);
        $cache = $first->getConfiguration()->getQueryCache();
        $this->variable($cache)->isNull();
        $this->variable($second->getConfiguration()->getQueryCache())->isNull();
        $this->object($first)->isNotIdenticalTo($second);
        $this->object($first->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
        $this->object($second->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
        $this->variable(Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform())->getQueryCache())->isNull();
        $original = $first->getClassMetadata(ConfigRecord::class)->generatorType;
        $first->getClassMetadata(ConfigRecord::class)->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        $this->integer($second->getClassMetadata(ConfigRecord::class)->generatorType)->isIdenticalTo($original);
        $read = static function (EntityManager $manager, string $name) use ($context): string {
            return $manager->createQuery('SELECT c.value FROM ' . ConfigRecord::class . ' c WHERE c.context = :context AND c.name = :name')
                ->setParameter('context', $context, Types::STRING)
                ->setParameter('name', $name, Types::STRING)
                ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, ConfigQueryCacheWalker::class)
                ->getSingleScalarResult();
        };
        try {
            $this->string($read($first, 'first'))->isIdenticalTo('before');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(1);
            $this->string($read($second, 'second'))->isIdenticalTo('other');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(2);
            ConfigModel::setConfigurationValues($context, ['first' => 'after']);
            $this->string($read(Orm::create($DB), 'first'))->isIdenticalTo('after');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(3);
            $this->string($read(Orm::create($DB), 'first'))->isIdenticalTo('after');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(4);
        } finally {
            ConfigModel::deleteConfigurationValues($context, ['first', 'second']);
        }
    }


    public function testCompilerRejectionKeepsTheOwnedMatchingManager(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $context = 'matching-admission-' . bin2hex(random_bytes(6));
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ConfigRecordPlanCache(storeSerialized: false);
        try {
            ConfigModel::setConfigurationValues($context, ['probe' => 'before']);
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $read = static fn (): array => MappedReads::matching($DB, 'glpi_configs', ['context' => $context], ['id']);
            $expected = array_column($read(), null, 'id');
            $before = null;
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$before): void {
                $before = $manager;
            });
            $writes = $memory->planWrites;
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $constructions = $factories->getValue();
            $criteria = ['context' => $context, 'id' => new QuerySubQuery([
                'SELECT' => 'id', 'FROM' => 'glpi_configs', 'WHERE' => ['context' => $context],
            ])];
            // This is the ordinary public fallback, including typed, ID-keyed rows.
            $this->array((new ConfigModel())->find($criteria, ['id']))->isIdenticalTo($expected);
            $this->integer($memory->planWrites)->isIdenticalTo($writes);
            $this->array($before->getUnitOfWork()->getIdentityMap())->isEmpty();
            $connection->update('glpi_configs', ['value' => 'after'], ['context' => $context]);
            $this->string($read()[0]['value'])->isIdenticalTo('after');
            $after = null;
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$after): void {
                $after = $manager;
            });
            // Expected to fail on genuine 8b6 BEFORE: it replaces the manager after rejection.
            $this->object($after)->isIdenticalTo($before);
            $this->integer($factories->getValue() - $constructions)->isIdenticalTo(0);
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testMatchingRejectionsKeepDirectSuppliedAndNestedContracts(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $context = 'matching-contract-' . bin2hex(random_bytes(6));
        $supplied = null;
        try {
            ConfigModel::setConfigurationValues($context, ['probe' => 'current']);
            $id = (int)$connection->fetchOne('SELECT id FROM glpi_configs WHERE context = ?', [$context]);
            $criteria = ['id' => new QuerySubQuery(['SELECT' => 'id', 'FROM' => 'glpi_configs', 'WHERE' => ['context' => $context]])];
            Orm::withConnection($connection, function (EntityManager $manager) use ($connection, $id, $criteria, $DB): void {
                $sentinel = $manager->find(ConfigRecord::class, $id);
                $operation = new RecordReadOperation($connection, $manager);
                try {
                    // The public operation must still throw even with the active shared manager.
                    $this->exception(static fn () => $operation->matching('glpi_configs', $criteria, [], null, 0))
                        ->isInstanceOf(UnsupportedCriteria::class);
                    $this->boolean($manager->contains($sentinel))->isTrue();
                    $this->object($operation->matchingResult('glpi_configs', $criteria, [], null, 0))
                        ->isInstanceOf(UnsupportedCriteria::class);
                    $this->exception(static function () use ($connection, $criteria): void {
                        Orm::withConnection($connection, static function (EntityManager $nested) use ($connection, $criteria): void {
                            $read = new RecordReadOperation($connection, $nested);
                            try {
                                $read->matchingResult('glpi_configs', $criteria, [], null, 0);
                            } finally {
                                $read->close();
                            }
                        });
                    })->isInstanceOf(UnsupportedCriteria::class);
                    $this->boolean($manager->contains($sentinel))->isTrue();
                    $this->exception(static fn () => (new RecordRepository($manager))->matching('glpi_configs', $criteria))
                        ->isInstanceOf(UnsupportedCriteria::class);
                    // Reentrant public work owns an isolated manager and must not clear the outer one.
                    $this->exception(static fn () => MappedReads::matching($DB, 'glpi_configs', $criteria))
                        ->isInstanceOf(UnsupportedCriteria::class);
                    $this->boolean($manager->contains($sentinel))->isTrue();
                    $this->boolean($connection->ownsApplicationEntityManager($manager))->isTrue();
                } finally {
                    $operation->close();
                }
            });
            $supplied = Orm::forConnection($connection);
            $sentinel = $supplied->find(ConfigRecord::class, $id);
            $operation = new RecordReadOperation($connection, $supplied);
            try {
                $this->exception(static fn () => $operation->matching('glpi_configs', $criteria, [], null, 0))
                    ->isInstanceOf(UnsupportedCriteria::class);
                $this->boolean($supplied->contains($sentinel))->isTrue();
                $this->exception(static fn () => $operation->matchingResult('glpi_configs', $criteria, [], null, 0))
                    ->isInstanceOf(UnsupportedCriteria::class);
            } finally {
                $operation->close();
            }
            $this->boolean($supplied->contains($sentinel))->isTrue();
            ConfigModel::setConfigurationValues($context, ['fallback' => 'hydrated']);
            $fallbackId = (int)$connection->fetchOne('SELECT id FROM glpi_configs WHERE context = ? AND name = ?', [$context, 'fallback']);
            $listener = new class () {
                public array $managers = [];

                public function postLoad(PostLoadEventArgs $event): void
                {
                    $this->managers[] = $event->getObjectManager();
                }
            };
            $supplied->getEventManager()->addEventListener([Events::postLoad], $listener);
            $operation = new RecordReadOperation($connection, $supplied);
            try {
                $this->string($operation->row('glpi_configs', 'id', $fallbackId)['value'])->isIdenticalTo('hydrated');
                $this->boolean($supplied->contains($sentinel))->isTrue('A hydrated point read cannot clear its caller manager');
                $this->array($listener->managers)->isIdenticalTo([$supplied]);
                $supplied->detach($supplied->find(ConfigRecord::class, $fallbackId));
                $this->string($operation->matching('glpi_configs', ['id' => $fallbackId], [], null, 0)[0]['value'])->isIdenticalTo('hydrated');
                $this->boolean($supplied->contains($sentinel))->isTrue('A hydrated collection cannot clear unrelated caller entities');
                $this->array($listener->managers)->isIdenticalTo([$supplied, $supplied]);
                $sentinel->value = 'pending-with-listener';
                $this->string($operation->matching('glpi_configs', ['id' => $id], [], null, 0)[0]['value'])
                    ->isIdenticalTo('pending-with-listener');
                $this->boolean($supplied->contains($sentinel))->isTrue('Matching a caller entity must not detach its pending edit');
            } finally {
                $operation->close();
                $supplied->getEventManager()->removeEventListener([Events::postLoad], $listener);
            }
            $this->boolean($supplied->contains($sentinel))->isTrue();
            $supplied->flush();
            $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE id = ?', [$id]))
                ->isIdenticalTo('pending-with-listener');
            $sentinel->value = 'pending-without-listener';
            $operation = new RecordReadOperation($connection, $supplied);
            try {
                $this->string($operation->matching('glpi_configs', ['id' => $id], [], null, 0)[0]['value'])
                    ->isIdenticalTo('pending-without-listener');
                $this->boolean($supplied->contains($sentinel))->isTrue();
            } finally {
                $operation->close();
            }
            $supplied->flush();
            $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE id = ?', [$id]))
                ->isIdenticalTo('pending-without-listener');
            $private = new RecordReadOperation($connection);
            try {
                $this->exception(static fn () => $private->matchingResult('glpi_configs', $criteria, [], null, 0))
                    ->isInstanceOf(UnsupportedCriteria::class);
            } finally {
                $private->close();
            }
        } finally {
            $supplied?->clear();
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testMatchingCompilationRetainsFirstFailureOrder(): void
    {
        global $DB;
        $manager = Orm::create($DB);
        try {
            $records = new RecordRepository($manager);
            $subquery = new QuerySubQuery(['SELECT' => 'id', 'FROM' => 'glpi_configs']);
            $this->exception(static fn () => $records->matching('glpi_configs', [['id' => []], ['id' => $subquery]]))
                ->isInstanceOf(RuntimeException::class)->hasMessage('Empty IN are not allowed');
            $this->exception(static fn () => $records->matching('glpi_configs', [['id' => $subquery], ['id' => []]]))
                ->isInstanceOf(UnsupportedCriteria::class)->hasMessage('Expressions and subqueries require mapped queries.');
            $this->exception(static fn () => $records->matching('glpi_configs', [['missing_admission_field' => 1], ['id' => $subquery]]))
                ->isInstanceOf(UnsupportedCriteria::class)->hasMessage('Unmapped column in record criteria: missing_admission_field');
            $this->exception(static fn () => $records->matching('glpi_configs', ['id' => 0], ['name; SELECT 1']))
                ->isInstanceOf(UnsupportedCriteria::class)->hasMessage('Invalid mapped ordering.');
            $failure = static function (callable $read): Throwable {
                try {
                    $read();
                } catch (Throwable $error) {
                    return $error;
                }
                throw new LogicException('Expected the first invalid temporal value to fail.');
            };
            $invalidDate = $failure(static fn () => $records->matching('glpi_computers', ['date_mod' => 'not-a-calendar-value']));
            $beforeSubquery = $failure(static fn () => $records->matching('glpi_computers', [
                ['date_mod' => 'not-a-calendar-value'], ['id' => $subquery],
            ]));
            $this->string($beforeSubquery::class)->isIdenticalTo($invalidDate::class);
            $this->string($beforeSubquery->getMessage())->isIdenticalTo($invalidDate->getMessage());
            $this->exception(static fn () => $records->matching('glpi_computers', [
                ['id' => $subquery], ['date_mod' => 'not-a-calendar-value'],
            ]))->isInstanceOf(UnsupportedCriteria::class)->hasMessage('Expressions and subqueries require mapped queries.');
        } finally {
            $manager->clear();
        }
    }

    public function testMatchingCallbackFailuresStillResetTheOwnedManager(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $context = 'matching-failure-' . bin2hex(random_bytes(6));
        $originalText = Type::getType('text');
        $failed = null;
        try {
            ConfigModel::setConfigurationValues($context, ['probe' => 'current']);
            foreach (['metadata', 'sql', 'postLoad', 'prepare'] as $phase) {
                // Each phase begins with fresh metadata and query cache on the real owned route.
                Orm::withConnection($connection, static function (EntityManager $manager): void {
                    $manager->close();
                });
                $expected = new UnsupportedCriteria('Foreign ' . $phase . ' callback failure');
                $caught = null;
                $failed = null;
                try {
                    Orm::withConnection($connection, static function (EntityManager $manager) use ($phase, $expected, $context, &$failed): void {
                        $failed = $manager;
                        if ($phase === 'metadata' || $phase === 'postLoad') {
                            $listener = new class ($expected) {
                                public function __construct(private UnsupportedCriteria $error)
                                {
                                }
                                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                                {
                                    if ($event->getClassMetadata()->name === ConfigRecord::class) {
                                        throw $this->error;
                                    }
                                }
                                public function postLoad(PostLoadEventArgs $event): void
                                {
                                    if ($event->getObject() instanceof ConfigRecord) {
                                        throw $this->error;
                                    }
                                }
                            };
                            $manager->getEventManager()->addEventListener([
                                $phase === 'metadata' ? Events::loadClassMetadata : Events::postLoad,
                            ], $listener);
                        } elseif ($phase === 'sql') {
                            // Install after ownership admission to exercise its actual error guard.
                            Type::overrideType('text', new class ($expected) extends TextType {
                                public function __construct(private UnsupportedCriteria $error)
                                {
                                }
                                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                                {
                                    throw $this->error;
                                }
                            });
                        }
                        $owner = $phase === 'prepare' ? new class ($expected) implements ReadQueryOwner {
                            public function __construct(private UnsupportedCriteria $error)
                            {
                            }
                            public function prepareQuery(Query $query, ClassMetadata $metadata): void
                            {
                                throw $this->error;
                            }
                        } : null;
                        (new RecordRepository($manager))->matchingResult('glpi_configs', ['context' => $context], operation: $owner);
                    });
                } catch (Throwable $error) {
                    $caught = $error;
                } finally {
                    Type::overrideType('text', $originalText);
                }
                $this->object($caught)->isIdenticalTo($expected);
                $this->array($failed->getUnitOfWork()->getIdentityMap())->isEmpty();
                $next = null;
                Orm::withConnection($connection, static function (EntityManager $manager) use (&$next): void {
                    $next = $manager;
                });
                $this->object($next)->isNotIdenticalTo($failed);
                $this->object($next->getConfiguration()->getQueryCache())->isNotIdenticalTo($failed->getConfiguration()->getQueryCache());
                $this->string(MappedReads::matching($DB, 'glpi_configs', ['context' => $context])[0]['value'])->isIdenticalTo('current');
            }
            // A PHP-only converter remains eligible for the real public shared read.
            // Keep it installed through the identity check: restoring the registry first
            // would itself reset the owner and conceal an incorrectly swallowed error.
            $expected = new UnsupportedCriteria('Public scalar PHP callback failure');
            Type::overrideType('text', new class ($expected) extends TextType {
                public function __construct(private UnsupportedCriteria $error)
                {
                }
                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
                {
                    throw $this->error;
                }
            });
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$failed): void {
                $failed = $manager;
            });
            $caught = null;
            try {
                MappedReads::matching($DB, 'glpi_configs', ['context' => $context]);
            } catch (Throwable $error) {
                $caught = $error;
            }
            $this->object($caught)->isIdenticalTo($expected);
            $this->array($failed->getUnitOfWork()->getIdentityMap())->isEmpty();
            $next = null;
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$next): void {
                $next = $manager;
            });
            $this->object($next)->isNotIdenticalTo($failed);
            $this->object($next->getConfiguration()->getQueryCache())->isNotIdenticalTo($failed->getConfiguration()->getQueryCache());
        } finally {
            Type::overrideType('text', $originalText);
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testMatchingAdmissionOnlyReturnsItsOwnCompilerDiagnostic(): void
    {
        global $DB;
        $probe = new class ($DB->getDoctrineConnection()) extends ScalarReadProbe {
            public ?UnsupportedCriteria $platformFailure = null;
            public function getDatabasePlatform(): AbstractPlatform
            {
                if ($this->platformFailure !== null) {
                    throw $this->platformFailure;
                }
                return parent::getDatabasePlatform();
            }
        };
        $manager = Orm::forConnection($probe);
        try {
            $metadata = $manager->getClassMetadata(ConfigRecord::class);
            $query = $manager->createQueryBuilder()->select('r')->from(ConfigRecord::class, 'r');
            $compiler = new RecordCriteria($query, $metadata);
            $diagnostic = $compiler->applyMatching(['id' => new QuerySubQuery([
                'SELECT' => 'id', 'FROM' => 'glpi_configs',
            ])], []);
            $this->object($diagnostic)->isInstanceOf(UnsupportedCriteria::class);
            $this->string($diagnostic->getMessage())->isIdenticalTo('Expressions and subqueries require mapped queries.');
            $this->array($probe->queries)->isEmpty();
            $this->object((new RecordRepository($manager))->matchingResult('glpi_configs', ['id' => new QuerySubQuery([
                'SELECT' => 'id', 'FROM' => 'glpi_configs',
            ])]))->isInstanceOf(UnsupportedCriteria::class);
            $this->array($probe->queries)->isEmpty();
            // Rejected builders are discarded rather than reused as executable queries.
            $query = $manager->createQueryBuilder()->select('r')->from(ConfigRecord::class, 'r');
            $compiler = new RecordCriteria($query, $metadata);
            $foreign = new UnsupportedCriteria('Connection callback, not a compiler diagnostic');
            $caught = null;
            $probe->platformFailure = $foreign;
            try {
                // Arm only after metadata/configuration setup; LIKE performs the observed callback.
                $compiler->applyMatching(['name' => ['LIKE', '%']], []);
            } catch (Throwable $error) {
                $caught = $error;
            } finally {
                $probe->platformFailure = null;
            }
            $this->object($caught)->isIdenticalTo($foreign);
            $this->array($probe->queries)->isEmpty();
            $this->exception(static fn () => $compiler->applyMatching(['id' => []], []))
                ->isInstanceOf(RuntimeException::class)->hasMessage('Empty IN are not allowed');
            $query = $manager->createQueryBuilder()->select('r')->from(ConfigRecord::class, 'r');
            $compiler = new RecordCriteria($query, $metadata);
            $this->variable($compiler->applyMatching(['id' => 0], ['id']))->isNull();
            $this->array($probe->queries)->isEmpty();
        } finally {
            $probe->platformFailure = null;
            $manager->clear();
        }
    }

    public function testOwnedMatchingPlansKeepRowsParametersAndPaginationLive(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ConfigRecordPlanCache(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $context = 'owned-matching-' . bin2hex(random_bytes(6));
        $originalText = Type::getType('text');
        try {
            ConfigModel::setConfigurationValues($context, ['first' => 'before', 'second' => 'other']);
            $GLOBALS['GLPI_CACHE'] = $pool;
            $read = static fn (string $selected, int $offset = 0): array => MappedReads::matching(
                $DB,
                'glpi_configs',
                ['context' => $selected],
                ['name'],
                1,
                $offset
            );
            $this->string($read($context)[0]['value'])->isIdenticalTo('before');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $this->string($pool->get($memory->planKeys[0]))->contains('Doctrine\\ORM\\Query\\ParserResult');
            $this->string($read($context, 1)[0]['value'])->isIdenticalTo('other');
            $this->array($read($context . '-absent'))->isEmpty();
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $connection->update('glpi_configs', ['value' => 'after'], ['context' => $context, 'name' => 'first']);
            $this->string($read($context)[0]['value'])->isIdenticalTo('after');
            Type::overrideType('text', new ConfigRecordUpperTextType());
            $this->string($read($context)[0]['value'])->isIdenticalTo('AFTER');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            Type::overrideType('text', $originalText);
            $count = static fn (): int => MappedReads::countMatching($DB, 'glpi_configs', ['context' => $context]);
            $this->integer($count())->isIdenticalTo(2);
            $this->integer($memory->planWrites)->isIdenticalTo(2);
            $connection->insert('glpi_configs', ['context' => $context, 'name' => 'third', 'value' => 'new']);
            $this->integer($count())->isIdenticalTo(3);
            $this->integer($memory->planWrites)->isIdenticalTo(2);

            $owner = new RecordReadOperation($connection);
            $supplied = Orm::forConnection($connection);
            try {
                $query = $supplied->createQuery('SELECT c.id FROM ' . ConfigRecord::class . ' c');
                $metadata = $supplied->getClassMetadata(ConfigRecord::class);
                $this->exception(static fn () => $owner->prepareQuery($query, $metadata))
                    ->isInstanceOf(LogicException::class)->hasMessage('A compiled read plan belongs to its private operation.');
                $this->variable((new ReflectionProperty(Query::class, 'queryCache'))->getValue($query))->isNull();
                $configurationCache = new ArrayAdapter(storeSerialized: true);
                $factoryCache = new ArrayAdapter(storeSerialized: true);
                $supplied->getConfiguration()->setMetadataCache($configurationCache);
                $factory = $supplied->getMetadataFactory();
                $factory->setCache($factoryCache);
                $borrowed = new RecordReadOperation($connection, $supplied);
                try {
                    $this->integer($borrowed->countMatching('glpi_configs', ['context' => $context]))->isIdenticalTo(3);
                    $this->object($supplied->getConfiguration()->getMetadataCache())->isIdenticalTo($configurationCache);
                    $this->object((new ReflectionMethod($factory, 'getCache'))->invoke($factory))->isIdenticalTo($factoryCache);
                } finally {
                    $borrowed->close();
                }
            } finally {
                $owner->close();
                $supplied->clear();
            }
        } finally {
            Type::overrideType('text', $originalText);
            $GLOBALS['GLPI_CACHE'] = $previous;
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testOwnedCountChecksActualAssociationParameterSqlType(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $group = $this->createItem(Group::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $groupId = (int)$group->getID();
        $connection->insert('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => (int)Session::getLoginUserID()]);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_groups_users WHERE groups_id = -1'))->isIdenticalTo(0);
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ConfigRecordPlanCache(storeSerialized: false);
        $original = Type::getType('integer');
        try {
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $manager = Orm::forConnection($connection);
            $types = array_column($manager->getClassMetadata(GroupMembership::class)->fieldMappings, 'type');
            $this->boolean(in_array('integer', $types, true))->isFalse();
            $manager->clear();
            $count = static fn (): int => MappedReads::countMatching($DB, 'glpi_groups_users', ['groups_id' => $groupId]);
            $this->integer($count())->isIdenticalTo(1);
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            Type::overrideType('integer', new ConfigReadNegativeIntegerType());
            $this->integer($count())->isIdenticalTo(0);
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            Type::overrideType('integer', $original);
            $this->integer($count())->isIdenticalTo(1);
            $this->integer($memory->planWrites)->isIdenticalTo(1);
        } finally {
            Type::overrideType('integer', $original);
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testOwnedReadsKeepExtensionPlatformsAndInheritedListenersLocal(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $existing = $connection->fetchAssociative('SELECT id, name FROM glpi_configs ORDER BY id LIMIT 1');
        $this->array($existing)->isNotEmpty();
        EntityRegistry::tables();
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        try {
            foreach ([ConfigReadExtensionConnection::class, ConfigReadListenerConnection::class] as $wrapper) {
                $params = $connection->getParams();
                $params['wrapperClass'] = $wrapper;
                $selected = DriverManager::getConnection($params, $connection->getConfiguration());
                $memory = new ConfigRecordPlanCache(storeSerialized: false);
                $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
                $listener = null;
                $owner = null;
                try {
                    $owner = new RecordReadOperation($selected);
                    if ($selected instanceof ConfigReadListenerConnection) {
                        $listener = new class () {
                            public int $loads = 0;
                            public int $targetLoads = 0;
                            public ?EntityManagerInterface $manager = null;
                            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                            {
                                if ($event->getClassMetadata()->name === ConfigRecord::class) {
                                    ++$this->loads;
                                    $event->getClassMetadata()->fieldMappings['name']->type = 'text';
                                    $this->manager = $event->getEntityManager();
                                }
                                if ($event->getClassMetadata()->name === EntityRecord::class) {
                                    ++$this->targetLoads;
                                    $event->getClassMetadata()->fieldMappings['id']->type = 'decimal';
                                }
                            }
                        };
                        $selected->getEventManager()->addEventListener(Events::loadClassMetadata, $listener);
                    }
                    $row = $owner->row('glpi_configs', 'id', (int)$existing['id']);
                    $this->variable($row['name'])->isIdenticalTo($existing['name']);
                    $this->integer($memory->planWrites)->isIdenticalTo(0);
                    $this->array($memory->getValues())->isEmpty();
                    if ($listener !== null) {
                        $this->integer($listener->loads)->isIdenticalTo(1);
                        $this->string($listener->manager->getClassMetadata(ConfigRecord::class)->getTypeOfField('name'))->isIdenticalTo('text');
                        $this->object($listener->manager->getConfiguration()->getMetadataCache())->isInstanceOf(ArrayAdapter::class);
                        $user = $selected->fetchAssociative('SELECT id, entities_id FROM glpi_users ORDER BY id LIMIT 1');
                        $this->array($user)->isNotEmpty();
                        $row = $owner->row('glpi_users', 'id', (int)$user['id']);
                        $this->string($row['entities_id'])->isIdenticalTo((string)$user['entities_id']);
                        $this->integer($listener->targetLoads)->isIdenticalTo(1);
                        $this->integer($listener->loads)->isIdenticalTo(1);
                        $this->integer($memory->planWrites)->isIdenticalTo(0);
                        $this->array($memory->getValues())->isEmpty();
                    }
                } finally {
                    $owner?->close();
                    if ($listener !== null) {
                        $listener->manager = null;
                    }
                    $selected->close();
                    $this->boolean($selected->isConnected())->isFalse();
                }
            }
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testPrivateRecordPlansKeepLiveRowsAndRecoverDamagedCache(): void
    {
        global $DB;
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ConfigRecordPlanCache(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $context = 'private-plan-' . bin2hex(random_bytes(6));
        $connection = $DB->getDoctrineConnection();
        $table = $connection->quoteIdentifier('glpi_configs');
        try {
            ConfigModel::setConfigurationValues($context, ['probe' => 'before']);
            $id = (int)$connection->fetchOne('SELECT id FROM ' . $table . ' WHERE context = ? AND name = ?', [$context, 'probe']);
            $GLOBALS['GLPI_CACHE'] = $pool;
            $read = static function () use ($id): array {
                $item = new ConfigModel();
                if (!$item->getFromDB($id)) {
                    throw new RuntimeException('The private record fixture disappeared.');
                }
                return $item->fields;
            };
            $this->string($read()['value'])->isIdenticalTo('before');
            // Observe a real serialized ParserResult, not an inferred cache hit.
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $key = $memory->planKeys[0];
            $this->string($pool->get($key))->contains('Doctrine\\ORM\\Query\\ParserResult');
            $connection->update('glpi_configs', ['value' => 'after'], ['id' => $id]);
            $this->string($read()['value'])->isIdenticalTo('after');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $originalText = Type::getType('text');
            try {
                Type::overrideType('text', new ConfigRecordUpperTextType());
                $this->string($read()['value'])->isIdenticalTo('AFTER');
                $this->integer($memory->planWrites)->isIdenticalTo(1);
            } finally {
                Type::overrideType('text', $originalText);
            }
            $this->string($read()['value'])->isIdenticalTo('after');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $pool->set($key, 'invalid serialized query plan');
            $this->string($read()['value'])->isIdenticalTo('after');
            $this->integer($memory->planWrites)->isIdenticalTo(2);
            $pool->clear();
            $this->string($read()['value'])->isIdenticalTo('after');
            $this->integer($memory->planWrites)->isIdenticalTo(3);

            // Public manager customization must not poison the private metadata.
            $pool->clear();
            $manager = Orm::create($DB);
            $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new class () {
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    if ($event->getClassMetadata()->name === ConfigRecord::class) {
                        $event->getClassMetadata()->setPrimaryTable(['name' => 'private_plan_wrong_table']);
                    }
                }
            });
            $this->string($manager->getClassMetadata(ConfigRecord::class)->getTableName())->isIdenticalTo('private_plan_wrong_table');
            $this->string($read()['value'])->isIdenticalTo('after');
            $this->integer($memory->planWrites)->isIdenticalTo(4);
            $manager->clear();
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testPrivateRecordFallbackDetachesCachesBeforeCallbacks(): void
    {
        global $DB;
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new ConfigRecordPlanCache(storeSerialized: false);
        $context = 'private-callback-' . bin2hex(random_bytes(6));
        $connection = $DB->getDoctrineConnection();
        EntityRegistry::tables();
        $registry = new ReflectionProperty(EntityRegistry::class, 'model');
        $original = $registry->getValue();
        $public = Orm::configuration($connection->getDatabasePlatform());
        ConfigRecordCallback::$publicDriver = $public->getMetadataDriverImpl();
        ConfigRecordCallback::$observed = [];
        try {
            ConfigModel::setConfigurationValues($context, ['probe' => 'live']);
            $id = (int)$connection->fetchOne('SELECT id FROM ' . $connection->quoteIdentifier('glpi_configs') . ' WHERE context = ? AND name = ?', [$context, 'probe']);
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $model = $original;
            $model['tables']['glpi_configs'] = ConfigRecordCallback::class;
            $registry->setValue(null, $model);
            $item = new ConfigModel();
            $this->boolean($item->getFromDB($id))->isTrue();
            $this->string($item->fields['value'])->isIdenticalTo('callback:live');
            $this->array(ConfigRecordCallback::$observed)->isIdenticalTo([
                'localConfiguration' => true, 'localFactory' => true,
                'noPersistentQuery' => true, 'privateDriver' => true,
            ]);
            $this->integer($memory->planWrites)->isIdenticalTo(0);
            $rows = MappedReads::matching($DB, 'glpi_configs', ['id' => $id]);
            $this->string($rows[0]['value'])->isIdenticalTo('callback:live');
            $this->array(ConfigRecordCallback::$observed)->isIdenticalTo([
                'localConfiguration' => true, 'localFactory' => true,
                'noPersistentQuery' => true, 'privateDriver' => true,
            ]);
            $this->integer($memory->planWrites)->isIdenticalTo(0);
            $registry->setValue(null, $original);
            $this->boolean($item->getFromDB($id))->isTrue();
            $this->string($item->fields['value'])->isIdenticalTo('live');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            $this->object(Orm::configuration($connection->getDatabasePlatform())->getMetadataDriverImpl())->isNotIdenticalTo(ConfigRecordCallback::$publicDriver);
        } finally {
            $registry->setValue(null, $original);
            $GLOBALS['GLPI_CACHE'] = $previous;
            ConfigRecordCallback::$publicDriver = null;
            ConfigRecordCallback::$observed = [];
            $connection->delete('glpi_configs', ['context' => $context]);
        }
    }

    public function testGetConfigurationValues()
    {
        $conf = ConfigModel::getConfigurationValues('core');
        $this->array($conf)
           ->hasKeys(['version', 'dbversion'])
           ->size->isGreaterThan(170);

        $conf = ConfigModel::getConfigurationValues('core', ['version', 'dbversion']);
        $this->array($conf)->isEqualTo([
           'dbversion' => \ITSM_SCHEMA_VERSION,
           'version'   => \ITSM_VERSION
        ]);
        global $DB;
        $originalAdapter = $DB;
        $connection = $DB->getDoctrineConnection();
        $context = 'literal-NULL-' . $this->getUniqueString();
        $literalName = $context . '-key';
        $manager = Orm::forConnection($connection);
        $ordinary = new ConfigurationRepository($manager);
        $probe = new ScalarReadProbe($connection);
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new ConfigurationAdapter();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $DB->getProvider();
        $string = Type::getType('string');
        $text = Type::getType('text');
        try {
            foreach (['z-key' => 'first', 'a-key' => 'last', 'NULL' => 'NULL'] as $name => $value) {
                $connection->insert('glpi_configs', ['context' => $context, 'name' => $name, 'value' => $value]);
            }
            $expected = ['z-key' => 'first', 'a-key' => 'last', 'NULL' => 'NULL'];
            $this->array($ordinary->values($context))->isIdenticalTo($expected);
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($expected);
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeFactories = $factories->getValue();
            for ($repeat = 0; $repeat < 3; ++$repeat) {
                $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($expected);
            }
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
            $this->exception(static fn () => ConfigurationRepository::forConnection($probe, $manager))
                ->isInstanceOf(LogicException::class)
                ->hasMessage('A configuration read must use its selected physical connection.');
            $connection->update('glpi_configs', ['value' => 'fresh'], ['context' => $context, 'name' => 'z-key']);
            $this->array(ConfigModel::getConfigurationValues($context, ['z-key']))->isIdenticalTo(['z-key' => 'fresh']);
            $connection->update('glpi_configs', ['value' => 'first'], ['context' => $context, 'name' => 'z-key']);
            $DB = $adapter;
            $beforeFactories = $factories->getValue();
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($expected);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(1);

            $this->array(ConfigModel::getConfigurationValues($context, ['later' => 'a-key', 'earlier' => 'z-key']))
                ->isIdenticalTo(['z-key' => 'first', 'a-key' => 'last']);
            $this->array(ConfigModel::getConfigurationValues($context, ['NULL']))->isIdenticalTo(['NULL' => 'NULL']);
            $this->array(ConfigModel::getConfigurationValues($context, ['absent']))->isEmpty();
            $connection->insert('glpi_configs', ['context' => 'NULL', 'name' => $literalName, 'value' => null]);
            $this->array(ConfigModel::getConfigurationValues('NULL', [$literalName]))->isIdenticalTo([$literalName => null]);
            $otherProbe = new ScalarReadProbe($connection);
            $otherAdapter = new ConfigurationAdapter();
            $this->calling($otherAdapter)->getDoctrineConnection = $otherProbe;
            $contextCallback = new class ($context, $otherAdapter, $factories) {
                public int $factoriesAtCast = 0;

                public function __construct(private string $context, private object $replacement, private ReflectionProperty $factories)
                {
                }

                public function __toString(): string
                {
                    $this->factoriesAtCast = $this->factories->getValue();
                    $GLOBALS['DB'] = $this->replacement;
                    return $this->context;
                }
            };
            $queryCount = count($probe->queries);
            $beforeFactories = $factories->getValue();
            $this->array(ConfigModel::getConfigurationValues($contextCallback))->isIdenticalTo($expected);
            $this->integer($contextCallback->factoriesAtCast - $beforeFactories)->isIdenticalTo(1);
            $this->integer(count($probe->queries))->isIdenticalTo($queryCount + 1);
            $this->array($otherProbe->queries)->isEmpty();
            $this->object($DB)->isIdenticalTo($otherAdapter);
            $DB = $adapter;

            $connection->update('glpi_configs', ['value' => 'changed'], ['context' => $context, 'name' => 'z-key']);
            $this->array(ConfigModel::getConfigurationValues($context, ['z-key']))->isIdenticalTo(['z-key' => 'changed']);
            $connection->delete('glpi_configs', ['context' => $context, 'name' => 'NULL']);
            $this->array(ConfigModel::getConfigurationValues($context, ['NULL']))->isEmpty();

            Type::overrideType('text', new class () extends TextType {
                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return 'UPPER(' . $sqlExpr . ')';
                }

                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                {
                    throw new LogicException('Scalar projection must not apply PHP conversion');
                }
            });
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($ordinary->values($context))
                ->isIdenticalTo(['z-key' => 'CHANGED', 'a-key' => 'LAST']);
            Type::overrideType('string', new class () extends StringType {
                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return "'same-key'";
                }
            });
            // Distinct stored names collapse after SQL conversion; increasing id
            // retains the same last-row overwrite as the original scalar reader.
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($ordinary->values($context))
                ->isIdenticalTo(['same-key' => 'LAST']);
            Type::overrideType('text', new class () extends TextType {
                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return 'NULL';
                }
            });
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo($ordinary->values($context))
                ->isIdenticalTo(['same-key' => null]);
            Type::overrideType('text', $text);
            Type::overrideType('string', $string);

            $connection->insert('glpi_configs', ['context' => $context . '_sql', 'name' => 'z-key', 'value' => 'array-name']);
            Type::overrideType('string', new class () extends StringType {
                public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return $platform->getConcatExpression($sqlExpr, "'_sql'");
                }
            });
            $this->array(ConfigModel::getConfigurationValues($context, ['z-key']))
                ->isIdenticalTo($ordinary->values($context, ['z-key']))->isIdenticalTo(['z-key' => 'array-name']);
            Type::overrideType('string', $string);

            $extension = new class ($connection) extends ScalarReadProbe {
                private ?EventManager $events = null;

                public function getEventManager(): EventManager
                {
                    return $this->events ??= new EventManager();
                }
            };
            $reader = ConfigurationRepository::forConnection($extension);
            $listener = new class () {
                public int $loads = 0;

                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    if ($event->getClassMetadata()->name === ConfigRecord::class) {
                        ++$this->loads;
                        $event->getClassMetadata()->fieldMappings['value']->columnName = 'name';
                    }
                }
            };
            $extension->getEventManager()->addEventListener(['loadClassMetadata'], $listener);
            $this->array($reader->values($context))->isIdenticalTo(['z-key' => 'z-key', 'a-key' => 'a-key']);
            $this->integer($listener->loads)->isIdenticalTo(1);
            $this->integer($extension->builders)->isIdenticalTo(0);
            $this->array($ordinary->values($context))->isIdenticalTo(['z-key' => 'changed', 'a-key' => 'last']);
            $manager->getClassMetadata(ConfigRecord::class)->fieldMappings['value']->columnName = 'name';
            $this->array($ordinary->values($context))->isIdenticalTo(['z-key' => 'z-key', 'a-key' => 'a-key']);
            $this->array(ConfigModel::getConfigurationValues($context))->isIdenticalTo(['z-key' => 'changed', 'a-key' => 'last']);
        } finally {
            Type::overrideType('string', $string);
            Type::overrideType('text', $text);
            $DB = $originalAdapter;
            $manager->clear();
            $connection->delete('glpi_configs', ['context' => $context]);
            $connection->delete('glpi_configs', ['context' => $context . '_sql']);
            $connection->delete('glpi_configs', ['context' => 'NULL', 'name' => $literalName]);
        }
    }

    public function testSetConfigurationValues()
    {
        $conf = ConfigModel::getConfigurationValues('core', ['version', 'notification_to_myself']);
        $this->array($conf)->isEqualTo([
           'notification_to_myself'   => '1',
           'version'                  => \ITSM_VERSION
        ]);

        //update configuration value
        ConfigModel::setConfigurationValues('core', ['notification_to_myself' => 0]);
        $conf = ConfigModel::getConfigurationValues('core', ['version', 'notification_to_myself']);
        $this->array($conf)->isEqualTo([
           'notification_to_myself'   => '0',
           'version'                  => \ITSM_VERSION
        ]);
        ConfigModel::setConfigurationValues('core', ['notification_to_myself' => 1]); //reset

        //check new configuration key does not exists
        $conf = ConfigModel::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'version' => \ITSM_VERSION
        ]);

        //add new configuration key
        ConfigModel::setConfigurationValues('core', ['new_configuration_key' => 'test']);
        $conf = ConfigModel::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'new_configuration_key' => 'test',
           'version'               => \ITSM_VERSION
        ]);

        //drop new configuration key
        ConfigModel::deleteConfigurationValues('core', ['new_configuration_key']);
        $conf = ConfigModel::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'version' => \ITSM_VERSION
        ]);

        // Bind raw CLI arguments through the real command, including its initialization.
        global $DB, $PLUGIN_HOOKS;
        $connection = $DB->getDoctrineConnection();
        $key = 'command_value_' . bin2hex(random_bytes(6));
        $hooks = $PLUGIN_HOOKS;
        $PLUGIN_HOOKS ??= [];
        $tester = new CommandTester(new SetCommand());
        $values = [
            "C:\\new\\temp\\fixture'\"first\nsecond",
            json_encode([
                'itemtype' => 'GlpiPlugin\\Example\\Device',
                'path' => 'C:\\new\\temp',
                'text' => "Équipement\nline",
            ], JSON_THROW_ON_ERROR),
        ];
        try {
            $PLUGIN_HOOKS['secured_configs']['configcli'] = [$key];
            foreach (['plugin:configcli' => true, 'plugin:configplain' => false] as $context => $secured) {
                $this->boolean((new GLPIKey())->isConfigSecured($context, $key))->isIdenticalTo($secured);
                foreach ($values as $value) {
                    $lastLog = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_logs');
                    $this->integer($tester->execute(
                        ['key' => $key, 'value' => $value, '--context' => $context],
                        ['interactive' => false]
                    ))->isIdenticalTo(0);
                    $stored = ConfigModel::getConfigurationValues($context, [$key])[$key];
                    if ($secured) {
                        $this->string($stored)->isNotIdenticalTo($value);
                        $this->string(Toolbox::sodiumDecrypt($stored))->isIdenticalTo($value);
                        $this->string($tester->getDisplay())->notContains($value);
                    } else {
                        $this->string($stored)->isIdenticalTo($value);
                    }
                    $history = $connection->fetchAllAssociative(
                        'SELECT old_value, new_value FROM glpi_logs WHERE itemtype = ? AND id > ? ORDER BY id',
                        [ConfigModel::class, $lastLog]
                    );
                    $this->array($history)->hasSize(1);
                    $this->string($history[0]['new_value'])->isIdenticalTo($secured ? '********' : $value);
                    if ($secured) {
                        $this->string($history[0]['old_value'])->isIdenticalTo($key . ' (' . $context . ') ********');
                    }
                }
            }

            // Classification uses the exact legacy name passed to the setter.
            $quotedKey = $key . "'\\suffix";
            $PLUGIN_HOOKS['secured_configs']['configcli'] = [$quotedKey];
            $this->boolean((new GLPIKey())->isConfigSecured('plugin:configcli', $quotedKey))->isTrue();
            $this->boolean((new GLPIKey())->isConfigSecured('plugin:configcli', Toolbox::addslashes_deep($quotedKey)))->isFalse();
            $this->integer($tester->execute(
                ['key' => $quotedKey, 'value' => $values[0], '--context' => 'plugin:configcli'],
                ['interactive' => false]
            ))->isIdenticalTo(0);
            $this->string(ConfigModel::getConfigurationValues('plugin:configcli', [$quotedKey])[$quotedKey])
                ->isIdenticalTo($values[0]);
        } finally {
            $PLUGIN_HOOKS = $hooks;
            // DbTestCase rolls back these synthetic configurations and their audit rows.
        }
    }

    public function testGetRights()
    {
        $conf = new ConfigModel();
        $this->array($conf->getRights())->isIdenticalTo([
           READ     => 'Read',
           UPDATE   => 'Update'
        ]);
    }

    public function testGetPalettes()
    {
        $palettes_dir = GLPI_ROOT . '/css/palettes/';
        if (!is_dir($palettes_dir)) {
            $this->boolean(is_dir($palettes_dir))->isFalse();
            return;
        }

        $expected = [];
        foreach (scandir($palettes_dir) as $file) {
            if (strpos($file, '.scss') !== false) {
                $name = substr($file, 1, -5);
                $expected[$name] = ucfirst($name);
            }
        }
        $this
           ->if($this->newTestedInstance)
           ->then
              ->array($this->testedInstance->getPalettes())
              ->isIdenticalTo($expected);

    }

    /**
     * Database engines data provider
     *
     * @return array
     */
    protected function dbEngineProvider()
    {
        return [
           [
              'raw'       => '10.2.14-MariaDB',
              'version'   => '10.2.14',
              'compat'    => false // Native CHECK catalogue is available from 10.2.22.
           ], [
              'raw'       => '5.5.10-MariaDB',
              'version'   => '5.5.10',
              'compat'    => false
           ], [
              'raw'       => '5.6.38-log',
              'version'   => '5.6.38',
              'compat'    => false // CHECK syntax without enforcement is insufficient.
           ], [
              'raw'       => '5-5-57',
              'version'   => '5',
              'compat'    => false
           ], [
              'raw'       => '5-6-31',
              'version'   => '5',
              'compat'    => false // since version is 5, this is not compat.
           ], [
              'raw'       => '10-2-35',
              'version'   => '10',
              'compat'    => false // An ambiguous version cannot establish capabilities.
           ], [
              'raw'       => '8.0.15',
              'version'   => '8.0.15',
              'compat'    => false
           ], [
              'raw'       => '8.0.16',
              'version'   => '8.0.16',
              'compat'    => true
           ], [
              'raw'       => '10.2.0-MariaDB',
              'version'   => '10.2.0',
              'compat'    => false
           ], [
              'raw'       => '5.5.5-10.2.22-MariaDB',
              'version'   => '10.2.22',
              'compat'    => true
           ]
        ];
    }

    /**
     * @dataProvider dbEngineProvider
     */
    public function testCheckDbEngine($raw, $version, $compat)
    {
        global $DB;

        // The explicit diagnostic input keeps DbTestCase's actual writer and
        // transaction intact throughout every version-provider invocation.
        $result = ConfigModel::checkDbEngine($raw);
        $this->array($result)->isIdenticalTo([$version => $compat]);
        $this->array(ConfigModel::checkDbEngine())->isIdenticalTo(ConfigModel::checkDbEngine($DB->getVersion()));
    }

    public function testGetLanguage()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->getLanguage('fr'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('fr_FR'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('fr-FR'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('Français'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('french'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('notalang'))
                 ->isIdenticalTo('');

    }

    /**
     * Provides list of classes that can be linked to configuration.
     *
     * @return array
     */
    protected function itemtypeLinkedToConfigurationProvider()
    {
        return [
           [
              'key'      => 'documentcategories_id_forticket',
              'itemtype' => 'DocumentCategory',
           ],
           [
              'key'      => 'default_requesttypes_id',
              'itemtype' => 'RequestType',
           ],
           [
              'key'      => 'softwarecategories_id_ondelete',
              'itemtype' => 'SoftwareCategory',
           ],
           [
              'key'      => 'ssovariables_id',
              'itemtype' => 'SsoVariable',
           ],
           [
              'key'      => 'transfers_id_auto',
              'itemtype' => 'Transfer',
           ],
        ];
    }

    /**
     * Check that relation between items and configuration are correctly cleaned.
     *
     * @param string $key
     * @param string $itemtype
     *
     * @dataProvider itemtypeLinkedToConfigurationProvider
     */
    public function testCleanRelationDataOfLinkedItems($key, $itemtype)
    {

        // Case 1: used item is cleaned without replacement
        $item = new $itemtype();
        $item->fields = ['id' => 15];

        ConfigModel::setConfigurationValues('core', [$key => $item->fields['id']]);

        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isTrue();
        }
        $item->cleanRelationData();
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $this->array(ConfigModel::getConfigurationValues('core', [$key]))
           ->hasKey($key)
           ->variable[$key]->isEqualTo(0);

        // Case 2: unused item is cleaned without effect
        $item = new $itemtype();
        $item->fields = ['id' => 15];

        $random_id = mt_rand(20, 100);

        ConfigModel::setConfigurationValues('core', [$key => $random_id]);

        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $item->cleanRelationData();
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $this->array(ConfigModel::getConfigurationValues('core', [$key]))
           ->hasKey($key)
           ->variable[$key]->isEqualTo($random_id);

        // Case 3: used item is cleaned with replacement (CommonDropdown only)
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $replacement_item = new $itemtype();
            $replacement_item->fields = ['id' => 12];

            $item = new $itemtype();
            $item->fields = ['id' => 15];
            $item->input = ['_replace_by' => $replacement_item->fields['id']];

            ConfigModel::setConfigurationValues('core', [$key => $item->fields['id']]);

            $this->boolean($item->isUsed())->isTrue();
            $this->boolean($replacement_item->isUsed())->isFalse();
            $item->cleanRelationData();
            $this->boolean($item->isUsed())->isFalse();
            $this->boolean($replacement_item->isUsed())->isTrue();
            $this->array(ConfigModel::getConfigurationValues('core', [$key]))
               ->hasKey($key)
               ->variable[$key]
                  ->isEqualTo($replacement_item->fields['id']);
        }
    }

    public function testDevicesInMenu()
    {
        global $CFG_GLPI, $DB;

        $conf = new ConfigModel();
        $this->array($CFG_GLPI['devices_in_menu'])->isIdenticalTo([
           'Item_DeviceSimcard'
        ]);

        //Config::prepareInputForUpdate() always return false.
        $conf->update([
           'id'                       => 1,
           '_update_devices_in_menu'  => 1,
           'devices_in_menu'          => ['Item_DeviceSimcard', 'Item_DeviceBattery']
        ]);

        //check values in db
        $res = $DB->request([
           'SELECT' => 'value',
           'FROM'   => $conf->getTable(),
           'WHERE'  => ['name' => 'devices_in_menu']
        ])->next();
        $this->array($res)->isIdenticalTo(
            ['value' => exportArrayToDB(['Item_DeviceSimcard', 'Item_DeviceBattery'])]
        );
    }

    /**
     * Test password expiration delay configuration update.
     */
    public function testPasswordExpirationDelayUpdate()
    {
        global $DB;

        $conf = new ConfigModel();
        $crontask = new \CronTask();

        // create some non local users for the test
        foreach ([\Auth::LDAP, \Auth::EXTERNAL, \Auth::CAS] as $authtype) {
            $user = new \User();
            $user_id = $user->add(
                [
                  'name'     => 'test_user_' . mt_rand(),
                  'authtype' => $authtype,
            ]
            );
            $this->integer($user_id)->isGreaterThan(0);
        }

        // get count of users using local auth
        $local_users_count = countElementsInTable(
            \User::getTable(),
            ['authtype' => \Auth::DB_GLPI]
        );
        // get count of users using external auth
        $external_users_count = countElementsInTable(
            \User::getTable(),
            ['NOT' => ['authtype' => \Auth::DB_GLPI]]
        );
        // reset 'password_last_update' to null for the test
        $DB->update(\User::getTable(), ['password_last_update' => null], [true]);

        // initial data:
        //  - password expiration is not active
        //  - users from installation data have no value for password_last_update
        //  - crontask is not active
        $values = ConfigModel::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(-1);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => null]
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
        $this->boolean($crontask->getFromDBbyName(\User::getType(), 'passwordexpiration'))->isTrue();
        $this->integer((int)$crontask->fields['state'])->isIdenticalTo(0);

        // check that activation of password expiration reset `password_last_update` to current date
        // for all local users but not for external users
        // and activate passwordexpiration crontask
        $current_time = $_SESSION['glpi_currenttime'];
        $update_datetime = date('Y-m-d H:i:s', strtotime('-15 days')); // arbitrary date
        $_SESSION['glpi_currenttime'] = $update_datetime;
        $conf->update(
            [
              'id'                        => 1,
              'password_expiration_delay' => 30
         ]
        );
        $_SESSION['glpi_currenttime'] = $current_time;
        $values = ConfigModel::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(30);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => $update_datetime]
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
        $this->boolean($crontask->getFromDBbyName(\User::getType(), 'passwordexpiration'))->isTrue();
        $this->integer((int)$crontask->fields['state'])->isIdenticalTo(1);

        // check that changing password expiration delay does not reset `password_last_update` to current date
        // if password expiration was already active
        $current_time = $_SESSION['glpi_currenttime'];
        $new_update_datetime = date('Y-m-d H:i:s', strtotime('-5 days')); // arbitrary date
        $_SESSION['glpi_currenttime'] = $new_update_datetime;
        $conf->update(
            [
              'id'                        => 1,
              'password_expiration_delay' => 45
         ]
        );
        $_SESSION['glpi_currenttime'] = $current_time;
        $values = ConfigModel::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(45);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => $update_datetime] // previous config update
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
    }

    protected function logConfigChangeProvider()
    {
        global $PLUGIN_HOOKS;

        $PLUGIN_HOOKS['secured_configs']['tester'] = ['passwd'];

        return [
           [
              'context'          => 'core',
              'name'             => 'unexisting_config',
              'is_secured'       => false,
              'old_value_prefix' => 'unexisting_config ',
           ],
           [
              'context'          => 'plugin:tester',
              'name'             => 'check',
              'is_secured'       => false,
              'old_value_prefix' => 'check (plugin:tester) ',
           ],
           [
              'context'          => 'plugin:tester',
              'name'             => 'passwd',
              'is_secured'       => true,
              'old_value_prefix' => 'passwd (plugin:tester) ',
           ]
        ];
    }

    /**
     * @dataProvider logConfigChangeProvider
     */
    public function testLogConfigChange(string $context, string $name, bool $is_secured, string $old_value_prefix)
    {
        $this->variable(Session::getLoginUserID(false))->isIdenticalTo(false);
        $history_crit = [
            'itemtype' => ConfigModel::getType(),
            'old_value' => ['LIKE', $name . ' %'],
            'ORDER' => 'id ASC',
        ];

        $expected_history = [];
        $history_entry_fields = [
           'itemtype'         => ConfigModel::getType(),
           'items_id'         => 1,
           'itemtype_link'    => '',
           'linked_action'    => 0,
           'user_name'        => '',
           'date_mod'         => $_SESSION['glpi_currenttime'],
           'id_search_option' => 1,
        ];

        $clean_ids = function (&$value, $key) {
            unset($value['id']);
        };

        // History on first value
        ConfigModel::setConfigurationValues($context, [$name => 'first value']);
        $expected_history = [
           $history_entry_fields + [
              'old_value' => $old_value_prefix . ($is_secured ? '********' : ''),
              'new_value' => $is_secured ? '********' : 'first value',
           ],
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);

        // History on updated value
        ConfigModel::setConfigurationValues($context, [$name => 'new value']);
        $expected_history[] = $history_entry_fields + [
           'old_value' => $old_value_prefix . ($is_secured ? '********' : 'first value'),
           'new_value' => $is_secured ? '********' : 'new value',
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);

        // History on config deletion
        ConfigModel::deleteConfigurationValues($context, [$name]);
        $expected_history[] = $history_entry_fields + [
           'old_value' => $old_value_prefix . ($is_secured ? '********' : 'new value'),
           'new_value' => $is_secured ? '********' : '',
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);
    }

    public function testAutoCreateInfocom()
    {
        global $CFG_GLPI, $DB;

        $this->login();

        $infocom_types = $CFG_GLPI["infocom_types"];
        $excluded_types = [
           'Cartridge', // Should inherit from CartridgeItem
           'Consumable', // Should inherit from ConsumableItem
        ];
        $infocom_types = array_diff($infocom_types, $excluded_types);

        $had_auto_create = array_key_exists('auto_create_infocoms', $CFG_GLPI);
        $auto_create_original = $CFG_GLPI['auto_create_infocoms'] ?? null;
        $em = Orm::create($DB);
        $parents = [];
        $inputFor = function (CommonDBTM $item, string $name) use ($em, &$createParent): array {
            $input = [];
            if ($item->isField($item::getNameField())) {
                $input[$item::getNameField()] = $name;
            }
            if ($item->isField('entities_id')) {
                $input['entities_id'] = (int)$_SESSION['glpiactive_entity'];
            }
            $metadata = $em->getClassMetadata(EntityRegistry::tables()[$item::getTable()]);
            foreach ($metadata->associationMappings as $mapping) {
                if (!$mapping->isToOneOwningSide()) {
                    continue;
                }
                foreach ($mapping->joinColumns as $join) {
                    if (!$join->nullable && !array_key_exists($join->name, $input)) {
                        $target = $em->getClassMetadata($mapping->targetEntity)->getTableName();
                        $input[$join->name] = $createParent(getItemTypeForTable($target));
                    }
                }
            }
            // Item_Devices owns a subject through its actual public role fields.
            // Use an existing, authorized Computer instead of a fabricated ID.
            if ($item instanceof Item_Devices) {
                $this->array($item::itemAffinity())->contains('Computer');
                $input[$item::$itemtype_1] = 'Computer';
                $input[$item::$items_id_1] = $createParent('Computer');
            }
            return $input;
        };
        $createParent = function (string $type) use (&$parents, $inputFor): int {
            if (!array_key_exists($type, $parents)) {
                $parent = new $type();
                $id = $parent->add($inputFor($parent, 'auto_infocom_parent_' . $type));
                $this->integer($id)->isGreaterThan(0);
                $parents[$type] = $id;
            }
            return $parents[$type];
        };

        try {
            $infocom = new Infocom();
            foreach ($infocom_types as $asset_type) {
                // Prepare public parents before either child control. Required
                // ownership comes from entity mappings, not a fixture catalogue.
                $CFG_GLPI['auto_create_infocoms'] = 0;
                $asset = new $asset_type();
                $input = $inputFor($asset, 'auto_infocom_test');
                $CFG_GLPI['auto_create_infocoms'] = 1;
                $asset_id = $asset->add($input);
                $this->integer($asset_id)->isGreaterThan(0);
                $this->boolean($infocom->getFromDBforDevice($asset_type, $asset_id))->isTrue();

                $CFG_GLPI['auto_create_infocoms'] = 0;
                $asset = new $asset_type();
                $asset_id2 = $asset->add($inputFor($asset, 'auto_infocom_test2'));
                $this->integer($asset_id2)->isGreaterThan(0);
                $this->boolean($infocom->getFromDBforDevice($asset_type, $asset_id2))->isFalse();
            }
        } finally {
            if ($had_auto_create) {
                $CFG_GLPI['auto_create_infocoms'] = $auto_create_original;
            } else {
                unset($CFG_GLPI['auto_create_infocoms']);
            }
        }
    }
}

/** Count real SQL compilation while retaining Doctrine's standard finalizer. */
final class ConfigQueryCacheWalker extends SqlOutputWalker
{
    public static int $compilations = 0;

    public function getFinalizer(DeleteStatement|UpdateStatement|SelectStatement $AST): SqlFinalizer
    {
        ++self::$compilations;
        return parent::getFinalizer($AST);
    }
}

/** Count actual persistent plan writes; leave ordinary cache behavior unchanged. */
final class ConfigRecordPlanCache extends ArrayAdapter
{
    public int $planWrites = 0;
    public array $planKeys = [];

    public function save(CacheItemInterface $item): bool
    {
        if (is_string($item->get()) && str_contains($item->get(), 'Doctrine\\ORM\\Query\\ParserResult')) {
            ++$this->planWrites;
            $this->planKeys[] = $item->getKey();
        }
        return parent::save($item);
    }
}

#[Mapping\Entity]
#[Mapping\Table(name: 'glpi_configs')]
#[Mapping\HasLifecycleCallbacks]
final class ConfigRecordCallback
{
    public static ?object $publicDriver = null;
    public static array $observed = [];

    #[Mapping\Id]
    #[Mapping\Column(type: 'bigint')]
    public ?int $id = null;
    #[Mapping\Column(type: 'string', nullable: true)]
    public ?string $context = null;
    #[Mapping\Column(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Mapping\Column(type: 'text', nullable: true)]
    public ?string $value = null;

    #[Mapping\PostLoad]
    public function loaded(PostLoadEventArgs $event): void
    {
        $manager = $event->getObjectManager();
        $configuration = $manager->getConfiguration();
        $factoryCache = new ReflectionMethod($manager->getMetadataFactory(), 'getCache');
        self::$observed = [
            'localConfiguration' => $configuration->getMetadataCache() instanceof ArrayAdapter,
            'localFactory' => $factoryCache->invoke($manager->getMetadataFactory()) === $configuration->getMetadataCache(),
            'noPersistentQuery' => $configuration->getQueryCache() === null,
            'privateDriver' => $configuration->getMetadataDriverImpl() !== self::$publicDriver,
        ];
        $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $event->getClassMetadata()->setPrimaryTable(['name' => 'callback_wrong_table']);
            }
        });
        $manager->getClassMetadata(ConfigRecord::class);
        $this->value = 'callback:' . $this->value;
    }
}

/** A supported global type extension whose SQL must not inherit a warm core plan. */
final class ConfigRecordUpperTextType extends TextType
{
    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return 'UPPER(' . $sqlExpr . ')';
    }
}


final class ConfigReadNegativeIntegerType extends IntegerType
{
    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return '(' . $sqlExpr . ' * 0 - 1)';
    }
}

class ConfigReadListenerConnection extends Connection
{
    private ?EventManager $readEvents = null;

    public function getEventManager(): EventManager
    {
        return $this->readEvents ??= new EventManager();
    }
}

class ConfigReadExtensionConnection extends Connection
{
    private ?AbstractPlatform $readPlatform = null;

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->readPlatform ??= (parent::getDatabasePlatform() instanceof PostgreSQLPlatform
            ? new ConfigReadPostgreSQLPlatform() : new ConfigReadMySQLPlatform());
    }
}

class ConfigReadPostgreSQLPlatform extends PostgreSQLPlatform
{
}

class ConfigReadMySQLPlatform extends MySQLPlatform
{
}
