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
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Entity;
use Infocom;
use Plugin;
use ReflectionProperty;
use Software;
use SoftwareLicense as LegacySoftwareLicense;
use itsmng\Database\Orm;
use itsmng\Database\MySQLConnection;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\Computer as ComputerRecord;
use itsmng\Database\Entity\Software as SoftwareRecord;
use itsmng\Database\Entity\SoftwareLicense as SoftwareLicenseRecord;
use itsmng\Database\Entity\SoftwareVersion as SoftwareVersionRecord;
use itsmng\Database\Entity\ItemSoftwareLicense as AllocationRecord;
use itsmng\Database\Entity\ItemSoftwareVersion as InstallationRecord;
use itsmng\Database\Repository\FinancialRepository;
use itsmng\Reporting\Criteria;

/* Test for inc/softwarelicense.class.php */

/**
 * @engine isolate
 */
class SoftwareLicense extends DbTestCase
{
    public function testFinancialReportUsesCurrentLicenseQuantitiesWithoutHydration(): void
    {
        global $DB, $CFG_GLPI;
        $session = $_SESSION;
        $config = $CFG_GLPI;
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
            public int $queries = 0;
            public function createQuery(string $dql = ''): Query
            {
                ++$this->queries;
                return parent::createQuery($dql);
            }
        };
        $loads = new class () {
            public int $licenses = 0;
            public function postLoad(PostLoadEventArgs $event): void
            {
                if ($event->getObject() instanceof SoftwareLicenseRecord) {
                    ++$this->licenses;
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $loads);
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $parent = (int)$_SESSION['glpiactive_entity'];
            $entity = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => $parent]);
            $entityId = (int)$entity->getID();
            $CFG_GLPI['auto_create_infocoms'] = 0;
            $software = $this->createItem(Software::class, ['name' => $this->getUniqueString(), 'entities_id' => $entityId]);
            $outsideSoftware = $this->createItem(Software::class, ['name' => $this->getUniqueString(), 'entities_id' => $parent]);
            $licenses = [];
            $financial = [];
            foreach ([
                ['global', 2, '12.5000', '2090-01-01', null, $software],
                ['individual', 5, '7.2500', '2090-01-02', null, $software],
                ['global', -1, '4.1250', null, '2090-01-03', $software],
                ['global', 0, '2.5000', '2090-01-04', null, $software],
                ['global', 10, '99.0000', '2089-12-31', '2090-02-01', $software],
                ['global', 10, '99.0000', '2090-01-01', null, $outsideSoftware],
            ] as [$serial, $number, $value, $buy, $use, $owner]) {
                $license = $this->createItem(LegacySoftwareLicense::class, ['name' => $this->getUniqueString(),
                    'softwares_id' => $owner->getID(), 'entities_id' => $owner->fields['entities_id'],
                    'serial' => $serial, 'number' => $number]);
                $licenses[] = $license;
                $financial[] = $this->createItem(Infocom::class, ['itemtype' => 'SoftwareLicense',
                    'items_id' => $license->getID(), 'value' => $value, 'buy_date' => $buy, 'use_date' => $use,
                    'sink_type' => 1, 'sink_time' => 3, 'sink_coeff' => 2.0]);
            }
            $this->setEntity($entityId, false);
            $scope = Criteria::entities();
            $this->array(array_values($scope))->isIdenticalTo([$entityId]);
            $repository = new FinancialRepository($em);
            $rows = $repository->rows('SoftwareLicense', '2090-01-01', '2090-01-31', $scope, false);
            $this->integer($em->queries)->isIdenticalTo(1);
            $this->array(array_column($rows, 'id'))->isIdenticalTo(array_map(
                static fn (Infocom $item): int => (int)$item->getID(),
                array_slice($financial, 0, 4)
            ));
            $this->array(array_column($rows, 'value'))->isIdenticalTo(['12.5000', '7.2500', '4.1250', '2.5000']);
            $this->array(array_column($rows, 'license_serial'))->isIdenticalTo(['global', 'individual', 'global', 'global']);
            $this->array(array_column($rows, 'license_number'))->isIdenticalTo([2, 5, -1, 0]);
            $this->array(array_column($rows, 'buy_date'))->isIdenticalTo(['2090-01-01', '2090-01-02', null, '2090-01-04']);
            $this->string($rows[2]['use_date'])->isIdenticalTo('2090-01-03');
            $this->integer($loads->licenses)->isIdenticalTo(0);
            $this->array($repository->rows('SoftwareLicense', '2090-01-01', '2090-01-31', [], false))->isEmpty();
            $managed = $em->find(SoftwareLicenseRecord::class, (int)$licenses[0]->getID());
            $this->boolean($DB->update('glpi_softwarelicenses', ['number' => 3], ['id' => $licenses[0]->getID()]))->isTrue();
            $fresh = $repository->rows('SoftwareLicense', '2090-01-01', '2090-01-31', $scope, false);
            $this->integer($fresh[0]['license_number'])->isIdenticalTo(3);
            $this->integer($managed->number)->isIdenticalTo(2);
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($loads->licenses)->isIdenticalTo(1);
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $loads);
            $em->clear();
            $_SESSION = $session;
            $CFG_GLPI = $config;
        }
    }

    public function softwareAdmissionProvider(): array
    {
        return [['Software'], ['SoftwareLicense'], ['Item_SoftwareLicense'], ['Item_SoftwareVersion']];
    }

    /** @dataProvider softwareAdmissionProvider */
    public function testSessionAdmissionPrecedesLifecycle(string $class): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $manager = Orm::create($DB);
        $connection = $manager->getConnection();
        $depth = $connection->getTransactionNestingLevel();
        $scope = $connection->captureManagedTransactionScope();
        $flag = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? MySQLConnection::snapshotIsolation($connection)
            : null;
        try {
            $root = $manager->find(EntityRecord::class, $_SESSION['glpiactive_entity']);
            $computer = $manager->find(ComputerRecord::class, getItemByTypeName('Computer', '_test_pc01', true));
            $this->object($root)->isInstanceOf(EntityRecord::class);
            $this->object($computer)->isInstanceOf(ComputerRecord::class);
            $owner = new SoftwareRecord();
            $owner->entities = $root;
            $owner->name = 'Session admission ' . $this->getUniqueString();
            $license = new SoftwareLicenseRecord();
            $license->softwares = $owner;
            $license->entities = $root;
            $license->number = 3;
            $license->name = $owner->name;
            $version = new SoftwareVersionRecord();
            $version->softwares = $owner;
            $version->entities = $root;
            $version->name = $owner->name;
            $allocation = new AllocationRecord();
            $allocation->softwarelicenses = $license;
            $allocation->itemtype = 'Computer';
            $allocation->computer = $computer;
            $installation = new InstallationRecord();
            $installation->softwareversions = $version;
            $installation->entities = $root;
            $installation->itemtype = 'Computer';
            $installation->computer = $computer;
            foreach ([$owner, $license, $version, $allocation, $installation] as $record) {
                $manager->persist($record);
            }
            $manager->flush();
            $record = ['Software' => $owner, 'SoftwareLicense' => $license,
                'Item_SoftwareLicense' => $allocation, 'Item_SoftwareVersion' => $installation][$class];
            $model = new $class();
            $this->boolean($model->can($record->id, UPDATE))->isTrue();
            $hookCalls = 0;
            $plugins->setValue(null, [...$activePlugins, 'software_session_admission_fixture']);
            foreach (['pre_item_add', 'pre_item_update', 'pre_item_restore', 'pre_item_delete', 'pre_item_purge'] as $event) {
                $PLUGIN_HOOKS[$event]['software_session_admission_fixture'][$class] = static function () use (&$hookCalls): void {
                    ++$hookCalls;
                };
            }
            $changes = match ($class) {
                'Software' => ['name' => $owner->name . ' accepted'],
                'SoftwareLicense' => ['number' => 4],
                default => ['is_dynamic' => 1],
            };
            $input = ['id' => $record->id] + $changes;
            $this->boolean($model->update($input))->isTrue();
            $this->integer($hookCalls)->isGreaterThan(0);
            $this->boolean($model->getFromDB($record->id))->isTrue();
            if ($flag === null) {
                // PostgreSQL/MySQL without this MariaDB flag retain the supported public flow.
                $scope->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isEqualTo($depth);
                return;
            }
            $this->boolean($flag)->isFalse();
            $stored = LifecycleModelJournal::state($model);
            $snapshot = static function () use ($connection, $owner, $license, $version, $allocation, $installation): array {
                $rows = [];
                foreach (['glpi_softwares' => $owner->id, 'glpi_softwarelicenses' => $license->id,
                    'glpi_softwareversions' => $version->id, 'glpi_items_softwarelicenses' => $allocation->id,
                    'glpi_items_softwareversions' => $installation->id] as $table => $id) {
                    $rows[] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]);
                }
                return [$rows, $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id'),
                    $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id'),
                    $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version')];
            };
            $before = $snapshot();
            $hookCalls = 0;
            $refusedInput = ['id' => $record->id] + match ($class) {
                'Software' => ['name' => $owner->name . ' retry'],
                'SoftwareLicense' => ['number' => 5],
                default => ['is_dynamic' => 0],
            };
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
            $this->boolean($model->update($refusedInput))->isFalse();
            $this->boolean($model->restore(['id' => $record->id]))->isFalse();
            $this->boolean($model->delete(['id' => $record->id], true))->isFalse();
            $copy = $model->fields;
            unset($copy['id']);
            $this->boolean($model->add($copy))->isFalse();
            $this->integer($hookCalls)->isEqualTo(0);
            $this->array(LifecycleModelJournal::state($model))->isIdenticalTo($stored);
            $this->array($snapshot())->isIdenticalTo($before);
            $this->boolean(MySQLConnection::snapshotIsolation($connection))->isTrue();
            $scope->assertActive();
            $this->boolean($connection->getNativeConnection()->inTransaction())->isTrue();
            $this->integer($connection->getTransactionNestingLevel())->isEqualTo($depth);
            $connection->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
            $this->boolean($model->update($refusedInput))->isTrue();
            $this->integer($hookCalls)->isGreaterThan(0);
            $this->boolean($model->getFromDB($record->id))->isTrue();
            foreach ($refusedInput as $field => $value) {
                $this->variable($model->fields[$field])->isEqualTo($value);
            }
        } finally {
            if ($flag !== null) {
                $connection->executeStatement('SET SESSION innodb_snapshot_isolation = ' . ($flag ? 'ON' : 'OFF'));
            }
            $plugins->setValue(null, $activePlugins);
            $PLUGIN_HOOKS = $hooks;
            $_SESSION = $session;
            $manager->clear();
        }
    }

    public function testTypeName()
    {
        $this->string(\SoftwareLicense::getTypeName(1))->isIdenticalTo('License');
        $this->string(\SoftwareLicense::getTypeName(0))->isIdenticalTo('Licenses');
        $this->string(\SoftwareLicense::getTypeName(10))->isIdenticalTo('Licenses');
    }

    public function testPrepareInputForAdd()
    {
        $license = new \SoftwareLicense();

        //Without softwares_id, import refused
        $input = [
           'name'         => 'not_inserted_software_license',
           'entities_id'  => 0
        ];
        $this->boolean($license->prepareInputForAdd($input))->isFalse();
        $this->hasSessionMessages(ERROR, ['Please select a software for this license']);

        //With a softwares_id, import ok
        $input = [ 'name' => 'inserted_sofwarelicense', 'softwares_id' => 1];
        $license->input['softwares_id'] = 1;
        $expected = [ 'name' => 'inserted_sofwarelicense', 'softwares_id' => 1,
                      'softwarelicenses_id' => 0, 'level' => 1,
                      'completename' => 'inserted_sofwarelicense'
                   ];
        $this->array($license->prepareInputForAdd($input))->isIdenticalTo($expected);

        //withtemplate, empty 'expire' should be ignored. id will be replaced in _oldID
        $input = [ 'name' => 'other_inserted_sofwarelicense', 'softwares_id' => 1,
                   'id' => 1, 'withtemplate' => 0, 'expire' => '',
                   'softwarelicenses_id' => 0
                ];
        $expected = [ 'name' => 'other_inserted_sofwarelicense', 'softwares_id' => 1,
                      'softwarelicenses_id' => 0, 'level' => 1,
                      'completename' => 'other_inserted_sofwarelicense', '_oldID' => 1
                   ];
        $this->array($license->prepareInputForAdd($input))->isIdenticalTo($expected);
    }

    /**
     * Creates a new software
     *
     * @return \Software
     */
    private function createSoft(int $entity = 0, bool $recursive = false)
    {
        $software     = new \Software();
        $softwares_id = $software->add([
           'name'         => 'Software ' .$this->getUniqueString(),
           'is_template'  => 0,
           'entities_id'  => $entity,
           'is_recursive' => $recursive ? 1 : 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);
        $this->boolean($software->getFromDB($softwares_id))->isTrue();

        return $software;
    }

    public function testAdd()
    {
        $this->login();

        $license = new \SoftwareLicense();
        $input = [ 'name' => 'not_inserted_software_license_child'];

        $this->boolean($license->add($input))->isFalse();
        $this->hasSessionMessages(ERROR, ['Please select a software for this license']);

        $software     = $this->createSoft();

        $parentlicense = new \SoftwareLicense();
        $parentlicense_id = $parentlicense->add([
           'name'         => 'a_software_license',
           'softwares_id' => $software->getID(),
           'entities_id'  => 0
        ]);
        $this->integer((int)$parentlicense_id)->isGreaterThan(0);
        $this->boolean($parentlicense->getFromDB($parentlicense_id))->isTrue();

        $this->string($parentlicense->fields['completename'])->isIdenticalTo("a_software_license");
        $this->string($parentlicense->fields['name'])->isIdenticalTo('a_software_license');
        $this->variable($parentlicense->fields['expire'])->isNull();
        $this->variable($parentlicense->fields['level'])->isEqualTo(1);

        $input  = [
           'softwares_id'          => $software->getID(),
           'expire'                => '2017-01-01 00:00:00',
           'name'                  => 'a_child_license',
           'softwarelicenses_id'   => $parentlicense_id,
           'entities_id'           => $parentlicense->fields['entities_id']
        ];
        $lic_id = $license->add($input);
        $this->integer($lic_id)->isGreaterThan($parentlicense_id);
        $this->boolean($license->getFromDB($lic_id))->isTrue();

        $this->string($license->fields['completename'])->isIdenticalTo("a_software_license > a_child_license");
        $this->string($license->fields['name'])->isIdenticalTo('a_child_license');
        $this->string($license->fields['expire'])->isIdenticalTo('2017-01-01');
        $this->variable($license->fields['level'])->isEqualTo(2);
    }

    public function testComputeValidityIndicator()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $license = new \SoftwareLicense();

        $software = $this->createSoft((int)$_SESSION['glpiactive_entity'], true);

        $input   = [
           'softwares_id' => $software->getID(),
           'expire'       => '2017-01-01 00:00:00',
           'name'         => 'Test licence ' . $this->getUniqueString(),
           'number'       => 3,
           'entities_id'  => $software->getEntityID(),
           'is_recursive' => 1
        ];
        $lic_id = $license->add($input);
        $this->integer((int)$lic_id)->isGreaterThan(0);
        $this->boolean($license->can($license->getID(), UPDATE))->isTrue();
        $this->boolean($license->getFromDB($lic_id))->isTrue();

        $license_computer = new \Item_SoftwareLicense();
        $comp1            = getItemByTypeName('Computer', '_test_pc01');
        $comp2            = getItemByTypeName('Computer', '_test_pc02');

        $input_comp = [
           'softwarelicenses_id'   => $lic_id,
           'items_id'              => $comp1->getID(),
           'itemtype'              => 'Computer',
           'is_deleted'            => 0,
           'is_dynamic'            => 0
        ];
        $this->integer((int)$license_computer->add($input_comp))->isGreaterThan(0);

        //Test if number is illimited
        $this->variable(\SoftwareLicense::computeValidityIndicator($lic_id, -1))->isEqualTo(1);
        $this->variable(\SoftwareLicense::computeValidityIndicator($lic_id, 0))->isEqualTo(0);

        $input_comp['items_id'] = $comp2->getID();
        $this->integer((int)$license_computer->add($input_comp))->isGreaterThan(0);

        $this->variable(\SoftwareLicense::computeValidityIndicator($lic_id, 2))->isEqualTo(1);
        $this->variable(\SoftwareLicense::computeValidityIndicator($lic_id, 1))->isEqualTo(0);
    }

    public function testPrepareInputForUpdate()
    {
        $this->login();

        $license = new \SoftwareLicense();

        $software = $this->createSoft();

        $input   = [
           'softwares_id' => $software->getID(),
           'expire'       => '2017-01-01 00:00:00',
           'name'         => 'Test licence ' . $this->getUniqueString(),
           'number'       => 3,
           'entities_id'  => 0
        ];
        $lic_id = $license->add($input);
        $this->integer((int)$lic_id)->isGreaterThan(0);

        $input    = ['id' => $lic_id, 'number' => 3];
        $expected = ['id' => $lic_id, 'number' => 3, 'is_valid' => 1];
        $this->array($license->prepareInputForUpdate($input))->isIdenticalTo($expected);
    }

    public function testUpdateValidityIndicator()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $license = new \SoftwareLicense();
        $comp1  = getItemByTypeName('Computer', '_test_pc01');

        $software = $this->createSoft((int)$_SESSION['glpiactive_entity'], true);
        $input   = [
           'softwares_id' => $software->getID(),
           'expire'       => '2017-01-01 00:00:00',
           'name'         => 'Test licence ' . $this->getUniqueString(),
           'number'       => 3,
           'entities_id'  => $software->getEntityID(),
           'is_recursive' => 1
        ];
        $lic_id = $license->add($input);
        $this->integer((int)$lic_id)->isGreaterThan(0);
        $this->boolean($license->can($license->getID(), UPDATE))->isTrue();
        $this->boolean($license->getFromDB($lic_id))->isTrue();

        $this->createLicenseInstall(
            $license,
            ['_test_pc01', '_test_pc02', '_test_pc22']
        );

        //Delete a license installation
        $license_computer = new \Item_SoftwareLicense();
        $input = [
           'softwarelicenses_id'   => $license->getID(),
           'items_id'              => $comp1->getID(),
           'itemtype'              => 'Computer'
        ];
        $this->boolean($license_computer->deleteByCriteria($input, true))->isTrue();

        $orig_number = $license->getField('number');
        //Change the number of assets to 1
        $input = [
           'id'     => $license->getID(),
           'number' => 1,
        ];
        $license->update($input);
        $this->boolean($license->getFromDB($license->getID()))->isTrue();

        $this->integer((int)$license->getID())->isGreaterThan(0);
        $this->variable($input['number'])->isEqualTo($license->fields['number']);

        //Update validity indicator
        $license->updateValidityIndicator($license->getID());
        $this->variable($license->fields['is_valid'])->isEqualTo(0);

        //cleanup
        $input = [
           'id'     => $license->getID(),
           'number' => $orig_number,
        ];
        $license->update($input);

        //Update validity indicator
        $license->updateValidityIndicator($license->fields['id']);
        $this->variable($license->fields['is_valid'])->isEqualTo(1);
    }

    private function createLicenseInstall(\SoftwareLicense $license, $computers)
    {
        foreach ($computers as $computer) {
            $comp = getItemByTypeName('Computer', $computer);
            $this->createInstall($license->getID(), $comp->getID());
        }
    }

    private function createInstall($licenses_id, $items_id)
    {
        $license_computer = new \Item_SoftwareLicense();
        $input = [
           'softwarelicenses_id'   => $licenses_id,
           'items_id'              => $items_id,
           'itemtype'              => 'Computer',
           'is_dynamic'            => 0,
           'is_deleted'            => 0
        ];
        $this->integer((int)$license_computer->add($input))->isGreaterThan(0);
    }
}
