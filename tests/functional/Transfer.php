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

use Computer;
use DbTestCase;
use Item_SoftwareVersion;
use Software;
use SoftwareVersion;

/* Test for inc/transfer.class.php */

class Transfer extends DbTestCase
{
    public function testTransfer()
    {
        $this->login();

        //Original entity
        $fentity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        //Destination entity
        $dentity = (int)getItemByTypeName('Entity', '_test_child_2', true);

        $location_id = getItemByTypeName('Location', '_location01', true);

        $itemtypeslist = $this->getClasses(
            'searchOptions',
            [
              '/^Rule.*/',
              '/^Common.*/',
              '/^DB.*/',
              '/^SlaLevel.*/',
              '/^OlaLevel.*/',
              'Reservation',
              'ReservationItem',
              'Event',
              'Glpi\\Event',
              'KnowbaseItem',
              'NetworkPortMigration',
              '/^TicketTemplate.*/',
              '/^Computer_Software.*/',
              '/SavedSearch.*/',
              '/.*Notification.*/',
              '/.*Cost.*/',
              '/^Item_.*/',
              '/^Device.*/',
              '/.*Validation$/',
              '/^Network.*/',
              'CalendarSegment',
              'IPAddress',
              'IPNetwork',
              'FQDN',
              '/^SoftwareVersion.*/',
              '/^SoftwareLicense.*/',
              '/.*Predefined.*/',
              '/.*Mandatory.*/',
              '/.*Hidden.*/',
              'Entity_Reminder',
              'Document_Item',
              'Cartridge',
              '/.*Task.*/',
              'Entity_RSSFeed',
              'ComputerVirtualMachine',
              'FieldUnicity',
              'PurgeLogs',
              '/.*_?KnowbaseItem_?.*/',
              'Consumable',
              'Infocom',
              'ComputerAntivirus',
              'TicketRecurrent'
         ]
        );

        $fields_values = [
           'name'            => 'Object to transfer',
           'entities_id'     => $fentity,
           'content'         => 'A content',
           'definition_time' => 'hour',
           'number_time'     => 4,
           'begin_date'      => '2020-01-01',
           'url'            => 'file://' . realpath(__DIR__ . '/../fixtures/rssfeed.xml'),
           'itemtype'       => 'Computer',
        ];

        $addParent = function (string $parentType, string $itemtype) use ($fields_values): int {
            $parent = new $parentType();
            $parentInput = [];
            foreach ($fields_values as $field => $value) {
                if ($parent->isField($field)) {
                    $parentInput[$field] = $value;
                }
            }
            $parentId = $parent->add($parentInput);
            $this->integer((int)$parentId)->isGreaterThan(0, "Cannot add required $parentType for $itemtype");
            return (int)$parentId;
        };

        $count = 0;
        foreach ($itemtypeslist as $itemtype) {
            if (!in_array($itemtype, ['Accessibility', 'Oidc'])) {
                $item_class = new \ReflectionClass($itemtype);
                if ($item_class->isAbstract()) {
                    continue;
                }

                $obj = new $itemtype();
                if (!$obj->isEntityAssign()) {
                    continue;
                }

                // Add
                $input = [];
                foreach ($fields_values as $field => $value) {
                    if ($obj->isField($field)) {
                        $input[$field] = $value;
                    }
                }

                if ($obj->maybeLocated()) {
                    $input['locations_id'] = $location_id;
                }

                if ($obj instanceof \CommonDBRelation) {
                    // Fixed relation endpoints are required owners, rather
                    // than optional scalar defaults in this transfer fixture.
                    foreach ([[$obj::$itemtype_1, $obj::$items_id_1], [$obj::$itemtype_2, $obj::$items_id_2]] as [$parentType, $parentColumn]) {
                        if (!class_exists($parentType) || isset($input[$parentColumn])) {
                            continue;
                        }
                        $input[$parentColumn] = $addParent($parentType, $itemtype);
                    }
                }

                $entityClass = \itsmng\Database\EntityRegistry::tables()[$obj::getTable()] ?? null;
                if ($entityClass !== null) {
                    $orm = \itsmng\Database\Orm::create($GLOBALS['DB']);
                    foreach ($orm->getClassMetadata($entityClass)->associationMappings as $association) {
                        if (!$association->isToOneOwningSide()) {
                            continue;
                        }
                        foreach ($association->joinColumns as $join) {
                            if ($join->nullable || array_key_exists($join->name, $input)) {
                                continue;
                            }
                            $parentType = \getItemTypeForTable($orm->getClassMetadata($association->targetEntity)->getTableName());
                            $input[$join->name] = $addParent($parentType, $itemtype);
                        }
                    }
                }

                $id = $obj->add($input);
                $this->integer((int)$id)->isGreaterThan(0, "Cannot add $itemtype");
                $this->boolean($obj->getFromDB($id))->isTrue();

                //transer to another entity
                $transfer = new \Transfer();

                $controller = new \atoum\atoum\mock\controller();
                $controller->__construct = function () {
                    // void
                };

                $ma = new \mock\MassiveAction([], [], 'process', $controller);

                \MassiveAction::processMassiveActionsForOneItemtype(
                    $ma,
                    $obj,
                    [$id]
                );
                $owner = $obj;
                if (!$obj->isField('entities_id') && $obj instanceof \CommonDBChild) {
                    $action = 'MassiveAction' . \MassiveAction::CLASS_ACTION_SEPARATOR . 'add_transfer_list';
                    $this->array($obj->getSpecificMassiveActions())->notHasKey($action);
                    $owner = $obj->getItem();
                    $this->array($owner->getSpecificMassiveActions())->hasKey($action);
                }
                $ownerId = $owner->getID();
                $transfer->moveItems([$owner->getType() => [$ownerId]], $dentity, [$ownerId]);
                unset($_SESSION['glpitransfer_list']);

                $this->boolean($obj->getFromDB($id))->isTrue();
                $entity = $obj->isField('entities_id') ? $obj->fields['entities_id'] : $obj->getEntityID();
                $this->integer((int)$entity)->isidenticalTo($dentity, "Transfer has failed on $itemtype");
                if ($owner !== $obj) {
                    $this->integer((int)$obj->getItem()->getID())->isIdenticalTo((int)$ownerId);
                }

                ++$count;
            }
        }
        $this->dump(
            sprintf(
                '%1$s itemtypes tested',
                $count
            )
        );
    }

