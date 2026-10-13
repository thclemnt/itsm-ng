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
use Doctrine\ORM\EntityManager;
use Software as LegacySoftware;
use SoftwareLicense as LegacySoftwareLicense;
use itsmng\Database\Entity\SoftwareLicense as LicenseRecord;
use mock\DBmysql as LicenseCountAdapterProbe;
use Stringable;
use tests\fixtures\ScalarReadProbe;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Item_SoftwareLicense as LegacyItem_SoftwareLicense;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\Software;
use itsmng\Database\Entity\SoftwareVersion;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\SoftwareAssignmentRepository;
use itsmng\Domain\SoftwareAssignmentCancelled;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/item_softwarelicense.class.php */

class Item_SoftwareLicense extends DbTestCase
{
    public function testCountForLicense()
    {
        $this->login();

        // Check new functionality
        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_1');
        $this->integer((int)\Item_SoftwareLicense::countForLicense($lic->fields['id']))->isIdenticalTo(3);

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_2');
        $this->integer((int)\Item_SoftwareLicense::countForLicense($lic->fields['id']))->isIdenticalTo(2);

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_3');
        $this->integer((int)\Item_SoftwareLicense::countForLicense($lic->fields['id']))->isIdenticalTo(2);

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_4');
        $this->integer((int)\Item_SoftwareLicense::countForLicense($lic->fields['id']))->isIdenticalTo(0);
    }

    public function testCountForSoftware()
    {
        $this->login();

        //Check new functionality
        $soft = getItemByTypeName('Software', '_test_soft');
        $this->integer((int)\Item_SoftwareLicense::countForSoftware($soft->fields['id']))->isIdenticalTo(7);

        $soft = getItemByTypeName('Software', '_test_soft2');
        $this->integer((int)\Item_SoftwareLicense::countForSoftware($soft->fields['id']))->isIdenticalTo(0);

    }

    public function testGetLicenseForInstallation()
    {
        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $version1 = getItemByTypeName('SoftwareVersion', '_test_softver_1');

        $this->Login();

        $this->array(
            \Item_SoftwareLicense::getLicenseForInstallation(
                'Computer',
                $computer1->fields['id'],
                $version1->fields['id']
            )
        )->isEmpty();

        //simulate license install
        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_1');
        $this->boolean(
            $lic->update([
              'id'                       => $lic->fields['id'],
              'softwareversions_id_use'  => $version1->fields['id']
         ])
        )->isTrue();

        $rows = LegacyItem_SoftwareLicense::getLicenseForInstallation(
            'Computer',
            $computer1->fields['id'],
            $version1->fields['id']
        );
        $this->array($rows)->hasSize(1);
        // The public helper remains a full legacy row for plugins and other callers.
        $this->array($rows[$lic->fields['id']])->hasKeys(['number', 'comment', 'softwares_id', 'softwareversions_id_use']);
        $this->integer((int)$rows[$lic->fields['id']]['softwareversions_id_use'])->isIdenticalTo((int)$version1->fields['id']);

        //reset license
        $this->boolean(
            $lic->update([
              'id'                       => $lic->fields['id'],
              'softwareversions_id_use'  => 0
         ])
        )->isTrue();
    }

