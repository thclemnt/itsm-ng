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

use Budget;
use Closure;
use Computer;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BigIntType;
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
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity as MappingEntity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Dropdown as LegacyDropdown;
use Entity;
use Generator;
use LogicException;
use Profile;
use Psr\Cache\CacheItemInterface;
use ReflectionProperty;
use Supplier;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use ValueError;
use itsmng\Database\DropdownReadOperation;
use itsmng\Database\EntityRegistry;
use itsmng\Database\EntityScopeReadOperation;
use itsmng\Database\Entity\Budget as BudgetEntity;
use itsmng\Database\Entity\Config as ConfigRecord;
use itsmng\Database\Entity\Contact;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\Entity\Profile as ProfileEntity;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DropdownChoiceRepository;
use itsmng\Database\Repository\DropdownTranslationRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TreeRepository;
use itsmng\Database\TreeReadOperation;
use mock\DBmysql;

/* Test for inc/dropdown.class.php */

class Dropdown extends DbTestCase
{
    public function testOwnedEntityRestrictionsReadFreshRowsWithinOneOperation(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $parent = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $child = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => $parent->getID()]);
        $id = (int)$child->getID();
        $expected = getEntitiesRestrictCriteria('glpi_suppliers', '', [$id], true);
        $counter = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $counter->getValue();
        $operation = new EntityScopeReadOperation();
        $this->array($operation->criteria('glpi_suppliers', '', [$id], true))->isIdenticalTo($expected);
        $this->array($operation->criteria('glpi_suppliers', '', [$id], true))->isIdenticalTo($expected);
        $this->integer($counter->getValue() - $before)->isIdenticalTo(0, 'Current permission reads reuse the warmed canonical manager');
        $connection->update('glpi_entities', ['entities_id' => 0], ['id' => $id]);
        $fresh = getEntitiesRestrictCriteria('glpi_suppliers', '', [$id], true);
        $this->array($fresh)->isNotIdenticalTo($expected);
        $this->array($operation->criteria('glpi_suppliers', '', [$id], true))->isIdenticalTo($fresh);
    }

    public function testTreePointReadsUseTypedDbalAndRetainScalarValues(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $entity = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $id = (int)$entity->getID();
        $child = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => $id]);
        $childId = (int)$child->getID();
        $connection->update('glpi_entities', ['ancestors_cache' => 'cache lower', 'sons_cache' => null], ['id' => $id]);
        $fields = ['id', 'entities_id', 'ancestors_cache', 'sons_cache'];
        $manager = Orm::forConnection($connection);
        $oracle = new TreeRepository($manager);
        $expected = $oracle->rows('glpi_entities', $fields, ['id' => $id]);
        $probe = new DropdownScalarReadProbe($connection);
        $reader = new TreeReadOperation($probe);
        $originalText = Type::getType('text');
        $originalBigint = Type::getType('bigint');
        $database = $DB;
        try {
            $publicProbe = new DropdownScalarReadProbe($connection);
            $this->mockGenerator->orphanize('__construct');
            $DB = new DBmysql();
            $this->calling($DB)->getProvider = $database->getProvider();
            $routes = 0;
            $this->calling($DB)->getDoctrineConnection = static function () use ($publicProbe, &$routes) {
                ++$routes;
                return $publicProbe;
            };
            // Exercise the public scalar caller, which casts its ID to an IN list.
            $this->array(getAncestorsOf('glpi_entities', $childId))->isIdenticalTo([0 => 0, $id => $id]);
            $this->array($publicProbe->queries)->hasSize(2);
            $this->integer($routes)->isIdenticalTo(4, 'Each ancestor read uses the selected connection');
            $this->integer($publicProbe->builders)->isIdenticalTo(2);
            $this->array($publicProbe->queries[0]['params'])->isIdenticalTo(['id0' => (string)$childId]);
            $this->array($publicProbe->queries[0]['types'])->isIdenticalTo(['id0' => Types::BIGINT]);
            $connection->update('glpi_entities', ['entities_id' => 0], ['id' => $childId]);
            $this->array(getAncestorsOf('glpi_entities', [$childId, $id, $childId, 0]))->isIdenticalTo([0 => 0]);
            $this->array($publicProbe->queries)->hasSize(3);
            $this->integer($publicProbe->builders)->isIdenticalTo(3, 'Tree rows remain live after reparenting');
            $this->array(getAncestorsOf('glpi_entities', [$id => $id, $childId => $childId]))->isIdenticalTo([0 => 0]);
            $this->integer($publicProbe->builders)->isIdenticalTo(4, 'Keyed entity scopes use the same ID projection');
            $this->array(getAncestorsOf('glpi_entities', []))->isEmpty();
            $this->array(getAncestorsOf('glpi_entities', 0))->isEmpty();
            $this->array($publicProbe->queries)->hasSize(5, 'Empty selections skip SQL; entity zero is read without an ancestor');
            $DB = $database;
            $this->array($reader->rows('glpi_entities', $fields, ['id' => $id]))->isIdenticalTo($expected);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
            $this->array($probe->queries[0]['types'])->isIdenticalTo(['id' => Types::BIGINT]);
            $this->array($probe->queries[0]['params'])->isIdenticalTo(['id' => (string)$id]);
            foreach ([[$id], [$id, 0, $id], [$id, null, 'NULL'], [null, 'null'], ['selected' => $id]] as $ids) {
                $this->array($reader->rows('glpi_entities', $fields, ['id' => $ids]))
                    ->isIdenticalTo($oracle->rows('glpi_entities', $fields, ['id' => $ids]));
            }
            $this->integer($probe->builders)->isIdenticalTo(6);
            $this->array($probe->queries[3]['params'])->isIdenticalTo(['id0' => (string)$id, 'id1' => null, 'id2' => null]);
            $this->array($probe->queries[3]['types'])->isIdenticalTo(array_fill_keys(['id0', 'id1', 'id2'], Types::BIGINT));
            $builders = $probe->builders;
            foreach ([null, 'NULL', ['=', $id], ['>', $id], [true, $id]] as $criteria) {
                $this->array($reader->rows('glpi_entities', ['id'], ['id' => $criteria]))
                    ->isIdenticalTo($oracle->rows('glpi_entities', ['id'], ['id' => $criteria]));
            }
            $this->exception(static fn () => $reader->rows('glpi_entities', ['id'], ['id' => []]))
                ->hasMessage('Empty IN are not allowed');
            $this->integer($probe->builders)->isIdenticalTo($builders, 'Unsupported ID predicates retain the ordinary criteria path');
            $this->string($expected[0]['ancestors_cache'])->isIdenticalTo('cache lower');
            $this->variable($expected[0]['sons_cache'])->isNull();
            $this->array($reader->rows('glpi_entities', ['id', 'entities_id'], ['id' => 0]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['id', 'entities_id'], ['id' => 0]));
            $this->integer((int)$reader->rows('glpi_entities', ['entities_id'], ['id' => 0])[0]['entities_id'])->isIdenticalTo(-1);
            $connection->update('glpi_entities', ['ancestors_cache' => 'changed lower'], ['id' => $id]);
            $this->array($reader->rows('glpi_entities', $fields, ['id' => $id]))
                ->isIdenticalTo($oracle->rows('glpi_entities', $fields, ['id' => $id]));
            // Scalar SQL aliases historically do not call PHP value converters.
            Type::overrideType('text', new DropdownScalarSqlText());
            $this->string($reader->rows('glpi_entities', ['ancestors_cache'], ['id' => $id])[0]['ancestors_cache'])
                ->isIdenticalTo('CHANGED LOWER');
            $this->array($reader->rows('glpi_entities', ['ancestors_cache'], ['id' => $id]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['ancestors_cache'], ['id' => $id]));
            $this->array($reader->rows('glpi_entities', ['ancestors_cache'], ['id' => [$id, null]]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['ancestors_cache'], ['id' => [$id, null]]));
            Type::overrideType('bigint', new DropdownNegativeScalarId());
            $this->array($reader->rows('glpi_entities', ['id'], ['id' => $id]))->isEmpty();
            $this->array($reader->rows('glpi_entities', ['id'], ['id' => [$id, 0]]))->isEmpty();
            $this->array($reader->rows('glpi_entities', ['id'], ['id' => [$id, 0]]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['id'], ['id' => [$id, 0]]));
            Type::overrideType('bigint', $originalBigint);
            Type::overrideType('text', $originalText);
            $extension = new class ($connection) extends DropdownScalarReadProbe {
                private ?EventManager $events = null;
                public function getEventManager(): EventManager
                {
                    return $this->events ??= new EventManager();
                }
            };
            $local = new TreeReadOperation($extension);
            $listener = new class () {
                public int $loads = 0;
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    ++$this->loads;
                }
            };
            $extension->getEventManager()->addEventListener([Events::loadClassMetadata], $listener);
            $this->array($local->rows('glpi_entities', ['id', 'name'], ['id' => $id]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['id', 'name'], ['id' => $id]));
            $this->array($local->rows('glpi_entities', ['id', 'name'], ['id' => [$id]]))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['id', 'name'], ['id' => [$id]]));
            $this->integer($listener->loads)->isGreaterThan(0);
            $this->integer($extension->builders)->isIdenticalTo(0, 'Late inherited listeners retain the ordinary ORM path');
            $local->close();
            $builders = $probe->builders;
            $this->array($reader->rows('glpi_entities', ['id'], ['id' => [$id]], ['id DESC']))
                ->isIdenticalTo($oracle->rows('glpi_entities', ['id'], ['id' => [$id]], ['id DESC']));
            $this->integer($probe->builders)->isIdenticalTo($builders, 'General predicates/order retain the existing ORM criteria contract');
        } finally {
            $DB = $database;
            Type::overrideType('text', $originalText);
            Type::overrideType('bigint', $originalBigint);
            $reader->close();
            $manager->clear();
        }
    }

    public function testOwnedEntityScopeResolvesOneCurrentRoutePerRead(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $entity = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $id = (int)$entity->getID();
        $depth = $connection->getTransactionNestingLevel();
        $other = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        $this->mockGenerator->orphanize('__construct');
        $adapter = new DBmysql();
        $calls = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use (&$calls, $connection, $other) {
            return ++$calls % 2 === 1 ? $connection : $other;
        };
        $operation = new EntityScopeReadOperation();
        try {
            $first = $operation->rows($adapter, 'glpi_entities', ['id', 'name'], ['id' => $id]);
            $this->array($first)->hasSize(1);
            $this->integer($calls)->isIdenticalTo(1);
            // The second route cannot see the first connection's uncommitted fixture.
            $this->array($operation->rows($adapter, 'glpi_entities', ['id', 'name'], ['id' => $id]))->isEmpty();
            $this->integer($calls)->isIdenticalTo(2);
            $this->array($operation->rows($adapter, 'glpi_entities', ['id', 'name'], ['id' => $id]))->isIdenticalTo($first);
            $this->integer($calls)->isIdenticalTo(3);
        } finally {
            unset($operation);
            $other->close();
            $this->boolean($other->isConnected())->isFalse();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        }
    }

    public function testDropdownRecomputesCurrentAuthorityAfterVirtualCallbacks(): void
    {
        $this->login();
        $session = $_SESSION;
        try {
            $left = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $right = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $leftId = (int)$left->getID();
            $rightId = (int)$right->getID();
            $name = $this->getUniqueString();
            $one = $this->createItem(Supplier::class, ['name' => $name . ' left', 'entities_id' => $leftId]);
            $two = $this->createItem(Supplier::class, ['name' => $name . ' right', 'entities_id' => $rightId]);
            $collect = static function (array $rows) use (&$collect): array {
                $ids = [];
                foreach ($rows as $row) {
                    if (isset($row['children'])) {
                        $ids = array_merge($ids, $collect($row['children']));
                    } elseif (isset($row['id']) && (int)$row['id'] > 0) {
                        $ids[] = (int)$row['id'];
                    }
                }
                sort($ids);
                return $ids;
            };
            foreach ([
                [[$leftId, $rightId], [$rightId], false, [(int)$two->getID()]],
                [[$leftId, $rightId], [], true, []],
                [[$leftId, $rightId], [$rightId], true, [(int)$one->getID(), (int)$two->getID()]],
                [[$leftId], [$rightId], false, []],
            ] as [$requested, $active, $showAll, $expected]) {
                $_SESSION['glpiactiveentities'] = [$leftId];
                $_SESSION['glpishowallentities'] = false;
                ScopeCallbackSupplier::$checks = 0;
                ScopeCallbackSupplier::$beforeAuthority = static function () use ($active, $showAll): void {
                    $_SESSION['glpiactiveentities'] = $active;
                    $_SESSION['glpishowallentities'] = $showAll;
                };
                $result = LegacyDropdown::getDropdownValue([
                    'itemtype' => ScopeCallbackSupplier::class, 'entity_restrict' => $requested,
                    'searchText' => $name, 'display_emptychoice' => false, 'page' => 1, 'page_limit' => 20,
                ], false);
                sort($expected);
                $this->array($collect($result['results']))->isIdenticalTo($expected);
                $this->integer(ScopeCallbackSupplier::$checks)->isIdenticalTo(2);
            }
        } finally {
            ScopeCallbackSupplier::$beforeAuthority = null;
            ScopeCallbackSupplier::$checks = 0;
            $_SESSION = $session;
        }
    }

    public function testChoiceRowsProjectTypesTranslationsAndStablePages(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $manager = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
            public array $hydrationModes = [];
            public function newHydrator(string|int $hydrationMode): AbstractHydrator
            {
                $this->hydrationModes[] = $hydrationMode;
                return parent::newHydrator($hydrationMode);
            }
        };
        $oracle = Orm::create($DB);
        $depth = $connection->getTransactionNestingLevel();
        try {
            $ids = [];
            foreach (range(0, 2) as $index) {
                $budget = $this->createItem(Budget::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
                $id = $ids[] = (int)$budget->getID();
                $connection->update('glpi_budgets', [
                    'name' => $index === 0 ? null : "O'Reilly\\budget", 'comment' => null,
                    'begin_date' => '2026-02-03', 'end_date' => null, 'is_deleted' => false,
                    'locations_id' => $index === 2 ? (int)getItemByTypeName('Location', '_location01', true) : null,
                ], ['id' => $id], ['is_deleted' => Types::BOOLEAN]);
            }
            $connection->insert('glpi_dropdowntranslations', ['itemtype' => 'Budget', 'items_id' => $ids[1],
                'language' => 'en_GB', 'field' => 'name', 'value' => "Translated O'Reilly\\budget"]);
            $translations = ['translatedName' => ['field' => 'name', 'output' => 'transname']];
            $expected = [];
            foreach ($ids as $index => $id) {
                $record = $oracle->find(BudgetEntity::class, $id);
                $expected[] = (new RecordRepository($oracle))->toRow($record)
                    + ['transname' => $index === 1 ? "Translated O'Reilly\\budget" : null];
            }
            $repository = $manager->getRepository(BudgetEntity::class);
            $rows = $repository->choices(['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 0, 0);
            $this->array($rows)->isIdenticalTo($expected);
            $this->array($manager->hydrationModes)->isIdenticalTo(
                [Query::HYDRATE_ARRAY],
                'Dropdown choices must project typed rows without hydrating complete entities'
            );
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->array($repository->choices(['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 1, 1))
                ->isIdenticalTo([$expected[1]]);
            $this->array($repository->choices(['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 1, 3))->isEmpty();
            $this->array(array_column($repository->choices(['id' => $ids], ['name'], [], 'Budget', 'en_GB', 0, -1), 'id'))
                ->isIdenticalTo($ids);
            $this->array(array_column($repository->choices(['id' => $ids], ['translatedName.value'], $translations, 'Budget', 'en_GB', 0, 0), 'id'))
                ->isIdenticalTo([$ids[0], $ids[2], $ids[1]]);

            $previousCache = $GLOBALS['GLPI_CACHE'] ?? null;
            $memory = new DropdownOwnedPlanCache(storeSerialized: false);
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            try {
                $owned = new DropdownReadOperation($connection);
                $this->array($owned->choices('glpi_budgets', ['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 0, 0))
                    ->isIdenticalTo($expected);
                $this->integer($memory->planWrites)->isIdenticalTo(1);
                $owned->close();
                $warm = new DropdownReadOperation($connection);
                $this->array($warm->choices('glpi_budgets', ['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 0, 0))
                    ->isIdenticalTo($expected);
                $this->integer($memory->planWrites)->isIdenticalTo(1, 'A new private reader uses the actual compiled query');
                $privateManager = (new ReflectionProperty($warm, 'manager'))->getValue($warm);
                $loaded = array_keys($privateManager->getMetadataFactory()->getLoadedMetadata());
                sort($loaded);
                $this->array($loaded)->isIdenticalTo([
                    BudgetEntity::class,
                    DropdownTranslation::class,
                ], 'Warm scalar choices do not load Entity/Location target metadata');
                $connection->update('glpi_budgets', ['name' => 'Changed live choice'], ['id' => $ids[2]]);
                $connection->update('glpi_dropdowntranslations', ['value' => 'Changed live translation'], ['itemtype' => 'Budget', 'items_id' => $ids[1], 'field' => 'name', 'language' => 'en_GB']);
                $fresh = $warm->choices('glpi_budgets', ['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 0, 0);
                $this->array(array_column($fresh, 'id'))->isIdenticalTo([$ids[0], $ids[2], $ids[1]]);
                $this->string($fresh[1]['name'])->isIdenticalTo('Changed live choice');
                $this->string($fresh[2]['transname'])->isIdenticalTo('Changed live translation');
                $this->integer($memory->planWrites)->isIdenticalTo(1);
                $this->array($warm->choices('glpi_budgets', ['id' => $ids], ['name'], $translations, 'Budget', 'en_GB', 1, 1))->isIdenticalTo([$fresh[1]]);
                $warm->close();
            } finally {
                $GLOBALS['GLPI_CACHE'] = $previousCache;
            }

            // Domain query/criteria overrides still select one profile despite several rights.
            $profile = $this->createItem(Profile::class, ['name' => $this->getUniqueString()]);
            $profileId = (int)$profile->getID();
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_profilerights WHERE profiles_id = ?', [$profileId]))
                ->isGreaterThan(1);
            $profileRepository = $manager->getRepository(ProfileEntity::class);
            $manager->hydrationModes = [];
            $this->array(array_column($profileRepository->choices(['id' => $profileId], ['name'], [], 'Profile', 'en_GB', 0, 0), 'id'))
                ->isIdenticalTo([$profileId]);
            $this->array($manager->hydrationModes)->isIdenticalTo([Query::HYDRATE_ARRAY]);
            $this->array($profileRepository->choices(['id' => $profileId, 'glpi_profilerights.rights' => -1], ['name'], [], 'Profile', 'en_GB', 0, 0))
                ->isEmpty();
            $previousCache = $GLOBALS['GLPI_CACHE'] ?? null;
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $writes = $memory->planWrites;
            try {
                $ownedProfile = new DropdownReadOperation($connection);
                $this->array(array_column($ownedProfile->choices('glpi_profiles', ['id' => $profileId], ['name'], [], 'Profile', 'en_GB', 0, 0), 'id'))
                    ->isIdenticalTo([$profileId]);
                $this->array($ownedProfile->choices('glpi_profiles', ['id' => $profileId, 'glpi_profilerights.rights' => -1], ['name'], [], 'Profile', 'en_GB', 0, 0))->isEmpty();
                $this->integer($memory->planWrites)->isIdenticalTo($writes, 'Domain query and criteria overrides stay local');
            } finally {
                $GLOBALS['GLPI_CACHE'] = $previousCache;
            }
        } finally {
            $manager->clear();
            $oracle->clear();
            $this->object($manager->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        }
    }

    public function testChoiceRowsRetainManagedRecordsHooksAndPresenters(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $manager = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
            public array $hydrationModes = [];
            public function newHydrator(string|int $hydrationMode): AbstractHydrator
            {
                $this->hydrationModes[] = $hydrationMode;
                return parent::newHydrator($hydrationMode);
            }
        };
        $id = (int)getItemByTypeName('Budget', '_budget01', true);
        $listener = new class ('dropdown-owner-' . bin2hex(random_bytes(6))) {
            public int $loaded = 0;
            public array $managers = [];
            public array $created = [];
            public function __construct(private string $context)
            {
            }
            public function postLoad(PostLoadEventArgs $event): void
            {
                if ($event->getObject() instanceof BudgetEntity) {
                    ++$this->loaded;
                    $this->managers[] = $event->getObjectManager();
                    $event->getObject()->name = 'Post-load presentation';
                    $record = new ConfigRecord();
                    $record->context = $this->context;
                    $record->name = 'load-' . $this->loaded;
                    $record->value = 'pending callback write';
                    $event->getObjectManager()->persist($record);
                    $this->created[] = $record;
                }
            }
        };
        try {
            $repository = $manager->getRepository(BudgetEntity::class);
            $managed = $manager->find(BudgetEntity::class, $id);
            $managed->name = 'Pending managed value';
            $manager->hydrationModes = [];
            $this->string($repository->choices(['id' => $id], [], [], 'Budget', 'en_GB', 0, 0)[0]['name'])
                ->isIdenticalTo('Pending managed value');
            $this->array($manager->hydrationModes)->isIdenticalTo([Query::HYDRATE_OBJECT]);
            $this->boolean($manager->contains($managed))->isTrue();
            $manager->flush();
            $this->string($connection->fetchOne('SELECT name FROM glpi_budgets WHERE id = ?', [$id]))
                ->isIdenticalTo('Pending managed value');
            $manager->clear();
            $manager->getEventManager()->addEventListener([Events::postLoad], $listener);
            $this->string($repository->choices(['id' => $id], [], [], 'Budget', 'en_GB', 0, 0)[0]['name'])
                ->isIdenticalTo('Post-load presentation');
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->boolean($manager->contains($listener->created[0]))->isTrue();
            $manager->flush();
            $this->string($connection->fetchOne('SELECT name FROM glpi_budgets WHERE id = ?', [$id]))
                ->isIdenticalTo('Post-load presentation');
            $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE id = ?', [$listener->created[0]->id]))
                ->isIdenticalTo('pending callback write');
            $manager->getEventManager()->removeEventListener([Events::postLoad], $listener);
            $manager->clear();
            $manager->hydrationModes = [];
            $collision = $repository->choices(['id' => $id], [], [
                'translatedName' => ['field' => 'name', 'output' => 'value0'],
            ], 'Budget', 'en_GB', 0, 0);
            $this->array($collision[0])->hasKey('value0');
            $this->array($manager->hydrationModes)->isIdenticalTo([Query::HYDRATE_OBJECT]);
            $manager->clear();

            // A custom presenter can leave pending changes for the caller's later flush.
            $custom = new class ($manager, $manager->getClassMetadata(BudgetEntity::class)) extends DropdownChoiceRepository {
                public bool $presentedManaged = false;
                public bool $dispatchedOriginalSignature = false;
                public ?BudgetEntity $presentedRecord = null;
                public function choices(array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
                {
                    $this->dispatchedOriginalSignature = true;
                    return parent::choices($criteria, $order, $translations, $kind, $language, $limit, $offset);
                }
                protected function choiceQuery(): QueryBuilder
                {
                    return parent::choiceQuery()->andWhere('r.is_deleted = false');
                }
                protected function presentChoice(array $row): array
                {
                    $this->presentedManaged = $this->getEntityManager()->getUnitOfWork()->size() > 0;
                    $this->presentedRecord = $this->getEntityManager()->find(BudgetEntity::class, $row['id']);
                    $this->presentedRecord->comment = 'Pending presenter comment';
                    $row['name'] .= ' custom';
                    return $row;
                }
            };
            $manager->hydrationModes = [];
            $this->string($custom->choices(['id' => $id], [], [], 'Budget', 'en_GB', 0, 0)[0]['name'])->endWith(' custom');
            $this->boolean($custom->presentedManaged)->isTrue();
            $this->boolean($custom->dispatchedOriginalSignature)->isTrue();
            $this->array($manager->hydrationModes)->isIdenticalTo([Query::HYDRATE_OBJECT]);
            $this->boolean($manager->contains($custom->presentedRecord))->isTrue();
            $manager->flush();
            $this->string($connection->fetchOne('SELECT comment FROM glpi_budgets WHERE id = ?', [$id]))
                ->isIdenticalTo('Pending presenter comment');
            $manager->clear();
            $contact = getItemByTypeName('Contact', '_contact01_name');
            $this->string($manager->getRepository(Contact::class)
                ->choices(['id' => (int)$contact->getID()], [], [], 'Contact', 'en_GB', 0, 0)[0]['name'])
                ->isIdenticalTo(($contact->fields['name'] ?? '') . ' ' . ($contact->fields['firstname'] ?? ''));
            $sentinel = $manager->find(Contact::class, (int)$contact->getID());
            $configuration = $manager->getConfiguration();
            $cache = $configuration->getMetadataCache();
            $listener->managers = [];
            $manager->getEventManager()->addEventListener([Events::postLoad], $listener);
            $borrowed = new DropdownReadOperation($connection, $manager);
            try {
                $this->string($borrowed->label('glpi_budgets', $id, 'Budget', 'en_GB', [])['name'])
                    ->isIdenticalTo('Post-load presentation');
                $this->boolean($manager->contains($sentinel))->isTrue('Label fallback preserves unrelated caller entities');
                $labelled = $manager->find(BudgetEntity::class, $id);
                $labelled->comment = 'Pending labelled value';
                $this->string($borrowed->label('glpi_budgets', $id, 'Budget', 'en_GB', [])['comment'])
                    ->isIdenticalTo('Pending labelled value');
                $this->boolean($manager->contains($labelled))->isTrue('Label fallback preserves the selected caller entity');
                $this->object($manager->getConfiguration())->isIdenticalTo($configuration);
                $this->object($configuration->getMetadataCache())->isIdenticalTo($cache);
                $this->string($borrowed->choices('glpi_budgets', ['id' => $id], [], [], 'Budget', 'en_GB', 0, 0)[0]['name'])
                    ->isIdenticalTo('Post-load presentation');
                $this->boolean($manager->contains($sentinel))->isTrue('Choice fallback preserves unrelated caller entities');
                $this->object($manager->getConfiguration())->isIdenticalTo($configuration);
                $this->object($configuration->getMetadataCache())->isIdenticalTo($cache);
                // Label and choice reads share the same caller identity, so postLoad runs once.
                $this->array($listener->managers)->isIdenticalTo([$manager]);
            } finally {
                $borrowed->close();
            }
            $this->boolean($manager->contains($sentinel))->isTrue();
            $this->boolean($manager->contains($labelled))->isTrue();
            $manager->flush();
            $this->string($connection->fetchOne('SELECT comment FROM glpi_budgets WHERE id = ?', [$id]))
                ->isIdenticalTo('Pending labelled value');
            $created = end($listener->created);
            $this->string($connection->fetchOne('SELECT value FROM glpi_configs WHERE id = ?', [$created->id]))
                ->isIdenticalTo('pending callback write');
        } finally {
            $manager->getEventManager()->removeEventListener([Events::postLoad], $listener);
            $manager->clear();
        }
    }

    public function testOwnedChoicesKeepUnknownRepositoryConstructionLocal(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $id = (int)getItemByTypeName('Budget', '_budget01', true);
        $expected = $connection->fetchOne('SELECT name FROM glpi_budgets WHERE id = ?', [$id]);
        EntityRegistry::tables();
        $registry = new ReflectionProperty(EntityRegistry::class, 'model');
        $original = $registry->getValue();
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new DropdownOwnedPlanCache(storeSerialized: false);
        try {
            $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
            $model = $original;
            $model['tables']['glpi_budgets'] = DropdownUnknownBudget::class;
            $registry->setValue(null, $model);
            DropdownUnknownRepository::$constructedLocally = false;
            DropdownUnknownRepository::$called = false;
            $reader = new DropdownReadOperation($connection);
            $rows = $reader->choices('glpi_budgets', ['id' => $id], ['name'], [], 'Budget', 'en_GB', 0, 0);
            $this->string($rows[0]['name'])->isIdenticalTo($expected . ' original override');
            $this->boolean(DropdownUnknownRepository::$constructedLocally)->isTrue();
            $this->boolean(DropdownUnknownRepository::$called)->isTrue();
            $this->integer($memory->planWrites)->isIdenticalTo(0);
            $registry->setValue(null, $original);
            $reader = new DropdownReadOperation($connection);
            $this->string($reader->choices('glpi_budgets', ['id' => $id], ['name'], [], 'Budget', 'en_GB', 0, 0)[0]['name'])->isIdenticalTo($expected);
            $this->integer($memory->planWrites)->isIdenticalTo(1);
        } finally {
            $registry->setValue(null, $original);
            $GLOBALS['GLPI_CACHE'] = $previous;
            DropdownUnknownRepository::$constructedLocally = false;
            DropdownUnknownRepository::$called = false;
        }
    }

    public function testOwnedChoiceJoinedSqlTypeRemainsLive(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $profile = $this->createItem(Profile::class, ['name' => $this->getUniqueString()]);
        $id = (int)$connection->fetchOne('SELECT id FROM glpi_profilerights WHERE profiles_id = ? ORDER BY id', [(int)$profile->getID()]);
        $connection->insert('glpi_dropdowntranslations', ['itemtype' => 'ProfileRight', 'items_id' => $id, 'language' => 'en_GB', 'field' => 'name', 'value' => 'live lower']);
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new DropdownOwnedPlanCache(storeSerialized: false);
        $GLOBALS['GLPI_CACHE'] = new Psr16Cache($memory);
        $registry = Type::getTypeRegistry();
        $original = $registry->get(Types::TEXT);
        $translations = ['translatedName' => ['field' => 'name', 'output' => 'transname']];
        try {
            $oracle = Orm::create($DB);
            $root = $oracle->getClassMetadata(ProfileRight::class);
            $this->array(array_column($root->fieldMappings, 'type'))->notContains(Types::TEXT);
            $reader = new DropdownReadOperation($connection);
            $this->string($reader->choices('glpi_profilerights', ['id' => $id], ['name'], $translations, 'ProfileRight', 'en_GB', 0, 0)[0]['transname'])->isIdenticalTo('live lower');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
            Type::overrideType(Types::TEXT, DropdownUpperTextType::class);
            $reader = new DropdownReadOperation($connection);
            $this->string($reader->choices('glpi_profilerights', ['id' => $id], ['name'], $translations, 'ProfileRight', 'en_GB', 0, 0)[0]['transname'])->isIdenticalTo('LIVE LOWER');
            $this->integer($memory->planWrites)->isIdenticalTo(1, 'Joined-field SQL conversion bypasses the warm default plan');
            $registry->override(Types::TEXT, $original);
            $reader = new DropdownReadOperation($connection);
            $this->string($reader->choices('glpi_profilerights', ['id' => $id], ['name'], $translations, 'ProfileRight', 'en_GB', 0, 0)[0]['transname'])->isIdenticalTo('live lower');
            $this->integer($memory->planWrites)->isIdenticalTo(1);
        } finally {
            $registry->override(Types::TEXT, $original);
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testReadonlyArrayDropdownRetainsOnlyHiddenSelection(): void
    {
        foreach ([false, true] as $multiple) {
            $options = ['readonly' => true, 'multiple' => $multiple, 'noselect2' => true,
                'display' => false, 'rand' => 313, 'values' => $multiple ? [7, 9] : [7]];
            $html = LegacyDropdown::showFromArray('readonly_choice', [7 => 'First label', 9 => 'Second label'], $options);
            $field = $multiple ? 'readonly_choice[]' : 'readonly_choice';
            $this->string($html)->contains("<input type='hidden' name='$field' value='7'>")
                ->contains('First label')->notContains('<select');
            if ($multiple) {
                $this->string($html)->contains("<input type='hidden' name='$field' value='9'>")
                    ->contains('First label<br>Second label');
            } else {
                $this->string($html)->notContains('Second label');
            }
            $result = null;
            $options['display'] = true;
            $this->output(function () use ($options, &$result): void {
                $result = LegacyDropdown::showFromArray('readonly_choice', [7 => 'First label', 9 => 'Second label'], $options);
            })->isIdenticalTo($html);
            $this->integer($result)->isIdenticalTo(313);
        }
    }

    public function testDropdownNameProjectionAvoidsHydration(): void
    {
        global $DB;
        $em = Orm::create($DB);
        $repository = new DropdownTranslationRepository($em);
        $reader = new DropdownReadOperation($em->getConnection());
        $listener = new class () {
            public int $loaded = 0;

            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);
        try {
            foreach ([
                'Computer' => '_test_pc01', 'Contact' => '_contact01_name',
                'Supplier' => '_suplier01_name', 'Netpoint' => '_netpoint01', 'Budget' => '_budget01',
            ] as $type => $name) {
                $item = getItemByTypeName($type, $name);
                $id = (int)$item->getID();
                $em->clear();
                $listener->loaded = 0;
                $full = $repository->dropdownRow($item->getTable(), $id, $type, 'en_GB', []);
                // Positive control: the existing full-row API really loads an entity.
                $this->integer($listener->loaded)->isGreaterThan(0);
                foreach ([true, false] as $tooltip) {
                    $em->clear();
                    $listener->loaded = 0;
                    $columns = $item->getDropdownNameFields($tooltip);
                    $row = $repository->dropdownRow($item->getTable(), $id, $type, 'en_GB', [], $columns);
                    $expected = [];
                    foreach ($columns as $column) {
                        $expected[$column] = $full[$column];
                    }
                    $this->array($row)->isIdenticalTo($expected + ['transname' => '', 'transcomment' => '']);
                    $this->array($reader->label($item->getTable(), $id, $type, 'en_GB', [], $columns))->isIdenticalTo($row);
                    $this->integer($listener->loaded)->isIdenticalTo(0);
                    $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
                }
            }
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $listener);
            $em->clear();
        }
    }

    public function testDropdownNameProjectionLegacyValues(): void
    {
        global $DB;
        $budget = (int)getItemByTypeName('Budget', '_budget01', true);
        $location = (int)getItemByTypeName('Location', '_location01', true);
        $connection = $DB->getDoctrineConnection();
        $connection->update('glpi_budgets', [
            'name' => "O'Reilly\\budget", 'comment' => null, 'is_deleted' => false,
            'begin_date' => '2026-02-03', 'end_date' => null, 'locations_id' => $location,
        ], ['id' => $budget], ['is_deleted' => Types::BOOLEAN]);
        $repository = new DropdownTranslationRepository(Orm::create($DB));
        $publicExpected = LegacyDropdown::getDropdownName('glpi_budgets', $budget, false, false, false);
        $database = $DB;
        $probe = new DropdownScalarReadProbe($connection);
        try {
            $this->mockGenerator->orphanize('__construct');
            $DB = new DBmysql();
            $this->calling($DB)->getProvider = $database->getProvider();
            $routes = 0;
            $this->calling($DB)->getDoctrineConnection = static function () use ($probe, &$routes) {
                ++$routes;
                return $probe;
            };
            $this->string(LegacyDropdown::getDropdownName('glpi_budgets', $budget, false, false, false))->isIdenticalTo($publicExpected);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($routes)->isIdenticalTo(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
        } finally {
            $DB = $database;
        }
        $reader = new DropdownReadOperation($connection);
        $columns = ['id', 'name', 'comment', 'is_deleted', 'begin_date', 'end_date', 'locations_id'];
        $expected = [
            'id' => $budget, 'name' => "O'Reilly\\budget", 'comment' => null, 'is_deleted' => 0,
            'begin_date' => '2026-02-03', 'end_date' => null, 'locations_id' => $location,
            'transname' => '', 'transcomment' => '',
        ];
        $this->array($repository->dropdownRow('glpi_budgets', $budget, 'Budget', 'en_GB', [], $columns))
            ->isIdenticalTo($expected);
        $this->array($reader->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], $columns))->isIdenticalTo($expected);
        $scalarColumns = array_values(array_diff($columns, ['locations_id']));
        $this->array((new DropdownReadOperation($connection))->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], $scalarColumns))
            ->isIdenticalTo(array_diff_key($expected, ['locations_id' => true]));
        $connection->update(
            'glpi_budgets',
            ['is_deleted' => true, 'locations_id' => null],
            ['id' => $budget],
            ['is_deleted' => Types::BOOLEAN]
        );
        $expected['is_deleted'] = 1;
        $expected['locations_id'] = null;
        $this->array($repository->dropdownRow('glpi_budgets', $budget, 'Budget', 'en_GB', [], $columns))
            ->isIdenticalTo($expected);
        $this->array($reader->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], $columns))->isIdenticalTo($expected);
        $scalarColumns = array_values(array_diff($columns, ['locations_id']));
        $this->array((new DropdownReadOperation($connection))->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], $scalarColumns))
            ->isIdenticalTo(array_diff_key($expected, ['locations_id' => true]));
        $this->variable($repository->dropdownRow('glpi_budgets', -1, 'Budget', 'en_GB', [], $columns))->isNull();

        $this->variable((new DropdownReadOperation($connection))->label('glpi_budgets', -1, 'Budget', 'en_GB', [], ['name']))->isNull();
        $originalString = Type::getType('string');
        $originalInteger = Type::getType('integer');
        try {
            Type::overrideType('string', new class () extends StringType {
                public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return 'UPPER(' . $sqlExpr . ')';
                }
                public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                {
                    return $value === null ? 'converted null' : 'converted ' . $value;
                }
            });
            // Enum hydration remains authoritative even for explicit scalar selections.
            $enumReader = new DropdownReadOperation($connection);
            $this->exception(fn () => $repository->dropdownRow('glpi_entities', 0, 'Entity', 'en_GB', [], ['ldap_mode']))
                ->isInstanceOf(ValueError::class);
            $this->exception(fn () => $enumReader->label('glpi_entities', 0, 'Entity', 'en_GB', [], ['ldap_mode']))
                ->isInstanceOf(ValueError::class);
            $enumReader->close();
            $selected = ['name', 'name', 'comment'];
            $ordinary = $repository->dropdownRow('glpi_budgets', $budget, 'Budget', 'en_GB', [], $selected);
            $this->string($ordinary['name'])->isIdenticalTo("converted O'REILLY\\BUDGET");
            $this->array((new DropdownReadOperation($connection))->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], $selected))->isIdenticalTo($ordinary);
            Type::overrideType('integer', new class () extends IntegerType {
                public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                {
                    return '(' . $sqlExpr . ' * 0 - 1)';
                }
            });
            $this->variable($repository->dropdownRow('glpi_budgets', $budget, 'Budget', 'en_GB', [], ['name']))->isNull();
            $this->variable((new DropdownReadOperation($connection))->label('glpi_budgets', $budget, 'Budget', 'en_GB', [], ['name']))->isNull();
        } finally {
            Type::overrideType('string', $originalString);
            Type::overrideType('integer', $originalInteger);
            $reader->close();
        }

        $customName = new class () extends Computer {
            public static function getNameField()
            {
                return 'serial';
            }
        };
        $this->array($customName->getDropdownNameFields())->isIdenticalTo(['serial', 'comment']);
    }

    public function testDropdownNameProjectedTranslationsAndMissing(): void
    {
        global $DB;
        $computer = (int)getItemByTypeName('Computer', '_test_pc01', true);
        $connection = $DB->getDoctrineConnection();
        $connection->update('glpi_computers', ['name' => '', 'comment' => 'Original comment'], ['id' => $computer]);
        $language = $_SESSION['glpilanguage'] ?? null;
        $translations = $_SESSION['glpi_dropdowntranslations'] ?? null;
        try {
            $_SESSION['glpilanguage'] = 'en_GB';
            $_SESSION['glpi_dropdowntranslations'] = ['Computer' => ['name' => 'name', 'comment' => 'comment']];
            foreach (['name' => 'Translated computer', 'comment' => "Translated O'Reilly\\comment"] as $field => $value) {
                $key = ['itemtype' => 'Computer', 'items_id' => $computer, 'language' => 'en_GB', 'field' => $field];
                $connection->delete('glpi_dropdowntranslations', $key);
                $connection->insert('glpi_dropdowntranslations', $key + ['value' => $value]);
            }
            $this->array(LegacyDropdown::getDropdownName('glpi_computers', $computer, true))->isIdenticalTo([
                'name' => 'Translated computer', 'comment' => "Translated O'Reilly\\comment",
            ]);
            // The existing formatter ignores translated comments when the source is NULL.
            $connection->update('glpi_computers', ['comment' => null], ['id' => $computer]);
            $this->array(LegacyDropdown::getDropdownName('glpi_computers', $computer, true))->isIdenticalTo([
                'name' => 'Translated computer', 'comment' => '',
            ]);
            $this->array(LegacyDropdown::getDropdownName('glpi_computers', $computer, true, false))->isIdenticalTo([
                'name' => '(' . $computer . ')', 'comment' => '',
            ]);
            $this->string(LegacyDropdown::getDropdownName('glpi_computers', -1))->isIdenticalTo('&nbsp;');
            $this->array(LegacyDropdown::getDropdownName('glpi_computers', -1, true))->isIdenticalTo([
                'name' => '&nbsp;', 'comment' => '',
            ]);
        } finally {
            if ($language === null) {
                unset($_SESSION['glpilanguage']);
            } else {
                $_SESSION['glpilanguage'] = $language;
            }
            if ($translations === null) {
                unset($_SESSION['glpi_dropdowntranslations']);
            } else {
                $_SESSION['glpi_dropdowntranslations'] = $translations;
            }
        }
    }

    public function testGetItemActionButtonsHonorsItemRights()
    {
        $_SESSION['glpiactiveprofile'][\RequestType::$rightname] = READ | CREATE;
        $buttons = \getItemActionButtons(['info', 'add'], \RequestType::class);
        $this->array($buttons)
           ->hasKey('info')
           ->hasKey('add');

        $_SESSION['glpiactiveprofile'][\RequestType::$rightname] = CREATE;
        $buttons = \getItemActionButtons(['info', 'add'], \RequestType::class);
        $this->array($buttons)
           ->notHasKey('info')
           ->hasKey('add');

        $_SESSION['glpiactiveprofile'][\RequestType::$rightname] = READ;
        $buttons = \getItemActionButtons(['info', 'add'], \RequestType::class);
        $this->array($buttons)
           ->hasKey('info')
           ->notHasKey('add');
    }

    public function testShowLanguages()
    {

        $opt = [ 'display_emptychoice' => true, 'display' => false ];
        $out = \Dropdown::showLanguages('dropfoo', $opt);
        $this->string($out)
           ->contains('name="dropfoo"')
           ->contains('value="" selected')
           ->notContains('value="0"')
           ->contains('value="fr_FR"');

        $opt = ['display' => false, 'value' => 'cs_CZ', 'rand' => '1234'];
        $out = \Dropdown::showLanguages('language', $opt);
        $this->string($out)
           ->notContains('value=""')
           ->notContains('value="0"')
           ->contains('name="language"')
           ->contains('id="dropdown_language1234')
           ->contains('value="cs_CZ" selected')
           ->contains('value="fr_FR"');
    }

    public function dataTestImport()
    {
        return [
              // input,             name,  message
              [ [ ],                '',    'missing name'],
              [ [ 'name' => ''],    '',    'empty name'],
              [ [ 'name' => ' '],   '',    'space name'],
              [ [ 'name' => ' a '], 'a',   'simple name'],
              [ [ 'name' => 'foo'], 'foo', 'simple name'],
        ];
    }

    /**
     * @dataProvider dataTestImport
     */
    public function testImport($input, $result, $msg)
    {
        $id = \Dropdown::import('UserTitle', $input);
        if ($result) {
            $this->integer((int)$id)->isGreaterThan(0);
            $ut = new \UserTitle();
            $this->boolean($ut->getFromDB($id))->isTrue();
            $this->string($ut->getField('name'))->isIdenticalTo($result);
        } else {
            $this->integer((int)$id)->isLessThan(0);
        }
    }

    public function dataTestTreeImport()
    {
        return [
              // input,                                  name,    completename, message
              [ [ ],                                     '',      '',           'missing name'],
              [ [ 'name' => ''],                          '',     '',           'empty name'],
              [ [ 'name' => ' '],                         '',     '',           'space name'],
              [ [ 'name' => ' a '],                       'a',    'a',          'simple name'],
              [ [ 'name' => 'foo'],                       'foo',  'foo',        'simple name'],
              [ [ 'completename' => 'foo > bar'],         'bar',  'foo > bar',  'two names'],
              [ [ 'completename' => ' '],                 '',     '',           'only space'],
              [ [ 'completename' => '>'],                 '',     '',           'only >'],
              [ [ 'completename' => ' > '],               '',     '',           'only > and spaces'],
              [ [ 'completename' => 'foo>bar'],           'bar',  'foo > bar',  'two names with no space'],
              [ [ 'completename' => '>foo>>bar>'],        'bar',  'foo > bar',  'two names with additional >'],
              [ [ 'completename' => ' foo >   > bar > '], 'bar',  'foo > bar',  'two names with garbage'],
        ];
    }

    /**
     * @dataProvider dataTestTreeImport
     */
    public function testTreeImport($input, $result, $complete, $msg)
    {
        $input['entities_id'] = getItemByTypeName('Entity', '_test_root_entity', true);
        $id = \Dropdown::import('Location', $input);
        if ($result) {
            $this->integer((int)$id, $msg)->isGreaterThan(0);
            $ut = new \Location();
            $this->boolean($ut->getFromDB($id))->isTrue();
            $this->string($ut->getField('name'))->isIdenticalTo($result);
            $this->string($ut->getField('completename'))->isIdenticalTo($complete);
        } else {
            $this->integer((int)$id)->isLessThanOrEqualTo(0);
        }
    }

    public function testGetDropdownName()
    {
        global $CFG_GLPI, $DB;

        $encoded_sep = \Toolbox::clean_cross_side_scripting_deep(' > ');

        $ret = \Dropdown::getDropdownName('not_a_known_table', 1);
        $this->string($ret)->isIdenticalTo('&nbsp;');

        $cat = getItemByTypeName('TaskCategory', '_cat_1');

        $subCat = getItemByTypeName('TaskCategory', '_subcat_1');

        // basic test returns string only
        $expected = $cat->fields['name'].$encoded_sep.$subCat->fields['name'];
        $ret = \Dropdown::getDropdownName('glpi_taskcategories', $subCat->getID());
        $this->string($ret)->isIdenticalTo($expected);

        // test of return with comments
        $expected = ['name'    => $cat->fields['name'].$encoded_sep.$subCat->fields['name'],
                          'comment' => "<span class='b'>Complete name</span>: ".$cat->fields['name'].$encoded_sep
                                      .$subCat->fields['name']."<br><span class='b'>&nbsp;Comments&nbsp;</span>"
                                      .$subCat->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_taskcategories', $subCat->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        // test of return without $tooltip
        $expected = ['name'    => $cat->fields['name'].$encoded_sep.$subCat->fields['name'],
                          'comment' => $subCat->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_taskcategories', $subCat->getID(), true, true, false);
        $this->array($ret)->isIdenticalTo($expected);

        // test of return with translations
        $CFG_GLPI['translate_dropdowns'] = 1;
        $_SESSION["glpilanguage"] = \Session::loadLanguage('fr_FR');
        $_SESSION['glpi_dropdowntranslations'] = \DropdownTranslation::getAvailableTranslations($_SESSION["glpilanguage"]);
        $expected = ['name'    => 'FR - _cat_1' . $encoded_sep . 'FR - _subcat_1',
                          'comment' => 'FR - Commentaire pour sous-catégorie _subcat_1'];
        $ret = \Dropdown::getDropdownName('glpi_taskcategories', $subCat->getID(), true, true, false);
        // switch back to default language
        $_SESSION["glpilanguage"] = \Session::loadLanguage('en_GB');
        $this->array($ret)->isIdenticalTo($expected);

        ////////////////////////////////
        // test for other dropdown types
        ////////////////////////////////

        ///////////
        // Computer
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $ret = \Dropdown::getDropdownName('glpi_computers', $computer->getID());
        $this->string($ret)->isIdenticalTo($computer->getName());

        $expected = ['name'    => $computer->getName(),
                          'comment' => $computer->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_computers', $computer->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        //////////
        // Contact
        $contact = getItemByTypeName('Contact', '_contact01_name');
        $expected = $contact->getName();
        $ret = \Dropdown::getDropdownName('glpi_contacts', $contact->getID());
        $this->string($ret)->isIdenticalTo($expected);

        // test of return with comments
        $expected = ['name'    => $contact->getName(),
                          'comment' => "Comment for contact _contact01_name<br><span class='b'>".
                                      "Phone: </span>0123456789<br><span class='b'>Phone 2: </span>0123456788<br><span class='b'>".
                                      "Mobile phone: </span>0623456789<br><span class='b'>Fax: </span>0123456787<br>".
                                      "<span class='b'>Email: </span>_contact01_firstname._contact01_name@glpi.com"];
        $ret = \Dropdown::getDropdownName('glpi_contacts', $contact->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        // test of return without $tooltip
        $expected = ['name'    => $contact->getName(),
                          'comment' => $contact->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_contacts', $contact->getID(), true, true, false);
        $this->array($ret)->isIdenticalTo($expected);

        ///////////
        // Supplier
        $supplier = getItemByTypeName('Supplier', '_suplier01_name');
        $expected = $supplier->getName();
        $ret = \Dropdown::getDropdownName('glpi_suppliers', $supplier->getID());
        $this->string($ret)->isIdenticalTo($expected);

        // test of return with comments
        $expected = ['name'    => $supplier->getName(),
                          'comment' => "Comment for supplier _suplier01_name<br><span class='b'>Phone: </span>0123456789<br>".
                                       "<span class='b'>Fax: </span>0123456787<br><span class='b'>Email: </span>info@_supplier01_name.com"];
        $ret = \Dropdown::getDropdownName('glpi_suppliers', $supplier->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        // test of return without $tooltip
        $expected = ['name'    => $supplier->getName(),
                          'comment' => $supplier->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_suppliers', $supplier->getID(), true, true, false);
        $this->array($ret)->isIdenticalTo($expected);

        ///////////
        // Netpoint
        $netpoint = getItemByTypeName('Netpoint', '_netpoint01');
        $location = getItemByTypeName('Location', '_location01');
        $expected = $netpoint->getName()." (".$location->getName().")";
        $ret = \Dropdown::getDropdownName('glpi_netpoints', $netpoint->getID());
        $this->string($ret)->isIdenticalTo($expected);

        // test of return with comments
        $expected = ['name'    => $expected,
                          'comment' => "Comment for netpoint _netpoint01"];
        $ret = \Dropdown::getDropdownName('glpi_netpoints', $netpoint->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        // test of return without $tooltip
        $ret = \Dropdown::getDropdownName('glpi_netpoints', $netpoint->getID(), true, true, false);
        $this->array($ret)->isIdenticalTo($expected);

        ///////////
        // Budget
        $budget = getItemByTypeName('Budget', '_budget01');
        $expected = $budget->getName();
        $ret = \Dropdown::getDropdownName('glpi_budgets', $budget->getID());
        $this->string($ret)->isIdenticalTo($expected);

        // test of return with comments
        $expected = ['name'    =>  $budget->getName(),
                          'comment' => "Comment for budget _budget01<br><span class='b'>Location</span>: ".
                                         "_location01<br><span class='b'>Type</span>: _budgettype01<br><span class='b'>".
                                         "Start date</span>: 2016-10-18 <br><span class='b'>End date</span>: 2016-12-31 "];
        $ret = \Dropdown::getDropdownName('glpi_budgets', $budget->getID(), true);
        $this->array($ret)->isIdenticalTo($expected);

        // Default tooltip=true must not resolve references for a discarded comment.
        $database = $DB;
        $probe = new DropdownScalarReadProbe($database->getDoctrineConnection());
        $this->mockGenerator->orphanize('__construct');
        $adapter = new DBmysql();
        $this->calling($adapter)->getProvider = $database->getProvider();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        try {
            $DB = $adapter;
            $this->string(LegacyDropdown::getDropdownName('glpi_budgets', $budget->getID(), false, false))
                ->isIdenticalTo($budget->getName());
            $this->array($probe->queries)->hasSize(1);
            $this->string($probe->queries[0]['sql'])->contains('glpi_budgets');
            $this->array(array_values($probe->queries[0]['params']))->contains((int)$budget->getID());
            $probe->queries = [];
            $this->array(LegacyDropdown::getDropdownName('glpi_budgets', $budget->getID(), true, false))
                ->isIdenticalTo($expected);
            $this->array($probe->queries)->hasSize(3);
            foreach ([0 => ['glpi_budgets', $budget->getID()],
                1 => ['glpi_locations', $budget->fields['locations_id']],
                2 => ['glpi_budgettypes', $budget->fields['budgettypes_id']]] as $index => [$table, $target]) {
                $this->string($probe->queries[$index]['sql'])->contains($table);
                $this->array(array_values($probe->queries[$index]['params']))->contains((int)$target);
            }
        } finally {
            $DB = $database;
        }

        // test of return without $tooltip
        $expected = ['name'    => $budget->getName(),
                          'comment' => $budget->fields['comment']];
        $ret = \Dropdown::getDropdownName('glpi_budgets', $budget->getID(), true, true, false);
        $this->array($ret)->isIdenticalTo($expected);
    }

    public function testGetDropdownNetpoint()
    {
        $netpoint = getItemByTypeName('Netpoint', '_netpoint01');
        $location = getItemByTypeName('Location', '_location01');
        $ret = \Dropdown::getDropdownNetpoint([], false);
        $this->array($ret)->hasKeys(['count', 'results'])->integer['count']->isIdenticalTo(1);
        $this->array($ret['results'])->isIdenticalTo([
           [
              'id'     => 0,
              'text'   => '-----'
           ], [
              'id'     => $netpoint->fields['id'],
              'text'   => $netpoint->getName() . ' (' . $location->getName() . ')',
              'title'  =>  $netpoint->getName() . ' - ' . $location->getName() . ' - ' . $netpoint->fields['comment']
           ]
        ]);
    }

    public function dataGetValueWithUnit()
    {
        return [
              [1,      'auto',        null, '1024 Kio'],
              [1,      'auto',        null, '1024 Kio'],
              [1025,   'auto',        null, '1 Gio'],
              [1,      'year',        null, '1 year'],
              [2,      'year',        null, '2 years'],
              [3,      '%',           null, '3%'],
              ['foo',  'bar',         null, 'foo bar'],
              [1,      'month',       null, '1 month'],
              [2,      'month',       null, '2 months'],
              ['any',  '',            null, 'any'],
              [1,      'day',         null, '1 day'],
              [2,      'day',         null, '2 days'],
              [1,      'hour',        null, '1 hour'],
              [2,      'hour',        null, '2 hours'],
              [1,      'minute',      null, '1 minute'],
              [2,      'minute',      null, '2 minutes'],
              [1,      'second',      null, '1 second'],
              [2,      'second',      null, '2 seconds'],
              [1,      'millisecond', null, '1 millisecond'],
              [2,      'millisecond', null, '2 milliseconds'],
              [10,     'bar',         null, '10 bar'],

              [3.3597, '%',           0,    '3%'],
              [3.3597, '%',           2,    '3.36%'],
              [3.3597, '%',           6,    '3.359700%'],
              [3579,   'day',         0,    '3&nbsp;579 days'],
        ];
    }

    /**
     * @dataProvider dataGetValueWithUnit
     */
    public function testGetValueWithUnit($input, $unit, $decimals, $expected)
    {
        $value = $decimals !== null
           ? \Dropdown::getValueWithUnit($input, $unit, $decimals)
           : \Dropdown::getValueWithUnit($input, $unit);
        $this->string($value)->isIdenticalTo($expected);
    }

    protected function getDropdownValueProvider()
    {
        return [
           [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'           => '_cat_1',
                             'level'          => 1,
                             'title'          => '_cat_1 - Comment for category _cat_1',
                             'selection_text' => '_cat_1',
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 2
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => 'subcat'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'   => '_cat_1',
                             'level'  => 1,
                             'disabled' => true
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 1,
                 'emptylabel'            => 'EEEEEE',
                 'itemtype'              => 'TaskCategory'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'        => 0,
                       'text'      => 'EEEEEE'
                    ],
                    1 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'           => '_cat_1',
                             'level'          => 1,
                             'title'          => '_cat_1 - Comment for category _cat_1',
                             'selection_text' => '_cat_1',
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 2
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'used'                  => [getItemByTypeName('TaskCategory', '_cat_1', true)]
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'   => '_cat_1',
                             'level'  => 1,
                             'disabled' => true
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'Computer',
                 'entity_restrict'       => getItemByTypeName('Entity', '_test_child_2', true)
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'text'      => 'Root entity > _test_root_entity > _test_child_2',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('Computer', '_test_pc21', true),
                             'text'   => '_test_pc21',
                             'title'  => '_test_pc21',
                          ],
                          1 => [
                             'id'     => getItemByTypeName('Computer', '_test_pc22', true),
                             'text'   => '_test_pc22',
                             'title'  => '_test_pc22',
                          ]
                       ]
                    ]
                 ],
                 'count'     => 2
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'Computer',
                 'entity_restrict'       => '[' . getItemByTypeName('Entity', '_test_child_2', true) .']'
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'text'      => 'Root entity > _test_root_entity > _test_child_2',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('Computer', '_test_pc21', true),
                             'text'   => '_test_pc21',
                             'title'  => '_test_pc21',
                          ],
                          1 => [
                             'id'     => getItemByTypeName('Computer', '_test_pc22', true),
                             'text'   => '_test_pc22',
                             'title'  => '_test_pc22',
                          ]
                       ]
                    ]
                 ],
                 'count'     => 2
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'Computer',
                 'entity_restrict'       => getItemByTypeName('Entity', '_test_child_2', true),
                 'searchText'            => '22'
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'text'      => 'Root entity > _test_root_entity > _test_child_2',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('Computer', '_test_pc22', true),
                             'text'   => '_test_pc22',
                             'title'  => '_test_pc22',
                          ]
                       ]
                    ]
                 ],
                 'count'     => 1
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => 'subcat',
                 'toadd'                 => ['key' => 'value']
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'     => 'key',
                       'text'   => 'value'
                    ],
                    1 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'     => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'   => '_cat_1',
                             'level'  => 1,
                             'disabled' => true
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => 'subcat'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_cat_1 > _subcat_1',
                             'level'          => 0,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ],
              'session_params' => [
                 'glpiuse_flat_dropdowntree' => true
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'           => '_cat_1',
                             'level'          => 0,
                             'title'          => '_cat_1 - Comment for category _cat_1',
                             'selection_text' => '_cat_1',
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_cat_1 > _subcat_1',
                             'level'          => 0,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 2
              ],
              'session_params' => [
                 'glpiuse_flat_dropdowntree' => true
              ]
           ], [
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => 'subcat',
                 'permit_select_parent'  => true
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'           => '_cat_1',
                             'level'          => 1,
                             'title'          => '_cat_1 - Comment for category _cat_1',
                             'selection_text' => '_cat_1',
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ]
           ], [
              // search using id on CommonTreeDropdown but without "glpiis_ids_visible" set to true -> no results
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => getItemByTypeName('TaskCategory', '_subcat_1', true),
              ],
              'expected'  => [
                 'results' => [
                 ],
                 'count' => 0
              ],
              'session_params' => [
                 'glpiis_ids_visible' => false
              ]
           ], [
              // search using id on CommonTreeDropdown with "glpiis_ids_visible" set to true -> results
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'TaskCategory',
                 'searchText'            => getItemByTypeName('TaskCategory', '_subcat_1', true),
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text'      => 'Root entity',
                       'children'  => [
                          0 => [
                             'id'             => getItemByTypeName('TaskCategory', '_cat_1', true),
                             'text'           => '_cat_1',
                             'level'          => 1,
                             'disabled'       => true
                          ],
                          1 => [
                             'id'             => getItemByTypeName('TaskCategory', '_subcat_1', true),
                             'text'           => '_subcat_1 (' . getItemByTypeName('TaskCategory', '_subcat_1', true) . ')',
                             'level'          => 2,
                             'title'          => '_cat_1 > _subcat_1 - Comment for sub-category _subcat_1',
                             'selection_text' => '_cat_1 > _subcat_1',
                          ]
                       ]
                    ]
                 ],
                 'count' => 1
              ],
              'session_params' => [
                 'glpiis_ids_visible' => true
              ]
           ], [
              // search using id on "not a CommonTreeDropdown" but without "glpiis_ids_visible" set to true -> no results
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'DocumentType',
                 'searchText'            => getItemByTypeName('DocumentType', 'markdown', true),
              ],
              'expected'  => [
                 'results' => [
                 ],
                 'count' => 0
              ],
              'session_params' => [
                 'glpiis_ids_visible' => false
              ]
           ], [
              // search using id on "not a CommonTreeDropdown" with "glpiis_ids_visible" set to true -> results
              'params' => [
                 'display_emptychoice'   => 0,
                 'itemtype'              => 'DocumentType',
                 'searchText'            => getItemByTypeName('DocumentType', 'markdown', true),
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'             => getItemByTypeName('DocumentType', 'markdown', true),
                       'text'           => 'markdown (' . getItemByTypeName('DocumentType', 'markdown', true) . ')',
                       'title'          => 'markdown',
                    ]
                 ],
                 'count' => 1
              ],
              'session_params' => [
                 'glpiis_ids_visible' => true
              ]
           ], [
              'params' => [
                 'display_emptychoice' => 0,
                 'itemtype'            => 'ComputerModel',
              ],
              'expected'  => [
                 'results'   => [
                    [
                       'id'     => getItemByTypeName('ComputerModel', '_test_computermodel_1', true),
                       'text'   => '_test_computermodel_1 - CMP_ADEAF5E1',
                       'title'  => '_test_computermodel_1 - CMP_ADEAF5E1',
                    ],
                    [
                       'id'     => getItemByTypeName('ComputerModel', '_test_computermodel_2', true),
                       'text'   => '_test_computermodel_2 - CMP_567AEC68',
                       'title'  => '_test_computermodel_2 - CMP_567AEC68',
                    ]
                 ],
                 'count'     => 2
              ]
           ], [
              'params' => [
                 'display_emptychoice' => 0,
                 'itemtype'            => 'ComputerModel',
                 'searchText'          => 'CMP_56',
              ],
              'expected'  => [
                 'results'   => [
                    [
                       'id'     => getItemByTypeName('ComputerModel', '_test_computermodel_2', true),
                       'text'   => '_test_computermodel_2 - CMP_567AEC68',
                       'title'  => '_test_computermodel_2 - CMP_567AEC68',
                    ]
                 ],
                 'count'     => 1
              ]
           ],
        ];
    }

    /**
     * @dataProvider getDropdownValueProvider
     */
    public function testGetDropdownValue($params, $expected, $session_params = [])
    {
        $this->login();

        $bkp_params = [];
        //set session params if any
        if (count($session_params)) {
            foreach ($session_params as $param => $value) {
                if (isset($_SESSION[$param])) {
                    $bkp_params[$param] = $_SESSION[$param];
                }
                $_SESSION[$param] = $value;
            }
        }

        $params['_idor_token'] = $this->generateIdor($params);

        $result = \Dropdown::getDropdownValue($params, false);
        unset($result['pagination']);
        array_walk_recursive(
            $result,
            static function (&$value, $key) {
                if ($key === 'text' && is_string($value)) {
                    $value = preg_replace('/^(?:&nbsp;)+>\s*&nbsp;/', '', $value);
                }
            }
        );

        //reset session params before executing test
        if (count($session_params)) {
            foreach ($session_params as $param => $value) {
                if (isset($bkp_params[$param])) {
                    $_SESSION[$param] = $bkp_params[$param];
                } else {
                    unset($_SESSION[$param]);
                }
            }
        }

        $this->array($result)->isEqualTo($expected);
    }

    protected function getDropdownConnectProvider()
    {
        $encoded_sep = \Toolbox::clean_cross_side_scripting_deep('>');

        return [
           [
              'params'    => [
                 'fromtype'  => 'Computer',
                 'itemtype'  => 'Printer'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id' => 0,
                       'text' => '-----',
                    ],
                    1 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_all', true),
                             'text'   => '_test_printer_all',
                          ],
                          1 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent0', true),
                             'text'   => '_test_printer_ent0',
                          ]
                       ]
                    ],
                    2 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity {$encoded_sep} _test_child_1",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent1', true),
                             'text'   => '_test_printer_ent1',
                          ]
                       ]
                    ],
                    3 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity {$encoded_sep} _test_child_2",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent2', true),
                             'text'   => '_test_printer_ent2',
                          ]
                       ]
                    ]
                 ]
              ]
           ], [
              'params'    => [
                 'fromtype'  => 'Computer',
                 'itemtype'  => 'Printer',
                 'used'      => [
                    'Printer' => [
                       getItemByTypeName('Printer', '_test_printer_ent0', true),
                       getItemByTypeName('Printer', '_test_printer_ent2', true)
                    ]
                 ]
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id' => 0,
                       'text' => '-----',
                    ],
                    1 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_all', true),
                             'text'   => '_test_printer_all',
                          ]
                       ]
                    ],
                    2 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity {$encoded_sep} _test_child_1",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent1', true),
                             'text'   => '_test_printer_ent1',
                          ]
                       ]
                    ]
                 ]
              ]
           ], [
              'params'    => [
                 'fromtype'     => 'Computer',
                 'itemtype'     => 'Printer',
                 'searchText'   => 'ent0'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent0', true),
                             'text'   => '_test_printer_ent0',
                          ]
                       ]
                    ]
                 ]
              ]
           ], [
              'params'    => [
                 'fromtype'     => 'Computer',
                 'itemtype'     => 'Printer',
                 'searchText'   => 'ent0'
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'text' => "Root entity {$encoded_sep} _test_root_entity",
                       'children' => [
                          0 => [
                             'id'     => getItemByTypeName('Printer', '_test_printer_ent0', true),
                             'text'   => '_test_printer_ent0 (' .getItemByTypeName('Printer', '_test_printer_ent0', true) . ')',
                          ]
                       ]
                    ]
                 ]
              ],
              'session_params' => [
                 'glpiis_ids_visible' => true
              ]
           ]
        ];
    }

    /**
     * @dataProvider getDropdownConnectProvider
     */
    public function testGetDropdownConnect($params, $expected, $session_params = [])
    {
        $this->login();

        $bkp_params = [];
        //set session params if any
        if (count($session_params)) {
            foreach ($session_params as $param => $value) {
                if (isset($_SESSION[$param])) {
                    $bkp_params[$param] = $_SESSION[$param];
                }
                $_SESSION[$param] = $value;
            }
        }

        $params['_idor_token'] = $this->generateIdor($params);

        $result = \Dropdown::getDropdownConnect($params, false);

        //reset session params before executing test
        if (count($session_params)) {
            foreach ($session_params as $param => $value) {
                if (isset($bkp_params[$param])) {
                    $_SESSION[$param] = $bkp_params[$param];
                } else {
                    unset($_SESSION[$param]);
                }
            }
        }

        $this->array($result)->isIdenticalTo($expected);
    }

    protected function getDropdownNumberProvider()
    {
        return [
           [
              'params'    => [],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'id'     => 1,
                       'text'   => '1'
                    ],
                    1 => [
                       'id'     => 2,
                       'text'   => '2'
                    ],
                    2 => [
                       'id'     => 3,
                       'text'   => '3'
                    ],
                    3 => [
                       'id'     => 4,
                       'text'   => '4'
                    ],
                    4 => [
                       'id'     => 5,
                       'text'   => '5'
                    ],
                    5 => [
                       'id'     => 6,
                       'text'   => '6'
                    ],
                    6 => [
                       'id'     => 7,
                       'text'   => '7'
                    ],
                    7 => [
                       'id'     => 8,
                       'text'   => '8'
                    ],
                    8 => [
                       'id'     => 9,
                       'text'   => '9'
                    ],
                    9 => [
                       'id'     => 10,
                       'text'   => '10'
                    ]
                 ],
                 'count'     => 10
              ]
           ], [
              'params'    => [
                 'min'    => 10,
                 'max'    => 30,
                 'step'   => 10
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'id'     => 10,
                       'text'   => '10'
                    ],
                    1 => [
                       'id'     => 20,
                       'text'   => '20'
                    ],
                    2 => [
                       'id'     => 30,
                       'text'   => '30'
                    ]
                 ],
                 'count'     => 3
              ]
           ], [
              'params'    => [
                 'min'    => 10,
                 'max'    => 30,
                 'step'   => 10,
                 'used'   => [20]
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'id'     => 10,
                       'text'   => '10'
                    ],
                    1 => [
                       'id'     => 30,
                       'text'   => '30'
                    ]
                 ],
                 'count'     => 2
              ]
           ], [
              'params'    => [
                 'min'    => 10,
                 'max'    => 30,
                 'step'   => 10,
                 'used'   => [20],
                 'toadd'  => [5 => 'five']
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'id'     => 5,
                       'text'   => 'five'
                    ],
                    1 => [
                       'id'     => 10,
                       'text'   => '10'
                    ],
                    2 => [
                       'id'     => 30,
                       'text'   => '30'
                    ]
                 ],
                 'count'     => 2
              ]
           ], [
              'params'    => [
                 'min'    => 10,
                 'max'    => 30,
                 'step'   => 10,
                 'used'   => [20],
                 'unit'   => 'second'
              ],
              'expected'  => [
                 'results'   => [
                    0 => [
                       'id'     => 10,
                       'text'   => '10 seconds'
                    ],
                    1 => [
                       'id'     => 30,
                       'text'   => '30 seconds'
                    ]
                 ],
                 'count'     => 2
              ]
           ]
        ];
    }

    /**
     * @dataProvider getDropdownNumberProvider
     */
    public function testGetDropdownNumber($params, $expected)
    {
        global $CFG_GLPI;
        $orig_max = $CFG_GLPI['dropdown_max'];
        $CFG_GLPI['dropdown_max'] = 10;
        $result = \Dropdown::getDropdownNumber($params, false);
        $CFG_GLPI['dropdown_max'] = $orig_max;
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testShowNumberInitializesAjaxDropdown()
    {
        $output = \Dropdown::showNumber(
            'warranty_duration',
            [
              'display' => false,
              'min'     => 0,
              'max'     => 120,
              'unit'    => 'month',
              'toadd'   => [-1 => 'Forever'],
            ]
        );

        $this->string($output)
           ->contains('getDropdownNumber.php')
           ->contains('select.select2')
           ->contains("type: 'POST'")
           ->contains('searchText')
           ->contains('"max":120')
           ->contains('0 months');
    }

    protected function getDropdownUsersProvider()
    {
        return [
           [
              'params'    => [],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'     => 0,
                       'text'   => '-----',
                    ],
                    1 => [
                       'id'     => (int)getItemByTypeName('User', '_test_user', true),
                       'text'   => '_test_user',
                       'title'  => '_test_user - _test_user',
                    ],
                    2 => [
                       'id'     => (int)getItemByTypeName('User', 'itsm', true),
                       'text'   => 'itsm',
                       'title'  => 'itsm - itsm',
                    ],
                    3 => [
                       'id'     => (int)getItemByTypeName('User', 'normal', true),
                       'text'   => 'normal',
                       'title'  => 'normal - normal',
                    ],
                    4 => [
                       'id'     => (int)getItemByTypeName('User', 'post-only', true),
                       'text'   => 'post-only',
                       'title'  => 'post-only - post-only',
                    ],
                    5 => [
                       'id'     => (int)getItemByTypeName('User', 'tech', true),
                       'text'   => 'tech',
                       'title'  => 'tech - tech',
                    ]
                 ],
                 'count' => 5
              ]
           ], [
              'params'    => [
                 'used'   => [
                    getItemByTypeName('User', 'itsm', true),
                    getItemByTypeName('User', 'tech', true)
                 ]
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'     => 0,
                       'text'   => '-----',
                    ],
                    1 => [
                       'id'     => (int)getItemByTypeName('User', '_test_user', true),
                       'text'   => '_test_user',
                       'title'  => '_test_user - _test_user',
                    ],
                    2 => [
                       'id'     => (int)getItemByTypeName('User', 'normal', true),
                       'text'   => 'normal',
                       'title'  => 'normal - normal',
                    ],
                    3 => [
                       'id'     => (int)getItemByTypeName('User', 'post-only', true),
                       'text'   => 'post-only',
                       'title'  => 'post-only - post-only',
                    ]
                 ],
                 'count' => 3
              ]
           ], [
              'params'    => [
                 'all'    => true,
                 'used'   => [
                    getItemByTypeName('User', 'itsm', true),
                    getItemByTypeName('User', 'tech', true),
                    getItemByTypeName('User', 'normal', true),
                    getItemByTypeName('User', 'post-only', true)
                 ]
              ],
              'expected'  => [
                 'results' => [
                    0 => [
                       'id'     => 0,
                       'text'   => 'All',
                    ],
                    1 => [
                       'id'     => (int)getItemByTypeName('User', '_test_user', true),
                       'text'   => '_test_user',
                       'title'  => '_test_user - _test_user',
                    ]
                 ],
                 'count' => 1
              ]
           ]
        ];
    }

    /**
     * @dataProvider getDropdownUsersProvider
     */
    public function testGetDropdownUsers($params, $expected)
    {
        global $DB;
        $this->login();

        // These fixtures have no display names, so the configured database
        // collation orders their logins. In particular, en_US.utf8 PostgreSQL
        // sorts _test_user after tech, unlike MySQL and PostgreSQL's C locale.
        // Keep exact rows and ordering assertions without imposing one locale.
        $emptyChoice = array_shift($expected['results']);
        $choices = array_column($expected['results'], null, 'id');
        $orderedIds = $DB->getDoctrineConnection()->fetchFirstColumn(
            'SELECT id FROM glpi_users WHERE id IN (?) ORDER BY name, id',
            [array_keys($choices)],
            [ArrayParameterType::INTEGER]
        );
        $this->integer(count($orderedIds))->isIdenticalTo(count($choices));
        $expected['results'] = [$emptyChoice];
        foreach ($orderedIds as $id) {
            $expected['results'][] = $choices[$id];
        }

        $params['_idor_token'] = \Session::getNewIDORToken('User');
        $result = \Dropdown::getDropdownUsers($params, false);
        $this->array($result)->isIdenticalTo($expected);
    }

    /**
     * Test getDropdownValue with paginated results on
     * an CommonTreeDropdown
     *
     * @return void
     */
    public function testGetDropdownValuePaginate()
    {
        //let's add some content in Locations
        $location = new \Location();
        for ($i = 0; $i <= 20; ++$i) {
            $this->integer(
                (int)$location->add([
                  'name'   => "Test location $i"
            ])
            )->isGreaterThan(0);
        }

        $post = [
           'itemtype'              => $location::getType(),
           'display_emptychoice'   => true,
           'entity_restrict'       => 0,
           'page'                  => 1,
           'page_limit'            => 10,
           '_idor_token'           => \Session::getNewIDORToken($location::getType())
        ];
        $values = \Dropdown::getDropdownValue($post);
        $values = (array)json_decode($values);

        $this->array($values)
           ->integer['count']->isEqualTo(10)
           ->array['results'];
        $this->array($values)->hasKey('pagination');

        $results = (array)$values['results'];
        $this->integer(count($results))->isGreaterThanOrEqualTo(2);
        $this->array((array)$results[0])
           ->isIdenticalTo([
              'id'     => 0,
              'text'   => '-----'
           ]);

        $list_results = [];
        foreach ($results as $result) {
            if (is_object($result) && isset($result->children)) {
                $list_results = (array)$result;
                break;
            }
        }
        $this->array($list_results)
           ->string['text']->contains('Root entity');
        $this->integer(count($list_results))->isGreaterThanOrEqualTo(2);

        $children = (array)$list_results['children'];
        $this->array($children)->hasSize(10);
        $this->array((array)$children[0])
           ->hasKeys([
              'id',
              'text',
              'level',
              'title',
              'selection_text'
           ]);

        $post['page'] = 2;
        $values = \Dropdown::getDropdownValue($post);
        $values = (array)json_decode($values);

        $this->array($values)
           ->integer['count']->isEqualTo(10);

        $this->array($values['results'])->hasSize(10);
        $this->array((array)$values['results'][0])
           ->hasKeys([
              'id',
              'text',
              'level',
              'title',
              'selection_text'
           ]);

        //use a string condition
        // Put condition in session and post its key
        $condition = ['name' => ['LIKE', "%3%"]];
        $condition_key = sha1(serialize($condition));
        $_SESSION['glpicondition'][$condition_key] = $condition;
        $post = [
           'itemtype'              => $location::getType(),
           'condition'             => $condition_key,
           'display_emptychoice'   => true,
           'entity_restrict'       => 0,
           'page'                  => 1,
           'page_limit'            => 10,
           '_idor_token'           => \Session::getNewIDORToken($location::getType())
        ];
        $values = \Dropdown::getDropdownValue($post);
        $values = (array)json_decode($values);

        $this->array($values)
           ->integer['count']->isEqualTo(2)
           ->array['results'];
        $this->integer(count((array)$values['results']))->isGreaterThanOrEqualTo(2);

        //use a condition that does not exists in session
        $post = [
           'itemtype'              => $location::getType(),
           'condition'             => 'not_in_session',
           'display_emptychoice'   => true,
           'entity_restrict'       => 0,
           'page'                  => 1,
           'page_limit'            => 10,
           '_idor_token'           => \Session::getNewIDORToken($location::getType())
        ];
        $values = \Dropdown::getDropdownValue($post);
        $values = (array)json_decode($values);

        $this->array($values)
           ->integer['count']->isEqualTo(10)
           ->array['results'];
        $this->integer(count((array)$values['results']))->isGreaterThanOrEqualTo(2);

    }

    private function generateIdor(array $params = [])
    {
        $idor_add_params = [];
        if (isset($params['entity_restrict'])) {
            $idor_add_params['entity_restrict'] = $params['entity_restrict'];
        }
        return \Session::getNewIDORToken(($params['itemtype'] ?? ''), $idor_add_params);
    }

    /**
     * Data provider for testDropdownNumber
     *
     * @return Generator
     */
    protected function testDropdownNumberProvider(): Generator
    {
        yield [
           'params' => [
              'min'  => 1,
              'max'  => 4,
              'step' => 1,
              'unit' => "",
           ],
           'expected' => [1, 2, 3, 4]
        ];

        yield [
           'params' => [
              'min'  => 1,
              'max'  => 4,
              'step' => 0.5,
              'unit' => "",
           ],
           'expected' => [1, 1.5, 2, 2.5, 3, 3.5, 4]
        ];

        yield [
           'params' => [
              'min'  => 1,
              'max'  => 4,
              'step' => 2,
              'unit' => "",
           ],
           'expected' => [1, 3]
        ];

        yield [
           'params' => [
              'min'  => 1,
              'max'  => 4,
              'step' => 2.5,
              'unit' => "",
           ],
           'expected' => [1, 3.5]
        ];

        yield [
           'params' => [
              'min'  => 1,
              'max'  => 4,
              'step' => 5.5,
              'unit' => "",
           ],
           'expected' => [1]
        ];
    }

    /**
     * Tests for Dropdown::DropdownNumber()
     *
     * @dataprovider testDropdownNumberProvider
     *
     * @param array $params
     * @param array $expected
     *
     * @return void
     */
    public function testDropdownNumber(array $params, array $expected): void
    {
        $params['display'] = false;

        $data = \Dropdown::getDropdownNumber($params, false);
        $this->array($data)->hasKey("results");
        $this->array($data['results'])->hasSize(count($expected));
        $this->integer($data['count'])->isEqualTo(count($expected));

        foreach ($data['results'] as $key => $dropdown_entry) {
            $this->array($dropdown_entry)->hasKeys(["id", "text"]);

            $numeric_text_value = floatval($dropdown_entry['text']);
            $this->variable($dropdown_entry['id'])->isEqualTo($numeric_text_value);

            $this->variable($dropdown_entry['id'])->isEqualTo($expected[$key]);
        }
    }
}


