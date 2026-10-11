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

use AuthLDAP;
use Closure;
use Computer;
use DBConnection;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Dropdown;
use Entity;
use itsmng\Database\AuthenticationType;
use itsmng\Database\Entity\Computer as ComputerRecord;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\Log as LogRecord;
use itsmng\Database\Entity\User as UserRecord;
use itsmng\Database\Orm;
use itsmng\Database\Repository\HistoryRepository;
use Log as LegacyLog;
use LogicException;
use mock\DBmysql as HistoryAdapter;
use ReflectionProperty;
use Session;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use tests\fixtures\ScalarReadProbe;
use User;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/log.class.php */

class Log extends DbTestCase
{
    public function testOwnedHistoryAppendPreservesActualUpdateAuditAndDirtyOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $computer = $this->createComputer();
            $connection = $DB->getDoctrineConnection();
            $connection->update(
                'glpi_computers',
                ['serial' => 'Before serial', 'otherserial' => 'Before inventory'],
                ['id' => $computer->getID()]
            );
            $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
            $this->boolean($computer->update([
                'id' => $computer->getID(), 'serial' => 'After serial', 'otherserial' => 'After inventory',
            ]))->isTrue();
            $rows = $connection->fetchAllAssociative(
                'SELECT id, id_search_option, old_value, new_value, user_name, date_mod FROM glpi_logs WHERE itemtype=? AND items_id=? ORDER BY id',
                [Computer::class, $computer->getID()]
            );
            $this->array($rows)->hasSize(2);
            $this->array(array_map('intval', array_column($rows, 'id_search_option')))->isIdenticalTo([5, 6]);
            $this->array(array_column($rows, 'old_value'))->isIdenticalTo(['Before serial', 'Before inventory']);
            $this->array(array_column($rows, 'new_value'))->isIdenticalTo(['After serial', 'After inventory']);
            $username = sprintf(__('%1$s (%2$s)'), getUserName(Session::getLoginUserID()), Session::getLoginUserID());
            $this->array(array_column($rows, 'user_name'))->isIdenticalTo([$username, $username]);
            $this->array(array_column($rows, 'date_mod'))->isIdenticalTo([$_SESSION['glpi_currenttime'], $_SESSION['glpi_currenttime']]);
            $this->boolean((int)$rows[1]['id'] > (int)$rows[0]['id'])->isTrue();
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo((int)$rows[1]['id']);
            $independent = Orm::create($DB);
            $dirty = $independent->find(ComputerRecord::class, (int)$computer->getID());
            $dirty->serial = 'Unflushed independent serial';
            Orm::read($DB, function (EntityManager $outer) use ($computer, $connection, $independent, $dirty): void {
                $owned = $outer->find(ComputerRecord::class, (int)$computer->getID());
                $owned->serial = 'Unflushed enclosing serial';
                $id = LegacyLog::history($computer->getID(), Computer::class, [5, 'After serial', 'Audit only']);
                $this->integer($id)->isGreaterThan(0);
                $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($id);
                $this->boolean($outer->contains($owned))->isTrue();
                $this->string($owned->serial)->isIdenticalTo('Unflushed enclosing serial');
                $this->boolean($independent->contains($dirty))->isTrue();
                $this->string($dirty->serial)->isIdenticalTo('Unflushed independent serial');
                $this->string($connection->fetchOne('SELECT serial FROM glpi_computers WHERE id=?', [$computer->getID()]))
                    ->isIdenticalTo('After serial');
                $this->string($connection->fetchOne('SELECT new_value FROM glpi_logs WHERE id=?', [$id]))->isIdenticalTo('Audit only');
            });
        } finally {
            $_SESSION = $session;
        }
    }

    public function testHistoryAssignmentReentryAndFailureKeepCompletedOwnership(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $computer = $this->createComputer();
            $connection = $DB->getDoctrineConnection();
            $kind = new class ($this, $connection, (int)$computer->getID()) {
                public int $nested = 0;

                public function __construct(private object $test, private Connection $connection, private int $id)
                {
                }

                public function __toString(): string
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isTrue();
                    $this->nested = LegacyLog::history($this->id, Computer::class, [6, 'Inner before', 'Inner after']);
                    return Computer::class;
                }
            };
            $id = LegacyLog::history($computer->getID(), $kind, [5, 'Outer before', 'Outer after']);
            $this->integer($kind->nested)->isGreaterThan(0);
            $this->integer($id)->isGreaterThan($kind->nested);
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($id);
            $rows = $connection->fetchAllAssociative(
                'SELECT id, id_search_option, old_value, new_value FROM glpi_logs WHERE itemtype=? AND items_id=? ORDER BY id',
                [Computer::class, $computer->getID()]
            );
            $this->array(array_map('intval', array_column($rows, 'id')))->isIdenticalTo([$kind->nested, $id]);
            $this->array(array_map('intval', array_column($rows, 'id_search_option')))->isIdenticalTo([6, 5]);
            $this->array(array_column($rows, 'old_value'))->isIdenticalTo(['Inner before', 'Outer before']);
            $this->array(array_column($rows, 'new_value'))->isIdenticalTo(['Inner after', 'Outer after']);
            $bad = new class () {

                public function __toString(): string
                {
                    throw new LogicException('History assignment failure');
                }
            };
            $this->exception(static fn () => LegacyLog::history($computer->getID(), $bad, [5, '', 'Rejected']))
                ->isInstanceOf(LogicException::class)->hasMessage('History assignment failure');
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($id);
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_logs WHERE itemtype=? AND items_id=?',
                [Computer::class, $computer->getID()]
            ))
                ->isIdenticalTo(2);
            $flushFailure = new LogicException('History post-insert flush failure');
            $observer = new class ($flushFailure) {
                public ?EntityManager $manager = null;
                public ?int $insertedId = null;

                public function __construct(private LogicException $failure)
                {
                }

                public function postPersist(PostPersistEventArgs $event): void
                {
                    if ($event->getObject() instanceof LogRecord) {
                        $this->manager = $event->getObjectManager();
                        $this->insertedId = $event->getObject()->id;
                        throw $this->failure;
                    }
                }
            };
            Orm::withOperation($connection, function (?EntityManager $manager) use ($observer): void {
                $this->object($manager)->isInstanceOf(EntityManager::class);
                $manager->getEventManager()->addEventListener(['postPersist'], $observer);
            });
            $depth = $connection->getTransactionNestingLevel();
            $this->exception(static fn () => LegacyLog::history($computer->getID(), Computer::class, [5, '', 'Inserted then rejected']))
                ->isIdenticalTo($flushFailure);
            $this->integer($observer->insertedId)->isGreaterThan($id);
            $this->boolean($observer->manager->isOpen())->isFalse();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($id);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE id=?', [$observer->insertedId]))
                ->isIdenticalTo(0);
            $this->array(array_map('intval', $connection->fetchFirstColumn(
                'SELECT id FROM glpi_logs WHERE itemtype=? AND items_id=? ORDER BY id',
                [Computer::class, $computer->getID()]
            )))->isIdenticalTo([$kind->nested, $id]);
            $recovered = LegacyLog::history($computer->getID(), Computer::class, [5, '', 'Recovered']);
            $this->integer($recovered)->isGreaterThan($id);
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($recovered);
            $this->string($connection->fetchOne('SELECT new_value FROM glpi_logs WHERE id=?', [$recovered]))->isIdenticalTo('Recovered');
        } finally {
            $_SESSION = $session;
        }
    }

    public function testCustomHistoryAppendRetainsSelectedRouteAndNoClear(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $computer = $this->createComputer();
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;

                public function prePersist(PrePersistEventArgs $event): void
                {
                    if ($event->getObject() instanceof LogRecord) {
                        $this->trace[] = 'persist';
                        $event->getObject()->new_value = 'Custom lifecycle value';
                    }
                }

                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            // Delegate the owning flush/savepoint to the existing fixture connection.
            $selected = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;

                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }

                public function prepare(string $sql): Statement
                {
                    return $this->selected->prepare($sql);
                }

                public function lastInsertId(): int|string
                {
                    return $this->selected->lastInsertId();
                }

                public function beginTransaction(): void
                {
                    $this->selected->beginTransaction();
                }

                public function commit(): void
                {
                    $this->selected->commit();
                }

                public function rollBack(): void
                {
                    $this->selected->rollBack();
                }

                public function getTransactionNestingLevel(): int
                {
                    return $this->selected->getTransactionNestingLevel();
                }

                public function isTransactionActive(): bool
                {
                    return $this->selected->isTransactionActive();
                }
            };
            $selected->observer = $observer;
            $selected->events = new EventManager();
            $selected->events->addEventListener(['prePersist', 'onClear'], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new HistoryAdapter();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route): Connection {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $kind = new class ($observer, $other, $route) {

                public function __construct(private object $observer, private Connection $other, private Connection &$route)
                {
                }

                public function __toString(): string
                {
                    $this->observer->trace[] = 'converted';
                    $this->route = $this->other;
                    return Computer::class;
                }
            };
            $level = $connection->getTransactionNestingLevel();
            $id = LegacyLog::history($computer->getID(), $kind, [5, 'Custom before', 'Custom after']);
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'converted', 'persist']);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->integer($_SESSION['glpi_maxhistory'])->isIdenticalTo($id);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            $this->array($other->queries)->isEmpty();
            $this->array($connection->fetchAssociative('SELECT old_value, new_value FROM glpi_logs WHERE id=?', [$id]))
                ->isIdenticalTo(['old_value' => 'Custom before', 'new_value' => 'Custom lifecycle value']);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testReplicationMaximumKeepsEmptyDisconnectedAndSuppliedRoute(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $this->integer($connection->getTransactionNestingLevel())->isGreaterThan(0);
        // DbTestCase rolls back this transaction; do not truncate or commit the log.
        $connection->executeStatement('DELETE FROM glpi_logs');
        $this->variable(DBConnection::getHistoryMaxDate($DB))->isNull();
        $connection->insert('glpi_logs', ['itemtype' => 'Replication probe', 'date_mod' => '2030-01-01 12:00:00']);
        $expected = $connection->fetchOne('SELECT ' . $DB->expressions()->epoch('MAX(date_mod)') . ' FROM glpi_logs');
        $this->variable(DBConnection::getHistoryMaxDate($DB))->isEqualTo($expected);
        $connection->update('glpi_logs', ['date_mod' => '2030-01-02 12:00:00'], ['itemtype' => 'Replication probe']);
        $later = $connection->fetchOne('SELECT ' . $DB->expressions()->epoch('MAX(date_mod)') . ' FROM glpi_logs');
        $this->variable(DBConnection::getHistoryMaxDate($DB))->isEqualTo($later);
        $this->boolean((float)$later > (float)$expected)->isTrue();
        $probe = new ScalarReadProbe($connection);
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new HistoryAdapter();
        $getters = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, &$getters): Connection {
            ++$getters;
            return $probe;
        };
        $adapter->connected = false;
        $this->variable(DBConnection::getHistoryMaxDate($adapter))->isIdenticalTo(0);
        $this->integer($getters)->isIdenticalTo(0);
        $adapter->connected = true;
        $this->variable(DBConnection::getHistoryMaxDate($adapter))->isEqualTo($later);
        $this->integer($getters)->isIdenticalTo(1);
        $this->array($probe->queries)->hasSize(1);
        // A route supplied explicitly remains authoritative even with slave semantics.
        $adapter->slave = true;
        $this->variable(DBConnection::getHistoryMaxDate($adapter))->isEqualTo($later);
        $this->integer($getters)->isIdenticalTo(2);
        $this->array($probe->queries)->hasSize(2);
    }

    public function testNullableUserAssignmentKeepsLegacyAuditLabels(): void
    {
        global $DB;

        $this->login();
        $computer = $this->createComputer();
        $connection = $DB->getDoctrineConnection();
        $parameters = ['id' => (int) $computer->getID()];
        $types = ['id' => ParameterType::INTEGER];
        $this->variable($connection->fetchOne(
            'SELECT users_id FROM glpi_computers WHERE id = :id',
            $parameters,
            $types
        ))->isNull();

        $userId = Session::getLoginUserID();
        $this->boolean($computer->update(['id' => $computer->getID(), 'users_id' => $userId]))->isTrue();
        $this->boolean($computer->update(['id' => $computer->getID(), 'users_id' => 0]))->isTrue();
        $this->variable($connection->fetchOne(
            'SELECT users_id FROM glpi_computers WHERE id = :id',
            $parameters,
            $types
        ))->isNull();

        $history = $connection->fetchAllAssociative(
            "SELECT old_value, new_value FROM glpi_logs
             WHERE itemtype = 'Computer' AND items_id = :id AND id_search_option = 70
             ORDER BY id",
            $parameters,
            $types
        );
        $userLabel = sprintf('%s (%s)', Dropdown::getDropdownName('glpi_users', $userId), $userId);
        $this->array($history)->isIdenticalTo([
            ['old_value' => '&nbsp; (0)', 'new_value' => $userLabel],
            ['old_value' => $userLabel, 'new_value' => '&nbsp; (0)'],
        ]);
    }

    public function testHistoryTabCountsOnlySavedItemsIncludingRootEntity(): void
    {
        global $DB;
        $previous = $_SESSION['glpishow_count_on_tabs'] ?? null;
        $_SESSION['glpishow_count_on_tabs'] = 1;
        try {
            $history = new LegacyLog();
            foreach ([new AuthLDAP(), new Computer()] as $item) {
                foreach (['', -1] as $id) {
                    $item->fields['id'] = $id;
                    $this->string($history->getTabNameForItem($item))->isIdenticalTo('Historical');
                }
            }

            $computer = $this->createComputer();
            $this->createLogEntry($computer, []);
            $this->string($history->getTabNameForItem($computer))
                ->isIdenticalTo("Historical <sup class='tab_nb'>1</sup>");
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $connection = $DB->getDoctrineConnection();
            // Borrow configuration only; no manager escapes its application scope.
            $configuration = Orm::withReadConnection($connection, static fn (?EntityManager $manager) => $manager->getConfiguration());
            $originalCache = $configuration->getQueryCache();
            $queryCache = new TraceableAdapter(new ArrayAdapter(storeSerialized: true));
            $configuration->setQueryCache($queryCache);
            try {
                $beforeFactories = $factories->getValue();
                for ($repeat = 0; $repeat < 3; ++$repeat) {
                    $this->string($history->getTabNameForItem($computer))
                        ->isIdenticalTo("Historical <sup class='tab_nb'>1</sup>");
                }
                $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
                $this->array($queryCache->getCalls())->isEmpty();
                $this->createLogEntry($computer, []);
                $this->string($history->getTabNameForItem($computer))
                    ->isIdenticalTo("Historical <sup class='tab_nb'>2</sup>");

                $queryCache->clearCalls();
                $count = Orm::withReadConnection($connection, static fn (?EntityManager $manager): int =>
                    (new HistoryRepository($manager))->count(['itemtype' => 'Computer', 'items_id' => $computer->getID()]));
                $this->integer($count)->isIdenticalTo(2);
                $this->array($queryCache->getCalls())->isNotEmpty();

                $hints = $configuration->getDefaultQueryHints();
                $configuration->setDefaultQueryHint(Query::HINT_READ_ONLY, true);
                try {
                    $queryCache->clearCalls();
                    $this->string($history->getTabNameForItem($computer))
                        ->isIdenticalTo("Historical <sup class='tab_nb'>2</sup>");
                    $this->array($queryCache->getCalls())->isNotEmpty();
                } finally {
                    $configuration->setDefaultQueryHints($hints);
                }

                Orm::withReadConnection($connection, static function (?EntityManager $manager): void {
                    $filter = new class ($manager) extends SQLFilter {
                        public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
                        {
                            return $targetEntity->name === LogRecord::class ? '1 = 0' : '';
                        }
                    };
                    $manager->getConfiguration()->addFilter('history_count_empty', $filter::class);
                    $manager->getFilters()->enable('history_count_empty');
                });
                try {
                    $queryCache->clearCalls();
                    $this->string($history->getTabNameForItem($computer))->isIdenticalTo('Historical');
                    $this->array($queryCache->getCalls())->isNotEmpty();
                } finally {
                    Orm::withReadConnection($connection, static function (?EntityManager $manager): void {
                        $manager->getFilters()->disable('history_count_empty');
                    });
                }

                Orm::withReadConnection($connection, function (?EntityManager $manager) use ($history, $computer, $factories): void {
                    $sentinel = $manager->getReference(LogRecord::class, 0);
                    $beforeFactories = $factories->getValue();
                    $this->string($history->getTabNameForItem($computer))
                        ->isIdenticalTo("Historical <sup class='tab_nb'>2</sup>");
                    $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(1);
                    $this->boolean($manager->contains($sentinel))->isTrue();
                });
            } finally {
                $configuration->setQueryCache($originalCache);
            }

            $originalAdapter = $DB;
            $readPreference = $GLOBALS['CFG_GLPI']['use_slave_for_search'];
            $events = new EventManager();
            $clears = new class () {
                public int $count = 0;

                public function onClear(): void
                {
                    ++$this->count;
                }
            };
            $events->addEventListener(['onClear'], $clears);
            $probe = new class ($DB->getDoctrineConnection(), $events) extends Connection {
                public int $queries = 0;

                public function __construct(private Connection $selected, private EventManager $events)
                {
                    parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
                }

                public function getDatabasePlatform(): AbstractPlatform
                {
                    return $this->selected->getDatabasePlatform();
                }

                public function getEventManager(): EventManager
                {
                    return $this->events;
                }

                public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
                {
                    ++$this->queries;
                    return $this->selected->executeQuery($sql, $params, $types, $qcp);
                }
            };
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new HistoryAdapter();
            $getters = 0;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, &$getters): Connection {
                ++$getters;
                return $probe;
            };
            $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
            $callbackItem = new class () extends Computer {
                public Closure $identityCallback;
                public static ?Closure $typeCallback = null;

                public function getID()
                {
                    ($this->identityCallback)();
                    return parent::getID();
                }

                public static function getType()
                {
                    if (self::$typeCallback !== null) {
                        (self::$typeCallback)();
                    }
                    return 'Computer';
                }
            };
            $callbackItem->fields = $computer->fields;
            $callbackItem->identityCallback = static function () use ($adapter): void {
                $GLOBALS['DB'] = $adapter;
            };
            $beforeFactories = $factories->getValue();
            $atType = [];
            $callbackItem::$typeCallback = static function () use ($originalAdapter, $factories, $probe, &$getters, &$atType): void {
                $atType = [$factories->getValue(), $getters, $probe->queries];
                $GLOBALS['DB'] = $originalAdapter;
            };
            try {
                $GLOBALS['CFG_GLPI']['use_slave_for_search'] = false;
                $this->string($history->getTabNameForItem($callbackItem))
                    ->isIdenticalTo("Historical <sup class='tab_nb'>2</sup>");
                $this->array($atType)->isIdenticalTo([$beforeFactories + 1, 1, 0]);
                $this->integer($probe->queries)->isIdenticalTo(1);
                $this->integer($clears->count)->isIdenticalTo(0);
                $this->object($DB)->isIdenticalTo($originalAdapter);
            } finally {
                $callbackItem::$typeCallback = null;
                $DB = $originalAdapter;
                $GLOBALS['CFG_GLPI']['use_slave_for_search'] = $readPreference;
            }

            $root = new Entity();
            $this->boolean($root->getFromDB(0))->isTrue();
            $count = (int)$DB->getDoctrineConnection()->fetchOne(
                'SELECT COUNT(*) FROM glpi_logs WHERE itemtype = ? AND items_id = ?',
                ['Entity', 0]
            );
            $this->createLogEntry($root, []);
            $this->string($history->getTabNameForItem($root))
                ->isIdenticalTo("Historical <sup class='tab_nb'>" . ($count + 1) . '</sup>');

            $_SESSION['glpishow_count_on_tabs'] = 0;
            $this->string($history->getTabNameForItem($computer))->isIdenticalTo('Historical');
        } finally {
            if ($previous === null) {
                unset($_SESSION['glpishow_count_on_tabs']);
            } else {
                $_SESSION['glpishow_count_on_tabs'] = $previous;
            }
        }
    }

    public function testHistoryDataReusesReadScopeOutsideFormatting(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $readPreference = $GLOBALS['CFG_GLPI']['use_slave_for_search'];
        $connection = $DB->getDoctrineConnection();
        $this->integer($connection->getTransactionNestingLevel())->isGreaterThan(0);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        try {
            $GLOBALS['CFG_GLPI']['use_slave_for_search'] = false;
            $_SESSION['glpinames_format'] = User::FIRSTNAME_BEFORE;
            $_SESSION['glpiis_ids_visible'] = 0;
            $computer = $this->createComputer();
            $manager = Orm::create($DB);
            $user = new UserRecord();
            $user->entities = $manager->getReference(EntityRecord::class, (int)$computer->fields['entities_id']);
            $user->name = 'history-reader-' . bin2hex(random_bytes(6));
            $user->firstname = 'Ada';
            $user->realname = 'History';
            $manager->persist($user);
            $manager->flush();
            $manager->clear();
            $logs = [];
            $logs[] = $this->createLogEntry($computer, [
                'id_search_option' => 1, 'old_value' => 'Old name', 'new_value' => 'New name',
            ]);
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $logs[] = $this->createLogEntry($computer, [
                    'id_search_option' => 70, 'old_value' => $user->name . ' (1)', 'new_value' => $user->name . ' (2)',
                ]);
            }
            $this->createLogEntry($computer, ['user_name' => 'excluded']);
            $this->createLogEntry($this->createComputer(), ['user_name' => 'someuser']);
            $filters = LegacyLog::convertFiltersValuesToSqlCriteria(['users_names' => ['someuser']]);
            $options = ['sort' => 'id', 'order' => 'ASC'];
            $rows = LegacyLog::getHistoryData($computer, 0, 0, $filters, $options);
            $this->array(array_column($rows, 'id'))->isIdenticalTo(array_map(static fn ($log): int => (int)$log->getID(), $logs));
            $this->string($rows[1]['change'])->isIdenticalTo('Change Ada History (1) to Ada History (2)');
            $this->string($rows[2]['change'])->isIdenticalTo($rows[1]['change']);
            $beforeFactories = $factories->getValue();
            $this->integer(LegacyLog::countForItem($computer, $filters))->isIdenticalTo(3);
            $page = LegacyLog::getHistoryData($computer, 1, 1, $filters, $options);
            $this->array($page)->isIdenticalTo([$rows[1]]);
            $this->array(LegacyLog::getHistoryData($computer, 0, 1, $filters, ['sort' => 'user_name', 'order' => 'DESC']))
                ->isIdenticalTo([$rows[2]]);
            $this->array(LegacyLog::getHistoryData($computer, 0, 0, ['user_name' => 'absent'], $options))->isEmpty();
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
            $connection->update('glpi_users', ['firstname' => 'Grace'], ['id' => $user->id]);
            $connection->update('glpi_logs', ['new_value' => $user->name . ' (3)'], ['id' => $logs[2]->getID()]);
            $fresh = LegacyLog::getHistoryData($computer, 0, 0, $filters, $options);
            $this->string($fresh[1]['change'])->isIdenticalTo('Change Grace History (1) to Grace History (2)');
            $this->string($fresh[2]['change'])->isIdenticalTo('Change Grace History (1) to Grace History (3)');
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);

            $item = new class () extends Computer {
                public Closure $formatCallback;

                public static function getType()
                {
                    return 'Computer';
                }

                public static function getTable($classname = null)
                {
                    return 'glpi_computers';
                }

                public function getValueToDisplay($field_id_or_search_options, $values, $options = [])
                {
                    ($this->formatCallback)();
                    return parent::getValueToDisplay($field_id_or_search_options, $values, $options);
                }
            };
            $item->fields = $computer->fields;
            $formats = 0;
            // Formatting may write between the row query and the following username lookup.
            $item->formatCallback = function () use ($connection, $user, &$formats): void {
                ++$formats;
                $connection->update('glpi_users', ['firstname' => 'Callback'], ['id' => $user->id]);
                $this->integer(Orm::withReadConnection($connection, static fn (?EntityManager $manager): int => $manager->getUnitOfWork()->size()))
                    ->isIdenticalTo(0);
            };
            $formatted = LegacyLog::getHistoryData($item, 0, 0, $filters, $options);
            $this->string($formatted[1]['change'])->isIdenticalTo('Change Callback History (1) to Callback History (2)');
            $this->string($formatted[2]['change'])->isIdenticalTo('Change Callback History (1) to Callback History (3)');
            $this->integer($formats)->isIdenticalTo(2);
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);

            // Formatting can replace a live SQL converter after the eager reader selection.
            $registry = DbalType::getTypeRegistry();
            $originalString = $registry->get(Types::STRING);
            $customString = new class () extends StringType {
                public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return $sqlExpr;
                }
            };
            $formats = 0;
            $item->formatCallback = static function () use ($registry, $customString, &$formats): void {
                ++$formats;
                $registry->override(Types::STRING, $customString);
            };
            try {
                $this->array(LegacyLog::getHistoryData($item, 0, 0, $filters, $options))->isIdenticalTo($formatted);
                $this->integer($formats)->isIdenticalTo(2);
            } finally {
                $registry->override(Types::STRING, $originalString);
            }

            // Canonical scopes must still hydrate records when a postLoad listener owns their labels.
            $labelListener = new class () {
                public int $users = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof UserRecord) {
                        ++$this->users;
                        $event->getObject()->firstname = 'Loaded';
                    }
                }
            };
            Orm::withReadConnection($connection, static function (EntityManager $manager) use ($labelListener): void {
                $manager->getEventManager()->addEventListener(['postLoad'], $labelListener);
            });
            try {
                $loaded = LegacyLog::getHistoryData($computer, 0, 0, $filters, $options);
                $this->string($loaded[1]['change'])->isIdenticalTo('Change Loaded History (1) to Loaded History (2)');
                $this->string($loaded[2]['change'])->isIdenticalTo('Change Loaded History (1) to Loaded History (3)');
                $this->integer($labelListener->users)->isIdenticalTo(4);
            } finally {
                Orm::withReadConnection($connection, static function (EntityManager $manager) use ($labelListener): void {
                    $manager->getEventManager()->removeEventListener(['postLoad'], $labelListener);
                });
            }

            // Custom readers keep both eager managers and the second selected physical route.
            $events = new EventManager();
            $listener = new class () {
                public int $clears = 0;
                public int $users = 0;

                public function onClear(): void
                {
                    ++$this->clears;
                }

                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof UserRecord) {
                        ++$this->users;
                    }
                }
            };
            $events->addEventListener(['onClear', 'postLoad'], $listener);
            $historyConnection = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;

                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $historyConnection->events = $events;
            $userConnection = clone $historyConnection;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new HistoryAdapter();
            $getters = 0;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($historyConnection, $userConnection, &$getters): Connection {
                return ++$getters === 1 ? $historyConnection : $userConnection;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $atFormatting = [];
            $beforeFactories = $factories->getValue();
            $item->formatCallback = static function () use ($original, $factories, $historyConnection, $userConnection, &$getters, &$atFormatting): void {
                $atFormatting[] = [
                    $factories->getValue(),
                    $getters,
                    count($historyConnection->queries),
                    count($userConnection->queries),
                ];
                $GLOBALS['DB'] = $original;
            };
            $DB = $adapter;
            $this->array(LegacyLog::getHistoryData($item, 0, 0, $filters, $options))->isIdenticalTo($formatted);
            $this->array($atFormatting)->isIdenticalTo(array_fill(0, 2, [$beforeFactories + 2, 2, 1, 0]));
            $this->integer($getters)->isIdenticalTo(2);
            $this->array($historyConnection->queries)->hasSize(1);
            $this->array($userConnection->queries)->hasSize(4);
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(2);
            $this->integer($listener->users)->isIdenticalTo(1);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $this->object($DB)->isIdenticalTo($original);

            // Duplicate logins remain ordered by ID; missing and nullable names retain their fallback labels.
            $manager = Orm::create($DB);
            try {
                $duplicate = new UserRecord();
                $duplicate->entities = $manager->getReference(EntityRecord::class, (int)$computer->fields['entities_id']);
                $duplicate->name = $user->name;
                $duplicate->authtype = AuthenticationType::Local->value;
                $duplicate->firstname = 'Last';
                $duplicate->realname = 'Duplicate';
                $manager->persist($duplicate);
                $manager->flush();
            } finally {
                $manager->clear();
            }
            $missing = 'history-missing-' . bin2hex(random_bytes(6));
            $edge = $this->createLogEntry($computer, [
                'user_name' => 'history-label-edge', 'id_search_option' => 70,
                'old_value' => $missing . ' (7)', 'new_value' => $user->name . ' (9)',
            ]);
            $edgeFilter = ['id' => (int)$edge->getID()];
            $labels = LegacyLog::getHistoryData($computer, 0, 0, $edgeFilter, $options);
            $this->string($labels[0]['change'])->isIdenticalTo('Change ' . $missing . ' (7) to Last Duplicate (9)');
            $connection->update('glpi_users', ['firstname' => null, 'realname' => null], ['id' => $duplicate->id]);
            $labels = LegacyLog::getHistoryData($computer, 0, 0, $edgeFilter, $options);
            $this->string($labels[0]['change'])->isIdenticalTo('Change ' . $missing . ' (7) to ' . $user->name . ' (9)');
        } finally {
            $DB = $original;
            $_SESSION = $session;
            $GLOBALS['CFG_GLPI']['use_slave_for_search'] = $readPreference;
        }
    }

    private function createComputer()
    {
        $computer = new \Computer();
        $this->integer(
            (int)$computer->add(['entities_id' => getItemByTypeName('Entity', '_test_root_entity', true)], [], false)
        )->isGreaterThan(0);
        return $computer;
    }

    private function createLogEntry(
        \CommonDBTM $item,
        $log_data
    ) {
        $log_data = array_merge(
            [
              'items_id'         => $item->fields['id'],
              'itemtype'         => $item->getType(),
              'itemtype_link'    => '',
              'linked_action'    => 0,
              'user_name'        => 'someuser',
              'date_mod'         => date('Y-m-d H:i:s'),
              'id_search_option' => 0,
              'old_value'        => '',
              'new_value'        => '',
         ],
            $log_data
        );
        unset($log_data['date_creation']);
        unset($log_data['date_mod']);

        $log = new \Log();
        $this->integer((int)$log->add($log_data))->isGreaterThan(0);

        return $log;
    }

    public function testGetDistinctUserNamesValuesInItemLog()
    {
        $computer = $this->createComputer();

        $user_names = ['Huey', 'Dewey', 'Louie', 'Phooey'];

        // Add at least one item per user
        foreach ($user_names as $user_name) {
            $this->createLogEntry(
                $computer,
                [
                  'linked_action' => \Log::HISTORY_LOG_SIMPLE_MESSAGE,
                  'user_name'     => $user_name,
            ]
            );
        }

        // Add 10 items affected randomly to users
        for ($i = 0; $i < 10; $i++) {
            $this->createLogEntry(
                $computer,
                [
                  'linked_action' => \Log::HISTORY_LOG_SIMPLE_MESSAGE,
                  'user_name'     => $user_names[array_rand($user_names)],
            ]
            );
        }

        $expected_user_names = ['Dewey', 'Huey', 'Louie', 'Phooey'];
        $expected_result = array_combine($expected_user_names, $expected_user_names);

        $this->array(\Log::getDistinctUserNamesValuesInItemLog($computer))->isIdenticalTo($expected_result);
    }

    protected function dataLogToAffectedField()
    {
        $item_related_linked_action_values = implode(
            ',',
            [
              \Log::HISTORY_ADD_DEVICE,
              \Log::HISTORY_DELETE_DEVICE,
              \Log::HISTORY_LOCK_DEVICE,
              \Log::HISTORY_UNLOCK_DEVICE,
              \Log::HISTORY_DISCONNECT_DEVICE,
              \Log::HISTORY_CONNECT_DEVICE,
              \Log::HISTORY_ADD_RELATION,
              \Log::HISTORY_UPDATE_RELATION,
              \Log::HISTORY_DEL_RELATION,
              \Log::HISTORY_LOCK_RELATION,
              \Log::HISTORY_UNLOCK_RELATION,
              \Log::HISTORY_ADD_SUBITEM,
              \Log::HISTORY_UPDATE_SUBITEM,
              \Log::HISTORY_DELETE_SUBITEM,
              \Log::HISTORY_LOCK_SUBITEM,
              \Log::HISTORY_UNLOCK_SUBITEM,
         ]
        );
        $device_related_type_link = 'Item_DeviceHardDrive';
        $device_related_key = 'linked_action::' . $item_related_linked_action_values . ';itemtype_link::Item_DeviceHardDrive;';
        $device_related_value = 'Item - Hard drive link';

        $relation_related_type_link = 'Monitor';
        $relation_related_key = 'linked_action::' . $item_related_linked_action_values . ';itemtype_link::Monitor;';
        $relation_related_value = 'Monitor';

        $sub_item_related_type_link = 'NetworkPort';
        $sub_item_related_key = 'linked_action::' . $item_related_linked_action_values . ';itemtype_link::NetworkPort;';
        $sub_item_related_value = 'Network port';

        $software_related_linked_action_values = implode(
            ',',
            [
              \Log::HISTORY_INSTALL_SOFTWARE,
              \Log::HISTORY_UNINSTALL_SOFTWARE,
         ]
        );
        $software_related_key = 'linked_action::' . $software_related_linked_action_values . ';';
        $software_related_value = 'Software';

        $others_linked_action_values_to_exclude = implode(
            ',',
            [
              0,
              \Log::HISTORY_ADD_DEVICE,
              \Log::HISTORY_DELETE_DEVICE,
              \Log::HISTORY_LOCK_DEVICE,
              \Log::HISTORY_UNLOCK_DEVICE,
              \Log::HISTORY_DISCONNECT_DEVICE,
              \Log::HISTORY_CONNECT_DEVICE,
              \Log::HISTORY_ADD_RELATION,
              \Log::HISTORY_UPDATE_RELATION,
              \Log::HISTORY_DEL_RELATION,
              \Log::HISTORY_LOCK_RELATION,
              \Log::HISTORY_UNLOCK_RELATION,
              \Log::HISTORY_ADD_SUBITEM,
              \Log::HISTORY_UPDATE_SUBITEM,
              \Log::HISTORY_DELETE_SUBITEM,
              \Log::HISTORY_LOCK_SUBITEM,
              \Log::HISTORY_UNLOCK_SUBITEM,
              \Log::HISTORY_UPDATE_DEVICE,
              \Log::HISTORY_INSTALL_SOFTWARE,
              \Log::HISTORY_UNINSTALL_SOFTWARE,
         ]
        );
        $others_key = 'linked_action:NOT:' . $others_linked_action_values_to_exclude . ';';
        $others_value = 'Others';

        return [
           [
              [
                 'linked_action' => \Log::HISTORY_ADD_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UPDATE_DEVICE,
                 'itemtype_link' => 'Item_DeviceHardDrive#capacity',
              ],
              [
                 'linked_action::' . \Log::HISTORY_UPDATE_DEVICE . ';itemtype_link::Item_DeviceHardDrive#capacity;' => 'DeviceHardDrive (Capacity)',
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_DELETE_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_INSTALL_SOFTWARE,
              ],
              [
                 $software_related_key => $software_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UNINSTALL_SOFTWARE,
              ],
              [
                 $software_related_key => $software_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_DISCONNECT_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_CONNECT_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_LOCK_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UNLOCK_DEVICE,
                 'itemtype_link' => $device_related_type_link,
              ],
              [
                 $device_related_key => $device_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_LOG_SIMPLE_MESSAGE,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_DELETE_ITEM,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_RESTORE_ITEM,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_ADD_RELATION,
                 'itemtype_link' => $relation_related_type_link,
              ],
              [
                 $relation_related_key => $relation_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_DEL_RELATION,
                 'itemtype_link' => $relation_related_type_link,
              ],
              [
                 $relation_related_key => $relation_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_ADD_SUBITEM,
                 'itemtype_link' => $sub_item_related_type_link,
              ],
              [
                 $sub_item_related_key => $sub_item_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UPDATE_SUBITEM,
                 'itemtype_link' => $sub_item_related_type_link,
              ],
              [
                 $sub_item_related_key => $sub_item_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_DELETE_SUBITEM,
                 'itemtype_link' => $sub_item_related_type_link,
              ],
              [
                 $sub_item_related_key => $sub_item_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_CREATE_ITEM,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UPDATE_RELATION,
                 'itemtype_link' => $relation_related_type_link,
              ],
              [
                 $relation_related_key => $relation_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_LOCK_RELATION,
                 'itemtype_link' => $relation_related_type_link,
              ],
              [
                 $relation_related_key => $relation_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_LOCK_SUBITEM,
                 'itemtype_link' => $sub_item_related_type_link,
              ],
              [
                 $sub_item_related_key => $sub_item_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UNLOCK_RELATION,
                 'itemtype_link' => $relation_related_type_link,
              ],
              [
                 $relation_related_key => $relation_related_value
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UNLOCK_SUBITEM,
                 'itemtype_link' => $sub_item_related_type_link,
              ],
              [
                 $sub_item_related_key => $sub_item_related_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_LOCK_ITEM,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_UNLOCK_ITEM,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_PLUGIN,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
           [
              [
                 'linked_action' => \Log::HISTORY_PLUGIN + 1,
              ],
              [
                 $others_key => $others_value,
              ]
           ],
        ];
    }

    /**
     * @dataProvider dataLogToAffectedField
     */
    public function testValuesComputationForGetDistinctAffectedFieldValuesInItemLog($log_data, $expected_result)
    {
        $computer = $this->createComputer();

        $this->createLogEntry($computer, $log_data);

        $this->array(\Log::getDistinctAffectedFieldValuesInItemLog($computer))->isIdenticalTo($expected_result);
    }

    public function testValuesSortInGetDistinctAffectedFieldValuesInItemLog()
    {
        $computer = $this->createComputer();

        foreach ($this->dataLogToAffectedField() as $data) {
            $this->createLogEntry($computer, $data[0]);
        }

        $result = \Log::getDistinctAffectedFieldValuesInItemLog($computer);

        $previous_value = null;
        foreach ($result as $key => $value) {
            if (null !== $previous_value) {
                $this->boolean('Others' === $value || strcmp($previous_value, (string) $value) < 0)->isTrue();
            }

            $previous_value = $value;
        }
    }

    protected function dataLinkedActionLabel()
    {
        return [
           [0, null],
           [\Log::HISTORY_ADD_DEVICE, __('Add a component')],
           [\Log::HISTORY_UPDATE_DEVICE, __('Change a component')],
           [\Log::HISTORY_DELETE_DEVICE, __('Delete a component')],
           [\Log::HISTORY_INSTALL_SOFTWARE, __('Install a software')],
           [\Log::HISTORY_UNINSTALL_SOFTWARE, __('Uninstall a software')],
           [\Log::HISTORY_DISCONNECT_DEVICE, __('Disconnect an item')],
           [\Log::HISTORY_CONNECT_DEVICE, __('Connect an item')],
           [\Log::HISTORY_LOCK_DEVICE, __('Lock a component')],
           [\Log::HISTORY_UNLOCK_DEVICE, __('Unlock a component')],
           [\Log::HISTORY_LOG_SIMPLE_MESSAGE, null],
           [\Log::HISTORY_DELETE_ITEM, __('Delete the item')],
           [\Log::HISTORY_RESTORE_ITEM, __('Restore the item')],
           [\Log::HISTORY_ADD_RELATION, __('Add a link with an item')],
           [\Log::HISTORY_DEL_RELATION, __('Delete a link with an item')],
           [\Log::HISTORY_ADD_SUBITEM, __('Add an item')],
           [\Log::HISTORY_UPDATE_SUBITEM, __('Update an item')],
           [\Log::HISTORY_DELETE_SUBITEM, __('Delete an item')],
           [\Log::HISTORY_CREATE_ITEM, __('Add the item')],
           [\Log::HISTORY_UPDATE_RELATION, __('Update a link with an item')],
           [\Log::HISTORY_LOCK_RELATION, __('Lock a link with an item')],
           [\Log::HISTORY_LOCK_SUBITEM, __('Lock an item')],
           [\Log::HISTORY_UNLOCK_RELATION, __('Unlock a link with an item')],
           [\Log::HISTORY_UNLOCK_SUBITEM, __('Unlock an item')],
           [\Log::HISTORY_LOCK_ITEM, __('Lock the item')],
           [\Log::HISTORY_UNLOCK_ITEM, __('Unlock the item')],
           [\Log::HISTORY_PLUGIN, null],
           [\Log::HISTORY_PLUGIN + 1, null],
        ];
    }

    /**
     * @dataProvider dataLinkedActionLabel
     */
    public function testGetLinkedActionLabel($linked_action, $expected_label)
    {
        $this->variable(\Log::getLinkedActionLabel($linked_action))->isIdenticalTo($expected_label);
    }

    /**
     * @dataProvider dataLinkedActionLabel
     */
    public function testValuesComputationForGetDistinctLinkedActionValuesInItemLog($linked_action, $expected_value)
    {
        $computer = $this->createComputer();

        $this->createLogEntry($computer, ['linked_action' => $linked_action]);

        $expected_key = $linked_action;
        if (0 === $linked_action) {
            //Special case for field update
            $expected_value = __('Update a field');
        } elseif (null === $expected_value) {
            //Null values fallbacks to 'Others'.
            $expected_key = 'other';
            $expected_value = __('Others');
        }

        $this->array(\Log::getDistinctLinkedActionValuesInItemLog($computer))
           ->isIdenticalTo([$expected_key => $expected_value]);
    }

    public function testValuesSortInGetDistinctLinkedActionValuesInItemLog()
    {
        $computer = $this->createComputer();

        foreach ($this->dataLinkedActionLabel() as $data) {
            $this->createLogEntry($computer, ['linked_action' => $data[0]]);
        }

        $result = \Log::getDistinctLinkedActionValuesInItemLog($computer);

        $previous_value = null;
        foreach ($result as $key => $value) {
            if (null !== $previous_value) {
                $this->boolean('Others' === $value || strcmp($previous_value, (string) $value) < 0)->isTrue();
            }

            $previous_value = $value;
        }
    }

    protected function dataFiltersValuesToSqlCriteria()
    {
        return [
           [
              [
                 'affected_fields' => ['linked_action::35;'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'linked_action' => [35],
                       ]
                    ]
                 ]
              ]
           ],
           [
              [
                 'affected_fields' => ['id_search_option:NOT:0;'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'NOT' => [
                             'id_search_option' => [0],
                          ]
                       ]
                    ]
                 ]
              ]
           ],
           [
              [
                 'affected_fields' => ['linked_action::1,5,42;itemtype_link::Computer;'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'linked_action' => [1, 5, 42],
                          'itemtype_link' => ['Computer'],
                       ]
                    ]
                 ]
              ]
           ],
           [
              [
                 'affected_fields' => ['id_search_option::24;', 'linked_action:NOT:35;itemtype_link::Monitor;'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'id_search_option' => [24],
                       ],
                       [
                          'NOT' => [
                             'linked_action' => [35],
                          ],
                          'itemtype_link' => ['Monitor'],
                       ]
                    ]
                 ]
              ]
           ],
           [
              [
                 'date' => '2018-04-22',
              ],
              [
                 [
                    ['date_mod' => ['>=', '2018-04-22 00:00:00']],
                    ['date_mod' => ['<=', '2018-04-22 23:59:59']],
                 ]
              ]
           ],
           [
              [
                 'linked_actions' => [3],
              ],
              [
                 [
                    'OR' => [
                       [
                          'linked_action' => 3,
                       ],
                    ],
                 ]
              ]
           ],
           [
              [
                 'linked_actions' => [1, 25, 47],
              ],
              [
                 [
                    'OR' => [
                       [
                          'linked_action' => 1,
                       ],
                       [
                          'linked_action' => 25,
                       ],
                       [
                          'linked_action' => 47,
                       ]
                    ],
                 ]
              ]
           ],
           [
              [
                 'linked_actions' => ['other'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'linked_action' => \Log::HISTORY_LOG_SIMPLE_MESSAGE,
                       ],
                       [
                          'linked_action' => ['>=', \Log::HISTORY_PLUGIN],
                       ]
                    ],
                 ]
              ]
           ],
           [
              [
                 'users_names' => ['user1']
              ],
              [
                 'user_name' => ['user1']
              ]
           ],
           [
              [
                 'users_names' => ['user1', 'glpi', 'noone']
              ],
              [
                 'user_name' => ['user1', 'glpi', 'noone']
              ]
           ],
           [
              [
                 'affected_fields' => ['id_search_option::5;', 'linked_action:NOT:1,3,4;itemtype_link::Ticket;'],
                 'date' => '2018-04-22',
                 'linked_actions' => [3, 26, 'other'],
                 'users_names' => ['user1'],
              ],
              [
                 [
                    'OR' => [
                       [
                          'id_search_option' => [5],
                       ],
                       [
                          'NOT' => [
                             'linked_action' => [1, 3, 4],
                          ],
                          'itemtype_link' => ['Ticket'],
                       ]
                    ]
                 ],
                 [
                    ['date_mod' => ['>=', '2018-04-22 00:00:00']],
                    ['date_mod' => ['<=', '2018-04-22 23:59:59']],
                 ],
                 [
                    'OR' => [
                       [
                          'linked_action' => 3,
                       ],
                       [
                          'linked_action' => 26,
                       ],
                       [
                          'linked_action' => \Log::HISTORY_LOG_SIMPLE_MESSAGE,
                       ],
                       [
                          'linked_action' => ['>=', \Log::HISTORY_PLUGIN],
                       ]
                    ],
                 ],
                 'user_name' => ['user1'],
              ]
           ],
        ];
    }


    /**
     * @dataProvider dataFiltersValuesToSqlCriteria
     */
    public function testConvertFiltersValuesToSqlCriteria($filters_values, $expected_result)
    {
        $this->array(\Log::convertFiltersValuesToSqlCriteria($filters_values))->isIdenticalTo($expected_result);
    }
}