    public function testAddUpdateDelete()
    {
        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $computer2 = getItemByTypeName('Computer', '_test_pc02');
        $computer3 = getItemByTypeName('Computer', '_test_pc11');
        $lic       = getItemByTypeName('SoftwareLicense', '_test_softlic_4');

        // Do some installations
        $lic_computer = new \Item_SoftwareLicense();

        $input = [
           'items_id'              => $computer1->fields['id'],
           'itemtype'              => 'Computer',
           'softwarelicenses_id'   => $lic->fields['id'],
        ];
        $this->integer((int)$lic_computer->add($input))->isGreaterThan(0);

        $input = [
           'items_id'              => $computer2->fields['id'],
           'itemtype'              => 'Computer',
           'softwarelicenses_id'   => $lic->fields['id'],
        ];
        $this->integer((int)$lic_computer->add($input))->isGreaterThan(0);

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_4');
        //License is valid: the number of affectations doesn't exceed declared number
        $this->variable($lic->fields['is_valid'])->isEqualTo(1);

        $input = [
           'items_id'              => $computer3->fields['id'],
           'itemtype'              => 'Computer',
           'softwarelicenses_id'   => $lic->fields['id']
        ];
        $this->integer((int)$lic_computer->add($input))->isGreaterThan(0);

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_4');
        //Number of affectations exceed the number declared in the license
        $this->variable($lic->fields['is_valid'])->isEqualTo(0);

        //test upgrade
        $old_lic      = getItemByTypeName('SoftwareLicense', '_test_softlic_4');
        $new_lic      = getItemByTypeName('SoftwareLicense', '_test_softlic_3');

        $lic_computer = new \Item_SoftwareLicense();
        $computer     = getItemByTypeName('Computer', '_test_pc01');
        $result = $lic_computer->find([
           'items_id'              => $computer->fields['id'],
           'itemtype'              => 'Computer',
           'softwarelicenses_id'   => $old_lic->fields['id']
        ]);
        $this->boolean($lic_computer->getFromDB(array_keys($result)[0]))->isTrue();

        $lic_computer->upgrade($lic_computer->getID(), $new_lic->fields['id']);

        $this->variable($lic_computer->fields['softwarelicenses_id'])
           ->isNotEqualTo($old_lic->getID())
           ->isEqualTo($new_lic->getID());

        //test delete
        $lic_computer = new \Item_SoftwareLicense();
        $this->boolean($lic_computer->deleteByCriteria(['softwarelicenses_id' => $lic->fields['id']], true))->isTrue();

        $lic = getItemByTypeName('SoftwareLicense', '_test_softlic_4');
        //Number of installations shouldn't now exceed the number declared in the license
        $this->variable($lic->fields['is_valid'])->isEqualTo(1);
    }


    public function testCloneItem()
    {
        $this->login();

        $source_computer = getItemByTypeName('Computer', '_test_pc21');
        $target_computer = getItemByTypeName('Computer', '_test_pc22');

        $item_softwareLicenses = \Item_SoftwareLicense::getItemsAssociatedTo($source_computer->getType(), $source_computer->getID());
        $override_input['items_id'] = $target_computer->getID();
        foreach ($item_softwareLicenses as $item_softwareLicense) {
            $item_softwareLicense->clone($override_input);
        }

        $input = [
           'items_id'  => $source_computer->fields['id'],
           'itemtype'  => 'Computer'];
        $this->integer((int)countElementsInTable('glpi_items_softwarelicenses', $input))
           ->isIdenticalTo(3);

        $input = [
           'items_id' => $target_computer->fields['id'],
           'itemtype'  => 'Computer'];
        $this->integer((int)countElementsInTable('glpi_items_softwarelicenses', $input))
           ->isIdenticalTo(3);

        //cleanup
        $lic_computer = new \Item_SoftwareLicense();
        $lic_computer->deleteByCriteria([
           'items_id' => $target_computer->fields['id'],
           'itemtype'  => 'Computer'], true);
    }

    public function testGetTabNameForItem()
    {
        $this->login();

        $license      = getItemByTypeName('SoftwareLicense', '_test_softlic_2');
        $cSoftwareLicense = new \Item_SoftwareLicense();
        $this->string($cSoftwareLicense->getTabNameForItem(new \Computer(), 0))->isEmpty();
        $this->string($cSoftwareLicense->getTabNameForItem($license, 1))->isEmpty();

        $_SESSION['glpishow_count_on_tabs'] = 0;
        $expected = [1 => __('Summary'),
                     2 => _n('Item', 'Items', \Session::getPluralNumber())];
        $this->array($cSoftwareLicense->getTabNameForItem($license, 0))->isIdenticalTo($expected);

        $_SESSION['glpishow_count_on_tabs'] = 1;
        $expected = [1 => __('Summary'),
                     2 => \Item_SoftwareLicense::createTabEntry(
                         _n('Item', 'Items', \Session::getPluralNumber()),
                         2
                     )];
        $this->array($cSoftwareLicense->getTabNameForItem($license, 0))->isIdenticalTo($expected);
    }