    public function testChildWithInheritedEntityOwnership()
    {
        $this->login();
        $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $destination = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $link = new \Link();
        $linkId = $link->add(['name' => 'Inherited transfer owner', 'entities_id' => $source]);
        $this->integer((int)$linkId)->isGreaterThan(0);
        $child = new \Link_Itemtype();
        $childId = $child->add(['links_id' => $linkId, 'itemtype' => 'Computer']);
        $this->integer((int)$childId)->isGreaterThan(0);
        $this->boolean($child->getFromDB($childId))->isTrue();
        $this->boolean($child->isEntityAssign())->isTrue();
        $this->boolean($child->isField('entities_id'))->isFalse();
        $action = 'MassiveAction' . \MassiveAction::CLASS_ACTION_SEPARATOR . 'add_transfer_list';
        $this->array($child->getSpecificMassiveActions())->notHasKey($action);
        $owner = $child->getItem();
        $this->integer((int)$owner->getID())->isIdenticalTo((int)$linkId);
        $this->array($owner->getSpecificMassiveActions())->hasKey($action);
        (new \Transfer())->moveItems([$owner->getType() => [$owner->getID()]], $destination, [$owner->getID()]);
        unset($_SESSION['glpitransfer_list']);
        $this->boolean($child->getFromDB($childId))->isTrue();
        $this->integer((int)$child->fields['links_id'])->isIdenticalTo((int)$linkId);
        $this->integer((int)$child->getEntityID())->isIdenticalTo($destination);
        $this->boolean($link->getFromDB($linkId))->isTrue();
        $this->integer((int)$link->fields['entities_id'])->isIdenticalTo($destination);
    }

