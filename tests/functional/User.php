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

/* Test for inc/user.class.php */

class User extends \DbTestCase
{
    public function testPreferredEmailUsesCurrentTypedSelectedRead(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $user = $this->createItem(\User::class, ['name' => 'preferred-' . $this->getUniqueString()]);
        $id = (int)$user->getID();
        foreach (['first@example.test', 'second@example.test'] as $address) {
            $connection->insert('glpi_useremails', ['users_id' => $id, 'email' => $address, 'is_default' => false, 'is_dynamic' => false], ['users_id' => 'bigint', 'email' => 'string', 'is_default' => 'boolean', 'is_dynamic' => 'boolean']);
        }
        $manager = \itsmng\Database\Orm::forConnection($connection);
        $ordinary = new \itsmng\Database\Repository\UserEmailRepository($manager);
        $expected = $ordinary->preferred($id);
        $this->string($expected['email'])->isIdenticalTo('first@example.test');
        $probe = new UserScalarReadProbe($connection);
        $originalAdapter = $DB;
        $scope = $connection->captureManagedTransactionScope();
        $depth = $connection->getTransactionNestingLevel();
        $this->mockGenerator->orphanize('__construct');
        $adapter = new \mock\DBmysql();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $DB = $adapter;
            $this->string(\UserEmail::getDefaultForUser($id))->isIdenticalTo($expected['email']);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
        } finally {
            $DB = $originalAdapter;
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        }
        $reader = new \itsmng\Database\UserEmailReadOperation($probe);
        $integer = \Doctrine\DBAL\Types\Type::getType('integer');
        $string = \Doctrine\DBAL\Types\Type::getType('string');
        $bigint = \Doctrine\DBAL\Types\Type::getType('bigint');
        try {
            foreach ([0, -1, $id] as $selected) {
                $this->variable($reader->preferred($selected))->isIdenticalTo($ordinary->preferred($selected));
            }
            $connection->update('glpi_useremails', ['is_default' => true], ['users_id' => $id, 'email' => 'second@example.test'], ['is_default' => 'boolean', 'users_id' => 'bigint', 'email' => 'string']);
            $this->array($reader->preferred($id))->isIdenticalTo($ordinary->preferred($id));
            $this->string($reader->preferred($id)['email'])->isIdenticalTo('second@example.test');
            // Tied defaults still select the lowest physical identifier.
            $connection->update('glpi_useremails', ['is_default' => true], ['id' => $expected['id']], ['is_default' => 'boolean', 'id' => 'bigint']);
            $this->array($reader->preferred($id))->isIdenticalTo($expected);
            \Doctrine\DBAL\Types\Type::overrideType('string', new class () extends \Doctrine\DBAL\Types\StringType {
                public function convertToPHPValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                {
                    return 'UPPER(' . $sqlExpr . ')';
                }
                public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): mixed
                {
                    return $value === null ? 'converted-null' : 'php:' . $value;
                }
            });
            $this->array($reader->preferred($id))->isIdenticalTo($ordinary->preferred($id));
            $this->string($reader->preferred($id)['email'])->isIdenticalTo('php:FIRST@EXAMPLE.TEST');
            $connection->update('glpi_useremails', ['email' => null], ['id' => $expected['id']]);
            $this->array($reader->preferred($id))->isIdenticalTo($ordinary->preferred($id));
            $this->string($reader->preferred($id)['email'])->isIdenticalTo('converted-null');
            \Doctrine\DBAL\Types\Type::overrideType('string', $string);
            $this->array($reader->preferred($id))->isIdenticalTo($ordinary->preferred($id));
            $this->string(\UserEmail::getDefaultForUser($id))->isEmpty();
            \Doctrine\DBAL\Types\Type::overrideType('integer', new class () extends \Doctrine\DBAL\Types\IntegerType {
                public function convertToDatabaseValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                {
                    return '(' . $sqlExpr . ' * 0 - 1)';
                }
            });
            $this->variable($reader->preferred($id))->isNull();
            $this->variable($ordinary->preferred($id))->isNull();
            \Doctrine\DBAL\Types\Type::overrideType('integer', $integer);
            \Doctrine\DBAL\Types\Type::overrideType('bigint', new class () extends \Doctrine\DBAL\Types\BigIntType {
                public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): int|string|null
                {
                    throw new \Doctrine\ORM\NoResultException();
                }
            });
            $this->variable($reader->preferred($id))->isNull();
            $this->variable($ordinary->preferred($id))->isNull();
            \Doctrine\DBAL\Types\Type::overrideType('bigint', new class () extends \Doctrine\DBAL\Types\BigIntType {
                public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): int|string|null
                {
                    throw new \LogicException('Preferred email conversion failure');
                }
            });
            $this->exception(fn () => $reader->preferred($id))->isInstanceOf(\LogicException::class);
            $this->exception(fn () => $ordinary->preferred($id))->isInstanceOf(\LogicException::class);
            \Doctrine\DBAL\Types\Type::overrideType('bigint', $bigint);
            $extension = new class ($connection) extends UserScalarReadProbe {
                private ?\Doctrine\Common\EventManager $events = null;
                public function getEventManager(): \Doctrine\Common\EventManager
                {
                    return $this->events ??= new \Doctrine\Common\EventManager();
                }
            };
            $local = new \itsmng\Database\UserEmailReadOperation($extension);
            $listener = new class () {
                public int $loads = 0;
                public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $event): void
                {
                    ++$this->loads;
                }
            };
            $extension->getEventManager()->addEventListener([\Doctrine\ORM\Events::loadClassMetadata], $listener);
            $this->array($local->preferred($id))->isIdenticalTo($ordinary->preferred($id));
            $this->integer($listener->loads)->isGreaterThan(0);
            $this->integer($extension->builders)->isIdenticalTo(0);
            $local->close();
            $connection->delete('glpi_useremails', ['users_id' => $id]);
            $this->variable($reader->preferred($id))->isNull();
            $this->string(\UserEmail::getDefaultForUser($id))->isEmpty();
        } finally {
            \Doctrine\DBAL\Types\Type::overrideType('integer', $integer);
            \Doctrine\DBAL\Types\Type::overrideType('string', $string);
            \Doctrine\DBAL\Types\Type::overrideType('bigint', $bigint);
            $reader->close();
            $manager->clear();
        }
    }

    public function testPrivateUserScopesAndDisplayUseFreshTypedScalarReads(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $user = $this->createItem(\User::class, ['name' => 'scalar-' . $this->getUniqueString(), 'realname' => 'lower name']);
        $profile = $this->createItem(\Profile::class, ['name' => $this->getUniqueString()]);
        $id = (int)$user->getID();
        $profileId = (int)$profile->getID();
        $connection->insert('glpi_profiles_users', ['users_id' => $id, 'profiles_id' => $profileId, 'entities_id' => 0, 'is_recursive' => false], ['users_id' => 'bigint', 'profiles_id' => 'bigint', 'entities_id' => 'bigint', 'is_recursive' => 'boolean']);
        $right = 'scalar_scope_' . $id;
        $connection->insert('glpi_profilerights', ['profiles_id' => $profileId, 'name' => $right, 'rights' => 5]);
        $manager = \itsmng\Database\Orm::forConnection($connection);
        $grants = new \itsmng\Database\Repository\ProfileUserRepository($manager);
        $users = new \itsmng\Database\Repository\UserRepository($manager);
        $probe = new UserScalarReadProbe($connection);
        $expectedScope = [];
        foreach ($grants->scopes($id, $profileId) as $grant) {
            $expectedScope[$grant['entities_id']] = $grant['entities_id'];
        }
        $utils = new \DbUtils();
        $expectedName = $utils->getUserName($id);
        $this->array($expectedScope)->isNotEmpty();
        $this->string($expectedName)->isNotEmpty();
        $originalAdapter = $DB;
        $originalScope = $connection->captureManagedTransactionScope();
        $originalDepth = $connection->getTransactionNestingLevel();
        $this->mockGenerator->orphanize('__construct');
        $adapter = new \mock\DBmysql();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $DB = $adapter;
            $this->array(\Profile_User::getEntitiesForProfileByUser($id, $profileId))->isIdenticalTo($expectedScope);
            $this->string($utils->getUserName($id))->isIdenticalTo($expectedName);
            $this->array($probe->queries)->hasSize(2);
            $this->integer($probe->builders)->isIdenticalTo(2);
        } finally {
            $DB = $originalAdapter;
            $originalScope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($originalDepth);
        }
        $probe->builders = 0;
        $probe->queries = [];
        $scopes = new \itsmng\Database\ProfileUserReadOperation($probe);
        $display = new \itsmng\Database\UserDisplayReadOperation($probe);
        $integer = \Doctrine\DBAL\Types\Type::getType('integer');
        $string = \Doctrine\DBAL\Types\Type::getType('string');
        $sort = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int => [$a['entities_id'], $a['is_recursive']] <=> [$b['entities_id'], $b['is_recursive']]);
            return $rows;
        };
        try {
            foreach ([[null, null, 0], [$profileId, null, 0], [0, null, 0], [null, $right, 1], [null, $right, 2], [null, $right, 0], [null, '', 1]] as [$selectedProfile, $selectedRight, $mask]) {
                $this->array($sort($scopes->scopes($id, $selectedProfile, $selectedRight, $mask)))
                    ->isIdenticalTo($sort($grants->scopes($id, $selectedProfile, $selectedRight, $mask)));
            }
            $this->integer($probe->builders)->isIdenticalTo(7);
            $this->array($display->displayData($id))->isIdenticalTo($users->displayData($id));
            $this->integer($probe->builders)->isIdenticalTo(8);
            $this->variable($display->displayData(-1))->isNull();
            $connection->update('glpi_users', ['realname' => 'changed lower', 'phone' => null], ['id' => $id]);
            $this->array($display->displayData($id))->isIdenticalTo($users->displayData($id));
            $this->string($display->displayData($id)['realname'])->isIdenticalTo('changed lower');
            $connection->update('glpi_profiles_users', ['is_recursive' => true], ['users_id' => $id, 'profiles_id' => $profileId], ['is_recursive' => 'boolean', 'users_id' => 'bigint', 'profiles_id' => 'bigint']);
            $this->array($scopes->scopes($id, $profileId))->isIdenticalTo($grants->scopes($id, $profileId));
            $connection->update('glpi_profilerights', ['rights' => 0], ['profiles_id' => $profileId, 'name' => $right]);
            $this->array($scopes->scopes($id, right: $right, mask: 1))->isEmpty();
            $this->array(\Profile_User::getUserEntitiesForRight($id, $right, 1))->isEmpty();
            \Doctrine\DBAL\Types\Type::overrideType('integer', new class () extends \Doctrine\DBAL\Types\IntegerType {
                public function convertToDatabaseValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                {
                    return '(' . $sqlExpr . ' * 0 - 1)';
                }
            });
            $this->array($scopes->scopes($id))->isEmpty();
            $this->array($grants->scopes($id))->isEmpty();
            $this->variable($display->displayData($id))->isNull();
            $this->variable($users->displayData($id))->isNull();
            \Doctrine\DBAL\Types\Type::overrideType('integer', $integer);
            \Doctrine\DBAL\Types\Type::overrideType('string', new class () extends \Doctrine\DBAL\Types\StringType {
                public function convertToPHPValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                {
                    return 'UPPER(' . $sqlExpr . ')';
                }
                public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): mixed
                {
                    throw new \LogicException('Explicit scalar aliases must not convert PHP values.');
                }
            });
            $this->array($display->displayData($id))->isIdenticalTo($users->displayData($id));
            $this->string($display->displayData($id)['realname'])->isIdenticalTo('CHANGED LOWER');
            \Doctrine\DBAL\Types\Type::overrideType('string', new class () extends \Doctrine\DBAL\Types\StringType {
                public function convertToDatabaseValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                {
                    return "'no-matching-permission'";
                }
            });
            $connection->update('glpi_profilerights', ['rights' => 5], ['profiles_id' => $profileId, 'name' => $right]);
            $expectedRight = $grants->scopes($id, right: $right, mask: 1);
            $this->array($expectedRight)->isNotEmpty();
            $this->array($scopes->scopes($id, right: $right, mask: 1))->isIdenticalTo($expectedRight);
            \Doctrine\DBAL\Types\Type::overrideType('string', $string);
            $extension = new class ($connection) extends UserScalarReadProbe {
                private ?\Doctrine\Common\EventManager $events = null;
                public function getEventManager(): \Doctrine\Common\EventManager
                {
                    return $this->events ??= new \Doctrine\Common\EventManager();
                }
            };
            $localScopes = new \itsmng\Database\ProfileUserReadOperation($extension);
            $localDisplay = new \itsmng\Database\UserDisplayReadOperation($extension);
            $listener = new class () {
                public int $loads = 0;
                public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $event): void
                {
                    ++$this->loads;
                }
            };
            $extension->getEventManager()->addEventListener([\Doctrine\ORM\Events::loadClassMetadata], $listener);
            $this->array($sort($localScopes->scopes($id)))->isIdenticalTo($sort($grants->scopes($id)));
            $this->array($localDisplay->displayData($id))->isIdenticalTo($users->displayData($id));
            $this->integer($listener->loads)->isGreaterThan(0);
            $this->integer($extension->builders)->isIdenticalTo(0);
            $localScopes->close();
            $localDisplay->close();
        } finally {
            \Doctrine\DBAL\Types\Type::overrideType('integer', $integer);
            \Doctrine\DBAL\Types\Type::overrideType('string', $string);
            $scopes->close();
            $display->close();
            $manager->clear();
        }
    }

    public function testDefaultAddressSelectionUsesCurrentSurvivorsInCallerTransaction(): void
    {
        global $DB;
        $original = $DB;
        $originalConnection = $original->getDoctrineConnection();
        $originalScope = $originalConnection->captureManagedTransactionScope();
        $originalLevel = $originalConnection->getTransactionNestingLevel();
        $mysql = $original->getProvider() !== 'pgsql';
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public array $selections = [];
            public function log($level, $message, array $context = []): void
            {
                $sql = str_replace(['`', '"'], '', $context['sql'] ?? '');
                if (preg_match('/^SELECT\b.*\bFROM\s+glpi_useremails\b.*\bFOR UPDATE\b/is', $sql)) {
                    $this->selections[] = ['sql' => $sql, 'params' => array_values($context['params'] ?? [])];
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $reader = $writer = $frame = null;
        $fixtures = [];
        $failure = null;
        $cleanup = static function (callable $operation) use (&$failure): void {
            try {
                $operation();
            } catch (\Throwable $error) {
                $failure = $failure === null ? $error : new \itsmng\Database\MutationCleanupFailure($failure, $error);
            }
        };
        try {
            $parameters = $originalConnection->getParams();
            $reader = $mysql ? \itsmng\Database\MySQLConnection::create($parameters, $configuration)
                : \itsmng\Database\PostgresConnection::create($parameters, $configuration);
            $writer = $mysql ? \itsmng\Database\MySQLConnection::create($parameters)
                : \itsmng\Database\PostgresConnection::create($parameters);
            foreach ([$reader, $writer] as $connection) {
                if ($mysql) {
                    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
                } else {
                    $connection->executeStatement("SET SESSION lock_timeout = '5s'");
                    $connection->executeStatement("SET SESSION statement_timeout = '20s'");
                }
            }
            // PostgreSQL's RR rejects some changed locked rows with a legitimate
            // serialization failure. Only MySQL uses RR for this snapshot proof.
            $reader->setTransactionIsolation($mysql ? \Doctrine\DBAL\TransactionIsolationLevel::REPEATABLE_READ
                : \Doctrine\DBAL\TransactionIsolationLevel::READ_COMMITTED);
            foreach ([false, true] as $fallback) {
                $token = 'default-current-' . $this->getUniqueString();
                $fixture = \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $token): array {
                    $manager = new \Doctrine\ORM\EntityManager($writer, \itsmng\Database\Orm::configuration($writer->getDatabasePlatform()));
                    try {
                        $account = new \itsmng\Database\Entity\User();
                        $account->name = $token;
                        $account->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, 0);
                        $manager->persist($account);
                        $first = new \itsmng\Database\Entity\UserEmail();
                        $first->users = $account;
                        $first->email = $token . '-a@example.org';
                        $first->is_default = true;
                        $second = new \itsmng\Database\Entity\UserEmail();
                        $second->users = $account;
                        $second->email = $token . '-b@example.org';
                        $manager->persist($first);
                        $manager->persist($second);
                        $manager->flush();
                        return ['user' => $account->id, 'name' => $token, 'first' => $first->id, 'second' => $second->id];
                    } finally {
                        $manager->clear();
                    }
                });
                $fixtures[] = $fixture;
                $frame = \itsmng\Database\OwnedMutationFrame::begin($reader);
                $manager = new \Doctrine\ORM\EntityManager($reader, \itsmng\Database\Orm::configuration($reader->getDatabasePlatform()));
                $repository = new \itsmng\Database\Repository\UserEmailRepository($manager);
                $this->integer((int)$repository->preferred($fixture['user'])['id'])->isIdenticalTo($fixture['first']);
                \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $fixture): void {
                    $writer->delete('glpi_useremails', ['id' => $fixture['first'], 'users_id' => $fixture['user']]);
                    $writer->update(
                        'glpi_useremails',
                        ['is_default' => true],
                        ['id' => $fixture['second'], 'users_id' => $fixture['user']],
                        ['is_default' => \Doctrine\DBAL\Types\Types::BOOLEAN]
                    );
                });
                $context = $fallback ? 'Preferred survivor after concurrent deletion' : 'Explicit concurrently deleted address';
                $logger->selections = [];
                $this->boolean($repository->selectDefault($fixture['user'], $fallback ? null : $fixture['first']))
                    ->isIdenticalTo($fallback, $context);
                $frame->assertActive();
                $this->integer($reader->getTransactionNestingLevel())->isIdenticalTo(1, $context . ': caller frame retained');
                $this->array($logger->selections)->hasSize(1, $context . ': selected address uses one current locking read');
                $this->array($logger->selections[0]['params'])->isIdenticalTo($fallback
                    ? [$fixture['user']] : [$fixture['user'], $fixture['first']], $context . ': ownership and address are bound');
                $this->integer((int)$reader->fetchOne(
                    'SELECT is_default FROM glpi_useremails WHERE id = ? AND users_id = ? FOR UPDATE',
                    [$fixture['second'], $fixture['user']]
                ))
                    ->isIdenticalTo(1, $context . ': surviving default is never cleared for a missing address');
                if ($mysql) {
                    $this->integer((int)$repository->preferred($fixture['user'])['id'])
                        ->isIdenticalTo($fixture['first'], $context . ': the ordinary lookup still has the old caller snapshot');
                }
                $this->boolean($repository->selectDefault($fixture['user'], $fixture['second']))
                    ->isTrue($context . ': an already-default current row is valid even if UPDATE changes no bytes');
                $frame->assertActive();
                $frame->rollBack();
                $frame = null;
                $manager->clear();
                $this->array(array_map('intval', $writer->fetchAssociative(
                    'SELECT id, is_default FROM glpi_useremails WHERE users_id = ?',
                    [$fixture['user']]
                )))
                    ->isIdenticalTo(['id' => $fixture['second'], 'is_default' => 1], $context . ': writer commit survives caller rollback');
                $this->object($DB)->isIdenticalTo($original);
                $originalScope->assertActive();
                $this->integer($originalConnection->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
            }
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            if ($frame !== null) {
                $cleanup(static fn () => $frame->rollBack());
            }
            // These are independent test connections, never DbTestCase's owner.
            // Closing our reader releases only its own resources before cleanup.
            if ($reader !== null) {
                $cleanup(static fn () => $reader->close());
            }
            if ($writer !== null) {
                foreach ($fixtures as $fixture) {
                    $cleanup(static fn () => \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $fixture): void {
                        if ($writer->fetchOne('SELECT name FROM glpi_users WHERE id = ?', [$fixture['user']]) !== $fixture['name']) {
                            throw new \LogicException('Refusing cleanup of an unowned user fixture');
                        }
                        $rows = $writer->fetchAllAssociative('SELECT id, email FROM glpi_useremails WHERE users_id = ?', [$fixture['user']]);
                        foreach ($rows as $row) {
                            $suffix = match ((int)$row['id']) {
                                $fixture['first'] => '-a@example.org', $fixture['second'] => '-b@example.org',
                                default => throw new \LogicException('Refusing cleanup of an unexpected user address'),
                            };
                            if ($row['email'] !== $fixture['name'] . $suffix) {
                                throw new \LogicException('Refusing cleanup of a changed address identity');
                            }
                        }
                        foreach ($rows as $row) {
                            $writer->delete('glpi_useremails', ['id' => $row['id'], 'users_id' => $fixture['user'], 'email' => $row['email']]);
                        }
                        if ($writer->delete('glpi_users', ['id' => $fixture['user'], 'name' => $fixture['name']]) !== 1) {
                            throw new \LogicException('Owned user fixture cleanup failed');
                        }
                    }));
                }
                $cleanup(static fn () => $writer->close());
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        $this->object($DB)->isIdenticalTo($original);
        $originalScope->assertActive();
        $this->integer($originalConnection->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
    }

    public function testReusedUserPermissionScopesFollowCurrentIdentityAndGrants(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $database = $DB;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $parent = (int)$_SESSION['glpiactive_entity'];
            $allowed = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $denied = (int)getItemByTypeName('Entity', '_test_child_2', true);
            $accounts = [];
            foreach ([['scope-a-', $allowed], ['scope-b-', $denied]] as [$prefix, $entity]) {
                $stored = ['name' => $prefix . $this->getUniqueString(), 'comment' => 'Complete permission fields'];
                $account = new \User();
                $id = $account->add($stored + ['_entities_id' => $entity, '_is_recursive' => 0]);
                // Form-only inputs create a grant; they are not stored User fields.
                $this->checkInput($account, $id, $stored);
                $grant = new \Profile_User();
                $this->boolean($grant->getFromDBByCrit(['users_id' => $id]))->isTrue();
                $this->integer((int)$grant->fields['entities_id'])->isIdenticalTo($entity);
                $this->integer((int)$grant->fields['is_recursive'])->isIdenticalTo(0);
                $accounts[] = $account;
            }
            [$first, $second] = $accounts;
            $model = new \User();
            $scopes = new \ReflectionMethod(\User::class, 'getEntities');
            $this->setEntity('_test_child_1', false);
            $_SESSION['glpiactiveprofile']['user'] |= READ;

            // CLI deliberately bypasses canViewItem's entity restriction. Check
            // its actual scope source and exercise the real restrictive hook/link
            // boundary, without changing the production CLI policy.
            $mutate = null;
            $calls = [];
            $callback = static function (\User $user) use ($scopes, &$mutate, &$calls): void {
                $calls[] = ['id' => (int)$user->getID(), 'right' => $user->right, 'comment' => $user->fields['comment']];
                if ($mutate !== null) {
                    $mutate();
                    $mutate = null;
                }
                if (!\Session::haveAccessToOneOfEntities($scopes->invoke($user))) {
                    $user->right = false;
                }
            };
            $plugins->setValue(null, [...$active, 'current_user_scope_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['current_user_scope_fixture' => [\User::class => $callback]];
            foreach ([[$first, $allowed, true], [$second, $denied, false],
                [$second, $denied, false], [$first, $allowed, true]] as [$account, $entity, $canLink]) {
                $this->boolean($model->getFromDB($account->getID()))->isTrue();
                $this->array(array_map('intval', $scopes->invoke($model)))->isIdenticalTo([$entity]);
                $this->boolean(str_contains($model->getLink(), '<a '))->isIdenticalTo($canLink);
            }
            $this->array(array_column($calls, 'id'))->isIdenticalTo([(int)$first->getID(), (int)$second->getID(),
                (int)$second->getID(), (int)$first->getID()]);
            $this->array(array_column($calls, 'right'))->isIdenticalTo([READ, READ, READ, READ]);
            $this->array(array_column($calls, 'comment'))->isIdenticalTo(array_fill(0, 4, 'Complete permission fields'));

            $connection = $DB->getDoctrineConnection();
            $mutate = static fn () => $connection->update('glpi_profiles_users', ['entities_id' => $denied], ['users_id' => $first->getID()]);
            $this->string($model->getLink())->notContains('<a ');
            $this->array(array_map('intval', $scopes->invoke($model)))->isIdenticalTo([$denied]);
            $connection->update(
                'glpi_profiles_users',
                ['entities_id' => $parent, 'is_recursive' => true],
                ['users_id' => $first->getID()],
                ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN]
            );
            $this->boolean(in_array($allowed, $scopes->invoke($model)))->isTrue();
            $this->string($model->getLink())->contains('<a ');
            $connection->update(
                'glpi_profiles_users',
                ['is_recursive' => false],
                ['users_id' => $first->getID()],
                ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN]
            );
            $this->array(array_map('intval', $scopes->invoke($model)))->isIdenticalTo([$parent]);
            $this->string($model->getLink())->notContains('<a ');

            $before = $model->fields;
            $this->boolean($model->getFromDB(PHP_INT_MAX))->isFalse();
            $this->array($model->fields)->isIdenticalTo($before);
            $this->array(array_map('intval', $scopes->invoke($model)))->isIdenticalTo([$parent]);

            // Re-resolve the current adapter rather than retain a prior manager.
            $this->mockGenerator->orphanize('__construct');
            $routed = new \mock\DBmysql();
            $reads = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$reads) {
                ++$reads;
                return $connection;
            };
            $DB = $routed;
            $this->array(array_map('intval', $scopes->invoke($model)))->isIdenticalTo([$parent]);
            $this->integer($reads)->isGreaterThan(0);
        } finally {
            $DB = $database;
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testTimelineAuthorReaderOwnsOnlyOneRenderAndFollowsCurrentRoute(): void
    {
        global $DB;
        $this->login();
        $user = $this->createItem(\User::class, ['name' => 'render-author-' . $this->getUniqueString(), 'entities_id' => 0]);
        $id = (int)$user->getID();
        $reader = new \itsmng\Database\TimelineAuthorReader();
        $model = new \User();
        $this->boolean($reader->load($model, $id, $DB))->isTrue();
        // Inspect our private operation owner, without exposing it in the API.
        $owned = new \ReflectionProperty($reader, 'records');
        $getManager = static fn ($render) => (new \ReflectionProperty(\itsmng\Database\RecordReadOperation::class, 'manager'))->getValue($owned->getValue($render));
        $manager = $getManager($reader);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
        $previousCache = $GLOBALS['GLPI_CACHE'] ?? null;
        $connection = $DB->getDoctrineConnection();
        $alternate = $DB->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($connection->getParams())
            : \itsmng\Database\MySQLConnection::create($connection->getParams());
        try {
            $this->boolean($DB->update('glpi_users', ['comment' => 'Fresh callback write'], ['id' => $id]))->isTrue();
            $this->boolean($reader->load($model, $id, $DB))->isTrue();
            $this->object($getManager($reader))->isIdenticalTo($manager);
            $this->string($model->fields['comment'])->isIdenticalTo('Fresh callback write');
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->mockGenerator->orphanize('__construct');
            $routed = new \mock\DBmysql();
            $currentConnection = $connection;
            $routeCalls = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use (&$currentConnection, &$routeCalls) {
                ++$routeCalls;
                return $currentConnection;
            };
            $this->boolean($reader->load($model, $id, $routed))->isTrue();
            $this->integer($routeCalls)->isIdenticalTo(1);
            $adapterManager = $getManager($reader);
            $this->object($adapterManager)->isNotIdenticalTo($manager);
            $currentConnection = $alternate;
            $before = $model->fields;
            $this->boolean($reader->load($model, PHP_INT_MAX, $routed))->isFalse();
            $this->array($model->fields)->isIdenticalTo($before);
            $this->object($getManager($reader))->isNotIdenticalTo($adapterManager);
            $this->object($getManager($reader)->getConnection())->isIdenticalTo($alternate);
            $this->boolean($reader->load($model, $id, $DB))->isTrue();
            $beforePoolChange = $getManager($reader);
            $GLOBALS['GLPI_CACHE'] = new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
            $this->boolean($reader->load($model, $id, $DB))->isTrue();
            $this->object($getManager($reader))->isNotIdenticalTo($beforePoolChange);
            $metadata = $getManager($reader)->getClassMetadata(\itsmng\Database\Entity\User::class);
            $originalGenerator = $metadata->generatorType;
            $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            $GLOBALS['GLPI_CACHE']->clear();
            $nextRender = new \itsmng\Database\TimelineAuthorReader();
            $this->boolean($nextRender->load($model, $id, $DB))->isTrue();
            $this->object($getManager($nextRender))->isNotIdenticalTo($getManager($reader));
            $this->integer($getManager($nextRender)->getClassMetadata(\itsmng\Database\Entity\User::class)->generatorType)
                ->isIdenticalTo($originalGenerator);
            $this->array($getManager($nextRender)->getUnitOfWork()->getIdentityMap())->isEmpty();
            $warmRender = new \itsmng\Database\TimelineAuthorReader();
            $this->boolean($warmRender->load($model, $id, $DB))->isTrue();
            $this->array(array_keys($getManager($warmRender)->getMetadataFactory()->getLoadedMetadata()))
                ->isIdenticalTo([\itsmng\Database\Entity\User::class]);
            $extension = new class ($connection) extends UserScalarReadProbe {
                private ?\Doctrine\Common\EventManager $events = null;
                public function getEventManager(): \Doctrine\Common\EventManager
                {
                    return $this->events ??= new \Doctrine\Common\EventManager();
                }
            };
            $customLoads = new class () {
                public int $metadata = 0;
                public int $entities = 0;
                public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $event): void
                {
                    ++$this->metadata;
                    if ($event->getClassMetadata()->name === \itsmng\Database\Entity\Entity::class) {
                        $event->getClassMetadata()->fieldMappings['id']->type = 'decimal';
                    }
                }
                public function postLoad(): void
                {
                    ++$this->entities;
                }
            };
            $currentConnection = $extension;
            $localRender = new \itsmng\Database\TimelineAuthorReader();
            $extension->getEventManager()->addEventListener([\Doctrine\ORM\Events::loadClassMetadata, \Doctrine\ORM\Events::postLoad], $customLoads);
            $this->boolean($localRender->load($model, $id, $routed))->isTrue();
            $this->integer($customLoads->metadata)->isGreaterThan(0);
            $this->integer($customLoads->entities)->isIdenticalTo(0);
            $ordinaryManager = \itsmng\Database\Orm::forConnection($extension);
            $this->array($model->fields)->isIdenticalTo((new \itsmng\Database\Repository\UserRepository($ordinaryManager))->timelineAuthor($id));
            $this->string($model->fields['entities_id'])->isIdenticalTo('0');
            $ordinaryManager->find(\itsmng\Database\Entity\User::class, $id);
            $this->integer($customLoads->entities)->isGreaterThan(0);
            $ordinaryManager->clear();
            // A normal load still fires the listener: zero above is not a missing observer.
            $manager->find(\itsmng\Database\Entity\User::class, $id);
            $this->integer($loads->count)->isGreaterThan(0);
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previousCache;
            unset($reader, $nextRender, $warmRender, $localRender);
            $manager->clear();
            $alternate->close();
        }
    }

    public function testTimelineAuthorPreservesCompleteFreshRowsWithoutHydration(): void
    {
        global $DB;
        $this->login();
        $database = $DB;
        $user = $this->createItem(\User::class, ['name' => 'timeline-author-' . $this->getUniqueString(),
            'comment' => 'Complete author fields', 'authtype' => \Auth::DB_GLPI]);
        $id = (int)$user->getID();
        $manager = \itsmng\Database\Orm::create($DB);
        $repository = new \itsmng\Database\Repository\UserRepository($manager);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
        try {
            $model = new \User();
            foreach ([[true, '2020-02-03 04:05:06', 'Changed author'], [false, null, null]] as [$active, $date, $firstname]) {
                $this->boolean($DB->update('glpi_users', ['is_active' => $active, 'last_login' => $date,
                    'firstname' => $firstname], ['id' => $id]))->isTrue();
                $this->boolean($user->getFromDB($id))->isTrue();
                $this->array($repository->timelineAuthor($id))->isIdenticalTo($user->fields);
                $this->boolean($model->getTimelineAuthorFromDB($id))->isTrue();
                $this->array($model->fields)->isIdenticalTo($user->fields);
            }
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            // Positive listener control: the same manager's ordinary entity load fires it.
            $manager->find(\itsmng\Database\Entity\User::class, $id);
            $this->integer($loads->count)->isGreaterThan(0);
            $before = $model->fields;
            foreach ([null, '', PHP_INT_MAX] as $missing) {
                $this->boolean($model->getTimelineAuthorFromDB($missing))->isFalse();
                $this->array($model->fields)->isIdenticalTo($before);
            }
            $this->variable($repository->timelineAuthor(PHP_INT_MAX))->isNull();
            $custom = new class () extends \User {
                public int $calls = 0;
                public function getFromDB($id)
                {
                    ++$this->calls;
                    return false;
                }
            };
            $this->boolean($custom->getTimelineAuthorFromDB($id))->isFalse();
            $this->integer($custom->calls)->isIdenticalTo(1);
            $connection = $database->getDoctrineConnection();
            $this->mockGenerator->orphanize('__construct');
            $routed = new \mock\DBmysql();
            $reads = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$reads) {
                ++$reads;
                return $connection;
            };
            $DB = $routed;
            $this->boolean($model->getTimelineAuthorFromDB($id))->isTrue();
            $this->array($model->fields)->isIdenticalTo($before);
            $this->integer($reads)->isGreaterThan(0);
        } finally {
            $DB = $database;
            $manager->clear();
        }
    }

    public function testDisplayOptionsUpdatePreservesSerializedEscapes(): void
    {
        global $DB;
        $this->login();
        $session = $_SESSION;
        $user = $this->createItem(\User::class, ['name' => 'display-roundtrip-' . $this->getUniqueString()]);
        $id = (int)$user->getID();
        $display = new class () extends \CommonGLPI {
            public static function getAvailableDisplayOptions()
            {
                return ['test' => ['show_default' => ['default' => true]]];
            }
        };
        $type = $display::getType();
        $manager = \itsmng\Database\Orm::create($DB);
        $repository = new \itsmng\Database\Repository\UserRepository($manager);
        $expected = ['show_default' => true, 'extra' => 'current "quoted" \\path /'];
        try {
            $_SESSION['glpiID'] = $id;
            $_SESSION['glpi_display_options'] = [$type => $expected];
            $_SESSION['glpi_display_options'][$type]['show_default'] = false;
            $display::updateDisplayOptions(['reset' => true]);
            $raw = json_encode([$type => $expected]);
            $this->variable($repository->displayOptions($id))->isIdenticalTo($raw);
            $this->array($_SESSION['glpi_display_options'][$type])->isIdenticalTo($expected);
            unset($_SESSION['glpi_display_options']);
            $this->array($display::getDisplayOptions())->isIdenticalTo($expected);
        } finally {
            $_SESSION = $session;
            $manager->clear();
        }
    }

    public function testDisplayOptionsReadCurrentScalarAtSessionBoundary(): void
    {
        global $DB;
        $this->login();
        $session = $_SESSION;
        $database = $DB;
        $user = $this->createItem(\User::class, ['name' => 'display-options-' . $this->getUniqueString()]);
        $id = (int)$user->getID();
        $display = new class () extends \CommonGLPI {
            public static function getAvailableDisplayOptions()
            {
                return ['test' => ['show_default' => ['default' => true]]];
            }
        };
        $type = $display::getType();
        $manager = \itsmng\Database\Orm::create($database);
        $repository = new \itsmng\Database\Repository\UserRepository($manager);
        $writeManager = \itsmng\Database\Orm::create($database);
        $writer = new \itsmng\Database\Repository\RecordWriter($writeManager);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
        $this->mockGenerator->orphanize('__construct');
        $routed = new \mock\DBmysql();
        $connection = $database->getDoctrineConnection();
        $reads = 0;
        $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$reads) {
            ++$reads;
            return $connection;
        };
        try {
            $_SESSION['glpiID'] = $id;
            $DB = $routed;
            foreach ([
                [json_encode([$type => ['show_default' => false, 'extra' => 'current "quoted" \\path /']]), ['show_default' => false, 'extra' => 'current "quoted" \\path /']],
                ['outside=>legacy', ['show_default' => true]],
                [null, ['show_default' => true]],
                ['', ['show_default' => true]],
            ] as [$raw, $expected]) {
                $this->array($writer->update('glpi_users', $id, ['display_options' => $raw]))->isIdenticalTo(['display_options']);
                $this->variable($repository->displayOptions($id))->isIdenticalTo($raw);
                unset($_SESSION['glpi_display_options']);
                $this->array($display::getDisplayOptions())->isIdenticalTo($expected);
                if ($raw === 'outside=>legacy') {
                    $this->string($_SESSION['glpi_display_options']['outside'])->isIdenticalTo('legacy');
                }
            }
            $this->integer($reads)->isGreaterThan(0);
            $raw = json_encode([$type => ['Child' => ['show_default' => false, 'extra' => 'nested']]]);
            $this->array($writer->update('glpi_users', $id, ['display_options' => $raw]))->isIdenticalTo(['display_options']);
            unset($_SESSION['glpi_display_options']);
            $this->array($display::getDisplayOptions('Child'))->isIdenticalTo(['show_default' => false, 'extra' => 'nested']);
            $before = $reads;
            $this->array($writer->update('glpi_users', $id, ['display_options' => null]))->isIdenticalTo(['display_options']);
            $this->array($display::getDisplayOptions('Child'))->isIdenticalTo(['show_default' => false, 'extra' => 'nested']);
            $this->integer($reads)->isIdenticalTo($before);
            unset($_SESSION['glpi_display_options']);
            $this->array($display::getDisplayOptions('Child'))->isIdenticalTo(['show_default' => true]);
            $this->variable($repository->displayOptions(PHP_INT_MAX))->isNull();
            $_SESSION['glpiID'] = PHP_INT_MAX;
            unset($_SESSION['glpi_display_options']);
            $this->array($display::getDisplayOptions())->isIdenticalTo(['show_default' => true]);
            $_SESSION['glpiID'] = 0;
            unset($_SESSION['glpi_display_options']);
            $before = $reads;
            $this->array($display::getDisplayOptions())->isIdenticalTo(['show_default' => true]);
            $this->integer($reads)->isIdenticalTo($before);
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
        } finally {
            $DB = $database;
            $_SESSION = $session;
            $manager->clear();
            $writeManager->clear();
        }
    }

    public function testAccessibilityHeaderReadsCurrentFontWithoutUserHydration(): void
    {
        global $DB;
        $this->login();
        $session = $_SESSION;
        $user = $this->createItem(\User::class, [
            'name' => 'accessibility-header-' . $this->getUniqueString(),
            'access_font' => 'OpenDyslexic',
        ]);
        $id = (int)$user->getID();
        $manager = \itsmng\Database\Orm::create($DB);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
        $repository = new \itsmng\Database\Repository\UserRepository($manager);
        try {
            $_SESSION['glpiID'] = $id;
            $_SESSION['glpiactiveprofile']['accessibility'] = READ;
            foreach ([
                'OpenDyslexic' => 'http://fonts.cdnfonts.com/css/opendyslexic',
                'OpenDyslexicAlta' => 'http://fonts.cdnfonts.com/css/opendyslexic?styles=29221',
                'Tiresias Infofont' => 'http://fonts.cdnfonts.com/css/tiresias-infofont',
            ] as $font => $url) {
                $this->boolean($DB->update('glpi_users', ['access_font' => $font], ['id' => $id]))->isTrue();
                $this->string($repository->accessibilityFont($id))->isIdenticalTo($font);
                $this->output(fn () => \Html::accessibilityHeader())
                    ->isIdenticalTo('<link href="' . $url . '" rel="stylesheet">');
            }
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            foreach (['unknown-font', null] as $font) {
                $this->boolean($DB->update('glpi_users', ['access_font' => $font], ['id' => $id]))->isTrue();
                $this->variable($repository->accessibilityFont($id))->isIdenticalTo($font);
                $this->output(fn () => \Html::accessibilityHeader())->isEmpty();
            }
            $this->boolean($DB->update('glpi_users', ['access_font' => 'OpenDyslexic'], ['id' => $id]))->isTrue();
            $_SESSION['glpiactiveprofile']['accessibility'] = 0;
            $this->output(fn () => \Html::accessibilityHeader())->isEmpty();
            $this->boolean($user->delete(['id' => $id], true))->isTrue();
            $this->variable($repository->accessibilityFont($id))->isNull();
            $_SESSION['glpiactiveprofile']['accessibility'] = READ;
            $this->output(fn () => \Html::accessibilityHeader())->isEmpty();
        } finally {
            $_SESSION = $session;
            $manager->clear();
        }
    }

    public function testLockMessageUsesCurrentProjectedUserWithoutReload(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $original = $DB;
        $session = $_SESSION;
        $configurationBefore = $CFG_GLPI;
        $originalLevel = $original->getDoctrineConnection()->getTransactionNestingLevel();
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public array $userReads = [];

            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $sql = str_replace(['`', '"'], '', $context['sql']);
                    if (preg_match('/\bFROM\s+glpi_users\b/i', $sql)) {
                        $this->userReads[] = $sql; // SQL shape only, never bound data.
                    }
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $parameters = $original->getDoctrineConnection()->getParams();
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($parameters, $configuration)
            : \itsmng\Database\MySQLConnection::create($parameters, $configuration);
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $frame = null;
        $primary = null;
        try {
            $DB = $probe;
            $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $manager = \itsmng\Database\Orm::create($probe);
            $root = $manager->getReference(\itsmng\Database\Entity\Entity::class, 0);
            $locker = new \itsmng\Database\Entity\User();
            $locker->entities = $root;
            $locker->name = 'lock-display-' . bin2hex(random_bytes(6));
            $locker->firstname = 'Ada';
            $locker->realname = 'Lovelace';
            $locker->authtype = \Auth::DB_GLPI;
            $manager->persist($locker);
            $email = new \itsmng\Database\Entity\UserEmail();
            $email->users = $locker;
            $email->email = 'locker@example.test';
            $email->is_default = true;
            $manager->persist($email);
            $computer = new \itsmng\Database\Entity\Computer();
            $computer->entities = $root;
            $computer->name = 'Locked user display fixture';
            $manager->persist($computer);
            $lock = new \itsmng\Database\Entity\ObjectLock();
            $lock->itemtype = 'Computer';
            $lock->subjectComputer = $computer;
            $lock->users = $locker;
            $manager->persist($lock);
            $manager->flush();
            $manager->clear();

            $_SESSION['glpinames_format'] = \User::FIRSTNAME_BEFORE;
            $_SESSION['glpiis_ids_visible'] = 0;
            $_SESSION['glpilock_autolock_mode'] = 1;
            $CFG_GLPI['lock_use_lock_item'] = 1;
            $CFG_GLPI['lock_lockprofile_id'] = $_SESSION['glpiactiveprofile']['id'];
            $CFG_GLPI['lock_lockprofile'] = $_SESSION['glpiactiveprofile'];
            $CFG_GLPI['lock_item_list'] = ['Computer'];
            $activeSession = $_SESSION;
            $this->boolean(\Session::haveRightsOr('computer', [UPDATE, DELETE, PURGE, UPDATENOTE]))->isTrue();
            foreach ([['Ada', true, true], ['Grace', false, true], ['Grace', true, false]] as [$firstname, $mailing, $hasEmail]) {
                $_SESSION = $activeSession;
                $CFG_GLPI['notifications_mailing'] = (int)$mailing;
                $connection->update('glpi_users', ['firstname' => $firstname], ['id' => $locker->id]);
                if (!$hasEmail) {
                    $connection->delete('glpi_useremails', ['id' => $email->id]);
                }
                $logger->userReads = [];
                $options = ['id' => $computer->id];
                ob_start();
                try {
                    \ObjectLock::manageObjectLock('Computer', $options);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                    \ObjectLock::revertProfile();
                }
                $this->integer($options['locked'])->isIdenticalTo(1);
                $this->string($html)->contains($firstname . ' Lovelace')
                    ->contains("href='" . \User::getFormURLWithID($locker->id) . "'");
                $this->boolean(str_contains($html, 'function askUnlock()'))->isIdenticalTo($mailing && $hasEmail);
                $this->boolean(str_contains($html, 'locker@example.test'))->isIdenticalTo($hasEmail);
                $this->array($logger->userReads)->hasSize(1);
                $this->string($logger->userReads[0])->notContains('password');
                $frame->assertActive();
                $this->object($DB)->isIdenticalTo($probe);
            }
        } catch (\Throwable $error) {
            $primary = $error;
        } finally {
            $DB = $original;
            $_SESSION = $session;
            $CFG_GLPI = $configurationBefore;
            try {
                if ($frame !== null) {
                    $frame->rollBack();
                }
            } catch (\Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new \itsmng\Database\MutationRollbackFailure($primary, $cleanup);
            }
            try {
                $probe->close();
            } catch (\Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->integer($original->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
    }

    public function testGenerateUserToken()
    {
        $user = getItemByTypeName('User', TU_USER);
        $this->variable($user->fields['personal_token_date'])->isNull();
        $this->variable($user->fields['personal_token'])->isNull();

        $token = $user->getAuthToken();
        $this->string($token)->isNotEmpty();

        $user->getFromDB($user->getID());
        $this->string($user->fields['personal_token'])->isIdenticalTo($token);
        $this->string($user->fields['personal_token_date'])->isIdenticalTo($_SESSION['glpi_currenttime']);
    }

    /**
     *
     */
    public function testLostPassword()
    {
        // would not be logical to login here
        $_SESSION['glpicronuserrunning'] = "cron_phpunit";
        $user = getItemByTypeName('User', TU_USER);

        // Test request for a password with invalid email
        $this->when(
            function () use ($user) {
                $user->forgetPassword('this-email-does-not-exists@example.com');
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage("Failed to find a single user for 'this-email-does-not-exists@example.com', 0 user(s) found.")
           ->exists();

        // Test request for a password
        $result = $user->forgetPassword($user->getDefaultEmail());
        $this->boolean($result)->isTrue();

        // Test reset password with a bad token
        $token = $user->getField('password_forget_token');
        $input = [
           'password_forget_token' => $token . 'bad',
           'password'  => TU_PASS,
           'password2' => TU_PASS
        ];
        $this->exception(
            function () use ($user, $input) {
                $result = $user->updateForgottenPassword($input);
            }
        )
        ->isInstanceOf(\Glpi\Exception\ForgetPasswordException::class);

        // Test reset password with good token
        // 1 - Refresh the in-memory instance of user and get the current password
        $user->getFromDB($user->getID());

        // 2 - Set a new password
        $input = [
           'password_forget_token' => $token,
           'password'  => 'NewPassword',
           'password2' => 'NewPassword'
        ];

        // 3 - check the update succeeds
        $result = $user->updateForgottenPassword($input);
        $this->boolean($result)->isTrue();
        $newHash = $user->getField('password');

        // 4 - Restore the initial password in the DB before checking the updated password
        // This ensure the original password is restored even if the next test fails
        $updateSuccess = $user->update([
           'id'        => $user->getID(),
           'password'  => TU_PASS,
           'password2' => TU_PASS
        ]);
        $this->variable($updateSuccess)->isNotFalse('password update failed');

        // Test the new password was saved
        $this->variable(\Auth::checkPassword('NewPassword', $newHash))->isNotFalse();
    }

    public function testGetDefaultEmail()
    {
        $user = new \User();

        $this->string($user->getDefaultEmail())->isIdenticalTo('');
        $this->array($user->getAllEmails())->isIdenticalTo([]);
        $this->boolean($user->isEmail('one@test.com'))->isFalse();

        $uid = (int)$user->add([
           'name'   => 'test_email',
           '_useremails'  => [
              'one@test.com'
           ]
        ]);
        $this->integer($uid)->isGreaterThan(0);
        $this->boolean($user->getFromDB($user->fields['id']))->isTrue();
        $this->string($user->getDefaultEmail())->isIdenticalTo('one@test.com');

        $this->boolean(
            $user->update([
              'id'              => $uid,
              '_useremails'     => ['two@test.com'],
              '_default_email'  => 0
         ])
        )->isTrue();

        $this->boolean($user->getFromDB($user->fields['id']))->isTrue();
        $this->string($user->getDefaultEmail())->isIdenticalTo('two@test.com');

        $this->array($user->getAllEmails())->hasSize(2);
        $this->boolean($user->isEmail('one@test.com'))->isTrue();

        $tu_user = getItemByTypeName('User', TU_USER);
        $this->boolean($user->isEmail($tu_user->getDefaultEmail()))->isFalse();
    }

    public function testGetFromDBbyToken()
    {
        $user = $this->newTestedInstance;
        $uid = (int)$user->add([
           'name'   => 'test_token'
        ]);
        $this->integer($uid)->isGreaterThan(0);
        $this->boolean($user->getFromDB($uid))->isTrue();

        $token = $user->getToken($uid);
        $this->boolean($user->getFromDB($uid))->isTrue();
        $this->string($token)->hasLength(40);

        $user2 = new \User();
        $this->boolean($user2->getFromDBbyToken($token))->isTrue();
        $this->array($user2->fields)->isIdenticalTo($user->fields);

        $self = $this;
        $this->when(
            function () use ($self, $uid) {
                $self->boolean($self->testedInstance->getFromDBbyToken($uid, 'my_field'))->isFalse();
            }
        )->error
           ->withType(E_USER_WARNING)
           ->withMessage('Unexpected token value received: "string" expected, received "integer".')
              ->exists();
    }

    public function testPrepareInputForAdd()
    {
        $this->login();
        $user = $this->newTestedInstance();

        $input = [
           'name'   => 'prepare_for_add'
        ];
        $expected = [
           'name'         => 'prepare_for_add',
           'authtype'     => 1,
           'auths_id'     => 0,
           'is_active'    => 1,
           'is_deleted'   => 0,
           'entities_id'  => 0,
           'profiles_id'  => 0
        ];

        $this->array($user->prepareInputForAdd($input))->isIdenticalTo($expected);

        $input['_stop_import'] = 1;
        $this->boolean($user->prepareInputForAdd($input))->isFalse();

        $input = ['name' => 'invalid+login'];
        $this->boolean($user->prepareInputForAdd($input))->isFalse();
        $this->hasSessionMessages(ERROR, ['The login is not valid. Unable to add the user.']);

        //add same user twice
        $input = ['name' => 'new_user'];
        $this->integer($user->add($input))->isGreaterThan(0);
        $this->boolean($user->add($input))->isFalse(0);
        $this->hasSessionMessages(ERROR, ['Unable to add. The user already exists.']);

        $input = [
           'name'      => 'user_pass',
           'password'  => 'password',
           'password2' => 'nomatch'
        ];
        $this->boolean($user->prepareInputForAdd($input))->isFalse();
        $this->hasSessionMessages(ERROR, ['Error: the two passwords do not match']);

        $input = [
           'name'      => 'user_pass',
           'password'  => '',
           'password2' => 'nomatch'
        ];
        $expected = [
           'name'         => 'user_pass',
           'password2'    => 'nomatch',
           'authtype'     => 1,
           'auths_id'     => 0,
           'is_active'    => 1,
           'is_deleted'   => 0,
           'entities_id'  => 0,
           'profiles_id'  => 0
        ];
        $this->array($user->prepareInputForAdd($input))->isIdenticalTo($expected);

        $input['password'] = 'nomatch';
        $expected['password'] = 'unknonwn';
        unset($expected['password2']);
        $prepared = $user->prepareInputForAdd($input);
        $this->array($prepared)
           ->hasKeys(array_keys($expected))
           ->string['password']->hasLength(60)->startWith('$2y$');

        $input['password'] = 'mypass';
        $input['password2'] = 'mypass';
        $input['_extauth'] = 1;
        $expected = [
           'name'                 => 'user_pass',
           'password'             => '',
           '_extauth'             => 1,
           'authtype'             => 1,
           'auths_id'             => 0,
           'password_last_update' => $_SESSION['glpi_currenttime'],
           'is_active'            => 1,
           'is_deleted'           => 0,
           'entities_id'          => 0,
           'profiles_id'          => 0,
        ];
        $this->array($user->prepareInputForAdd($input))->isIdenticalTo($expected);
    }

    public function testCanonicalAuthenticationCreation()
    {
        $this->login();
        $ldap = new \AuthLDAP();
        $ldapId = (int)$ldap->add(['name' => 'Canonical input directory', 'is_active' => 0, 'is_default' => 0]);
        $otherLdap = new \AuthLDAP();
        $otherId = (int)$otherLdap->add(['name' => 'Other canonical input directory', 'is_active' => 0, 'is_default' => 0]);
        $mail = new \AuthMail();
        $mailId = (int)$mail->add(['name' => 'Canonical input mail server']);
        $this->integer($ldapId)->isGreaterThan(0);
        $this->integer($otherId)->isGreaterThan(0)->isNotEqualTo($ldapId);
        $this->integer($mailId)->isGreaterThan(0);

        foreach ([[], ['auths_id' => null], ['auths_id' => 0]] as $legacyDefault) {
            $prepared = (new \User())->prepareInputForAdd(['name' => 'legacy-authentication-default'] + $legacyDefault);
            $this->integer($prepared['auths_id'])->isIdenticalTo(0);
            $this->integer($prepared['authtype'])->isIdenticalTo(\Auth::DB_GLPI);
        }

        // A fallback account must not be mistaken for a real selected owner.
        // The same login is valid for distinct authentication identities.
        $login = 'canonical-authentication-input';
        foreach ([
            [\Auth::LDAP, 'authldaps_id', null, 0],
            [\Auth::LDAP, 'authldaps_id', $ldapId, $ldapId],
            [\Auth::LDAP, 'authldaps_id', $otherId, $otherId],
            [\Auth::MAIL, 'authmails_id', $mailId, $mailId],
            [\Auth::DB_GLPI, 'auth_source_code', -5, -5],
        ] as [$type, $column, $canonical, $selection]) {
            $input = ['name' => $login, 'authtype' => $type, $column => $canonical];
            $user = new \User();
            $prepared = $user->prepareInputForAdd($input);
            $this->array($prepared)->notHasKey('auths_id');
            $this->variable($prepared[$column])->isIdenticalTo($canonical);
            $id = (int)$user->add($input);
            $this->integer($id)->isGreaterThan(0);
            $this->boolean($user->getFromDB($id))->isTrue();
            $before = $user->fields;
            $this->integer($before['auths_id'])->isIdenticalTo($selection);
            $this->variable($before[$column])->isIdenticalTo($canonical);

            $this->boolean((new \User())->add($input))->isFalse();
            $this->hasSessionMessages(ERROR, ['Unable to add. The user already exists.']);
            $this->boolean((new \User())->add([
                'name' => $login, 'authtype' => $type, 'auths_id' => $selection,
            ]))->isFalse();
            $this->hasSessionMessages(ERROR, ['Unable to add. The user already exists.']);
            $this->boolean($user->getFromDB($id))->isTrue();
            $this->array($user->fields)->isIdenticalTo($before);
        }

        foreach ([
            ['authldaps_id' => $ldapId, 'auths_id' => $otherId],
            ['authmails_id' => $mailId],
        ] as $conflict) {
            $input = ['name' => 'rejected-canonical-authentication-input', 'authtype' => \Auth::LDAP] + $conflict;
            $this->exception(static function () use ($input) {
                (new \User())->add($input);
            })->isInstanceOf(\InvalidArgumentException::class);
            $this->boolean((new \User())->getFromDBbyName($input['name']))->isFalse();
        }
    }

    protected function prepareInputForTimezoneUpdateProvider()
    {
        return [
           [
              'input'     => [
                 'timezone' => 'Europe/Paris',
              ],
              'expected'  => [
                 'timezone' => 'Europe/Paris',
              ],
           ],
           [
              'input'     => [
                 'timezone' => '0',
              ],
              'expected'  => [
                 'timezone' => 'NULL',
              ],
           ],
           // check that timezone is not reset unexpectedly
           [
              'input'     => [
                 'registration_number' => 'no.1',
              ],
              'expected'  => [
                 'registration_number' => 'no.1',
              ],
           ],
        ];
    }

    /**
     * @dataProvider prepareInputForTimezoneUpdateProvider
     */
    public function testPrepareInputForUpdateTimezone(array $input, $expected)
    {
        $this->login();
        $user = $this->newTestedInstance();
        $username = 'prepare_for_update_' . mt_rand();
        $user_id = $user->add(
            [
              'name'         => $username,
              'password'     => 'mypass',
              'password2'    => 'mypass',
              '_profiles_id' => 1
         ]
        );
        $this->integer((int)$user_id)->isGreaterThan(0);

        $this->login($username, 'mypass');

        $input = ['id' => $user_id] + $input;
        $result = $user->prepareInputForUpdate($input);

        $expected = ['id' => $user_id] + $expected;
        $this->array($result)->isIdenticalTo($expected);
    }

    protected function prepareInputForUpdatePasswordProvider()
    {
        return [
           [
              'input'     => [
                 'password'  => 'initial_pass',
                 'password2' => 'initial_pass'
              ],
              'expected'  => [
              ],
           ],
           [
              'input'     => [
                 'password'  => 'new_pass',
                 'password2' => 'new_pass_not_match'
              ],
              'expected'  => false,
              'messages'  => [ERROR => ['Error: the two passwords do not match']],
           ],
           [
              'input'     => [
                 'password'  => 'new_pass',
                 'password2' => 'new_pass'
              ],
              'expected'  => [
                 'password_last_update' => true,
                 'password' => true,
              ],
           ],
        ];
    }

    /**
     * @dataProvider prepareInputForUpdatePasswordProvider
     */
    public function testPrepareInputForUpdatePassword(array $input, $expected, array $messages = null)
    {
        $this->login();
        $user = $this->newTestedInstance();
        $username = 'prepare_for_update_' . mt_rand();
        $user_id = $user->add(
            [
              'name'         => $username,
              'password'     => 'initial_pass',
              'password2'    => 'initial_pass',
              '_profiles_id' => 1
         ]
        );
        $this->integer((int)$user_id)->isGreaterThan(0);

        $this->login($username, 'initial_pass');

        $input = ['id' => $user_id] + $input;
        $result = $user->prepareInputForUpdate($input);

        if (null !== $messages) {
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'])->isIdenticalTo($messages);
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = []; //reset
        }

        if (false === $expected) {
            $this->boolean($result)->isIdenticalTo($expected);
            return;
        }

        if (array_key_exists('password', $expected) && true === $expected['password']) {
            // password_hash result is unpredictible, so we cannot test its exact value
            $this->array($result)->hasKey('password');
            $this->string($result['password'])->isNotEmpty();

            unset($expected['password']);
            unset($result['password']);
        }

        $expected = ['id' => $user_id] + $expected;
        if (array_key_exists('password_last_update', $expected) && true === $expected['password_last_update']) {
            // $_SESSION['glpi_currenttime'] was reset on login, value cannot be provided by test provider
            $expected['password_last_update'] = $_SESSION['glpi_currenttime'];
        }

        $this->array($result)->isIdenticalTo($expected);
    }

    public function testPost_addItem()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $eid = getItemByTypeName('Entity', '_test_root_entity', true);

        $user = $this->newTestedInstance;

        //user with a profile
        $pid = getItemByTypeName('Profile', 'Technician', true);
        $uid = (int)$user->add([
           'name'         => 'create_user',
           '_profiles_id' => $pid
        ]);
        $this->integer($uid)->isGreaterThan(0);

        $this->boolean($user->getFromDB($uid))->isTrue();
        $this->array($user->fields)
           ->string['name']->isIdenticalTo('create_user');
        $this->variable($user->fields['profiles_id'])->isNull();

        $puser = new \Profile_User();
        $this->boolean($puser->getFromDBByCrit(['users_id' => $uid]))->isTrue();
        $this->array($puser->fields)
           ->integer['profiles_id']->isEqualTo($pid)
           ->integer['entities_id']->isEqualTo($eid)
           ->integer['is_recursive']->isEqualTo(0)
           ->integer['is_dynamic']->isEqualTo(0);

        $pid = (int)\Profile::getDefault();
        $this->integer($pid)->isGreaterThan(0);

        //user without a profile (will take default one)
        $uid2 = (int)$user->add([
           'name' => 'create_user2',
        ]);
        $this->integer($uid2)->isGreaterThan(0);

        $this->boolean($user->getFromDB($uid2))->isTrue();
        $this->array($user->fields)
           ->string['name']->isIdenticalTo('create_user2');
        $this->variable($user->fields['profiles_id'])->isNull();

        $puser = new \Profile_User();
        $this->boolean($puser->getFromDBByCrit(['users_id' => $uid2]))->isTrue();
        $this->array($puser->fields)
           ->integer['profiles_id']->isEqualTo($pid)
           ->integer['entities_id']->isEqualTo($eid)
           ->integer['is_recursive']->isEqualTo(0)
           ->integer['is_dynamic']->isEqualTo(1);

        //user with entity not recursive
        $eid2 = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $this->integer($eid2)->isGreaterThan(0);
        $uid3 = (int)$user->add([
           'name'         => 'create_user3',
           '_entities_id' => $eid2
        ]);
        $this->integer($uid3)->isGreaterThan(0);

        $this->boolean($user->getFromDB($uid3))->isTrue();
        $this->array($user->fields)
           ->string['name']->isIdenticalTo('create_user3');

        $puser = new \Profile_User();
        $this->boolean($puser->getFromDBByCrit(['users_id' => $uid3]))->isTrue();
        $this->array($puser->fields)
           ->integer['profiles_id']->isEqualTo($pid)
           ->integer['entities_id']->isEqualTo($eid2)
           ->integer['is_recursive']->isEqualTo(0)
           ->integer['is_dynamic']->isEqualTo(1);

        //user with entity recursive
        $uid4 = (int)$user->add([
           'name'            => 'create_user4',
           '_entities_id'    => $eid2,
           '_is_recursive'   => 1
        ]);
        $this->integer($uid4)->isGreaterThan(0);

        $this->boolean($user->getFromDB($uid4))->isTrue();
        $this->array($user->fields)
           ->string['name']->isIdenticalTo('create_user4');

        $puser = new \Profile_User();
        $this->boolean($puser->getFromDBByCrit(['users_id' => $uid4]))->isTrue();
        $this->array($puser->fields)
           ->integer['profiles_id']->isEqualTo($pid)
           ->integer['entities_id']->isEqualTo($eid2)
           ->integer['is_recursive']->isEqualTo(1)
           ->integer['is_dynamic']->isEqualTo(1);

    }

    public function testClone()
    {
        $this->login();

        $user = getItemByTypeName('User', TU_USER);

        $this->setEntity('_test_root_entity', true);

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        // Test item cloning
        $added = $user->clone();
        $this->integer((int)$added)->isGreaterThan(0);

        $clonedUser = new \User();
        $this->boolean($clonedUser->getFromDB($added))->isTrue();

        $fields = $user->fields;

        // Check the values. Id and dates must be different, everything else must be equal
        foreach ($fields as $k => $v) {
            switch ($k) {
                case 'id':
                    $this->variable($clonedUser->getField($k))->isNotEqualTo($user->getField($k));
                    break;
                case 'name':
                    $this->variable($clonedUser->getField($k))->isEqualTo("_test_user-copy");
                    break;
                case 'date_mod':
                case 'date_creation':
                    $dateClone = new \DateTime($clonedUser->getField($k));
                    $expectedDate = new \DateTime($date);
                    $this->dateTime($dateClone)->isEqualTo($expectedDate);
                    break;
                default:
                    $this->variable($clonedUser->getField($k))->isEqualTo($user->getField($k));
            }
        }
    }

    public function testCloneCopiesProfilesAndGroups()
    {
        global $DB;

        $this->login();
        $this->setEntity('_test_root_entity', true);

        $user = new class () extends \User {
            public static array $cloneTargets = [];
            public static function getType()
            {
                return 'User';
            }
            public static function getTable($classname = null)
            {
                return 'glpi_users';
            }
            public function post_clone($source, $history)
            {
                self::$cloneTargets[] = (int)$this->getID();
                // Record a refused dispatch without writing to a nonexistent user.
                if ((int)$this->getID() > 0) {
                    parent::post_clone($source, $history);
                }
            }
        };
        $this->boolean($user->getFromDB(getItemByTypeName('User', TU_USER, true)))->isTrue();
        $users_id = $user->getID();
        $entities_id = (int)getItemByTypeName('Entity', '_test_child_1', true);

        $profile_user = new \Profile_User();
        $profile_users_id = $profile_user->add([
           'users_id'           => $users_id,
           'profiles_id'        => 3,
           'entities_id'        => $entities_id,
           'is_recursive'       => 0,
           'is_dynamic'         => 1,
           'is_default_profile' => 0,
        ]);
        $this->integer($profile_users_id)->isGreaterThan(0);

        $group = new \Group();
        $groups_id = $group->add([
           'name'         => 'Group copied with user',
           'entities_id'  => $entities_id,
           'is_recursive' => 0,
        ]);
        $this->integer($groups_id)->isGreaterThan(0);

        $group_user = new \Group_User();
        $group_users_id = $group_user->add([
           'users_id'        => $users_id,
           'groups_id'       => $groups_id,
           'is_dynamic'      => 1,
           'is_manager'      => 1,
           'is_userdelegate' => 1,
        ]);
        $this->integer($group_users_id)->isGreaterThan(0);

        $get_relations = static function ($table, $users_id, array $fields) use ($DB) {
            $relations = [];
            foreach ($DB->request([
               'SELECT' => $fields,
               'FROM'   => $table,
               'WHERE'  => ['users_id' => $users_id],
            ]) as $relation) {
                $relations[] = $relation;
            }
            usort(
                $relations,
                static fn ($left, $right) => strcmp(json_encode($left), json_encode($right))
            );
            return $relations;
        };

        $profile_fields = [
           'profiles_id',
           'entities_id',
           'is_recursive',
           'is_dynamic',
           'is_default_profile',
        ];
        $group_fields = [
           'groups_id',
           'is_dynamic',
           'is_manager',
           'is_userdelegate',
        ];
        $source_profiles = $get_relations(\Profile_User::getTable(), $users_id, $profile_fields);
        $source_groups = $get_relations(\Group_User::getTable(), $users_id, $group_fields);

        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $scope = $connection->captureManagedTransactionScope();
        $counts = [];
        foreach (['glpi_users', 'glpi_profiles_users', 'glpi_groups_users'] as $table) {
            $counts[$table] = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        }
        $this->boolean($user->clone(['password' => 'Refused clone password', 'password2' => 'Different confirmation']))->isFalse();
        $this->hasSessionMessages(ERROR, [__('Error: the two passwords do not match')]);
        foreach ($counts as $table => $count) {
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table))->isIdenticalTo($count);
        }
        $this->array($get_relations(\Profile_User::getTable(), $users_id, $profile_fields))->isIdenticalTo($source_profiles);
        $this->array($get_relations(\Group_User::getTable(), $users_id, $group_fields))->isIdenticalTo($source_groups);
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        $scope->assertActive();
        $this->array($user::$cloneTargets)->isEmpty('Refused User creation must not dispatch dependent clone hooks');

        $cloned_users_id = $user->clone();
        $this->array($user::$cloneTargets)->isIdenticalTo([(int)$cloned_users_id]);
        $this->integer($cloned_users_id)->isGreaterThan($users_id);

        $this->array(
            $get_relations(\Profile_User::getTable(), $cloned_users_id, $profile_fields)
        )->isIdenticalTo($source_profiles);
        $this->array(
            $get_relations(\Group_User::getTable(), $cloned_users_id, $group_fields)
        )->isIdenticalTo($source_groups);
        $this->array($get_relations(\Profile_User::getTable(), $users_id, $profile_fields))->isIdenticalTo($source_profiles);
        $this->array($get_relations(\Group_User::getTable(), $users_id, $group_fields))->isIdenticalTo($source_groups);
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        $scope->assertActive();
    }

    public function testGetFromDBbyDn()
    {
        $user = $this->newTestedInstance;
        $dn = 'user=user_with_dn,dc=test,dc=glpi-project,dc=org';

        $uid = (int)$user->add([
           'name'      => 'user_with_dn',
           'user_dn'   => $dn
        ]);
        $this->integer($uid)->isGreaterThan(0);

        $this->boolean($user->getFromDBbyDn($dn))->isTrue();
        $this->array($user->fields)
           ->integer['id']->isIdenticalTo($uid)
           ->string['name']->isIdenticalTo('user_with_dn');
    }

    public function testGetFromDBbySyncField()
    {
        $user = $this->newTestedInstance;
        $sync_field = 'abc-def-ghi';

        $uid = (int)$user->add([
           'name'         => 'user_with_syncfield',
           'sync_field'   => $sync_field
        ]);

        $this->integer($uid)->isGreaterThan(0);

        $this->boolean($user->getFromDBbySyncField($sync_field))->isTrue();
        $this->array($user->fields)
           ->integer['id']->isIdenticalTo($uid)
           ->string['name']->isIdenticalTo('user_with_syncfield');
    }

    public function testGetFromDBbyName()
    {
        $user = $this->newTestedInstance;
        $name = 'user_with_name';

        $uid = (int)$user->add([
           'name' => $name
        ]);

        $this->integer($uid)->isGreaterThan(0);

        $this->boolean($user->getFromDBbyName($name))->isTrue();
        $this->array($user->fields)
           ->integer['id']->isIdenticalTo($uid);
    }

    public function testGetFromDBbyNameAndAuth()
    {
        $user = $this->newTestedInstance;
        $name = 'user_with_auth';

        $uid = (int)$user->add([
           'name'      => $name,
           'authtype'  => \Auth::DB_GLPI,
           'auths_id'  => 12
        ]);

        $this->integer($uid)->isGreaterThan(0);

        $this->boolean($user->getFromDBbyNameAndAuth($name, \Auth::DB_GLPI, 12))->isTrue();
        $this->array($user->fields)
           ->integer['id']->isIdenticalTo($uid)
           ->string['name']->isIdenticalTo($name);
    }

    protected function rawNameProvider()
    {
        return [
           [
              'input'     => ['name' => 'myname'],
              'rawname'   => 'myname'
           ], [
              'input'     => [
                 'name'      => 'anothername',
                 'realname'  => 'real name'
              ],
              'rawname'      => 'real name'
           ], [
              'input'     => [
                 'name'      => 'yet another name',
                 'firstname' => 'first name'
              ],
              'rawname'   => 'yet another name'
           ], [
              'input'     => [
                 'name'      => 'yet another one',
                 'realname'  => 'real name',
                 'firstname' => 'first name'
              ],
              'rawname'   => 'real name first name'
           ]
        ];
    }

    /**
     * @dataProvider rawNameProvider
     */
    public function testGetFriendlyName($input, $rawname)
    {
        $user = $this->newTestedInstance;

        $this->string($user->getFriendlyName())->isIdenticalTo('');

        $this
           ->given($this->newTestedInstance)
              ->then
                 ->integer($uid = (int)$this->testedInstance->add($input))
                    ->isGreaterThan(0)
                 ->boolean($this->testedInstance->getFromDB($uid))->isTrue()
                 ->string($this->testedInstance->getFriendlyName())->isIdenticalTo($rawname);
    }

    public function testBlankPassword()
    {
        $input = [
           'name'      => 'myname',
           'password'  => 'mypass',
           'password2' => 'mypass'
        ];
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->integer($uid = (int)$this->testedInstance->add($input))
                    ->isGreaterThan(0)
                 ->boolean($this->testedInstance->getFromDB($uid))->isTrue()
                 ->array($this->testedInstance->fields)
                    ->string['name']->isIdenticalTo('myname')
                    ->string['password']->hasLength(60)->startWith('$2y$')
           ->given($this->testedInstance->blankPassword())
              ->then
                 ->boolean($this->testedInstance->getFromDB($uid))->isTrue()
                 ->array($this->testedInstance->fields)
                    ->string['name']->isIdenticalTo('myname')
                    ->string['password']->isIdenticalTo('');
    }

    public function testPre_updateInDB()
    {
        $this->login();
        $user = $this->newTestedInstance();

        $uid = (int)$user->add([
           'name' => 'preupdate_user'
        ]);
        $this->integer($uid)->isGreaterThan(0);
        $this->boolean($user->getFromDB($uid))->isTrue();

        $this->boolean($user->update([
           'id'     => $uid,
           'name'   => 'preupdate_user_edited'
        ]))->isTrue();
        $this->hasNoSessionMessages([ERROR, WARNING]);

        //can update with same name when id is identical
        $this->boolean($user->update([
           'id'     => $uid,
           'name'   => 'preupdate_user_edited'
        ]))->isTrue();
        $this->hasNoSessionMessages([ERROR, WARNING]);

        $this->integer(
            (int)$user->add(['name' => 'do_exist'])
        )->isGreaterThan(0);
        $this->boolean($user->update([
           'id'     => $uid,
           'name'   => 'do_exist'
        ]))->isTrue();
        $this->hasSessionMessages(ERROR, ['Unable to update login. A user already exists.']);

        $this->boolean($user->getFromDB($uid))->isTrue();
        $this->string($user->fields['name'])->isIdenticalTo('preupdate_user_edited');

        $this->boolean($user->update([
           'id'     => $uid,
           'name'   => 'in+valid'
        ]))->isTrue();
        $this->hasSessionMessages(ERROR, ['The login is not valid. Unable to update login.']);
    }

    public function testGetIdByName()
    {
        $user = $this->newTestedInstance;

        $uid = (int)$user->add(['name' => 'id_by_name']);
        $this->integer($uid)->isGreaterThan(0);

        $this->integer($user->getIdByName('id_by_name'))->isIdenticalTo($uid);
    }

    public function testGetIdByField()
    {
        $user = $this->newTestedInstance;

        $uid = (int)$user->add([
           'name'   => 'id_by_field',
           'phone'  => '+33123456789'
        ]);
        $this->integer($uid)->isGreaterThan(0);

        $this->integer($user->getIdByField('phone', '+33123456789'))->isIdenticalTo($uid);

        $this->integer(
            $user->add([
              'name'   => 'id_by_field2',
              'phone'  => '+33123456789'
         ])
        )->isGreaterThan(0);
        $this->boolean($user->getIdByField('phone', '+33123456789'))->isFalse();

        $this->boolean($user->getIdByField('phone', 'donotexists'))->isFalse();
    }

    public function testgetAdditionalMenuOptions()
    {
        $this->Login();
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->getAdditionalMenuOptions())
                    ->hasSize(1)
                    ->hasKey('ldap');

        $this->Login('normal', 'normal');
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->boolean($this->testedInstance->getAdditionalMenuOptions())
                    ->isFalse();
    }

    protected function passwordExpirationMethodsProvider()
    {
        $time = time();

        return [
           [
              'last_update'                     => date('Y-m-d H:i:s', strtotime('-10 years', $time)),
              'expiration_delay'                => -1,
              'expiration_notice'               => -1,
              'expected_expiration_time'        => null,
              'expected_should_change_password' => false,
              'expected_has_password_expire'    => false,
           ],
           [
              'last_update'                     => date('Y-m-d H:i:s', strtotime('-10 days', $time)),
              'expiration_delay'                => 15,
              'expiration_notice'               => -1,
              'expected_expiration_time'        => strtotime('+5 days', $time),
              'expected_should_change_password' => false, // not yet in notice time
              'expected_has_password_expire'    => false,
           ],
           [
              'last_update'                     => date('Y-m-d H:i:s', strtotime('-10 days', $time)),
              'expiration_delay'                => 15,
              'expiration_notice'               => 10,
              'expected_expiration_time'        => strtotime('+5 days', $time),
              'expected_should_change_password' => true,
              'expected_has_password_expire'    => false,
           ],
           [
              'last_update'                     => date('Y-m-d H:i:s', strtotime('-20 days', $time)),
              'expiration_delay'                => 15,
              'expiration_notice'               => -1,
              'expected_expiration_time'        => strtotime('-5 days', $time),
              'expected_should_change_password' => true,
              'expected_has_password_expire'    => true,
           ],
        ];
    }

    /**
     * @dataProvider passwordExpirationMethodsProvider
     */
    public function testPasswordExpirationMethods(
        string $last_update,
        int $expiration_delay,
        int $expiration_notice,
        $expected_expiration_time,
        $expected_should_change_password,
        $expected_has_password_expire
    ) {
        global $CFG_GLPI;

        $user = $this->newTestedInstance();
        $username = 'prepare_for_update_' . mt_rand();
        $user_id = $user->add(
            [
              'name'      => $username,
              'password'  => 'pass',
              'password2' => 'pass'
         ]
        );
        $this->integer($user_id)->isGreaterThan(0);
        $this->boolean($user->update(['id' => $user_id, 'password_last_update' => $last_update]))->isTrue();
        $this->boolean($user->getFromDB($user->fields['id']))->isTrue();

        $cfg_backup = $CFG_GLPI;
        $CFG_GLPI['password_expiration_delay'] = $expiration_delay;
        $CFG_GLPI['password_expiration_notice'] = $expiration_notice;

        $expiration_time = $user->getPasswordExpirationTime();
        $should_change_password = $user->shouldChangePassword();
        $has_password_expire = $user->hasPasswordExpired();

        $CFG_GLPI = $cfg_backup;

        $this->variable($expiration_time)->isEqualTo($expected_expiration_time);
        $this->boolean($should_change_password)->isEqualTo($expected_should_change_password);
        $this->boolean($has_password_expire)->isEqualTo($expected_has_password_expire);
    }


    protected function cronPasswordExpirationNotificationsProvider()
    {
        // create 10 users with differents password_last_update dates
        // first has its password set 1 day ago
        // second has its password set 11 day ago
        // and so on
        // tenth has its password set 91 day ago
        $user = new \User();
        for ($i = 1; $i < 100; $i += 10) {
            $user_id = $user->add(
                [
                  'name'     => 'cron_user_' . mt_rand(),
                  'authtype' => \Auth::DB_GLPI,
            ]
            );
            $this->integer($user_id)->isGreaterThan(0);
            $this->boolean(
                $user->update(
                    [
                     'id' => $user_id,
                     'password_last_update' => date('Y-m-d H:i:s', strtotime('-' . $i . ' days')),
               ]
                )
            )->isTrue();
        }

        return [
           // validate that cron does nothing if password expiration is not active (default config)
           [
              'expiration_delay'               => -1,
              'notice_delay'                   => -1,
              'lock_delay'                     => -1,
              'cron_limit'                     => 100,
              'expected_result'                => 0, // 0 = nothing to do
              'expected_notifications_count'   => 0,
              'expected_lock_count'            => 0,
           ],
           // validate that cron send no notification if password_expiration_notice == -1
           [
              'expiration_delay'               => 15,
              'notice_delay'                   => -1,
              'lock_delay'                     => -1,
              'cron_limit'                     => 100,
              'expected_result'                => 0, // 0 = nothing to do
              'expected_notifications_count'   => 0,
              'expected_lock_count'            => 0,
           ],
           // validate that cron send notifications instantly if password_expiration_notice == 0
           [
              'expiration_delay'               => 50,
              'notice_delay'                   => 0,
              'lock_delay'                     => -1,
              'cron_limit'                     => 100,
              'expected_result'                => 1, // 1 = fully processed
              'expected_notifications_count'   => 5, // 5 users should be notified (them which has password set more than 50 days ago)
              'expected_lock_count'            => 0,
           ],
           // validate that cron send notifications before expiration if password_expiration_notice > 0
           [
              'expiration_delay'               => 50,
              'notice_delay'                   => 20,
              'lock_delay'                     => -1,
              'cron_limit'                     => 100,
              'expected_result'                => 1, // 1 = fully processed
              'expected_notifications_count'   => 7, // 7 users should be notified (them which has password set more than 50-20 days ago)
              'expected_lock_count'            => 0,
           ],
           // validate that cron returns partial result if there is too many notifications to send
           [
              'expiration_delay'               => 50,
              'notice_delay'                   => 20,
              'lock_delay'                     => -1,
              'cron_limit'                     => 5,
              'expected_result'                => -1, // -1 = partially processed
              'expected_notifications_count'   => 5, // 5 on 7 users should be notified (them which has password set more than 50-20 days ago)
              'expected_lock_count'            => 0,
           ],
           // validate that cron disable users instantly if password_expiration_lock_delay == 0
           [
              'expiration_delay'               => 50,
              'notice_delay'                   => -1,
              'lock_delay'                     => 0,
              'cron_limit'                     => 100,
              'expected_result'                => 1, // 1 = fully processed
              'expected_notifications_count'   => 0,
              'expected_lock_count'            => 5, // 5 users should be locked (them which has password set more than 50 days ago)
           ],
           // validate that cron disable users with given delay if password_expiration_lock_delay > 0
           [
              'expiration_delay'               => 20,
              'notice_delay'                   => -1,
              'lock_delay'                     => 10,
              'cron_limit'                     => 100,
              'expected_result'                => 1, // 1 = fully processed
              'expected_notifications_count'   => 0,
              'expected_lock_count'            => 7, // 7 users should be locked (them which has password set more than 20+10 days ago)
           ],
        ];
    }

    /**
     * @dataProvider cronPasswordExpirationNotificationsProvider
     */
    public function testCronPasswordExpirationNotifications(
        int $expiration_delay,
        int $notice_delay,
        int $lock_delay,
        int $cron_limit,
        int $expected_result,
        int $expected_notifications_count,
        int $expected_lock_count
    ) {
        global $CFG_GLPI, $DB;

        $this->login();

        $crontask = new \CronTask();
        $this->boolean($crontask->getFromDBbyName(\User::getType(), 'passwordexpiration'))->isTrue();
        $crontask->fields['param'] = $cron_limit;

        $cfg_backup = $CFG_GLPI;
        $CFG_GLPI['password_expiration_delay'] = $expiration_delay;
        $CFG_GLPI['password_expiration_notice'] = $notice_delay;
        $CFG_GLPI['password_expiration_lock_delay'] = $lock_delay;
        $CFG_GLPI['use_notifications']  = true;
        $CFG_GLPI['notifications_ajax'] = 1;
        $result = \User::cronPasswordExpiration($crontask);
        $CFG_GLPI = $cfg_backup;

        $this->integer($result)->isEqualTo($expected_result);
        $this->integer(
            countElementsInTable(\Alert::getTable(), ['itemtype' => \User::getType()])
        )->isEqualTo($expected_notifications_count);
        $DB->delete(\Alert::getTable(), ['itemtype' => \User::getType()]); // reset alerts

        $user_crit = [
           'authtype'  => \Auth::DB_GLPI,
           'is_active' => 0,
        ];
        $this->integer(countElementsInTable(\User::getTable(), $user_crit))->isEqualTo($expected_lock_count);
        $DB->update(\User::getTable(), ['is_active' => 1], $user_crit); // reset users
    }
}

/** Observe the actual selected connection without opening another transaction or socket. */
class UserScalarReadProbe extends \Doctrine\DBAL\Connection
{
    public int $builders = 0;
    public array $queries = [];

    public function __construct(private readonly \Doctrine\DBAL\Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getDatabasePlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): \Doctrine\DBAL\Query\QueryBuilder
    {
        ++$this->builders;
        return parent::createQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?\Doctrine\DBAL\Cache\QueryCacheProfile $qcp = null): \Doctrine\DBAL\Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