    public function testCountLicenses()
    {
        $this->login();

        $software = getItemByTypeName('Software', '_test_soft');
        $this->integer((int)\Item_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(5);

        $software = getItemByTypeName('Software', '_test_soft2');
        $this->integer((int)\Item_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(0);
    }

    public function testLicenseCountKeepsScopesAndLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $original = $DB;
        $writer = null;
        try {
            $this->login();
            $root = getItemByTypeName('Entity', '_test_root_entity', true);
            $child = $this->createItem('Entity', ['name' => 'count-child-' . $this->getUniqueString(), 'entities_id' => $root]);
            $software = $this->createItem(LegacySoftware::class, ['name' => 'count-software-' . $this->getUniqueString(), 'entities_id' => 0, 'is_recursive' => 1]);
            $rootLicense = $this->createItem(LegacySoftwareLicense::class, ['name' => 'count-root', 'entities_id' => 0, 'softwares_id' => $software->getID()]);
            $local = $this->createItem(LegacySoftwareLicense::class, ['name' => 'count-local', 'entities_id' => $root, 'softwares_id' => $software->getID()]);
            $descendant = $this->createItem(LegacySoftwareLicense::class, ['name' => 'count-descendant', 'entities_id' => $child->getID(), 'softwares_id' => $software->getID()]);
            $writer = Orm::create($DB);
            $live = $writer->find(LicenseRecord::class, (int)$local->getID());
            $live->name = 'Independent pending license';
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [$root];
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(1);
            $this->boolean($local->update(['id' => $local->getID(), 'is_template' => 1, 'is_deleted' => 1]))->isTrue();
            $this->integer(LegacyItem_SoftwareLicense::countLicenses((string)$software->getID()))->isIdenticalTo(1);
            $_SESSION['glpiactiveentities'] = [$root, $child->getID()];
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(2);
            $_SESSION['glpiactiveentities'] = [0];
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(1);
            $_SESSION['glpiactiveentities'] = [];
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(0);
            $_SESSION['glpishowallentities'] = true;
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(3);
            $computer = $this->createItem('Computer', ['name' => 'count-assigned-' . $this->getUniqueString(), 'entities_id' => 0]);
            $this->createItem(LegacyItem_SoftwareLicense::class, ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'softwarelicenses_id' => $rootLicense->getID()]);
            $this->output(static fn () => LegacyItem_SoftwareLicense::showForLicense($rootLicense))->contains('move_license');
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [0];
            $this->output(static fn () => LegacyItem_SoftwareLicense::showForLicense($rootLicense))->notContains('move_license');
            $_SESSION['glpishowallentities'] = true;
            foreach ([null, 'Null', 'nUlL', -1] as $missing) {
                $this->integer(LegacyItem_SoftwareLicense::countLicenses($missing))->isIdenticalTo(0);
            }
            $this->createItem(LegacySoftwareLicense::class, ['name' => 'count-current', 'entities_id' => $child->getID(), 'softwares_id' => $software->getID()]);
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(4);
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($software, $descendant, $child): void {
                    $owned = $outer->find(LicenseRecord::class, (int)$descendant->getID());
                    $owned->name = 'Outer pending license';
                    $this->integer(LegacyItem_SoftwareLicense::countLicenses($software->getID()))->isIdenticalTo(4);
                    $this->boolean($outer->contains($owned))->isTrue();
                    $this->string($owned->name)->isIdenticalTo('Outer pending license');
                }
            );
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Independent pending license');
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [$root];
            $probe = new ScalarReadProbe($original->getDoctrineConnection());
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new LicenseCountAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $identity = new class ((int)$software->getID(), $original, (int)$child->getID()) implements Stringable {
                public int $calls = 0;
                public function __construct(private int $id, private object $database, private int $child)
                {
                }
                public function __toString(): string
                {
                    ++$this->calls;
                    $GLOBALS['DB'] = $this->database;
                    $_SESSION['glpiactiveentities'] = [$this->child];
                    return (string)$this->id;
                }
            };
            $DB = $adapter;
            $this->integer(LegacyItem_SoftwareLicense::countLicenses($identity))->isIdenticalTo(1);
            $this->integer($identity->calls)->isIdenticalTo(1);
            $this->object($DB)->isIdenticalTo($original);
            $this->array($probe->queries)->hasSize(1);
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Independent pending license');
        } finally {
            $DB = $original;
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    public function testGetSearchOptionsNew()
    {
        $this->login();

        $cSoftwareLicense = new \Item_SoftwareLicense();
        $this->array($cSoftwareLicense->rawSearchOptions())
           ->hasSize(5);
    }

    public function testInstalledVersionOwnerBatchRetainsRequiredOwners(): void
    {
        global $DB;
        $this->login();
        $manager = Orm::create($DB);
        $root = $manager->getReference(Entity::class, 0);
        $owners = [];
        foreach (['Allocation owner A', 'Allocation owner B'] as $name) {
            $owner = new Software();
            $owner->entities = $root;
            $owner->name = $name;
            $manager->persist($owner);
            $owners[] = $owner;
        }
        $versions = [];
        for ($index = 0; $index < 25; ++$index) {
            $version = new SoftwareVersion();
            $version->entities = $root;
            $version->softwares = $owners[$index % 2];
            $version->name = 'Allocation owner version ' . $index;
            $manager->persist($version);
            $versions[] = $version;
        }
        $manager->flush();
        $ids = array_map(static fn ($version): int => $version->id, $versions);
        $expected = [$owners[0]->id, $owners[1]->id];
        sort($expected);
        $repository = new SoftwareAssignmentRepository($manager);
        $this->array($repository->softwareIdsForVersions([...array_reverse($ids), $ids[0]], current: false))->isIdenticalTo($expected);
        $this->array($repository->softwareIdsForVersions([...array_reverse($ids), $ids[0]], current: true))->isIdenticalTo($expected);
        $this->array($repository->softwareIdsForVersions([], current: true))->isEmpty();
        $missing = 1 + (int)$DB->getDoctrineConnection()->fetchOne('SELECT MAX(id) FROM glpi_softwareversions');
        foreach ([false, true] as $current) {
            $this->exception(static fn () => $repository->softwareIdsForVersions([...$ids, $missing], current: $current))
                ->isInstanceOf(SoftwareAssignmentCancelled::class)
                ->hasMessage('A required owning software version is missing.');
        }
        $this->exception(static fn () => $repository->softwareIdsForVersions([$ids[0], 0]))
            ->isInstanceOf(SoftwareAssignmentCancelled::class);
        $this->exception(static fn () => $repository->softwareIdsForVersions([$ids[0], -1]))
            ->isInstanceOf(SoftwareAssignmentCancelled::class);
        // A real owning reassignment must be visible through a new current read,
        // even though the manager still has the earlier version object managed.
        $connection = $DB->getDoctrineConnection();
        $frame = OwnedMutationFrame::begin($connection);
        try {
            $connection->update('glpi_softwareversions', ['softwares_id' => $owners[1]->id], ['id' => $versions[0]->id]);
            $this->array($repository->softwareIdsForVersions([$versions[0]->id], current: true))->isIdenticalTo([$owners[1]->id]);
        } finally {
            $frame->rollBack();
        }
        $this->array($repository->softwareIdsForVersions([$versions[0]->id], current: true))->isIdenticalTo([$owners[0]->id]);
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $capability = $connection->fetchAllAssociative("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'");
            if ($capability !== []) {
                $originalFlag = $capability[0]['Value'];
                try {
                    $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
                    $this->exception(static fn () => $repository->softwareIdsForVersions($ids, current: true))
                        ->isInstanceOf(CurrentReadUnavailable::class);
                    $this->string(strtoupper($connection->fetchAllAssociative("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")[0]['Value']))->isIdenticalTo('ON');
                } finally {
                    $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ' . (strtoupper($originalFlag) === 'ON' ? 'ON' : 'OFF'));
                }
            }
        }
    }

}
