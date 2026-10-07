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

use DbTestCase;
use Software;
use TicketTask;

/* Test for inc/commondbtm.class.php */

class CommonDBTM extends DbTestCase
{
    public function testReplacementDeletionUsesCurrentSourceAndTargetScopes(): void
    {
        global $DB;
        $original = $DB;
        $originalConnection = $original->getDoctrineConnection();
        $originalScope = $originalConnection->captureManagedTransactionScope();
        $originalLevel = $originalConnection->getTransactionNestingLevel();
        $session = $_SESSION;
        $mysql = $original->getProvider() !== 'pgsql';
        $otherEntity = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $this->integer($otherEntity)->isGreaterThan(0);
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
            $reader = $mysql ? \itsmng\Database\MySQLConnection::create($parameters)
                : \itsmng\Database\PostgresConnection::create($parameters);
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
            $reader->setTransactionIsolation($mysql ? \Doctrine\DBAL\TransactionIsolationLevel::REPEATABLE_READ
                : \Doctrine\DBAL\TransactionIsolationLevel::READ_COMMITTED);
            $routed = clone $original;
            (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($routed, $reader);
            foreach (['source', 'target'] as $changed) {
                $token = 'delete-current-' . $this->getUniqueString();
                $fixture = \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $token): array {
                    $manager = new \Doctrine\ORM\EntityManager($writer, \itsmng\Database\Orm::configuration($writer->getDatabasePlatform()));
                    try {
                        $records = [];
                        foreach (['source', 'target'] as $name) {
                            $record = new \itsmng\Database\Entity\DomainType();
                            $record->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, 0);
                            $record->name = $token . '-' . $name;
                            $manager->persist($record);
                            $records[$name] = $record;
                        }
                        $manager->flush();
                        return ['name' => $token, 'source' => $records['source']->id, 'target' => $records['target']->id];
                    } finally {
                        $manager->clear();
                    }
                });
                $fixtures[] = $fixture;
                $frame = \itsmng\Database\OwnedMutationFrame::begin($reader);
                $snapshot = $reader->fetchAllAssociative(
                    'SELECT id, entities_id FROM glpi_domaintypes WHERE id IN (?, ?) ORDER BY id',
                    [$fixture['source'], $fixture['target']]
                );
                $this->array(array_map('intval', array_column($snapshot, 'entities_id')))->isIdenticalTo([0, 0]);
                \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $fixture, $changed, $otherEntity): void {
                    $writer->update(
                        'glpi_domaintypes',
                        ['entities_id' => $otherEntity],
                        ['id' => $fixture[$changed], 'name' => $fixture['name'] . '-' . $changed]
                    );
                });
                $observer = (object)['loads' => [], 'probing' => false, 'probed' => false, 'cloneEntity' => null,
                    'otherEntity' => null, 'source' => $fixture['source'], 'target' => $fixture['target']];
                $model = new class ($observer) extends \DomainType {
                    public function __construct(private object $observer)
                    {
                    }
                    public static function getTable($classname = null)
                    {
                        return 'glpi_domaintypes';
                    }
                    public static function getType()
                    {
                        return 'DomainType';
                    }
                    public function post_getFromDB()
                    {
                        parent::post_getFromDB();
                        if ($this->observer->probing) {
                            return;
                        }
                        $this->observer->loads[] = ['id' => (int)$this->fields['id'], 'entity' => (int)$this->fields['entities_id']];
                        // Valid derived fields must survive the authority check.
                        $this->fields['fixture_derived'] = $this->fields['name'] . ' derived';
                        if (!$this->observer->probed && (int)$this->fields['id'] === $this->observer->source) {
                            $this->observer->probed = $this->observer->probing = true;
                            $fields = $this->fields;
                            try {
                                $clone = clone $this;
                                $clone->getFromDB($this->observer->source);
                                $this->observer->cloneEntity = (int)$clone->fields['entities_id'];
                                $this->getFromDB($this->observer->target);
                                $this->observer->otherEntity = (int)$this->fields['entities_id'];
                            } finally {
                                $this->fields = $fields;
                                $this->observer->probing = false;
                            }
                        }
                    }
                };
                $DB = $routed;
                try {
                    $this->boolean($model->delete(['id' => $fixture['source'], '_replace_by' => $fixture['target'],
                        '_no_message' => 1, '_no_history' => 1], true, false))
                        ->isFalse('Concurrent ' . $changed . ' entity change must refuse the public replacement deletion');
                } finally {
                    $DB = $original;
                }
                $frame->assertActive();
                $this->integer($reader->getTransactionNestingLevel())->isIdenticalTo(1);
                $this->array($observer->loads)->isIdenticalTo([
                    ['id' => $fixture['source'], 'entity' => $changed === 'source' ? $otherEntity : 0],
                    ['id' => $fixture['target'], 'entity' => $changed === 'target' ? $otherEntity : 0],
                ], 'Each public post-load boundary sees its current owning scope once');
                $this->integer($observer->cloneEntity)->isIdenticalTo(
                    $mysql ? 0 : ($changed === 'source' ? $otherEntity : 0),
                    'A clone cannot inherit the original model current-read authority'
                );
                $this->integer($observer->otherEntity)->isIdenticalTo(
                    $mysql ? 0 : ($changed === 'target' ? $otherEntity : 0),
                    'A nested different identifier remains an ordinary read'
                );
                $this->array($_SESSION)->isIdenticalTo($session);
                $rows = $reader->fetchAllAssociative(
                    'SELECT id, entities_id FROM glpi_domaintypes WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                    [$fixture['source'], $fixture['target']]
                );
                $this->array(array_map('intval', array_column($rows, 'id')))->isIdenticalTo([$fixture['source'], $fixture['target']]);
                $this->array(array_map('intval', array_column($rows, 'entities_id')))->isIdenticalTo(
                    $changed === 'source' ? [$otherEntity, 0] : [0, $otherEntity]
                );
                // The explicit policy is operation-scoped, even after refusal.
                $DB = $routed;
                try {
                    $this->boolean($model->getFromDB($fixture[$changed]))->isTrue();
                    $this->integer((int)$model->fields['entities_id'])->isIdenticalTo($mysql ? 0 : $otherEntity);
                } finally {
                    $DB = $original;
                }
                $frame->rollBack();
                $frame = null;
                $this->integer((int)$writer->fetchOne('SELECT entities_id FROM glpi_domaintypes WHERE id = ?', [$fixture[$changed]]))
                    ->isIdenticalTo($otherEntity, 'Caller rollback preserves the independently committed scope change');
                $originalScope->assertActive();
                $this->integer($originalConnection->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
            }
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $DB = $original;
            if ($frame !== null) {
                $cleanup(static fn () => $frame->rollBack());
            }
            if ($reader !== null) {
                $cleanup(static fn () => $reader->close());
            }
            if ($writer !== null) {
                foreach ($fixtures as $fixture) {
                    $cleanup(static fn () => \itsmng\Database\OwnedMutationFrame::run($writer, static function () use ($writer, $fixture): void {
                        foreach (['source', 'target'] as $name) {
                            if ($writer->fetchOne('SELECT name FROM glpi_domaintypes WHERE id = ?', [$fixture[$name]]) !== $fixture['name'] . '-' . $name) {
                                throw new \LogicException('Refusing cleanup of a missing or unowned domain type');
                            }
                        }
                        foreach (['source', 'target'] as $name) {
                            if ($writer->delete('glpi_domaintypes', ['id' => $fixture[$name], 'name' => $fixture['name'] . '-' . $name]) !== 1) {
                                throw new \LogicException('Owned domain type fixture cleanup failed');
                            }
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

    public function testMappedIdentifierReadsCompleteFreshRowsWithoutHydration(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $entity = $this->createItem(\Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $computer = $this->createItem(\Computer::class, ['name' => 'Before scalar read', 'entities_id' => $entity->getID()]);
        $user = $this->createItem(\User::class, ['name' => $this->getUniqueString()]);
        $ticket = $this->createItem(\Ticket::class, ['name' => $this->getUniqueString(),
            'content' => 'Complete ticket fields', 'entities_id' => 0, '_disablenotif' => true]);
        $json = json_encode(['quoted' => 'A "label" / path', 'enabled' => true, 'nested' => [1, null]], JSON_THROW_ON_ERROR);
        $this->boolean($DB->update('glpi_users', \Toolbox::addslashes_deep([
            'is_active' => false, 'firstname' => null, 'last_login' => '2020-02-03 04:05:06',
            'access_custom_shortcuts' => $json,
        ]), ['id' => $user->getID()]))->isTrue();
        $this->boolean($DB->update('glpi_computers', ['ticket_tco' => '12.3456'], ['id' => $computer->getID()]))->isTrue();
        $certificate = $this->createItem(\Certificate::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity->getID()]);
        $binding = $this->createItem(\Certificate_Item::class, ['certificates_id' => $certificate->getID(),
            'itemtype' => 'Computer', 'items_id' => $computer->getID()]);
        $calendar = $this->createItem(\Calendar::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $segment = $this->createItem(\CalendarSegment::class, ['calendars_id' => $calendar->getID(),
            'day' => 1, 'begin' => '00:00:00', 'end' => '24:00:00']);
        $connection = $DB->getDoctrineConnection();
        $manager = new class ($connection, \itsmng\Database\Orm::configuration($connection->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
            public array $queries = [];
            public function createQuery(string $dql = ''): \Doctrine\ORM\Query
            {
                $this->queries[] = $dql;
                return parent::createQuery($dql);
            }
        };
        $oracle = \itsmng\Database\Orm::create($DB);
        $records = new \itsmng\Database\Repository\RecordRepository($manager);
        try {
            foreach ([['glpi_entities', 0], ['glpi_entities', (int)$entity->getID()],
                ['glpi_tickets', (int)$ticket->getID()], ['glpi_users', (int)$user->getID()],
                ['glpi_computers', (int)$computer->getID()], ['glpi_certificates_items', (int)$binding->getID()],
                ['glpi_calendarsegments', (int)$segment->getID()]] as [$table, $id]) {
                $class = \itsmng\Database\EntityRegistry::tables()[$table];
                // An independent ordinary entity load retains the previous conversion oracle.
                $managed = $oracle->getRepository($class)->findOneBy(['id' => $id]);
                $expected = (new \itsmng\Database\Repository\RecordRepository($oracle))->toRow($managed);
                $row = $records->find($table, 'id', $id);
                $this->array($row)->isIdenticalTo($expected);
                $connection = $DB->getDoctrineConnection();
                $physical = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table)
                    . ' WHERE ' . $connection->quoteIdentifier('id') . ' = ?', [$id]);
                $actualColumns = array_keys($row);
                $physicalColumns = array_keys($physical);
                sort($actualColumns);
                sort($physicalColumns);
                $this->array($actualColumns)->isIdenticalTo($physicalColumns);
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
                $this->variable($records->find($table, 'id', PHP_INT_MAX))->isNull();
                $oracle->clear();
            }
            $row = $records->find('glpi_users', 'id', (int)$user->getID());
            $this->integer($row['is_active'])->isIdenticalTo(0);
            $this->variable($row['firstname'])->isNull();
            $this->string($row['last_login'])->isIdenticalTo('2020-02-03 04:05:06');
            $actualShortcuts = json_decode($row['access_custom_shortcuts'], true, 512, JSON_THROW_ON_ERROR);
            $expectedShortcuts = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            // Native JSON objects may reorder members; nested arrays retain their order.
            ksort($actualShortcuts);
            ksort($expectedShortcuts);
            $this->array($actualShortcuts)->isIdenticalTo($expectedShortcuts);
            $this->string($records->find('glpi_computers', 'id', (int)$computer->getID())['ticket_tco'])->isIdenticalTo('12.3456');
            $this->string($records->find('glpi_entities', 'id', (int)$entity->getID())['ldap_mode'])
                ->isIdenticalTo($DB->getDoctrineConnection()->fetchOne('SELECT ldap_mode FROM glpi_entities WHERE id = ?', [$entity->getID()]));

            $this->string($records->find('glpi_calendarsegments', 'id', (int)$segment->getID())['end'])->isIdenticalTo('24:00:00');
            $bindingRow = $records->find('glpi_certificates_items', 'id', (int)$binding->getID());
            $this->integer((int)$bindingRow['items_id'])->isIdenticalTo((int)$computer->getID());
            $this->integer((int)$bindingRow['computers_id'])->isIdenticalTo((int)$computer->getID());
            foreach ($manager->queries as $query) {
                $this->string($query)->contains(' AS value0')->notContains('SELECT r FROM');
            }

            // Nonidentifier owning-reference indexes retain the original entity lookup.
            $indexed = $records->find('glpi_computers', 'entities_id', (int)$entity->getID());
            $this->integer((int)$indexed['id'])->isIdenticalTo((int)$computer->getID());
            $managed = $manager->find(\itsmng\Database\Entity\Computer::class, (int)$computer->getID());
            $this->boolean($DB->update('glpi_computers', ['name' => 'After legacy update'], ['id' => $computer->getID()]))->isTrue();
            $this->string($records->find('glpi_computers', 'id', (int)$computer->getID())['name'])->isIdenticalTo('After legacy update');
            $this->string($managed->name)->isIdenticalTo('Before scalar read');
            $this->boolean($manager->contains($managed))->isTrue();
            $observed = \itsmng\Database\Orm::create($DB);
            try {
                $loads = new class () {
                    public int $count = 0;
                    public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $event): void
                    {
                        ++$this->count;
                        if ($event->getObject() instanceof \itsmng\Database\Entity\Computer) {
                            $event->getObject()->name = 'Listener transformed row';
                        }
                    }
                };
                $observed->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
                $transformed = (new \itsmng\Database\Repository\RecordRepository($observed))
                    ->find('glpi_computers', 'id', (int)$computer->getID());
                $this->string($transformed['name'])->isIdenticalTo('Listener transformed row');
                $this->integer($loads->count)->isGreaterThan(0);
                $this->array($observed->getUnitOfWork()->getIdentityMap())->isNotEmpty();
            } finally {
                $observed->clear();
            }
        } finally {
            $manager->clear();
            $oracle->clear();
        }
    }

    public function testMappedIdentifierModelReadsKeepFreshTicketHooksActorsAndPermissions(): void
    {
        global $DB;
        $database = $DB;
        $session = $_SESSION;
        $oracle = null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $first = $this->createItem(\User::class, ['name' => $this->getUniqueString()]);
            $second = $this->createItem(\User::class, ['name' => $this->getUniqueString()]);
            $ticket = new \Ticket();
            $this->integer((int)$ticket->add(['name' => $this->getUniqueString(),
                'content' => 'Before public hook', 'entities_id' => 0,
                '_users_id_requester' => $first->getID(), '_disablenotif' => true]))->isGreaterThan(0);
            $id = (int)$ticket->getID();
            $this->boolean($ticket->getFromDB($id))->isTrue();
            $this->string($ticket->fields['content'])->isIdenticalTo('Before public hook');
            $model = new class () extends \Ticket {
                public array $loadedRows = [];
                public static function getType()
                {
                    return 'Ticket';
                }
                public static function getTable($classname = null)
                {
                    return 'glpi_tickets';
                }
                public function post_getFromDB()
                {
                    $this->loadedRows[] = $this->fields;
                    parent::post_getFromDB();
                    $this->fields['_public_hook'] = count($this->loadedRows);
                }
            };
            $oracle = \itsmng\Database\Orm::create($DB);
            $expected = (new \itsmng\Database\Repository\RecordRepository($oracle))
                ->toRow($oracle->find(\itsmng\Database\Entity\Ticket::class, $id));
            $this->boolean($model->getFromDB($id))->isTrue();
            $this->array($model->loadedRows)->isIdenticalTo([$expected]);
            $this->integer($model->fields['_public_hook'])->isIdenticalTo(1);
            $this->array(array_map('intval', array_column($model->getUsers(\CommonITILActor::REQUESTER), 'users_id')))
                ->contains((int)$first->getID());
            $this->boolean($model->can($id, READ))->isTrue();
            $this->boolean($DB->update('glpi_tickets', ['content' => 'After public hook'], ['id' => $id]))->isTrue();
            $this->boolean($DB->update(
                'glpi_tickets_users',
                ['users_id' => $second->getID()],
                ['tickets_id' => $id, 'users_id' => $first->getID(), 'type' => \CommonITILActor::REQUESTER]
            ))->isTrue();
            $this->boolean($model->getFromDB($id))->isTrue();
            $this->integer($model->fields['_public_hook'])->isIdenticalTo(2);
            $this->string($model->loadedRows[1]['content'])->isIdenticalTo('After public hook');
            $this->array($model->loadedRows[1])->notHasKey('_public_hook');
            $this->array(array_map('intval', array_column($model->getUsers(\CommonITILActor::REQUESTER), 'users_id')))
                ->contains((int)$second->getID())->notContains((int)$first->getID());
            $_SESSION['glpiactiveprofile']['ticket'] = 0;
            $_SESSION['glpiactiveprofile']['ticketvalidation'] = 0;
            $this->boolean($model->can($id, READ))->isFalse();
            $fields = $model->fields;
            foreach ([null, '', PHP_INT_MAX] as $missing) {
                $this->boolean($model->getFromDB($missing))->isFalse();
                $this->array($model->fields)->isIdenticalTo($fields);
                $this->array($model->loadedRows)->hasSize(2);
            }
            $connection = $DB->getDoctrineConnection();
            $this->mockGenerator->orphanize('__construct');
            $routed = new \mock\DBmysql();
            $routes = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$routes) {
                ++$routes;
                return $connection;
            };
            $DB = $routed;
            $this->boolean($model->getFromDB($id))->isTrue();
            $this->integer($routes)->isGreaterThan(0);
            $this->integer($model->fields['_public_hook'])->isIdenticalTo(3);
        } finally {
            $oracle?->clear();
            $DB = $database;
            $_SESSION = $session;
        }
    }

    public function testCurrentModelLoadRestoresPolicyAndRefusesUnprovenAuthority(): void
    {
        global $DB;
        $this->login();
        $source = $this->createItem(\DomainType::class, ['name' => 'current-model-' . $this->getUniqueString(), 'entities_id' => 0]);
        $target = $this->createItem(\DomainType::class, ['name' => 'current-model-target-' . $this->getUniqueString(), 'entities_id' => 0]);
        $connection = $DB->getDoctrineConnection();
        $scope = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $policy = new \ReflectionProperty(\CommonDBTM::class, 'currentRead');
        // A public adapter can route factory construction while retaining the
        // original connection at operation boundaries. Reject before any query.
        $original = $DB;
        $alternate = $DB->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create(['driver' => 'pdo_pgsql', 'serverVersion' => '14.0'])
            : \itsmng\Database\MySQLConnection::create(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0']);
        $routed = new class ($connection, $alternate) extends \DBmysql {
            public function __construct(private \Doctrine\DBAL\Connection $current, private \Doctrine\DBAL\Connection $alternate)
            {
            }
            public function getDoctrineConnection(): \Doctrine\DBAL\Connection
            {
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;
                return $caller === \itsmng\Database\Orm::class ? $this->alternate : $this->current;
            }
        };
        $observer = (object)['posts' => 0];
        $model = new class ($observer) extends \DomainType {
            public function __construct(private object $observer)
            {
            }
            public static function getTable($classname = null)
            {
                return 'glpi_domaintypes';
            }
            public function post_getFromDB()
            {
                ++$this->observer->posts;
            }
        };
        $error = null;
        try {
            $DB = $routed;
            try {
                $model->getFromDBForUpdate($source->getID(), $connection);
            } catch (\Throwable $failure) {
                $error = $failure;
            }
        } finally {
            $DB = $original;
        }
        try {
            $this->object($error)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
            $this->integer($observer->posts)->isIdenticalTo(0);
            $this->boolean($alternate->isConnected())->isFalse('Reject a different manager before it opens a native connection');
            $this->variable($policy->getValue($model))->isNull();
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $alternate->close();
        }
        $marker = new \RuntimeException('Original public load failure');
        foreach (['false', 'throw', 'bypass', 'scope', 'recursive', 'throw-replace'] as $mode) {
            $observed = (object)['calls' => 0, 'posts' => 0, 'mode' => $mode, 'marker' => $marker,
                'connection' => $connection, 'replacement' => null];
            $model = new class ($observed) extends \DomainType {
                public function __construct(private object $observed)
                {
                }
                public static function getTable($classname = null)
                {
                    return 'glpi_domaintypes';
                }
                public static function getType()
                {
                    return 'DomainType';
                }
                public function getFromDB($id)
                {
                    ++$this->observed->calls;
                    if ($this->observed->mode === 'false') {
                        return false;
                    }
                    if ($this->observed->mode === 'throw-replace') {
                        $this->observed->connection->rollBack();
                        $this->observed->replacement = \itsmng\Database\OwnedMutationFrame::begin($this->observed->connection);
                        throw $this->observed->marker;
                    }
                    if ($this->observed->mode === 'throw') {
                        throw $this->observed->marker;
                    }
                    if ($this->observed->mode === 'bypass') {
                        $this->post_getFromDB();
                        return true;
                    }
                    return parent::getFromDB($id);
                }
                public function post_getFromDB()
                {
                    ++$this->observed->posts;
                    parent::post_getFromDB();
                    if ($this->observed->mode === 'scope') {
                        $this->fields['entities_id'] = PHP_INT_MAX;
                    } elseif ($this->observed->mode === 'recursive') {
                        $this->fields['is_recursive'] = 1;
                    }
                }
            };
            $model->fields = $source->fields;
            $error = null;
            $owned = null;
            $primary = null;
            try {
                if (in_array($mode, ['scope', 'recursive'], true)) {
                    $this->boolean($model->delete(['id' => $source->getID(), '_replace_by' => $target->getID(),
                        '_no_message' => 1, '_no_history' => 1], true, false))
                        ->isFalse('A delegated post-load hook cannot substitute mapped ' . $mode . ' authority');
                } else {
                    if ($mode === 'throw-replace') {
                        $owned = \itsmng\Database\OwnedMutationFrame::begin($connection);
                    }
                    try {
                        $result = $model->getFromDBForUpdate($source->getID(), $connection);
                    } catch (\Throwable $failure) {
                        $error = $failure;
                    }
                    if ($mode === 'false') {
                        $this->boolean($result)->isFalse();
                        $this->variable($error)->isNull();
                    } elseif ($mode === 'throw') {
                        $this->object($error)->isIdenticalTo($marker);
                    } elseif ($mode === 'bypass') {
                        $this->object($error)->isInstanceOf(\itsmng\Database\CurrentReadUnavailable::class);
                    } else {
                        $this->object($error)->isInstanceOf(\itsmng\Database\MutationCleanupFailure::class);
                        $this->object($error->primary)->isIdenticalTo($marker);
                        $this->object($error->cleanup)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
                        $this->boolean($error->rollbackUnproven)->isTrue();
                        $observed->replacement->assertActive();
                        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                    }
                }
                $this->variable($policy->getValue($model))->isNull($mode . ': private read policy restored');
                $this->integer($observed->calls)->isIdenticalTo(1, $mode . ': public override invoked once');
                $this->integer($observed->posts)->isIdenticalTo(in_array($mode, ['bypass', 'scope', 'recursive'], true) ? 1 : 0);
            } catch (\Throwable $failure) {
                $primary = $failure;
                throw $failure;
            } finally {
                try {
                    if ($observed->replacement !== null) {
                        $observed->replacement->rollBack();
                    } elseif ($owned !== null) {
                        $owned->rollBack();
                    }
                } catch (\Throwable $cleanup) {
                    throw $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
                }
            }
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            $this->boolean($source->getFromDB($source->getID()))->isTrue();
            $this->integer((int)$source->fields['entities_id'])->isIdenticalTo(0);
            $this->integer((int)$source->fields['is_recursive'])->isIdenticalTo(0);
        }
        $computer = $this->createItem(\Computer::class, ['name' => 'current-disk-owner-' . $this->getUniqueString(), 'entities_id' => 0]);
        $dynamic = $this->createItem(\Item_Disk::class, ['name' => 'current-dynamic-' . $this->getUniqueString(),
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'is_dynamic' => 1]);
        $this->boolean($dynamic->useDeletedToLockIfDynamic())->isTrue();
        foreach (['is_dynamic' => 0, 'is_deleted' => 1, 'itemtype' => 'Monitor'] as $column => $value) {
            $posts = (object)['count' => 0];
            $model = new class ($posts, $column, $value) extends \Item_Disk {
                public function __construct(private object $posts, private string $column, private mixed $value)
                {
                }
                public static function getTable($classname = null)
                {
                    return 'glpi_items_disks';
                }
                public static function getType()
                {
                    return 'Item_Disk';
                }
                public function post_getFromDB()
                {
                    ++$this->posts->count;
                    parent::post_getFromDB();
                    $this->fields[$this->column] = $this->value;
                }
            };
            $this->boolean($model->delete(['id' => $dynamic->getID(), '_no_message' => 1, '_no_history' => 1], false, false))
                ->isFalse('A post-load callback cannot substitute current disk ' . $column . ' authority');
            $this->integer($posts->count)->isIdenticalTo(1);
            $this->boolean($dynamic->getFromDB($dynamic->getID()))->isTrue();
            $this->integer((int)$dynamic->fields['is_dynamic'])->isIdenticalTo(1);
            $this->integer((int)$dynamic->fields['is_deleted'])->isIdenticalTo(0);
            $this->string($dynamic->fields['itemtype'])->isIdenticalTo('Computer');
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        }
        $posts = (object)['count' => 0];
        $template = new class ($posts) extends \Computer {
            public function __construct(private object $posts)
            {
            }
            public static function getTable($classname = null)
            {
                return 'glpi_computers';
            }
            public static function getType()
            {
                return 'Computer';
            }
            public function post_getFromDB()
            {
                ++$this->posts->count;
                parent::post_getFromDB();
                $this->fields['is_template'] = 1;
            }
        };
        $this->boolean($template->delete(['id' => $computer->getID(), '_no_message' => 1, '_no_history' => 1], false, false))
            ->isFalse('A post-load callback cannot turn a current computer into a forced-purge template');
        $this->integer($posts->count)->isIdenticalTo(1);
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->integer((int)$computer->fields['is_template'])->isIdenticalTo(0);
        $this->integer((int)$computer->fields['is_deleted'])->isIdenticalTo(0);
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        $semanticBoolean = new class () extends \DomainType {
            public static function getTable($classname = null)
            {
                return 'glpi_domaintypes';
            }
            public static function getType()
            {
                return 'DomainType';
            }
            public function post_getFromDB()
            {
                parent::post_getFromDB();
                $this->fields['is_recursive'] = false;
                $this->fields['fixture_derived'] = 'Valid public boolean representation';
            }
        };
        $this->boolean($semanticBoolean->delete(['id' => $source->getID(), '_replace_by' => $target->getID(),
            '_no_message' => 1, '_no_history' => 1], true, false))
            ->isTrue('Native false and canonical zero preserve the same current authority and derived hook fields');
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testSingleItemActivationReadsFreshPresenceAndRetainsEmptyHooks(): void
    {
        global $DB, $PLUGIN_HOOKS;

        $savedDb = $DB;
        $savedSession = $_SESSION;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        $manager = null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $computer = $this->createItem(\Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $id = (int)$computer->getID();
            $existing = new \Infocom();
            if ($existing->getFromDBforDevice('Computer', $id)) {
                $this->boolean($existing->delete(['id' => $existing->getID()], true))->isTrue();
            }
            $emptyFields = [];
            $emptyModels = [];
            $plugins->setValue(null, [...$savedPlugins, 'financial_presence_fixture']);
            $PLUGIN_HOOKS['item_empty']['financial_presence_fixture'][\Infocom::class] =
                static function (\Infocom $model) use (&$emptyFields, &$emptyModels, $computer): void {
                    $emptyFields[] = $model->fields;
                    $emptyModels[] = $model;
                    $model->fields['comment'] = 'Plugin empty default';
                    $model->fields['items_id'] = 777;
                    $model->fields['itemtype'] = 'Plugin placeholder';
                    $computer->fields['id'] = PHP_INT_MAX;
                };
            $this->array($computer->getForbiddenSingleMassiveActions())->notContains('Infocom:activate');
            $this->array($emptyModels)->hasSize(1);
            $this->string($emptyFields[0]['items_id'])->isIdenticalTo('');
            $this->string($emptyFields[0]['itemtype'])->isIdenticalTo('');
            $this->integer((int)$emptyModels[0]->fields['items_id'])->isIdenticalTo($id);
            $this->string($emptyModels[0]->fields['itemtype'])->isIdenticalTo('Computer');
            $this->string($emptyModels[0]->fields['comment'])->isIdenticalTo('Plugin empty default');
            $computer->fields['id'] = $id;
            $fixtureHooks = $PLUGIN_HOOKS;
            $PLUGIN_HOOKS = $savedHooks;
            $financial = $this->createItem(\Infocom::class, ['itemtype' => 'Computer', 'items_id' => $id]);
            $PLUGIN_HOOKS = $fixtureHooks;
            $emptyFields = [];
            $emptyModels = [];
            $_SESSION['glpiactiveprofile']['infocom'] = 0;
            $this->array($computer->getForbiddenSingleMassiveActions())->contains('Infocom:activate');
            $this->array($emptyModels)->isEmpty();

            $connection = $DB->getDoctrineConnection();
            $manager = \itsmng\Database\Orm::create($DB);
            $repository = new \itsmng\Database\Repository\InfocomRepository($manager);
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
            $this->boolean($repository->isActivatedFor('Computer', $id))->isTrue();
            $this->boolean($repository->isActivatedFor('Peripheral', $id))->isFalse();
            $this->boolean($repository->isActivatedFor('Computer', PHP_INT_MAX))->isFalse();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            // The listener is live: ordinary complete model hydration does call it.
            $managed = $manager->find(\itsmng\Database\Entity\Infocom::class, $financial->getID());
            $this->object($managed)->isInstanceOf(\itsmng\Database\Entity\Infocom::class);
            $this->integer($loads->count)->isIdenticalTo(1);
            // A legacy write must win even while a stale entity remains managed.
            $this->boolean($financial->delete(['id' => $financial->getID()], true))->isTrue();
            $this->boolean($repository->isActivatedFor('Computer', $id))->isFalse();
            $this->boolean($manager->contains($managed))->isTrue();
            $this->integer($loads->count)->isIdenticalTo(1);

            // Subclasses retain their complete custom model-loading boundary.
            $custom = new class () extends \Infocom {
                public array $calls = [];
                public function getFromDBforDevice($itemtype, $ID)
                {
                    $this->calls[] = [$itemtype, $ID];
                    $this->fields['comment'] = 'Custom loaded fields';
                    return true;
                }
            };
            $this->boolean($custom->isActivatedForDevice('Computer', $id))->isTrue();
            $this->array($custom->calls)->isIdenticalTo([['Computer', $id]]);
            $this->string($custom->fields['comment'])->isIdenticalTo('Custom loaded fields');

            // Resolve the actual current adapter at each caller operation.
            $this->mockGenerator->orphanize('__construct');
            $routed = new \mock\DBmysql();
            $routes = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$routes) {
                ++$routes;
                return $connection;
            };
            $DB = $routed;
            $this->array($computer->getForbiddenSingleMassiveActions())->notContains('Infocom:activate');
            $this->integer($routes)->isGreaterThan(0);
        } finally {
            $manager?->clear();
            $DB = $savedDb;
            $_SESSION = $savedSession;
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testConnexityPermissionRetainsItsSingleLoadedOwner(): void
    {
        $session = $_SESSION;
        try {
            $this->login();
            $ticket = $this->createItem(\Ticket::class, ['name' => 'Connexity owner ' . $this->getUniqueString(),
                'content' => 'Complete parent fields', 'entities_id' => $_SESSION['glpiactive_entity']]);
            $child = new class () extends \ITILFollowup {
                public int $loads = 0;
                public mixed $loaded = null;
                public function getConnexityItem($itemtype, $items_id, $getFromDB = true, $getEmpty = true, $getFromDBOrEmpty = false)
                {
                    ++$this->loads;
                    $this->loaded = parent::getConnexityItem($itemtype, $items_id, $getFromDB, $getEmpty, $getFromDBOrEmpty);
                    return $this->loaded;
                }
            };
            $child->fields = ['itemtype' => 'Ticket', 'items_id' => $ticket->getID()];
            $owner = null;
            $this->boolean($child->canConnexityItem(
                'canViewItem',
                'canView',
                \CommonDBConnexity::HAVE_VIEW_RIGHT_ON_ITEM,
                'itemtype',
                'items_id',
                $owner
            ))->isTrue();
            $this->integer($child->loads)->isIdenticalTo(1);
            $this->object($owner)->isIdenticalTo($child->loaded);
            $this->array($owner->fields)->isIdenticalTo($ticket->fields);
            // Supplied owners preserve identity and any caller-side field changes.
            $owner->fields['content'] = 'Supplied owner content';
            $this->boolean($child->canConnexityItem(
                'canViewItem',
                'canView',
                \CommonDBConnexity::HAVE_VIEW_RIGHT_ON_ITEM,
                'itemtype',
                'items_id',
                $owner
            ))->isTrue();
            $this->integer($child->loads)->isIdenticalTo(1);
            $this->string($owner->fields['content'])->isIdenticalTo('Supplied owner content');
            $_SESSION['glpiactiveprofile']['ticket'] = 0;
            $_SESSION['glpiactiveprofile']['ticketvalidation'] = 0;
            $this->boolean(\Ticket::canView())->isFalse();
            $this->boolean($child->canConnexityItem(
                'canViewItem',
                'canView',
                \CommonDBConnexity::HAVE_VIEW_RIGHT_ON_ITEM,
                'itemtype',
                'items_id',
                $owner
            ))->isFalse();
            $this->integer($child->loads)->isIdenticalTo(1);
            $owner = null;
            $child->fields['items_id'] = PHP_INT_MAX;
            $this->boolean($child->canConnexityItem(
                'canViewItem',
                'canView',
                \CommonDBConnexity::DONT_CHECK_ITEM_RIGHTS,
                'itemtype',
                'items_id',
                $owner
            ))->isFalse();
            $this->variable($owner)->isNull();
            $this->integer($child->loads)->isIdenticalTo(2);
            $child->fields['items_id'] = 0;
            $this->exception(fn () => $child->canConnexityItem(
                'canViewItem',
                'canView',
                \CommonDBConnexity::DONT_CHECK_ITEM_RIGHTS,
                'itemtype',
                'items_id'
            ))->isInstanceOf(\CommonDBConnexityItemNotFound::class);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testNewItemPermissionHooksRetainNormalizedInputsAndCannotGrantRights(): void
    {
        global $DB, $PLUGIN_HOOKS;

        $savedSession = $_SESSION;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity(0, true);
            $computer = $this->createItem('Computer', ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $port = $this->createItem('NetworkPort', ['name' => $this->getUniqueString(), 'entities_id' => 0,
                'itemtype' => 'Computer', 'items_id' => $computer->getID()]);
            $vlan = $this->createItem('Vlan', ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $_SESSION['glpiactiveprofile']['computer'] = READ | CREATE | UPDATE;
            $_SESSION['glpiactiveprofile']['networking'] = READ | UPDATE;
            $_SESSION['glpiactiveprofile']['dropdown'] = READ;
            $connection = $DB->getDoctrineConnection();
            $rows = static fn (): array => [
                $connection->fetchAllAssociative('SELECT * FROM glpi_computers ORDER BY id'),
                $connection->fetchAllAssociative('SELECT * FROM glpi_networkports_vlans ORDER BY id'),
            ];
            $before = $rows();
            $decision = null;
            $calls = [];
            $callback = static function (\CommonDBTM $model) use (&$decision, &$calls): void {
                $calls[] = ['right' => $model->right, 'fields' => $model->fields, 'input' => $model->input];
                if ($decision !== null) {
                    $model->right = $decision;
                }
            };
            $plugins->setValue(null, [...$savedPlugins, 'new_item_permission_fixture']);
            foreach ([\Computer::class, \NetworkPort_Vlan::class, \SavedSearch::class] as $type) {
                $PLUGIN_HOOKS['item_can']['new_item_permission_fixture'][$type] = $callback;
            }
            foreach ([
                [\Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]],
                [\NetworkPort_Vlan::class, ['networkports_id' => $port->getID(), 'vlans_id' => $vlan->getID(), 'tagged' => 0]],
            ] as [$type, $input]) {
                foreach ([null, false, UPDATE] as $decision) {
                    $calls = [];
                    $model = new $type();
                    $this->boolean($model->can(-1, CREATE, $input))->isIdenticalTo($decision === null);
                    $this->array($calls)->hasSize(1);
                    $this->integer($calls[0]['right'])->isIdenticalTo(CREATE);
                    $this->array($calls[0]['input'])->isIdenticalTo($input);
                    foreach (array_keys($input) as $field) {
                        $this->variable($calls[0]['fields'][$field])->isIdenticalTo($input[$field]);
                    }
                }
            }
            // A hook which leaves the requested right cannot manufacture a
            // missing global right or a missing loaded-owner operation right.
            $decision = CREATE;
            $_SESSION['glpiactiveprofile']['computer'] = READ;
            $input = ['name' => $this->getUniqueString(), 'entities_id' => 0];
            $this->boolean((new \Computer())->can(-1, CREATE, $input))->isFalse();
            $input = ['networkports_id' => $port->getID(), 'vlans_id' => $vlan->getID(), 'tagged' => 0];
            $this->boolean((new \NetworkPort_Vlan())->can(-1, CREATE, $input))->isFalse();

            // Restrictive callbacks also precede the new personal-item shortcut.
            $_SESSION['glpiactiveprofile']['bookmark_public'] = 0;
            $input = ['name' => $this->getUniqueString(), 'itemtype' => 'Computer',
                'users_id' => (int)\Session::getLoginUserID(), 'is_private' => 1];
            $decision = false;
            $this->boolean((new \SavedSearch())->can(-1, CREATE, $input))->isFalse();
            $decision = null;
            $this->boolean((new \SavedSearch())->can(-1, CREATE, $input))->isTrue();
            $this->array($rows())->isIdenticalTo($before);
        } finally {
            $_SESSION = $savedSession;
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testgetIndexNameOtherThanID()
    {

        $networkport = new \NetworkPort();
        $networkequipment = new \NetworkEquipment();
        $networkportaggregate = new \NetworkPortAggregate();

        $ne_id = $networkequipment->add([
            'entities_id' => 0,
            'name'        => 'switch'
        ]);
        $this->integer($ne_id)->isGreaterThan(0);

        // Add 5 ports
        $port1 = (int)$networkport->add([
              'name'         => 'if0/1',
              'logicial_number' => 1,
              'items_id' => $ne_id,
              'itemtype' => 'NetworkEquipment',
              'entities_id'  => 0,
           ]);
        $port2 = (int)$networkport->add([
              'name'         => 'if0/2',
              'logicial_number' => 2,
              'items_id' => $ne_id,
              'itemtype' => 'NetworkEquipment',
              'entities_id'  => 0,
           ]);
        $port3 = (int)$networkport->add([
              'name'         => 'if0/3',
              'logicial_number' => 3,
              'items_id' => $ne_id,
              'itemtype' => 'NetworkEquipment',
              'entities_id'  => 0,
           ]);
        $port4 = (int)$networkport->add([
              'name'         => 'if0/4',
              'logicial_number' => 4,
              'items_id' => $ne_id,
              'itemtype' => 'NetworkEquipment',
              'entities_id'  => 0,
           ]);
        $port5 = (int)$networkport->add([
              'name'         => 'if0/5',
              'logicial_number' => 5,
              'items_id' => $ne_id,
              'itemtype' => 'NetworkEquipment',
              'entities_id'  => 0,
           ]);

        $this->integer($port1)->isGreaterThan(0);
        $this->integer($port2)->isGreaterThan(0);
        $this->integer($port3)->isGreaterThan(0);
        $this->integer($port4)->isGreaterThan(0);
        $this->integer($port5)->isGreaterThan(0);

        // add an aggregate port use port 3 and 4
        $aggport = (int)$networkportaggregate->add([
              'networkports_id' => $port5,
              'networkports_id_list' => [$port3, $port4],
           ]);

        $this->integer($aggport)->isGreaterThan(0);
        // Try update to use 2 and 4
        $this->boolean($networkportaggregate->update([
              'networkports_id' => $port5,
              'networkports_id_list' => [$port2, $port4],
        ]))->isTrue();

        // Try update with id not exist, it will return false
        $this->boolean($networkportaggregate->update([
              'networkports_id' => $port3,
              'networkports_id_list' => [$port2, $port4],
        ]))->isFalse();

        // A replacement key belongs to the public index, while self-replacement
        // compares the actual locked row. Exercise a deliberate cross collision
        // using only these new ports and absent, bounded physical fixture IDs.
        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $this->integer((int)$connection->fetchOne(
            'SELECT COUNT(*) FROM glpi_networkportlocals WHERE id IN (?, ?)',
            [$port2, $port2 + 1]
        ))->isIdenticalTo(0, 'Custom-index fixtures must never adopt existing rows');
        $manager = \itsmng\Database\Orm::create($GLOBALS['DB']);
        try {
            $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\NetworkPortLocal::class);
            $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            $metadata->setIdGenerator(new \Doctrine\ORM\Id\AssignedGenerator());
            foreach ([[$port2, $port1], [$port2 + 1, $port2]] as [$physical, $logical]) {
                $record = new \itsmng\Database\Entity\NetworkPortLocal();
                $record->id = $physical;
                $record->networkports_id = $manager->getReference(\itsmng\Database\Entity\NetworkPort::class, $logical);
                $manager->persist($record);
            }
            $manager->flush();
            $source = new \NetworkPortLocal();
            $this->boolean($source->getFromDB($port1))->isTrue();
            $this->integer((int)$source->fields['id'])->isIdenticalTo($port2);
            $this->integer((int)$source->getID())->isIdenticalTo($port1);
            $repository = new \itsmng\Database\Repository\DeletionRepository($manager);
            $this->boolean($repository->validateReplacement($source, ['_replace_by' => $port2]))
                ->isTrue('A distinct public key may equal the source physical identity');
            $this->boolean($repository->validateReplacement($source, ['_replace_by' => $port1]))
                ->isFalse('Self-replacement remains forbidden when public and physical identities differ');
        } finally {
            $manager->clear();
        }

    }

    public function testGetFromDBByRequest()
    {
        $instance = new \Computer();
        $instance->getFromDbByRequest([
           'LEFT JOIN' => [
              \Entity::getTable() => [
                 'FKEY' => [
                    \Entity::getTable() => 'id',
                    \Computer::getTable() => \Entity::getForeignKeyField()
                 ]
              ]
           ],
           'WHERE' => ['AND' => [
              'contact' => 'johndoe'],
              \Entity::getTable() . '.name' => '_test_root_entity',
           ]
        ]);
        // the instance must be populated
        $this->boolean($instance->isNewItem())->isFalse();

        $instance = new \Computer();
        $this->exception(
            function () use ($instance) {
                $instance->getFromDbByRequest([
                   'WHERE' => ['contact' => 'johndoe'],
                ]);
            }
        )->isInstanceOf(\RuntimeException::class)
        ->message
        ->contains('getFromDBByRequest expects to get one result, 2 found!');

        // the instance must not be populated
        $this->boolean($instance->isNewItem())->isTrue();
    }

    public function testGetFromResultSet()
    {
        global $DB;
        $result = $DB->request([
           'FROM'   => \Computer::getTable(),
           'LIMIT'  => 1
        ])->next();

        $this->array($result)->hasKeys(['name', 'uuid']);

        $computer = new \Computer();
        $computer->getFromResultSet($result);
        $this->array($computer->fields)->isIdenticalTo($result);
    }

    public function testGetId()
    {
        $comp = new \Computer();

        $this->integer($comp->getID())->isIdenticalTo(-1);

        $this->boolean($comp->getFromDBByCrit(['name' => '_test_pc01']))->isTrue();
        $this->integer((int)$comp->getID())->isGreaterThan(0);
    }

    public function testGetEmpty()
    {
        $comp = new \Computer();

        $this->array($comp->fields)->isEmpty();

        $this->boolean($comp->getEmpty())->isTrue();
        $this->array($comp->fields)->integer['entities_id']->isEqualTo(0);

        $_SESSION["glpiactive_entity"] = 12;
        $this->boolean($comp->getEmpty())->isTrue();
        unset($_SESSION['glpiactive_entity']);
        $this->array($comp->fields)
           ->integer['entities_id']->isIdenticalTo(12);
    }

    /**
     * Provider for self::testGetTable().
     *
     * @return array
     */
    protected function getTableProvider()
    {

        return [
           [\DBConnection::class, ''], // "static protected $notable = true;" case
           [\Item_Devices::class, ''], // "static protected $notable = true;" case
           [\Config::class, 'glpi_configs'],
           [\Computer::class, 'glpi_computers'],
           [\User::class, 'glpi_users'],
        ];
    }

    /**
     * Test CommonDBTM::getTable() method.
     *
     * @dataProvider getTableProvider
     * @return void
     */
    public function testGetTable($classname, $tablename)
    {

        $this->string($classname::getTable())
           ->isEqualTo(\CommonDBTM::getTable($classname))
           ->isEqualTo($tablename);
    }

    /**
     * Test CommonDBTM::getTableField() method.
     *
     * @return void
     */
    public function testGetTableField()
    {

        // Exception if field argument is empty
        $this->exception(
            function () {
                \Computer::getTableField('');
            }
        )->isInstanceOf(\InvalidArgumentException::class)
           ->hasMessage('Argument $field cannot be empty.');

        // Exception if class has no table
        $this->exception(
            function () {
                \Item_Devices::getTableField('id');
            }
        )->isInstanceOf(\LogicException::class)
           ->hasMessage('Invalid table name.');

        // Base case
        $this->string(\Computer::getTableField('serial'))
           ->isEqualTo(\CommonDBTM::getTableField('serial', \Computer::class))
           ->isEqualTo('glpi_computers.serial');

        // Wildcard case
        $this->string(\Config::getTableField('*'))
           ->isEqualTo(\CommonDBTM::getTableField('*', \Config::class))
           ->isEqualTo('glpi_configs.*');
    }

    public function testupdateOrInsert()
    {
        global $DB;

        //insert case
        $res = (int)$DB->updateOrInsert(
            \Computer::getTable(),
            [
              'name'   => 'serial-to-change',
              'serial' => 'serial-one'
         ],
            [
              'name'   => 'serial-to-change'
         ]
        );
        $this->integer($res)->isGreaterThan(0);

        $check = $DB->request([
           'FROM'   => \Computer::getTable(),
           'WHERE'  => ['name' => 'serial-to-change']
        ])->next();
        $this->array($check)
           ->string['serial']->isIdenticalTo('serial-one');

        //update case
        $res = $DB->updateOrInsert(
            \Computer::getTable(),
            [
              'name'   => 'serial-to-change',
              'serial' => 'serial-changed'
         ],
            [
              'name'   => 'serial-to-change'
         ]
        );
        $this->boolean($res)->isTrue();

        $check = $DB->request([
           'FROM'   => \Computer::getTable(),
           'WHERE'  => ['name' => 'serial-to-change']
        ])->next();
        $this->array($check)
           ->string['serial']->isIdenticalTo('serial-changed');

        $this->integer(
            (int)$DB->insert(
                \Computer::getTable(),
                ['name' => 'serial-to-change']
            )
        )->isGreaterThan(0);

        //multiple update case
        $this->exception(
            function () use ($DB) {
                $res = $DB->updateOrInsert(
                    \Computer::getTable(),
                    [
                      'name'   => 'serial-to-change',
                      'serial' => 'serial-changed'
               ],
                    [
                      'name'   => 'serial-to-change'
               ]
                );
            }
        )->message->contains('Update would change too many rows!');

        //allow multiples
        $res = $DB->updateOrInsert(
            \Computer::getTable(),
            [
              'name'   => 'serial-to-change',
              'serial' => 'serial-changed'
         ],
            [
              'name'   => 'serial-to-change'
         ],
            false
        );
        $this->boolean($res)->isTrue();
    }

    public function testupdateOrInsertMerged()
    {
        global $DB;

        //insert case
        $res = (int)$DB->updateOrInsert(
            \Computer::getTable(),
            [
              'serial' => 'serial-one'
         ],
            [
              'name'   => 'serial-to-change'
         ]
        );
        $this->integer($res)->isGreaterThan(0);

        $check = $DB->request([
           'FROM'   => \Computer::getTable(),
           'WHERE'  => ['name' => 'serial-to-change']
        ])->next();
        $this->array($check)
           ->string['serial']->isIdenticalTo('serial-one');

        //update case
        $res = $DB->updateOrInsert(
            \Computer::getTable(),
            [
              'serial' => 'serial-changed'
         ],
            [
              'name'   => 'serial-to-change'
         ]
        );
        $this->boolean($res)->isTrue();

        $check = $DB->request([
           'FROM'   => \Computer::getTable(),
           'WHERE'  => ['name' => 'serial-to-change']
        ])->next();
        $this->array($check)
           ->string['serial']->isIdenticalTo('serial-changed');

        $this->integer(
            (int)$DB->insert(
                \Computer::getTable(),
                ['name' => 'serial-to-change']
            )
        )->isGreaterThan(0);

        //multiple update case
        $this->exception(
            function () use ($DB) {
                $res = $DB->updateOrInsert(
                    \Computer::getTable(),
                    [
                      'serial' => 'serial-changed'
               ],
                    [
                      'name'   => 'serial-to-change'
               ]
                );
            }
        )->message->contains('Update would change too many rows!');

        //allow multiples
        $res = $DB->updateOrInsert(
            \Computer::getTable(),
            [
              'serial' => 'serial-changed'
         ],
            [
              'name'   => 'serial-to-change'
         ],
            false
        );
        $this->boolean($res)->isTrue();
    }
    /**
     * Check right on Recursive object
     *
     * @return void
     */
    public function testRecursiveObjectChecks()
    {
        $this->login();

        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        $printer = new \Printer();

        $id[0] = (int)$printer->add([
           'name'         => "Printer 1",
           'entities_id'  => $ent0,
           'is_recursive' => 0
        ]);
        $this->integer($id[0])->isGreaterThan(0);

        $id[1] = (int)$printer->add([
           'name'         => "Printer 2",
           'entities_id'  => $ent0,
           'is_recursive' => 1
        ]);
        $this->integer($id[1])->isGreaterThan(0);

        $id[2] = (int)$printer->add([
           'name'         => "Printer 3",
           'entities_id'  => $ent1,
           'is_recursive' => 1
        ]);
        $this->integer($id[2])->isGreaterThan(0);

        $id[3] = (int)$printer->add([
           'name'         => "Printer 4",
           'entities_id'  => $ent2
        ]);
        $this->integer($id[3])->isGreaterThan(0);

        // Super admin
        $this->login('itsm', 'itsm');
        $this->variable($_SESSION['glpiactiveprofile']['id'])->isEqualTo(4);
        $this->variable($_SESSION['glpiactiveprofile']['printer'])->isEqualTo(255);

        // See all
        $this->boolean(\Session::changeActiveEntities('all'))->isTrue();

        $this->boolean($printer->can($id[0], READ))->isTrue("Fail can read Printer 1");
        $this->boolean($printer->can($id[1], READ))->isTrue("Fail can read Printer 2");
        $this->boolean($printer->can($id[2], READ))->isTrue("Fail can read Printer 3");
        $this->boolean($printer->can($id[3], READ))->isTrue("Fail can read Printer 4");

        $this->boolean($printer->canEdit($id[0]))->isTrue("Fail can write Printer 1");
        $this->boolean($printer->canEdit($id[1]))->isTrue("Fail can write Printer 2");
        $this->boolean($printer->canEdit($id[2]))->isTrue("Fail can write Printer 3");
        $this->boolean($printer->canEdit($id[3]))->isTrue("Fail can write Printer 4");

        // See only in main entity
        $this->boolean(\Session::changeActiveEntities($ent0))->isTrue();

        $this->boolean($printer->can($id[0], READ))->isTrue("Fail can read Printer 1");
        $this->boolean($printer->can($id[1], READ))->isTrue("Fail can read Printer 2");
        $this->boolean($printer->can($id[2], READ))->isFalse("Fail can't read Printer 3");
        $this->boolean($printer->can($id[3], READ))->isFalse("Fail can't read Printer 1");

        $this->boolean($printer->canEdit($id[0]))->isTrue("Fail can write Printer 1");
        $this->boolean($printer->canEdit($id[1]))->isTrue("Fail can write Printer 2");
        $this->boolean($printer->canEdit($id[2]))->isFalse("Fail can't write Printer 1");
        $this->boolean($printer->canEdit($id[3]))->isFalse("Fail can't write Printer 1");

        // See only in child entity 1 + parent if recursive
        $this->boolean(\Session::changeActiveEntities($ent1))->isTrue();

        $this->boolean($printer->can($id[0], READ))->isFalse("Fail can't read Printer 1");
        $this->boolean($printer->can($id[1], READ))->isTrue("Fail can read Printer 2");
        $this->boolean($printer->can($id[2], READ))->isTrue("Fail can read Printer 3");
        $this->boolean($printer->can($id[3], READ))->isFalse("Fail can't read Printer 4");

        $this->boolean($printer->canEdit($id[0]))->isFalse("Fail can't write Printer 1");
        $this->boolean($printer->canEdit($id[1]))->isFalse("Fail can't write Printer 2");
        $this->boolean($printer->canEdit($id[2]))->isTrue("Fail can write Printer 2");
        $this->boolean($printer->canEdit($id[3]))->isFalse("Fail can't write Printer 2");

        // See only in child entity 2 + parent if recursive
        $this->boolean(\Session::changeActiveEntities($ent2))->isTrue();

        $this->boolean($printer->can($id[0], READ))->isFalse("Fail can't read Printer 1");
        $this->boolean($printer->can($id[1], READ))->isTrue("Fail can read Printer 2");
        $this->boolean($printer->can($id[2], READ))->isFalse("Fail can't read Printer 3");
        $this->boolean($printer->can($id[3], READ))->isTrue("Fail can read Printer 4");

        $this->boolean($printer->canEdit($id[0]))->isFalse("Fail can't write Printer 1");
        $this->boolean($printer->canEdit($id[1]))->isFalse("Fail can't write Printer 2");
        $this->boolean($printer->canEdit($id[2]))->isFalse("Fail can't write Printer 3");
        $this->boolean($printer->canEdit($id[3]))->isTrue("Fail can write Printer 4");
    }

    /**
     * Check right on CommonDBRelation object
     */
    public function testContact_Supplier()
    {

        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        // Super admin
        $this->login('itsm', 'itsm');
        $this->variable($_SESSION['glpiactiveprofile']['id'])->isEqualTo(4);
        $this->variable($_SESSION['glpiactiveprofile']['contact_enterprise'])->isEqualTo(255);

        // See all
        $this->boolean(\Session::changeActiveEntities('all'))->isTrue();

        // Create some contacts
        $contact = new \Contact();

        $idc[0] = (int)$contact->add([
           'name'         => "Contact 1",
           'entities_id'  => $ent0,
           'is_recursive' => 0
        ]);
        $this->integer($idc[0])->isGreaterThan(0);

        $idc[1] = (int)$contact->add([
           'name'         => "Contact 2",
           'entities_id'  => $ent0,
           'is_recursive' => 1
        ]);
        $this->integer($idc[1])->isGreaterThan(0);

        $idc[2] = (int)$contact->add([
           'name'         => "Contact 3",
           'entities_id'  => $ent1,
           'is_recursive' => 1
        ]);
        $this->integer($idc[2])->isGreaterThan(0);

        $idc[3] = (int)$contact->add([
           'name'         => "Contact 4",
           'entities_id'  => $ent2]);
        $this->integer($idc[3])->isGreaterThan(0);
        ;

        // Create some suppliers
        $supplier = new \Supplier();

        $ids[0] = (int)$supplier->add([
           'name'         => "Supplier 1",
           'entities_id'  => $ent0,
           'is_recursive' => 0
        ]);
        $this->integer($ids[0])->isGreaterThan(0);

        $ids[1] = (int)$supplier->add([
           'name'         => "Supplier 2",
           'entities_id'  => $ent0,
           'is_recursive' => 1
        ]);
        $this->integer($ids[1])->isGreaterThan(0);

        $ids[2] = (int)$supplier->add([
           'name'         => "Supplier 3",
           'entities_id'  => $ent1
        ]);
        $this->integer($ids[2])->isGreaterThan(0);

        $ids[3] = (int)$supplier->add([
           'name'         => "Supplier 4",
           'entities_id'  => $ent2
        ]);
        $this->integer($ids[3])->isGreaterThan(0);

        // Relation
        $rel = new \Contact_Supplier();
        $input = [
           'contacts_id' =>  $idc[0], // root
           'suppliers_id' => $ids[0]  //root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();

        $idr[0] = (int)$rel->add($input);
        $this->integer($idr[0])->isGreaterThan(0);
        $this->boolean($rel->can($idr[0], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[0]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[0], // root
           'suppliers_id' => $ids[1]  // root + rec
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[1] = (int)$rel->add($input);
        $this->integer($idr[1])->isGreaterThan(0);
        $this->boolean($rel->can($idr[1], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[1]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[0], // root
           'suppliers_id' => $ids[2]  // child 1
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id' =>  $idc[0], // root
           'suppliers_id' => $ids[3]  // child 2
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id' =>  $idc[1], // root + rec
           'suppliers_id' => $ids[0]  // root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[2] = (int)$rel->add($input);
        $this->integer($idr[2])->isGreaterThan(0);
        $this->boolean($rel->can($idr[2], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[2]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[1], // root + rec
           'suppliers_id' => $ids[1]  // root + rec
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[3] = (int)$rel->add($input);
        $this->integer($idr[3])->isGreaterThan(0);
        $this->boolean($rel->can($idr[3], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[3]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[1], // root + rec
           'suppliers_id' => $ids[2]  // child 1
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[4] = (int)$rel->add($input);
        $this->integer($idr[4])->isGreaterThan(0);
        $this->boolean($rel->can($idr[4], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[4]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[1], // root + rec
           'suppliers_id' => $ids[3]  // child 2
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[5] = (int)$rel->add($input);
        $this->integer($idr[5])->isGreaterThan(0);
        $this->boolean($rel->can($idr[5], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[5]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[2], // Child 1
           'suppliers_id' => $ids[0]  // root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id' =>  $idc[2], // Child 1
           'suppliers_id' => $ids[1]  // root + rec
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[6] = (int)$rel->add($input);
        $this->integer($idr[6])->isGreaterThan(0);
        $this->boolean($rel->can($idr[6], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[6]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[2], // Child 1
           'suppliers_id' => $ids[2]  // Child 1
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[7] = (int)$rel->add($input);
        $this->integer($idr[7])->isGreaterThan(0);
        $this->boolean($rel->can($idr[7], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[7]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[2], // Child 1
           'suppliers_id' => $ids[3]  // Child 2
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        // See only in child entity 2 + parent if recursive
        $this->boolean(\Session::changeActiveEntities($ent2))->isTrue();

        $this->boolean($rel->can($idr[0], READ))->isFalse();  // root / root
        //$this->boolean($rel->canEdit($idr[0]))->isFalse();
        $this->boolean($rel->can($idr[1], READ))->isFalse();  // root / root rec
        //$this->boolean($rel->canEdit($idr[1]))->isFalse();
        $this->boolean($rel->can($idr[2], READ))->isFalse();  // root rec / root
        //$this->boolean($rel->canEdit($idr[2]))->isFalse();
        $this->boolean($rel->can($idr[3], READ))->isTrue();   // root rec / root rec
        //$this->boolean($rel->canEdit($idr[3]))->isFalse();
        $this->boolean($rel->can($idr[4], READ))->isFalse();  // root rec / child 1
        //$this->boolean($rel->canEdit($idr[4]))->isFalse();
        $this->boolean($rel->can($idr[5], READ))->isTrue();   // root rec / child 2
        $this->boolean($rel->canEdit($idr[5]))->isTrue();
        $this->boolean($rel->can($idr[6], READ))->isFalse();  // child 1 / root rec
        //$this->boolean($rel->canEdit($idr[6]))->isFalse();
        $this->boolean($rel->can($idr[7], READ))->isFalse();  // child 1 / child 1
        //$this->boolean($rel->canEdit($idr[7]))->isFalse();

        $input = [
           'contacts_id' =>  $idc[0], // root
           'suppliers_id' => $ids[0]  // root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id'  =>  $idc[0],// root
           'suppliers_id' => $ids[1]  // root + rec
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id'  =>  $idc[1],// root + rec
           'suppliers_id' => $ids[0]  // root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id' =>  $idc[3], // Child 2
           'suppliers_id' => $ids[0]  // root
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id'  =>  $idc[3],// Child 2
           'suppliers_id' => $ids[1]  // root + rec
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[7] = (int)$rel->add($input);
        $this->integer($idr[7])->isGreaterThan(0);
        $this->boolean($rel->can($idr[7], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[7]))->isTrue();

        $input = [
           'contacts_id' =>  $idc[3], // Child 2
           'suppliers_id' => $ids[2]  // Child 1
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isFalse();

        $input = [
           'contacts_id' =>  $idc[3], // Child 2
           'suppliers_id' => $ids[3]  // Child 2
        ];
        $this->boolean($rel->can(-1, CREATE, $input))->isTrue();
        $idr[8] = (int)$rel->add($input);
        $this->integer($idr[8])->isGreaterThan(0);
        $this->boolean($rel->can($idr[8], READ))->isTrue();
        $this->boolean($rel->canEdit($idr[8]))->isTrue();
    }

    /**
     * Entity right check
     */
    public function testEntity()
    {
        $this->login();

        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        $entity = new \Entity();
        $ent3 = (int)$entity->add([
           'name'         => '_test_child_2_subchild_1',
           'entities_id'  => $ent2
        ]);
        $this->integer($ent3)->isGreaterThan(0);

        $ent4 = (int)$entity->add([
           'name'         => '_test_child_2_subchild_2',
           'entities_id'  => $ent2
        ]);
        $this->integer($ent4)->isGreaterThan(0);

        $this->boolean(\Session::changeActiveEntities('all'))->isTrue();

        $this->boolean($entity->can(0, READ))->isTrue("Fail: can't read root entity");
        $this->boolean($entity->can($ent0, READ))->isTrue("Fail: can't read entity 0");
        $this->boolean($entity->can($ent1, READ))->isTrue("Fail: can't read entity 1");
        $this->boolean($entity->can($ent2, READ))->isTrue("Fail: can't read entity 2");
        $this->boolean($entity->can($ent3, READ))->isTrue("Fail: can't read entity 2.1");
        $this->boolean($entity->can($ent4, READ))->isTrue("Fail: can't read entity 2.2");
        $this->boolean($entity->can(99999, READ))->isFalse("Fail: can read not existing entity");

        $this->boolean($entity->canEdit(0))->isTrue("Fail: can't write root entity");
        $this->boolean($entity->canEdit($ent0))->isTrue("Fail: can't write entity 0");
        $this->boolean($entity->canEdit($ent1))->isTrue("Fail: can't write entity 1");
        $this->boolean($entity->canEdit($ent2))->isTrue("Fail: can't write entity 2");
        $this->boolean($entity->canEdit($ent3))->isTrue("Fail: can't write entity 2.1");
        $this->boolean($entity->canEdit($ent4))->isTrue("Fail: can't write entity 2.2");
        $this->boolean($entity->canEdit(99999))->isFalse("Fail: can write not existing entity");

        $input = ['entities_id' => $ent1];
        $this->boolean($entity->can(-1, CREATE, $input))->isTrue("Fail: can create entity in root");
        $input = ['entities_id' => $ent2];
        $this->boolean($entity->can(-1, CREATE, $input))->isTrue("Fail: can't create entity in 2");
        $input = ['entities_id' => $ent3];
        $this->boolean($entity->can(-1, CREATE, $input))->isTrue("Fail: can't create entity in 2.1");
        $input = ['entities_id' => 99999];
        $this->boolean($entity->can(-1, CREATE, $input))->isFalse("Fail: can create entity in not existing entity");
        $input = ['entities_id' => -1];
        $this->boolean($entity->can(-1, CREATE, $input))->isFalse("Fail: can create entity in not existing entity");

        $this->boolean(\Session::changeActiveEntities($ent2, false))->isTrue();
        $input = ['entities_id' => $ent1];
        $this->boolean($entity->can(-1, CREATE, $input))->isFalse("Fail: can create entity in root");
        $input = ['entities_id' => $ent2];
        // next should be false (or not).... but check is done on glpiactiveprofile
        // will require to save current state in session - this is probably acceptable
        // this allow creation when no child defined yet (no way to select tree in this case)
        $this->boolean($entity->can(-1, CREATE, $input))->isTrue("Fail: can't create entity in 2");
        $input = ['entities_id' => $ent3];
        $this->boolean($entity->can(-1, CREATE, $input))->isFalse("Fail: can create entity in 2.1");
    }

    public function testAdd()
    {
        $computer = new \Computer();
        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $bkp_current = $_SESSION['glpi_currenttime'];
        $_SESSION['glpi_currenttime'] = '2000-01-01 00:00:00';

        //test with date set
        $computerID = $computer->add(\Toolbox::addslashes_deep([
           'name'            => 'Computer01 \'',
           'date_creation'   => '2018-01-01 11:22:33',
           'date_mod'        => '2018-01-01 22:33:44',
           'entities_id'     => $ent0
        ]));
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01 '");

        $this->integer($computerID)->isGreaterThan(0);
        $this->boolean(
            $computer->getFromDB($computerID)
        )->isTrue();
        // Verify you can override creation and modifcation dates from add
        $this->string($computer->fields['date_creation'])->isEqualTo('2018-01-01 11:22:33');
        $this->string($computer->fields['date_mod'])->isEqualTo('2018-01-01 22:33:44');
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01 '");

        //test with default date
        $computerID = $computer->add(\Toolbox::addslashes_deep([
           'name'            => 'Computer01 \'',
           'entities_id'     => $ent0
        ]));
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01 '");

        $this->integer($computerID)->isGreaterThan(0);
        $this->boolean(
            $computer->getFromDB($computerID)
        )->isTrue();
        // Verify default date has been used
        $this->string($computer->fields['date_creation'])->isEqualTo('2000-01-01 00:00:00');
        $this->string($computer->fields['date_mod'])->isEqualTo('2000-01-01 00:00:00');
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01 '");

        $_SESSION['glpi_currenttime'] = $bkp_current;
    }

    public function testUpdate()
    {
        $computer = new \Computer();
        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $bkp_current = $_SESSION['glpi_currenttime'];
        $_SESSION['glpi_currenttime'] = '2000-01-01 00:00:00';

        //test with date set
        $computerID = $computer->add(\Toolbox::addslashes_deep([
           'name'            => 'Computer01',
           'date_creation'   => '2018-01-01 11:22:33',
           'date_mod'        => '2018-01-01 22:33:44',
           'entities_id'     => $ent0
        ]));
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01");

        $this->integer($computerID)->isGreaterThan(0);
        $this->boolean(
            $computer->getFromDB($computerID)
        )->isTrue();
        $this->string($computer->fields['name'])->isIdenticalTo("Computer01");

        $this->boolean(
            $computer->update(['id' => $computerID, 'name' => \Toolbox::addslashes_deep('Computer01 \'')])
        )->isTrue();
        $this->string($computer->fields['name'])->isIdenticalTo('Computer01 \'');
        $this->boolean($computer->getFromDB($computerID))->isTrue();
        $this->string($computer->fields['name'])->isIdenticalTo('Computer01 \'');
    }


    public function testTimezones()
    {
        global $DB;

        //check if timezones are available
        $this->boolean($DB->areTimezonesAvailable())->isTrue();
        $this->array($DB->getTimezones())->size->isGreaterThan(0);

        //login with default TZ
        $this->login();
        //add a Compuer with creation and update dates
        $comp = new \Computer();
        $cid = $comp->add([
           'name'            => 'Computer with timezone',
           'date_creation'   => '2019-03-04 10:00:00',
           'date_mod'        => '2019-03-04 10:00:00',
           'entities_id'     => 0
        ]);
        $this->integer($cid)->isGreaterThan(0);

        $this->boolean($comp->getFromDB($cid));
        $this->string($comp->fields['date_creation'])->isIdenticalTo('2019-03-04 10:00:00');

        $user = getItemByTypeName('User', TU_USER);
        $this->boolean($user->update(['id' => $user->fields['id'], 'timezone' => 'Europe/Paris']))->isTrue();

        //check tz is set
        $this->boolean($user->getFromDB($user->fields['id']))->isTrue();
        $this->string($user->fields['timezone'])->isIdenticalTo('Europe/Paris');

        $this->login(TU_USER, TU_PASS);
        $this->boolean($comp->getFromDB($cid));
        $this->string($comp->fields['date_creation'])->matches('/2019-03-04 1[12]:00:00/');
    }

    public function testCircularRelation()
    {
        $project = new \Project();
        $project_id_1 = $project->add([
           'name' => 'Project 1',
           'auto_percent_done' => 1
        ]);
        $this->integer((int) $project_id_1)->isGreaterThan(0);
        $project_id_2 = $project->add([
           'name' => 'Project 2',
           'auto_percent_done' => 1,
           'projects_id' => $project_id_1
        ]);
        $this->integer((int) $project_id_2)->isGreaterThan(0);
        $project_id_3 = $project->add([
           'name' => 'Project 3',
           'projects_id' => $project_id_2
        ]);
        $this->integer((int) $project_id_3)->isGreaterThan(0);
        $project_id_4 = $project->add([
           'name' => 'Project 4',
        ]);
        $this->integer((int) $project_id_4)->isGreaterThan(0);

        // This should evaluate as a circular relation
        $this->boolean(\Project::checkCircularRelation($project_id_1, $project_id_3))->isTrue();
        // This should not evaluate as a circular relation
        $this->boolean(\Project::checkCircularRelation($project_id_4, $project_id_3))->isFalse();
    }

    protected function relationConfigProvider()
    {

        return [
           [
              'relation_itemtype' => \Infocom::getType(),
              'config_name'       => 'infocom_types',
           ],
           [
              'relation_itemtype' => \ReservationItem::getType(),
              'config_name'       => 'reservation_types',
           ],
           [
              'relation_itemtype' => \Contract_Item::getType(),
              'config_name'       => 'contract_types',
              'linked_itemtype'   => \Contract::class,
           ],
           [
              'relation_itemtype' => \Document_Item::getType(),
              'config_name'       => 'document_types',
              'linked_itemtype'   => \Document::class,
           ],
           [
              'relation_itemtype' => \KnowbaseItem_Item::getType(),
              'config_name'       => 'kb_types',
              'linked_itemtype'   => \KnowbaseItem::class,
           ],
        ];
    }

    /**
     * @dataProvider relationConfigProvider
     */
    public function testCleanRelationTableBasedOnConfiguredTypes(
        $relation_itemtype,
        $config_name,
        $linked_itemtype = null
    ) {
        global $CFG_GLPI;

        $entity_id = getItemByTypeName('Entity', '_test_root_entity', true);

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        $computer = new \Computer();
        $relation_item = new $relation_itemtype();

        $linked_item_input = [];
        if ($linked_itemtype !== null) {
            $linked_item = new $linked_itemtype();
            $linked_item_id = $linked_item->add(
                [
                  'name'        => 'Linked item',
                  'entities_id' => $entity_id,
            ]
            );
            $this->integer($linked_item_id)->isGreaterThan(0);
            $linked_item_input = [$linked_item->getForeignKeyField() => $linked_item_id];
        }

        // Create computer for which cleaning will be done.
        $computer_1_id = $computer->add(
            [
              'name'        => 'Computer 1',
              'entities_id' => $entity_id,
         ]
        );
        $this->integer($computer_1_id)->isGreaterThan(0);
        $relation_item_1_id = $relation_item->add(
            [
              'itemtype' => $computer->getType(),
              'items_id' => $computer_1_id,
         ] + $linked_item_input
        );
        $this->integer($relation_item_1_id)->isGreaterThan(0);
        $this->boolean($relation_item->getFromDB($relation_item_1_id))->isTrue();

        // Create witness computer.
        $computer_2_id = $computer->add(
            [
              'name'        => 'Computer 2',
              'entities_id' => $entity_id,
         ]
        );
        $this->integer($computer_2_id)->isGreaterThan(0);
        $relation_item_2_id = $relation_item->add(
            [
              'itemtype' => $computer->getType(),
              'items_id' => $computer_2_id,
         ] + $linked_item_input
        );
        $this->integer($relation_item_2_id)->isGreaterThan(0);
        $this->boolean($relation_item->getFromDB($relation_item_2_id))->isTrue();

        $cfg_backup = $CFG_GLPI;
        $CFG_GLPI[$config_name] = [$computer->getType()];
        $computer->delete(['id' => $computer_1_id], true);
        $CFG_GLPI = $cfg_backup;

        // Relation with deleted item has been cleaned
        $this->boolean($relation_item->getFromDB($relation_item_1_id))->isFalse();
        // Relation with witness object is still present
        $this->boolean($relation_item->getFromDB($relation_item_2_id))->isTrue();
    }


    protected function testCheckTemplateEntityProvider()
    {
        $sv1 = getItemByTypeName('SoftwareVersion', '_test_softver_1');

        $sv2 = getItemByTypeName('SoftwareVersion', '_test_softver_1');
        $sv2->fields['entities_id'] = 99999;

        $sv3 = getItemByTypeName('SoftwareVersion', '_test_softver_1');
        $sv3->fields['entities_id'] = 99999;

        return [
           [
              // Case 1: no entites field -> no change
              'data'            => ['test' => "test"],
              'parent_id'       => 999,
              'parent_itemtype' => Software::class,
              'active_entities' => [],
              'expected'        => ['test' => "test"],
           ],
           [
              // Case 2: entity is allowed -> no change
              'data'            => $sv1->fields,
              'parent_id'       => $sv1->fields['softwares_id'],
              'parent_itemtype' => Software::class,
              'active_entities' => [$sv1->fields['entities_id']],
              'expected'        => $sv1->fields,
           ],
           [
              // Case 3: entity is not allowed -> change to parent entity
              'data'            => $sv2->fields, // SV with modified entity
              'parent_id'       => $sv2->fields['softwares_id'],
              'parent_itemtype' => Software::class,
              'active_entities' => [],
              'expected'        => $sv1->fields, // SV with correct entity
           ],
           [
              // Case 4: can't load parent -> no change
              'data'            => $sv3->fields,
              'parent_id'       => -1,
              'parent_itemtype' => Software::class,
              'active_entities' => [],
              'expected'        => $sv3->fields,
           ],
        ];
    }

    /**
     * @dataProvider testCheckTemplateEntityProvider
     */
    public function testCheckTemplateEntity(
        array $data,
        $parent_id,
        $parent_itemtype,
        array $active_entities,
        array $expected
    ) {
        $_SESSION['glpiactiveentities'] = $active_entities;

        $res = \CommonDBTM::checkTemplateEntity($data, $parent_id, $parent_itemtype);
        $this->array($res)->isEqualTo($expected);

        // Reset session
        unset($_SESSION['glpiactiveentities']);
    }

    public function testGetById()
    {
        $itemtype = \Computer::class;

        // test existing item
        $instance = new $itemtype();
        $instance->getFromDBByRequest([
           'WHERE' => ['name' => '_test_pc01'],
        ]);
        $this->boolean($instance->isNewItem())->isFalse();
        $output = $itemtype::getById($instance->getID());
        $this->object($output)->isInstanceOf($itemtype);

        // test non-existing item
        $instance = new $itemtype();
        $instance->add([
           'name' => 'to be deleted',
           'entities_id' => 0,
        ]);
        $this->boolean($instance->isNewItem())->isFalse();
        $nonExistingId = $instance->getID();
        $instance->delete([
           'id' => $nonExistingId,
        ], 1);
        $this->boolean($instance->getFromDB($nonExistingId))->isFalse();

        $output = $itemtype::getById($nonExistingId);
        $this->boolean($output)->isFalse();
    }
}
