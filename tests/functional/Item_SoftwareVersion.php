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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Phone as PhoneModel;
use InvalidArgumentException;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Entity;
use Item_SoftwareLicense;
use Item_SoftwareVersion as ItemSoftwareVersionModel;
use Computer as ComputerModel;
use DbUtils as DbUtilsModel;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Plugin;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use Software;
use SoftwareCategory;
use SoftwareLicense;
use SoftwareVersion;
use itsmng\Database\EntityRestriction;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\ItemSoftwareLicense;
use itsmng\Database\Entity\ItemSoftwareVersion;
use itsmng\Database\Entity\Software as SoftwareEntity;
use itsmng\Database\Entity\SoftwareCategory as SoftwareCategoryEntity;
use itsmng\Database\Entity\SoftwareLicense as SoftwareLicenseEntity;
use itsmng\Database\Entity\SoftwareLicenseType;
use itsmng\Database\Entity\SoftwareVersion as SoftwareVersionEntity;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\PostgresConnection;
use itsmng\Database\Repository\SoftwareInstallationRepository;
use itsmng\Database\SoftwareRenderingReadOperation;
use LogicException;
use mock\DBmysql as SoftwareAdapter;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/item_softwareversion.class.php */

/**
 * @engine isolate
 */
class Item_SoftwareVersion extends DbTestCase
{
    public function testTypeName()
    {
        $this->string(\Item_SoftwareVersion::getTypeName(1))->isIdenticalTo('Installation');
        $this->string(\Item_SoftwareVersion::getTypeName(0))->isIdenticalTo('Installations');
        $this->string(\Item_SoftwareVersion::getTypeName(10))->isIdenticalTo('Installations');
    }