/** Change grants at the existing virtual callback immediately before the authority clamp. */
class ScopeCallbackSupplier extends Supplier
{
    public static int $checks = 0;
    public static ?Closure $beforeAuthority = null;

    public static function getTable($classname = null)
    {
        return Supplier::getTable();
    }

    public function isEntityAssign()
    {
        if (++self::$checks === 2 && self::$beforeAuthority !== null) {
            (self::$beforeAuthority)();
        }
        return true;
    }
}


final class DropdownOwnedPlanCache extends ArrayAdapter
{
    public int $planWrites = 0;

    public function save(CacheItemInterface $item): bool
    {
        if (is_string($item->get()) && str_contains($item->get(), 'Doctrine\\ORM\\Query\\ParserResult')) {
            ++$this->planWrites;
        }
        return parent::save($item);
    }
}

final class DropdownUpperTextType extends TextType
{
    public function convertToPHPValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        return 'UPPER(' . $sqlExpr . ')';
    }
}


#[MappingEntity(repositoryClass: DropdownUnknownRepository::class)]
#[Table(name: 'glpi_budgets')]
final class DropdownUnknownBudget
{
    #[Id]
    #[Column(type: 'bigint')]
    public ?int $id = null;
    #[Column(type: 'string', nullable: true)]
    public ?string $name = null;
}