    public function testDomainTransfer()
    {
        $this->login();

        //Original entity
        $fentity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        //Destination entity
        $dentity = (int)getItemByTypeName('Entity', '_test_child_2', true);

        //records types
        $type_a = (int)getItemByTypeName('DomainRecordType', 'A', true);
        $type_cname = (int)getItemByTypeName('DomainRecordType', 'CNAME', true);

        $domain = new \Domain();
        $record = new \DomainRecord();

        $did = (int)$domain->add([
           'name'         => 'glpi-project.org',
           'entities_id'  => $fentity
        ]);
        $this->integer($did)->isGreaterThan(0);
        $this->boolean($domain->getFromDB($did))->isTrue();

        $this->integer(
            (int)$record->add([
              'name'         => 'glpi-project.org.',
              'type'         => $type_a,
              'data'         => '127.0.1.1',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$record->add([
              'name'         => 'www.glpi-project.org.',
              'type'         => $type_cname,
              'data'         => 'glpi-project.org.',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$record->add([
              'name'         => 'doc.glpi-project.org.',
              'type'         => $type_cname,
              'data'         => 'glpi-doc.rtfd.io',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        //transer to another entity
        $transfer = new \Transfer();

        $controller = new \atoum\atoum\mock\controller();
        $controller->__construct = function () {
            // void
        };

        $ma = new \mock\MassiveAction([], [], 'process', $controller);

        \MassiveAction::processMassiveActionsForOneItemtype(
            $ma,
            $domain,
            [$did]
        );
        $transfer->moveItems(['Domain' => [$did]], $dentity, [$did]);
        unset($_SESSION['glpitransfer_list']);

        $this->boolean($domain->getFromDB($did))->isTrue();
        $this->integer((int)$domain->fields['entities_id'])->isidenticalTo($dentity);

        global $DB;
        $records = $DB->request([
           'FROM'   => $record->getTable(),
           'WHERE'  => [
              'domains_id' => $did
           ]
        ]);

        $this->integer(count($records))->isidenticalTo(3);
        foreach ($records as $rec) {
            $this->integer((int)$rec['entities_id'])->isidenticalTo($dentity);
        }
    }

    protected function testKeepSoftwareOptionProvider(): array
    {
        $test_entity = getItemByTypeName('Entity', '_test_root_entity', true);

        // Create test computers
        $computers_to_create = [
           'test_transfer_pc_1',
           'test_transfer_pc_2',
           'test_transfer_pc_3',
           'test_transfer_pc_4',
        ];
        foreach ($computers_to_create as $computer_name) {
            $computer = new Computer();
            $computers_id = $computer->add([
               'name'        => $computer_name,
               'entities_id' => $test_entity,
            ]);
            $this->integer($computers_id)->isGreaterThan(0);
        }

        // Create test softwares
        $softwares_to_create = [
           'test_transfer_software_1',
           'test_transfer_software_2',
           'test_transfer_software_3',
        ];
        foreach ($softwares_to_create as $software_name) {
            $software = new Software();
            $softwares_id = $software->add([
               'name'        => $software_name,
               'entities_id' => $test_entity,
            ]);
            $this->integer($softwares_id)->isGreaterThan(0);
        }

        // Create test software versions
        $software_versions_to_create = [
           'test_transfer_software_1' => ['V1', 'V2'],
           'test_transfer_software_2' => ['V1', 'V2'],
           'test_transfer_software_3' => ['V1', 'V2'],
        ];
        foreach ($software_versions_to_create as $software_name => $versions) {
            foreach ($versions as $version) {
                $softwareversion = new SoftwareVersion();
                $softwareversions_id = $softwareversion->add([
                   'name'         => $software_name . '::' . $version,
                   'softwares_id' => getItemByTypeName('Software', $software_name, true),
                   'entities_id'  => $test_entity,
                ]);
                $this->integer($softwareversions_id)->isGreaterThan(0);
            }
        }

        // Link softwares and computers
        $item_softwareversion_ids = [];
        $item_softwareversion_to_create = [
           'test_transfer_pc_1' => ['test_transfer_software_1::V1', 'test_transfer_software_2::V1'],
           'test_transfer_pc_2' => ['test_transfer_software_1::V2', 'test_transfer_software_2::V2'],
           'test_transfer_pc_3' => ['test_transfer_software_2::V1', 'test_transfer_software_3::V2'],
           'test_transfer_pc_4' => ['test_transfer_software_1::V2', 'test_transfer_software_3::V1'],
        ];
        foreach ($item_softwareversion_to_create as $computer_name => $versions) {
            foreach ($versions as $version) {
                $item_softwareversion = new Item_SoftwareVersion();
                $item_softwareversions_id = $item_softwareversion->add([
                   'items_id'     => getItemByTypeName('Computer', $computer_name, true),
                   'itemtype'     => 'Computer',
                   'softwareversions_id' => getItemByTypeName('SoftwareVersion', $version, true),
                   'entities_id'  => $test_entity,
                ]);
                $this->integer($item_softwareversions_id)->isGreaterThan(0);
                $item_softwareversion_ids[] = $item_softwareversions_id;
            }
        }

        return [
           [
              'items' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_1', true),
                    getItemByTypeName('Computer', 'test_transfer_pc_2', true),
                 ]
              ],
              'entities_id_destination' => $test_entity,
              'transfer_options'        => ['keep_software' => 1],
              'expected_softwares_after_transfer' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_1', true) => [
                       $item_softwareversion_ids[0],
                       $item_softwareversion_ids[1]
                    ],
                    getItemByTypeName('Computer', 'test_transfer_pc_2', true) => [
                       $item_softwareversion_ids[2],
                       $item_softwareversion_ids[3]
                    ],
                 ]
              ]
           ],
           [
              'items' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_3', true),
                    getItemByTypeName('Computer', 'test_transfer_pc_4', true),
                 ]
              ],
              'entities_id_destination' => $test_entity,
              'transfer_options'        => ['keep_software' => 0],
              'expected_softwares_after_transfer' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_3', true) => [],
                    getItemByTypeName('Computer', 'test_transfer_pc_4', true) => [],
                 ]
              ]
           ]
        ];
    }

    /**
     * @dataProvider testKeepSoftwareOptionProvider
     */
    public function testKeepSoftwareOption(
        array $items,
        int $entities_id_destination,
        array $transfer_options,
        array $expected_softwares_after_transfer
    ): void {
        $tranfer = new \Transfer();
        $tranfer->moveItems($items, $entities_id_destination, $transfer_options);

        foreach ($items as $itemtype => $ids) {
            foreach ($ids as $id) {
                $item_softwareversion = new Item_SoftwareVersion();
                $data = $item_softwareversion->find([
                   'items_id' => $id,
                   'itemtype' => $itemtype
                ]);
                $found_ids = array_column($data, 'id');
                $this->array($found_ids)->isEqualTo($expected_softwares_after_transfer[$itemtype][$id]);
            }
        }
    }
}