    /** Public fixtures belong to this method's rollback frame, never to shared dataset links. */
    private function installationFixtures(array $entities = ['_test_root_entity']): array
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $suffix = bin2hex(random_bytes(6));
        $root = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $software = $this->createItem(Software::class, [
            'name' => 'Installation fixture software ' . $suffix,
            'entities_id' => $root,
            'is_recursive' => 1,
        ]);
        $versions = [];
        foreach ([1, 2] as $number) {
            $versions[] = $this->createItem(SoftwareVersion::class, [
                'name' => 'Installation fixture version ' . $number . ' ' . $suffix,
                'softwares_id' => $software->getID(),
                'entities_id' => $root,
                'is_recursive' => 1,
            ]);
        }
        $computers = [];
        foreach ($entities as $index => $entity) {
            $computer = $this->createItem(ComputerModel::class, [
                'name' => 'Installation fixture computer ' . $index . ' ' . $suffix,
                'entities_id' => (int)getItemByTypeName('Entity', $entity, true),
            ]);
            $this->boolean($computer->can($computer->getID(), UPDATE))->isTrue();
            $computers[] = $computer;
        }
        return [$software, $versions, $computers];
    }

    public function testPrepareInputForAdd()
    {
        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        $input = [
           'items_id'  => $computer1->getID(),
           'itemtype'  => 'Computer',
           'name'      => 'A name'
        ];

        $expected = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'A name',
           'entities_id'           => $computer1->getEntityID(),
           'is_recursive'          => 0,
           'is_template_item'      => $computer1->getField('is_template'),
           'is_deleted_item'       => $computer1->getField('is_deleted')
        ];

        $this->setEntity('_test_root_entity', true);
        $this->array($ins->prepareInputForAdd($input))->isIdenticalTo($expected);
    }

    public function testPrepareInputForUpdate()
    {
        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        $input = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'Another name'
        ];

        $expected = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'Another name',
           'entities_id'           => $computer1->getEntityID(),
           'is_template_item'      => $computer1->getField('is_template'),
           'is_deleted_item'       => $computer1->getField('is_deleted')
        ];

        $this->array($ins->prepareInputForUpdate($input))->isIdenticalTo($expected);
    }


    public function testVersionCountStagesStayFreshAndPreserveIndependentLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $originalTable = ComputerModel::getTable();
        try {
            [, $versions, $computers] = $this->installationFixtures(['_test_root_entity', '_test_child_1', '_test_child_1']);
            $version = (int)$versions[0]->getID();
            $links = [];
            foreach ($computers as $computer) {
                $links[] = $this->createItem(ItemSoftwareVersionModel::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'softwareversions_id' => $version,
                ]);
            }
            $root = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $this->integer(ItemSoftwareVersionModel::countForVersion((string)$version))->isIdenticalTo(3);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, $root))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, [$child]))->isIdenticalTo(2);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, []))->isIdenticalTo(0);
            $connection = $DB->getDoctrineConnection();
            $this->integer($connection->update('glpi_items_softwareversions', ['is_deleted' => true],
                ['id' => $links[2]->getID()], ['is_deleted' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(2);
            $writer = Orm::create($DB);
            $pending = $writer->find(ItemSoftwareVersion::class, (int)$links[0]->getID());
            $pending->is_deleted = true;
            Orm::read($DB, function (EntityManager $outer) use ($version, $links, $writer, $pending): void {
                $live = $outer->find(ItemSoftwareVersion::class, (int)$links[1]->getID());
                $live->is_deleted = true;
                $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(2);
                $this->boolean($outer->contains($live))->isTrue();
                $this->boolean($live->is_deleted)->isTrue();
                $this->boolean($writer->contains($pending))->isTrue();
                $this->boolean($pending->is_deleted)->isTrue();
            });
            $this->integer($connection->update('glpi_computers', ['is_template' => true],
                ['id' => $computers[0]->getID()], ['is_template' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_computers', ['is_template' => false, 'is_deleted' => true],
                ['id' => $computers[0]->getID()], ['is_template' => Types::BOOLEAN, 'is_deleted' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(1);
            ComputerModel::forceTable('glpi_phones');
            $this->exception(static fn () => ItemSoftwareVersionModel::countForVersion($version))
                ->isInstanceOf(InvalidArgumentException::class);
            ComputerModel::forceTable($originalTable);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(1);
            $writer->clear();
        } finally {
            ComputerModel::forceTable($originalTable);
            $_SESSION = $session;
        }
    }

    public function testVersionCountKeepsScopeCallbacksBetweenMaterializedKindStages(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            [, $versions, $computers] = $this->installationFixtures();
            $version = (int)$versions[0]->getID();
            $root = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $phone = $this->createItem(PhoneModel::class, ['name' => 'Staged phone ' . $this->getUniqueString(), 'entities_id' => $child]);
            $computerLink = $this->createItem(ItemSoftwareVersionModel::class, [
                'itemtype' => 'Computer', 'items_id' => $computers[0]->getID(), 'softwareversions_id' => $version,
            ]);
            $this->createItem(ItemSoftwareVersionModel::class, [
                'itemtype' => 'Phone', 'items_id' => $phone->getID(), 'softwareversions_id' => $version,
            ]);
            $connection = $DB->getDoctrineConnection();
            $scope = new class ($this, $connection, (int)$computerLink->getID(), $root, $child) {
                public int $calls = 0;
                public function __construct(private object $test, private Connection $connection, private int $link, private int $root, private int $child)
                {
                }
                public function __toString(): string
                {
                    ++$this->calls;
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    // The loose comparison and explicit cast each invoke this
                    // callback. Keep both conversions for a kind on one scope.
                    if ($this->calls <= 2) {
                        $_SESSION['glpiactiveentities'] = [$this->root];
                    } else {
                        if ($this->calls === 3) {
                            // Computer's count must finish before Phone's first conversion.
                            $this->connection->update('glpi_items_softwareversions', ['is_deleted' => true],
                                ['id' => $this->link], ['is_deleted' => Types::BOOLEAN]);
                        }
                        $_SESSION['glpiactiveentities'] = [$this->child];
                    }
                    return '';
                }
            };
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, $scope))->isIdenticalTo(2);
            $this->integer($scope->calls)->isIdenticalTo(4);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, [$root, $child]))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, $root))->isIdenticalTo(0);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testVersionCountRetainsCustomMetadataAndSelectedRouteAcrossStages(): void
    {
        global $DB;
        $originalAdapter = $DB;
        $session = $_SESSION;
        try {
            [, $versions, $computers] = $this->installationFixtures(['_test_root_entity', '_test_root_entity']);
            $version = (int)$versions[0]->getID();
            $links = [];
            foreach ($computers as $computer) {
                $links[] = $this->createItem(ItemSoftwareVersionModel::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'softwareversions_id' => $version,
                ]);
            }
            $connection = $DB->getDoctrineConnection();
            $this->integer($connection->update('glpi_items_softwareversions', ['is_deleted' => true],
                ['id' => $links[1]->getID()], ['is_deleted' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(1);
            $observer = new class () {
                public int $loads = 0;
                public int $clears = 0;
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    $metadata = $event->getClassMetadata();
                    if ($metadata->name === ItemSoftwareVersion::class && ++$this->loads === 1) {
                        // A custom operation's first mapping selects a different real
                        // boolean column. Its next stage must retain that exact mapping.
                        $metadata->fieldMappings['is_deleted']->columnName = 'is_template_item';
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $probe = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $probe->events = new EventManager();
            $probe->events->addEventListener([Events::loadClassMetadata, Events::onClear], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $probe;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new SoftwareAdapter();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                return $route;
            };
            $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
            $DB = $adapter;
            $scope = new class ($other, $route) {
                public function __construct(private Connection $other, private Connection &$route)
                {
                }
                public function __toString(): string
                {
                    $this->route = $this->other;
                    return '';
                }
            };
            $this->integer(ItemSoftwareVersionModel::countForVersion($version, $scope))->isIdenticalTo(2);
            $this->integer($observer->loads)->isIdenticalTo(1);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->array($other->queries)->isEmpty();
            $route = $probe;
            // A subsequent public operation constructs its own custom metadata.
            $this->integer(ItemSoftwareVersionModel::countForVersion($version))->isIdenticalTo(1);
            $this->integer($observer->loads)->isIdenticalTo(2);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->array($other->queries)->isEmpty();
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $session;
        }
    }

    public function testCountInstall()
    {
        [, $versions, $computers] = $this->installationFixtures([
            '_test_root_entity', '_test_child_1', '_test_child_1',
        ]);
        $computer1 = $computers[0]->getID();
        $computer11 = $computers[1]->getID();
        $computer12 = $computers[2]->getID();
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);
        $this->integer($ins->add([
           'items_id'              => $computer11,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);
        $this->integer($ins->add([
           'items_id'              => $computer12,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        // Count installations
        $this->setEntity('_test_root_entity', true);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in all tree')
           ->isIdenticalTo(3);

        $this->setEntity('_test_root_entity', false);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in root')
           ->isIdenticalTo(1);

        $this->setEntity('_test_child_1', false);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in child')
           ->isIdenticalTo(2);
    }

    public function testUpdateDatasFromComputer()
    {
        global $DB;

        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver1 = $versions[0]->getID();
        $ver2 = $versions[1]->getID();
        $c00 = (int)$DB->getDoctrineConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM glpi_computers');
        $this->boolean((new ComputerModel())->getFromDB($c00))->isFalse();

        // Do some installations
        $softver = new \Item_SoftwareVersion();
        $softver01 = $softver->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver1,
        ]);
        $this->integer((int)$softver01)->isGreaterThan(0);
        $softver02 = $softver->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver2,
        ]);
        $this->integer((int)$softver02)->isGreaterThan(0);

        foreach ([$softver01, $softver02] as $tsoftver) {
            $o = new \Item_SoftwareVersion();
            $this->boolean($o->getFromDb($tsoftver))->isTrue();
            $this->variable($o->getField('is_deleted_item'))->isEqualTo(0);
        }

        //computer that does not exists
        $this->boolean($softver->updateDatasForItem('Computer', $c00))->isFalse();

        //update existing computer
        $input = $computer1->fields;
        $input['is_deleted'] = '1';
        $this->boolean($computer1->update($input))->isTrue();

        $this->boolean($softver->updateDatasForItem('Computer', $computer1->getID()))->isTrue();

        //check if all has been updated
        foreach ([$softver01, $softver02] as $tsoftver) {
            $o = new \Item_SoftwareVersion();
            $this->boolean($o->getFromDb($tsoftver))->isTrue();
            $this->variable($o->getField('is_deleted_item'))->isEqualTo(1);
        }

        //restore computer state
        $input['is_deleted'] = '0';
        $this->boolean($computer1->update($input))->isTrue();
    }

    public function testCountForSoftware()
    {
        [$soft1, $versions, $computers] = $this->installationFixtures();
        $ver1 = $versions[0];
        $computer1 = $computers[0];

        $this->integer(
            (int)\Item_SoftwareVersion::countForSoftware($soft1->fields['id'])
        )->isIdenticalTo(0);

        $csoftver = new \Item_SoftwareVersion();
        $this->integer(
            (int)$csoftver->add([
               'items_id'              => $computer1->fields['id'],
               'itemtype'              => 'Computer',
               'softwareversions_id'   => $ver1->fields['id']
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)\Item_SoftwareVersion::countForSoftware($soft1->fields['id'])
        )->isIdenticalTo(1);
    }

    public function testInstalledSoftwareDisplayKeepsLinksAndCurrentHookReads(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $request = $_REQUEST;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $child = $this->createItem(Entity::class, ['name' => $this->getUniqueString(),
                'entities_id' => (int)$_SESSION['glpiactive_entity']]);
            [$software, $versions, $computers] = $this->installationFixtures([$child->fields['name']]);
            $computer = $computers[0];
            $entity = (int)$software->fields['entities_id'];
            $categoryParent = $this->createItem(SoftwareCategory::class, ['name' => 'Parent ' . $this->getUniqueString()]);
            $category = $this->createItem(SoftwareCategory::class, ['name' => 'Child & category',
                'softwarecategories_id' => $categoryParent->getID()]);
            $this->boolean($software->update(['id' => $software->getID(),
                'softwarecategories_id' => $category->getID(), 'comment' => 'Full permission fields']))->isTrue();
            $installations = [];
            $licenses = [];
            foreach ($versions as $index => $version) {
                $installations[] = $this->createItem(ItemSoftwareVersionModel::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                    'softwareversions_id' => $version->getID(),
                ]);
                $licenses[] = $this->createItem(SoftwareLicense::class, [
                    'name' => $this->getUniqueString(), 'softwares_id' => $software->getID(),
                    'entities_id' => $entity, 'is_recursive' => 1, 'number' => -1,
                    'softwareversions_id_buy' => $versions[0]->getID(),
                    // Public zero input becomes an empty owning reference, retaining buy fallback.
                    'softwareversions_id_use' => $index === 0 ? 0 : $version->getID(),
                ]);
                $this->createItem(Item_SoftwareLicense::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                    'softwarelicenses_id' => $licenses[$index]->getID(),
                ]);
            }
            $this->boolean($licenses[0]->getFromDB($licenses[0]->getID()))->isTrue();
            $this->variable($licenses[0]->fields['softwareversions_id_use'])->isNull();
            $this->boolean($DB->update('glpi_softwares', ['is_template' => true], ['id' => $software->getID()]))->isTrue();
            $this->boolean($software->getFromDB($software->getID()))->isTrue();
            // The installation lives in the child entity; the recursive parent software remains readable.
            $this->setEntity((int)$child->getID(), false);
            $_SESSION['glpiactiveprofile']['software'] = READ;
            $_SESSION['glpiactiveprofile']['dropdown'] = READ;
            $_SESSION['glpiis_ids_visible'] = true;
            $_REQUEST['criterion'] = -1;
            $PLUGIN_HOOKS['item_can'] = [];
            $PLUGIN_HOOKS['import_item'] = [];
            // Exercise the real recursive public selection before the rendering callbacks.
            $originalAdapter = $DB;
            $scopeSession = $_SESSION;
            $manager = Orm::forConnection($connection);
            $ordinary = new SoftwareInstallationRepository($manager);
            $probe = new SoftwareRenderingProbe($connection);
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new SoftwareAdapter();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $DB->getProvider();
            $scope = (new DbUtilsModel())->getEntityRestriction('glpi_softwares', '', '', true);
            $expected = $ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, null);
            $this->array($expected)->hasSize(2);
            $publicExpected = $expected;
            foreach ($publicExpected as &$row) {
                unset($row['is_dynamic']);
            }
            unset($row);
            // The ordinary public path reuses its owner after the first read.
            $this->array(ItemSoftwareVersionModel::getFromItem($computer))->isIdenticalTo($publicExpected);
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeRead = $factories->getValue();
            $this->array(ItemSoftwareVersionModel::getFromItem($computer))->isIdenticalTo($publicExpected);
            $this->integer($factories->getValue() - $beforeRead)->isIdenticalTo(0);
            Orm::withReadConnection($connection, function (?EntityManager $outer) use ($connection, $computer, $software, $publicExpected, $factories): void {
                $this->boolean($connection->ownsApplicationEntityManager($outer))->isTrue();
                $managed = $outer->getReference(SoftwareEntity::class, (int)$software->getID());
                $beforeNested = $factories->getValue();
                $this->array(ItemSoftwareVersionModel::getFromItem($computer))->isIdenticalTo($publicExpected);
                $this->integer($factories->getValue() - $beforeNested)->isIdenticalTo(1);
                $this->boolean($outer->contains($managed))->isTrue();
            });
            $reader = null;
            $string = Type::getType('string');
            $bigint = Type::getType('bigint');
            $integer = Type::getType('integer');
            $boolean = Type::getType('boolean');
            try {
                $DB = $adapter;
                $this->array(ItemSoftwareVersionModel::getFromItem($computer))->isIdenticalTo($publicExpected);
                $nativeLists = array_filter($probe->queryBuilders, static fn ($query) =>
                    str_contains(str_replace(['`', '"'], '', $query->getSQL()), 'FROM glpi_items_softwareversions i'));
                $this->array($nativeLists)->hasSize(1);
                $DB = $originalAdapter;
                $reader = new SoftwareRenderingReadOperation($probe);
                foreach ([null, 0, (int)$category->getID(), PHP_INT_MAX] as $categoryFilter) {
                    $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, $categoryFilter))
                        ->isIdenticalTo($ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, $categoryFilter));
                }
                foreach ([0, [], [$entity], (int)$child->getID()] as $entities) {
                    $selection = (new DbUtilsModel())->getEntityRestriction('glpi_softwares', '', $entities, true);
                    $this->array($reader->forSubject('Computer', (int)$computer->getID(), $selection, true, null))
                        ->isIdenticalTo($ordinary->forSubject('Computer', (int)$computer->getID(), $selection->wrappedCriteria(), true, null));
                }
                $custom = new EntityRestriction(['glpi_softwares.name' => $software->fields['name']], 'glpi_softwares', 'entities_id', false, null);
                $builders = $probe->builders;
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $custom, true, null))->isIdenticalTo($expected);
                $this->integer($probe->builders)->isIdenticalTo($builders);
                $connection->update('glpi_items_softwareversions', ['is_deleted' => true], ['id' => $installations[0]->getID()], ['is_deleted' => 'boolean', 'id' => 'bigint']);
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, null))->hasSize(1);
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, false, null))->isIdenticalTo($expected);
                $connection->update('glpi_items_softwareversions', ['is_deleted' => false], ['id' => $installations[0]->getID()], ['is_deleted' => 'boolean', 'id' => 'bigint']);
                Type::overrideType('string', new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'UPPER(' . $sqlExpr . ')';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        throw new LogicException('Installation scalar aliases skip PHP conversion');
                    }
                });
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, null))
                    ->isIdenticalTo($ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, null));
                Type::overrideType('string', new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'NULL';
                    }
                });
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, null))
                    ->isIdenticalTo($ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, null));
                Type::overrideType('integer', new class () extends IntegerType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                foreach ([$entity, [$entity]] as $entities) {
                    $convertedScope = (new DbUtilsModel())->getEntityRestriction('glpi_softwares', '', $entities, false);
                    $this->array($ordinary->forSubject('Computer', (int)$computer->getID(), $convertedScope->wrappedCriteria(), true, null))->isEmpty();
                    $this->array($reader->forSubject('Computer', (int)$computer->getID(), $convertedScope, true, null))->isEmpty();
                }
                Type::overrideType('integer', $integer);
                Type::overrideType('boolean', new class () extends BooleanType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(CASE WHEN ' . $sqlExpr . ' THEN FALSE ELSE TRUE END)';
                    }
                });
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, null))
                    ->isIdenticalTo($ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, null));
                Type::overrideType('boolean', $boolean);
                Type::overrideType('string', $string);
                Type::overrideType('bigint', new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                $this->array($ordinary->forSubject('Computer', (int)$computer->getID(), $scope->wrappedCriteria(), true, null))->isEmpty();
                $this->array($reader->forSubject('Computer', (int)$computer->getID(), $scope, true, null))->isEmpty();
                Type::overrideType('bigint', $bigint);
                $extension = new class ($connection) extends SoftwareRenderingProbe {
                    private ?EventManager $events = null;
                    public function getEventManager(): EventManager
                    {
                        return $this->events ??= new EventManager();
                    }
                };
                $local = new SoftwareRenderingReadOperation($extension);
                $listener = new class () {
                    public int $loads = 0;
                    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                    {
                        ++$this->loads;
                    }
                };
                $extension->getEventManager()->addEventListener([Events::loadClassMetadata], $listener);
                try {
                    $this->array($local->forSubject('Computer', (int)$computer->getID(), $scope, true, null))->isIdenticalTo($expected);
                    $this->integer($listener->loads)->isGreaterThan(0);
                    $this->integer($extension->builders)->isIdenticalTo(0);
                } finally {
                    $local->close();
                }
                // getID can change current authority and global route after the owner captured its connection.
                $callback = new class () extends ComputerModel {
                    public mixed $replacement;
                    public static function getType()
                    {
                        return 'Computer';
                    }
                    public function getID()
                    {
                        $GLOBALS['DB'] = $this->replacement;
                        $_SESSION['glpiactiveentities'] = [];
                        $_SESSION['glpishowallentities'] = false;
                        return parent::getID();
                    }
                };
                $callback->fields = $computer->fields;
                $callback->replacement = $originalAdapter;
                $DB = $adapter;
                $probe->queries = [];
                $this->array(ItemSoftwareVersionModel::getFromItem($callback))->isEmpty();
                $selectedReads = array_filter($probe->queries, static fn ($query) =>
                    str_contains(str_replace(['`', '"'], '', $query['sql']), 'FROM glpi_items_softwareversions i'));
                $this->array($selectedReads)->hasSize(1);
                $this->object($DB)->isIdenticalTo($originalAdapter);
            } finally {
                $DB = $originalAdapter;
                $_SESSION = $scopeSession;
                Type::overrideType('string', $string);
                Type::overrideType('bigint', $bigint);
                Type::overrideType('integer', $integer);
                Type::overrideType('boolean', $boolean);
                $connection->update('glpi_items_softwareversions', ['is_deleted' => false], ['id' => $installations[0]->getID()], ['is_deleted' => 'boolean', 'id' => 'bigint']);
                $reader?->close();
                $manager->clear();
            }
            $render = function () use ($computer): array {
                ob_start();
                try {
                    ItemSoftwareVersionModel::showForItem($computer);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->integer(preg_match('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $match))->isIdenticalTo(1);
                return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            };
            $this->array(ItemSoftwareVersionModel::getFromItem($computer))->isIdenticalTo($publicExpected);
            $beforeRender = $factories->getValue();
            $table = $render();
            $this->integer($factories->getValue() - $beforeRender)->isIdenticalTo(0);
            $rows = $table['dataSource']['rows'];
            $this->array($rows)->hasSize(2);
            $this->array(array_column($rows, 3))->isIdenticalTo([
                (string)$licenses[0]->getID(), (string)$licenses[1]->getID(),
            ]);
            $this->array($table['selection']['values'])->isIdenticalTo(array_map(
                static fn ($installation): string => 'item[Item_SoftwareVersion][' . $installation->getID() . ']',
                $installations
            ));
            foreach ($versions as $index => $version) {
                $this->string($rows[$index][0])->isIdenticalTo($software->getLink());
                $this->string($rows[$index][2])->isIdenticalTo($version->getLink());
                $this->string($rows[$index][5])->isIdenticalTo($category->getLink());
            }
            $this->string($rows[0][0])->contains('&withtemplate=1');
            $this->string($rows[0][5])->contains(htmlentities($category->fields['completename'], ENT_QUOTES, 'utf-8'));
            $_SESSION['glpiactiveprofile']['dropdown'] = 0;
            $denied = $render();
            $this->string($denied['dataSource']['rows'][0][5])->isIdenticalTo($category->getLink())->notContains('<a ');
            $_SESSION['glpiactiveprofile']['dropdown'] = READ;
            $this->boolean($DB->update('glpi_softwares', ['softwarecategories_id' => null], ['id' => $software->getID()]))->isTrue();
            $uncategorized = $render();
            $this->string($uncategorized['dataSource']['rows'][0][5])->isEmpty();
            $this->boolean($DB->update('glpi_softwares', ['softwarecategories_id' => $category->getID()], ['id' => $software->getID()]))->isTrue();

            $calls = 0;
            $comments = [];
            $names = [];
            $plugins->setValue(null, [...$active, 'effective_license_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['effective_license_fixture' => [Software::class =>
                static function (Software $item) use (&$calls, &$comments, &$names, $connection, $licenses, $versions): void {
                    $comments[] = $item->fields['comment'];
                    $names[] = $item->fields['name'];
                    if (++$calls === 1) {
                        // The next installed row must observe this write rather than an earlier batch.
                        $connection->update('glpi_softwarelicenses', [
                            'softwareversions_id_use' => $versions[0]->getID(),
                        ], ['id' => $licenses[1]->getID()]);
                        $connection->update('glpi_softwares', ['name' => 'Current callback name'], ['id' => $item->getID()]);
                        $item->right = false;
                    }
                }]];
            $hooked = $render();
            $this->integer($calls)->isIdenticalTo(2);
            $this->array($comments)->isIdenticalTo(['Full permission fields', 'Full permission fields']);
            $this->array($names)->isIdenticalTo([$software->fields['name'], 'Current callback name']);
            $this->array(array_column($hooked['dataSource']['rows'], 3))->isIdenticalTo([
                (string)$licenses[0]->getID(), '',
            ]);
            $this->string($hooked['dataSource']['rows'][0][0])->notContains('<a ');
            $this->string($hooked['dataSource']['rows'][1][0])->contains('<a ');
            $this->array($hooked['selection']['values'])->isIdenticalTo($table['selection']['values']);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $_SESSION = $session;
            $_REQUEST = $request;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testInstallationLicenseProjectionIsScopedAndBounded(): void
    {
        global $DB;
        $originalLevel = $DB->getDoctrineConnection()->getTransactionNestingLevel();
        $logger = new class () extends AbstractLogger {
            public array $queries = [];

            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql']; // SQL shape only, never credentials or parameters.
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        $parameters = $DB->getDoctrineConnection()->getParams();
        $connection = $DB->getProvider() === 'pgsql'
            ? PostgresConnection::create($parameters, $configuration)
            : MySQLConnection::create($parameters, $configuration);
        // An independent real writer owns this rolled-back graph, not DbTestCase's caller frame.
        $frame = OwnedMutationFrame::begin($connection);
        try {
            $manager = new EntityManager($connection, Orm::configuration($connection->getDatabasePlatform()));
            $root = $manager->getReference(EntityRecord::class, 0);
            $owner = max(
                (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_computers'),
                (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_monitors')
            ) + 1;
            $subjects = [];
            foreach (['Computer', 'Monitor'] as $kind) {
                $class = '\\itsmng\\Database\\Entity\\' . $kind;
                $metadata = $manager->getClassMetadata($class);
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
                $metadata->setIdGenerator(new AssignedGenerator());
                $subject = new $class();
                $subject->id = $owner;
                $subject->entities = $root;
                $subject->name = 'License projection ' . $kind;
                $manager->persist($subject);
                $subjects[$kind] = $subject;
            }
            $software = new SoftwareEntity();
            $software->entities = $root;
            $software->name = 'Installation license projection';
            $category = new SoftwareCategoryEntity();
            $category->name = 'Installation category';
            $category->completename = 'Parent > Installation category';
            $manager->persist($category);
            $software->softwarecategories = $category;
            $manager->persist($software);
            $type = new SoftwareLicenseType();
            $type->entities = $root;
            $type->name = 'Projection type';
            $manager->persist($type);
            $versions = [];
            $licenses = [];
            $allocate = static function ($license, string $kind) use ($manager, $subjects): void {
                $allocation = new ItemSoftwareLicense();
                $allocation->itemtype = $kind;
                $association = $allocation::referenceAssociation($kind);
                $allocation->{$association} = $subjects[$kind];
                $allocation->softwarelicenses = $license;
                $manager->persist($allocation);
            };
            // The last real installation is deliberately outside the requested page keys.
            for ($index = 0; $index < 26; ++$index) {
                $version = new SoftwareVersionEntity();
                $version->entities = $root;
                $version->softwares = $software;
                $version->name = 'Projection version ' . $index;
                $manager->persist($version);
                $versions[] = $version;
                $installation = new ItemSoftwareVersion();
                $installation->entities = $root;
                $installation->itemtype = 'Computer';
                $installation->computer = $subjects['Computer'];
                $installation->softwareversions = $version;
                $manager->persist($installation);
                $license = new SoftwareLicenseEntity();
                $license->entities = $root;
                $license->softwares = $software;
                $license->name = 'Projection license ' . $index;
                $license->serial = 'serial-' . $index;
                $license->useVersion = $version;
                $license->buyVersion = $version;
                $manager->persist($license);
                $licenses[] = $license;
                $allocate($license, 'Computer');
            }
            $allocate($licenses[0], 'Computer'); // Duplicate allocation, not another displayed license.
            $other = new SoftwareLicenseEntity();
            $other->entities = $root;
            $other->softwares = $software;
            $other->name = 'Buy and use on different versions';
            $other->serial = 'other';
            $other->buyVersion = $versions[0];
            $other->useVersion = $versions[1];
            $other->softwarelicensetypes = $type;
            $manager->persist($other);
            $allocate($other, 'Computer');
            $monitorLicense = new SoftwareLicenseEntity();
            $monitorLicense->entities = $root;
            $monitorLicense->softwares = $software;
            $monitorLicense->name = 'Monitor license';
            $monitorLicense->buyVersion = $versions[0];
            $manager->persist($monitorLicense);
            $allocate($monitorLicense, 'Monitor');
            $monitorInstallation = new ItemSoftwareVersion();
            $monitorInstallation->entities = $root;
            $monitorInstallation->itemtype = 'Monitor';
            $monitorInstallation->monitor = $subjects['Monitor'];
            $monitorInstallation->softwareversions = $versions[0];
            $manager->persist($monitorInstallation);
            $manager->flush();
            $keys = array_map(static fn ($version): array => [
                'itemtype' => 'Computer', 'items_id' => $owner, 'softwareversions_id' => $version->id,
            ], array_slice($versions, 0, 25));
            $manager->clear();
            $repository = new SoftwareInstallationRepository($manager);
            $logger->queries = [];
            $this->array($repository->licensesForInstallations([]))->isEmpty();
            $this->array($logger->queries)->isEmpty();
            foreach ([1, 25] as $size) {
                $logger->queries = [];
                $rows = $repository->licensesForInstallations(array_slice($keys, 0, $size));
                $this->array($logger->queries)->hasSize(1);
                $this->array(array_keys($rows))->isIdenticalTo(['Computer']);
                $this->array($rows['Computer'][$owner])->hasSize($size);
                $this->boolean(isset($rows['Computer'][$owner][$versions[25]->id]))->isFalse();
                $first = $rows['Computer'][$owner][$versions[0]->id];
                $expectedIds = [$licenses[0]->id, $other->id];
                sort($expectedIds);
                $this->array(array_keys($first))->isIdenticalTo($expectedIds);
                $this->array($first[$licenses[0]->id])->isIdenticalTo([
                    'id' => $licenses[0]->id, 'name' => 'Projection license 0', 'serial' => 'serial-0', 'type' => null,
                ]);
                $this->string($first[$other->id]['type'])->isIdenticalTo('Projection type');
                if ($size === 25) {
                    $this->boolean(isset($rows['Computer'][$owner][$versions[1]->id][$other->id]))->isTrue();
                }
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
            $logger->queries = [];
            $rows = $repository->licensesForInstallations([$keys[0], $keys[0], [
                'itemtype' => 'Monitor', 'items_id' => $owner, 'softwareversions_id' => $versions[0]->id,
            ]]);
            $this->array($logger->queries)->hasSize(1);
            $this->array(array_keys($rows['Monitor'][$owner][$versions[0]->id]))->isIdenticalTo([$monitorLicense->id]);
            $this->array($rows['Computer'][$owner])->hasSize(1);
            $this->array($rows['Computer'][$owner][$versions[0]->id])->hasSize(2);
            // Match real graph rows on opposite sides of the provider-safe batch boundary.
            $boundaryKeys = [$keys[0]];
            $absentVersion = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_softwareversions') + 1;
            for ($index = 0; $index < 249; ++$index) {
                $boundaryKeys[] = [
                    'itemtype' => 'Computer', 'items_id' => $owner, 'softwareversions_id' => $absentVersion + $index,
                ];
            }
            $logger->queries = [];
            $boundary = $repository->licensesForInstallations($boundaryKeys);
            $this->array($logger->queries)->hasSize(1);
            $this->array($boundary)->isIdenticalTo(['Computer' => $rows['Computer']]);
            $boundaryKeys[] = [
                'itemtype' => 'Monitor', 'items_id' => $owner, 'softwareversions_id' => $versions[0]->id,
            ];
            $boundaryKeys[] = $keys[0]; // Duplicate tuples must not consume another batch slot.
            $logger->queries = [];
            $boundary = $repository->licensesForInstallations($boundaryKeys);
            $this->array($logger->queries)->hasSize(2);
            $this->array($boundary)->hasSize(2);
            $this->array($boundary['Computer'])->isIdenticalTo($rows['Computer']);
            $this->array($boundary['Monitor'])->isIdenticalTo($rows['Monitor']);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $connection->update('glpi_softwarelicenses', ['name' => 'Changed on supplied writer'], ['id' => $licenses[0]->id]);
            $rows = $repository->licensesForInstallations([$keys[0]]);
            $this->string($rows['Computer'][$owner][$versions[0]->id][$licenses[0]->id]['name'])->isIdenticalTo('Changed on supplied writer');
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();

            // This table uses the effective version, unlike the buy-or-use view above.
            $logger->queries = [];
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, []))->isEmpty();
            $this->array($logger->queries)->isEmpty();
            // This view historically includes locked/deleted allocations; filtering them changes its IDs.
            $connection->update('glpi_items_softwarelicenses', ['is_deleted' => true], ['softwarelicenses_id' => $licenses[0]->id]);
            foreach ([1, 25] as $size) {
                $logger->queries = [];
                $effective = $repository->effectiveLicenseIdsForVersions('Computer', $owner, array_column(array_slice($keys, 0, $size), 'softwareversions_id'));
                $this->array($logger->queries)->hasSize(1);
                $this->array($effective)->hasSize($size);
                // Preserve both allocations, but do not include the other license's purchase version.
                $this->array($effective[$versions[0]->id])->isIdenticalTo([$licenses[0]->id, $licenses[0]->id]);
                if ($size === 25) {
                    $this->array($effective[$versions[1]->id])->isIdenticalTo([$licenses[1]->id, $other->id]);
                }
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
            // Same numeric owner on another item type; an unset use version falls back to purchase.
            $this->array($repository->effectiveLicenseIdsForVersions('Monitor', $owner, [$versions[0]->id]))
                ->isIdenticalTo([$versions[0]->id => [$monitorLicense->id]]);
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', PHP_INT_MAX, [$versions[0]->id]))->isEmpty();
            $boundaryVersions = [$versions[0]->id, ...range($absentVersion, $absentVersion + 248), $versions[25]->id, $versions[0]->id];
            $logger->queries = [];
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, $boundaryVersions))->isIdenticalTo([
                $versions[0]->id => [$licenses[0]->id, $licenses[0]->id],
                $versions[25]->id => [$licenses[25]->id],
            ]);
            $this->array($logger->queries)->hasSize(2);
            $connection->update('glpi_softwarelicenses', ['softwareversions_id_use' => $versions[0]->id], ['id' => $other->id]);
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, [$versions[0]->id, $versions[1]->id]))->isIdenticalTo([
                $versions[0]->id => [$licenses[0]->id, $licenses[0]->id, $other->id],
                $versions[1]->id => [$licenses[1]->id],
            ]);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->object($manager->getConnection())->isIdenticalTo($connection);

            $emptyDisplay = ['softwares' => [], 'versions' => [], 'categories' => []];
            $logger->queries = [];
            $this->array($repository->displayDataForInstallations([]))->isIdenticalTo($emptyDisplay);
            $this->array($logger->queries)->isEmpty();
            $displayRows = array_map(static fn ($version): array => [
                'softwares_id' => $software->id, 'verid' => $version->id, 'softwarecategories_id' => $category->id,
            ], $versions);
            foreach ([1, 25] as $size) {
                $logger->queries = [];
                $display = $repository->displayDataForInstallations(array_slice($displayRows, 0, $size));
                $this->array($logger->queries)->hasSize(3);
                $this->array($display['softwares'])->isIdenticalTo([$software->id => [
                    'id' => $software->id, 'name' => $software->name, 'entities_id' => 0,
                    'is_recursive' => 0, 'is_template' => 0,
                ]]);
                $this->array($display['versions'])->hasSize($size);
                $this->array($display['versions'][$versions[0]->id])->isIdenticalTo([
                    'id' => $versions[0]->id, 'name' => $versions[0]->name, 'softwares_id' => $software->id,
                ]);
                $this->array($display['categories'])->isIdenticalTo([$category->id => [
                    'id' => $category->id, 'name' => $category->name, 'completename' => $category->completename,
                ]]);
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
            $logger->queries = [];
            $this->array($repository->displayDataForInstallations([[
                'softwares_id' => $software->id, 'verid' => $versions[0]->id, 'softwarecategories_id' => 0,
            ]])['categories'])->isEmpty();
            $this->array($logger->queries)->hasSize(2);
            $this->array($repository->displayDataForInstallations([[
                'softwares_id' => PHP_INT_MAX, 'verid' => PHP_INT_MAX, 'softwarecategories_id' => PHP_INT_MAX,
            ]]))->isIdenticalTo($emptyDisplay);
            $displayBoundary = array_map(static fn (int $version): array => [
                'softwares_id' => $software->id, 'verid' => $version, 'softwarecategories_id' => $category->id,
            ], $boundaryVersions);
            $logger->queries = [];
            $display = $repository->displayDataForInstallations($displayBoundary);
            $this->array($logger->queries)->hasSize(4);
            $this->array(array_keys($display['versions']))->isIdenticalTo([$versions[0]->id, $versions[25]->id]);
            $managedSoftware = $manager->find(SoftwareEntity::class, $software->id);
            $connection->update('glpi_softwares', ['name' => 'Fresh link label'], ['id' => $software->id]);
            $this->string($repository->displayDataForInstallations([$displayRows[0]])['softwares'][$software->id]['name'])
                ->isIdenticalTo('Fresh link label');
            $this->string($managedSoftware->name)->isIdenticalTo('Installation license projection');
            $this->boolean($manager->contains($managedSoftware))->isTrue();
            $probe = new SoftwareRenderingProbe($connection);
            $reader = new SoftwareRenderingReadOperation($probe);
            $string = Type::getType('string');
            $bigint = Type::getType('bigint');
            try {
                $this->array($reader->rendering('Computer', $owner, []))->isIdenticalTo([
                    'licenses' => [], 'display' => $emptyDisplay,
                ]);
                $this->integer($probe->builders)->isIdenticalTo(0);
                $expected = [
                    'licenses' => $repository->effectiveLicenseIdsForVersions('Computer', $owner, array_column($displayRows, 'verid')),
                    'display' => $repository->displayDataForInstallations($displayRows),
                ];
                $this->array($reader->rendering('Computer', $owner, $displayRows))->isIdenticalTo($expected);
                $this->array($probe->queries)->hasSize(4);
                $this->integer($probe->builders)->isIdenticalTo(4);
                $this->array($probe->queries[0]['types'])->isIdenticalTo([
                    Types::BIGINT,
                    ArrayParameterType::INTEGER,
                    ArrayParameterType::INTEGER,
                ]);
                foreach (['Computer', 'Monitor'] as $kind) {
                    $this->array($reader->rendering($kind, $owner, $displayBoundary))->isIdenticalTo([
                        'licenses' => $repository->effectiveLicenseIdsForVersions($kind, $owner, array_column($displayBoundary, 'verid')),
                        'display' => $repository->displayDataForInstallations($displayBoundary),
                    ]);
                }
                $stringIds = array_map(static fn (array $row): array => array_map(strval(...), $row), $displayRows);
                $this->array($reader->rendering('Computer', $owner, $stringIds))->isIdenticalTo($expected);
                $connection->update('glpi_softwarecategories', ['name' => 'Fresh category'], ['id' => $category->id]);
                $this->string($reader->rendering('Computer', $owner, $displayRows)['display']['categories'][$category->id]['name'])
                    ->isIdenticalTo('Fresh category');
                Type::overrideType('string', new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'UPPER(' . $sqlExpr . ')';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        throw new LogicException('Scalar result aliases must not run PHP conversion');
                    }
                });
                $this->array($reader->rendering('Computer', $owner, $displayRows)['display'])
                    ->isIdenticalTo($repository->displayDataForInstallations($displayRows));
                $this->string($reader->rendering('Computer', $owner, $displayRows)['display']['softwares'][$software->id]['name'])
                    ->isIdenticalTo('FRESH LINK LABEL');
                Type::overrideType('string', new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'NULL';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        throw new LogicException('NULL scalar aliases must not run PHP conversion');
                    }
                });
                $this->array($reader->rendering('Computer', $owner, $displayRows)['display'])
                    ->isIdenticalTo($repository->displayDataForInstallations($displayRows));
                $this->variable($reader->rendering('Computer', $owner, $displayRows)['display']['softwares'][$software->id]['name'])->isNull();
                Type::overrideType('string', $string);
                Type::overrideType('bigint', new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, array_column($displayRows, 'verid')))->isEmpty();
                $converted = $reader->rendering('Computer', $owner, $displayRows);
                $this->array($converted['licenses'])->isEmpty();
                // Inferred array enum bindings must not acquire a per-ID BIGINT SQL conversion.
                $this->array($converted['display'])->isIdenticalTo($repository->displayDataForInstallations($displayRows));
                $this->array($converted['display']['softwares'])->hasSize(1);
                Type::overrideType('bigint', $bigint);
                $extension = new class ($connection) extends SoftwareRenderingProbe {
                    private ?EventManager $events = null;
                    public function getEventManager(): EventManager
                    {
                        return $this->events ??= new EventManager();
                    }
                };
                $local = new SoftwareRenderingReadOperation($extension);
                $listener = new class () {
                    public int $loads = 0;
                    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                    {
                        ++$this->loads;
                    }
                };
                $extension->getEventManager()->addEventListener([Events::loadClassMetadata], $listener);
                try {
                    $this->array($local->rendering('Computer', $owner, $displayRows))->isIdenticalTo([
                        'licenses' => $repository->effectiveLicenseIdsForVersions('Computer', $owner, array_column($displayRows, 'verid')),
                        'display' => $repository->displayDataForInstallations($displayRows),
                    ]);
                    $this->integer($listener->loads)->isGreaterThan(0);
                    $this->integer($extension->builders)->isIdenticalTo(0);
                } finally {
                    $local->close();
                }
            } finally {
                Type::overrideType('string', $string);
                Type::overrideType('bigint', $bigint);
                $reader->close();
            }

        } finally {
            try {
                $frame->rollBack();
            } finally {
                $connection->close();
            }
        }
        $this->integer($DB->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
    }
}

class SoftwareRenderingProbe extends ScalarReadProbe
{
    public array $queryBuilders = [];

    public function isTransactionActive(): bool
    {
        return $this->selected->isTransactionActive();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilders[] = parent::createQueryBuilder();
    }
}