final class DropdownUnknownRepository extends DropdownChoiceRepository
{
    public static bool $constructedLocally = false;
    public static bool $called = false;

    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        self::$constructedLocally = $em->getConfiguration()->getMetadataCache() instanceof ArrayAdapter
            && $em->getConfiguration()->getQueryCache() === null;
        parent::__construct($em, $class);
    }

    public function choices(array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
    {
        self::$called = true;
        $rows = parent::choices($criteria, $order, $translations, $kind, $language, $limit, $offset);
        foreach ($rows as &$row) {
            $row['name'] .= ' original override';
        }
        return $rows;
    }
}


/** Observe the actual selected connection without opening another transaction or socket. */
class DropdownScalarReadProbe extends Connection
{
    public int $builders = 0;
    public array $queries = [];

    public function __construct(private readonly Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function isTransactionActive(): bool
    {
        return $this->selected->isTransactionActive();
    }

    public function createQueryBuilder(): DBALQueryBuilder
    {
        ++$this->builders;
        return parent::createQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}


final class DropdownScalarSqlText extends TextType
{
    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return 'UPPER(' . $sqlExpr . ')';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        throw new LogicException('Scalar aliases must not acquire entity PHP conversion');
    }
}

final class DropdownNegativeScalarId extends BigIntType
{
    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return '(' . $sqlExpr . ' * 0 - 1)';
    }
}
