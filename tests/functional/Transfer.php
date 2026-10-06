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

    /** A separate real session keeps DbTestCase's caller frame completely untouched. */
    private function withSoftwareOwnerQueryProbe(callable $operation): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $originalLevel = $original->getDoctrineConnection()->getTransactionNestingLevel();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $queries = [];
            public ?\Closure $beforeCurrentInstallations = null;

            public function log($level, $message, array $context = []): void
            {
                if (!isset($context['sql'])) {
                    return; // Never retain connection credentials or bound application data.
                }
                $sql = str_replace(['`', '"'], '', $context['sql']);
                $this->queries[] = $sql;
                if ($this->beforeCurrentInstallations !== null
                    && preg_match('/\bFROM\s+glpi_items_softwareversions\b/i', $sql)
                    && preg_match('/FOR (?:SHARE|UPDATE)|LOCK IN SHARE MODE/i', $sql)) {
                    $change = $this->beforeCurrentInstallations;
                    $this->beforeCurrentInstallations = null;
                    $change();
                }
            }

            public function ownerReads(): int
            {
                return count(array_filter($this->queries, static fn (string $sql): bool => (bool)preg_match('/\bFROM\s+glpi_softwareversions\b/i', $sql)));
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $parameters = $original->getDoctrineConnection()->getParams();
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($parameters, $configuration)
            : \itsmng\Database\MySQLConnection::create($parameters, $configuration);
        // Test-only adapter admission: a real canonical connection, never the
        // original adapter's physical owner or a mock transaction implementation.
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $frame = null;
        $primary = null;
        try {
            $DB = $probe;
            $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $operation($probe, $connection, $logger);
        } catch (\Throwable $error) {
            $primary = $error;
        } finally {
            $DB = $original;
            $_SESSION = $session;
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

    public function testTransferStopsAfterCallbackReplacesFrameOrWriter(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_frame_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger) use ($savedHooks): void {
                global $DB, $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = \itsmng\Database\Orm::create($database);
                $contacts = [];
                foreach (['First callback owner', 'Later transfer owner'] as $name) {
                    $contact = new \itsmng\Database\Entity\Contact();
                    $contact->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
                    $contact->name = $name;
                    $manager->persist($contact);
                    $contacts[] = $contact;
                }
                $manager->flush();
                $ids = array_map(static fn ($contact): int => $contact->id, $contacts);
                $level = $connection->getTransactionNestingLevel();
                foreach (['item_update', 'item_transfer', 'refused_update', 'writer_swap', 'owned_failure'] as $mode) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $replacement = null;
                    $retained = null;
                    $fired = false;
                    $session = $_SESSION;
                    $change = function () use ($connection, $database, $logger, $ids, $mode, &$replacement, &$fired): void {
                        $fired = true;
                        if ($mode === 'writer_swap') {
                            // Adapter identity matters even with the same physical owner.
                            $GLOBALS['DB'] = clone $database;
                        } elseif ($mode !== 'owned_failure') {
                            $connection->rollBack();
                            $replacement = \itsmng\Database\OwnedMutationFrame::begin($connection);
                            $connection->update('glpi_contacts', ['comment' => 'Replacement witness'], ['id' => $ids[0]]);
                        }
                        $_SESSION['transfer_frame_fixture'] = $mode;
                        $logger->queries = [];
                        if ($mode === 'owned_failure') {
                            throw new \RuntimeException('Refused owned transfer callback');
                        }
                    };
                    $PLUGIN_HOOKS['item_update']['transfer_frame_fixture'][\Contact::class] = static function (\Contact $item) use ($ids, $mode, $change, &$retained): void {
                        if ((int)$item->getID() === $ids[0]) {
                            $retained = $item;
                            if (in_array($mode, ['item_update', 'writer_swap', 'owned_failure'], true)) {
                                $change();
                            }
                        }
                    };
                    $PLUGIN_HOOKS['pre_item_update']['transfer_frame_fixture'][\Contact::class] = static function (\Contact $item) use ($ids, $mode, $change, &$retained): void {
                        if ($mode === 'refused_update' && (int)$item->getID() === $ids[0]) {
                            $retained = $item;
                            $item->input = [];
                            $change();
                        }
                    };
                    $PLUGIN_HOOKS['item_transfer']['transfer_frame_fixture'] = static function (array $item) use ($ids, $mode, $change): void {
                        if ($mode === 'item_transfer' && $item['type'] === 'Contact' && (int)$item['id'] === $ids[0]) {
                            $change();
                        }
                    };
                    $failure = null;
                    $result = null;
                    try {
                        try {
                            $result = (new \Transfer())->moveItems(['Contact' => $ids], $target, []);
                        } catch (\Throwable $error) {
                            $failure = $error;
                        } finally {
                            $DB = $database;
                        }
                        $this->boolean($fired)->isTrue();
                        // No later model, binding, audit or queue write may follow
                        // the callback that invalidated the active operation.
                        $writes = array_values(array_filter($logger->queries, static fn (string $sql): bool =>
                            preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1));
                        $this->array($writes)->isEmpty();
                        if ($replacement !== null) {
                            $this->object($failure)->isInstanceOf(\itsmng\Database\MutationRollbackFailure::class);
                            $this->object($failure->primary)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
                            $replacement->assertActive();
                            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                            $this->string($_SESSION['transfer_frame_fixture'])->isIdenticalTo($mode);
                            $this->integer((int)$retained->fields['entities_id'])->isIdenticalTo($mode === 'refused_update' ? $source : $target);
                            $this->string($connection->fetchOne('SELECT comment FROM glpi_contacts WHERE id=?', [$ids[0]]))->isIdenticalTo('Replacement witness');
                        } else {
                            // The strict test logger throws when runTransfer logs
                            // an owned refusal, after its proven rollback/rewind.
                            $this->object($failure)->isInstanceOf(\RuntimeException::class);
                            $this->string($failure->getMessage())->contains($mode === 'writer_swap'
                                ? 'A transfer callback replaced its active writer.'
                                : 'Refused owned transfer callback');
                            $this->variable($result)->isNull();
                            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                            $this->boolean(isset($_SESSION['transfer_frame_fixture']))->isFalse();
                            $this->integer((int)$retained->fields['entities_id'])->isIdenticalTo($source);
                        }
                        $owners = array_map('intval', $connection->fetchFirstColumn('SELECT entities_id FROM glpi_contacts WHERE id IN (?, ?) ORDER BY id', $ids));
                        $this->array($owners)->isIdenticalTo([$source, $source]);
                    } finally {
                        $DB = $database;
                        $PLUGIN_HOOKS = $savedHooks;
                        if ($replacement !== null) {
                            $replacement->rollBack();
                        }
                        $_SESSION = $session;
                    }
                }
                // A normal retry still joins and preserves the caller's frame.
                $this->boolean((new \Transfer())->moveItems(['Contact' => $ids], $target, []))->isTrue();
                $this->array(array_map('intval', $connection->fetchFirstColumn('SELECT entities_id FROM glpi_contacts WHERE id IN (?, ?) ORDER BY id', $ids)))->isIdenticalTo([$target, $target]);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testOverriddenHistoryCannotContinueIntoTicketMutations(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            $manager = \itsmng\Database\Orm::create($database);
            $entity = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $computer = new \itsmng\Database\Entity\Computer();
            $computer->entities = $entity;
            $computer->name = 'Public related transfer boundary';
            $ticket = new \itsmng\Database\Entity\Ticket();
            $ticket->entities = $entity;
            $ticket->name = 'Ticket must retain its original asset';
            $manager->persist($computer);
            $manager->persist($ticket);
            $manager->flush();
            $link = new \itsmng\Database\Entity\ItemTicket();
            $link->itemtype = 'Computer';
            $link->computer = $computer;
            $link->tickets = $ticket;
            $history = new \itsmng\Database\Entity\Log();
            $history->itemtype = 'Computer';
            $history->items_id = $computer->id;
            $history->new_value = 'Retained original history';
            $manager->persist($link);
            $manager->persist($history);
            $manager->flush();
            $transfer = new class extends \Transfer {
                public ?\Closure $afterHistory = null;
                public int $ticketCalls = 0;

                public function transferHistory($itemtype, $ID, $newID)
                {
                    parent::transferHistory($itemtype, $ID, $newID);
                    $callback = $this->afterHistory;
                    $this->afterHistory = null;
                    if ($callback !== null) {
                        $callback();
                    }
                }

                public function transferTickets($itemtype, $ID, $newID)
                {
                    ++$this->ticketCalls;
                    parent::transferTickets($itemtype, $ID, $newID);
                }
            };
            $replacement = null;
            $level = $connection->getTransactionNestingLevel();
            $transfer->afterHistory = static function () use ($connection, $computer, $logger, &$replacement): void {
                $connection->rollBack();
                $replacement = \itsmng\Database\OwnedMutationFrame::begin($connection);
                $connection->update('glpi_computers', ['comment' => 'Public helper replacement witness'], ['id' => $computer->id]);
                $logger->queries = [];
            };
            try {
                $this->exception(static fn () => $transfer->moveItems(['Computer' => [$computer->id]], $target, ['keep_ticket' => 1]))
                    ->isInstanceOf(\itsmng\Database\MutationRollbackFailure::class);
                $this->object($replacement)->isInstanceOf(\itsmng\Database\OwnedMutationFrame::class);
                $replacement->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                $this->integer($transfer->ticketCalls)->isIdenticalTo(0);
                $this->array(array_values(array_filter($logger->queries, static fn (string $sql): bool =>
                    preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1)))->isEmpty();
                $this->string($connection->fetchOne('SELECT comment FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo('Public helper replacement witness');
                $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo($source);
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_items_tickets WHERE id=? AND computers_id=? AND tickets_id=?', [$link->id, $computer->id, $ticket->id]))->isIdenticalTo(1);
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE id=?', [$history->id]))->isIdenticalTo(1);
            } finally {
                $replacement?->rollBack();
            }
            // The actual public overrides remain usable with normal ownership.
            $this->boolean($transfer->moveItems(['Computer' => [$computer->id]], $target, ['keep_ticket' => 1]))->isTrue();
            $this->integer($transfer->ticketCalls)->isIdenticalTo(1);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_items_tickets WHERE id=?', [$link->id]))->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE id=?', [$history->id]))->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo($target);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        });
    }

    public function testManyInstalledVersionsUseBoundedOwnerReads(): void
    {
        $this->login();
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $manager = \itsmng\Database\Orm::create($database);
            $root = $manager->getReference(\itsmng\Database\Entity\Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $software = new \itsmng\Database\Entity\Software();
            $software->entities = $root;
            $software->name = 'Bounded installation owners';
            $manager->persist($software);
            $computer = new \itsmng\Database\Entity\Computer();
            $computer->entities = $root;
            $computer->name = 'Bounded installation subject';
            $manager->persist($computer);
            $manager->flush();
            $counts = [];
            for ($index = 1; $index <= 25; ++$index) {
                $version = new \itsmng\Database\Entity\SoftwareVersion();
                $version->entities = $root;
                $version->softwares = $software;
                $version->name = 'Owner version ' . $index;
                $manager->persist($version);
                $installation = new \itsmng\Database\Entity\ItemSoftwareVersion();
                $installation->itemtype = 'Computer';
                $installation->entities = $root;
                $installation->computer = $computer;
                $installation->softwareversions = $version;
                $manager->persist($installation);
                $manager->flush();
                if (in_array($index, [1, 25], true)) {
                    $before = $connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]);
                    $logger->queries = [];
                    (new \itsmng\Domain\SoftwareAssignmentService($database))->lockTransferSubject('Computer', $computer->id);
                    $counts[] = $logger->ownerReads();
                    $this->integer($logger->ownerReads())->isIdenticalTo(2);
                    $model = new Computer();
                    $this->boolean($model->can($computer->id, UPDATE))->isTrue();
                    $logger->queries = [];
                    $this->boolean($model->update(['id' => $computer->id, 'is_template' => $index === 1 ? 1 : 0]))->isTrue();
                    $this->integer($logger->ownerReads())->isIdenticalTo(2);
                    $this->array($connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]))->isIdenticalTo($before);
                }
            }
            $this->array($counts)->isIdenticalTo([2, 2]);
        });
    }

    public function testInstalledVersionOwnerRecheckedAfterGraphLock(): void
    {
        $this->login();
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $manager = \itsmng\Database\Orm::create($database);
            $root = $manager->getReference(\itsmng\Database\Entity\Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $owners = [];
            foreach (['Initially selected owner', 'Changed owner'] as $name) {
                $owner = new \itsmng\Database\Entity\Software();
                $owner->entities = $root;
                $owner->name = $name;
                $manager->persist($owner);
                $owners[] = $owner;
            }
            $computer = new \itsmng\Database\Entity\Computer();
            $computer->entities = $root;
            $manager->persist($computer);
            $version = new \itsmng\Database\Entity\SoftwareVersion();
            $version->entities = $root;
            $version->softwares = $owners[0];
            $manager->persist($version);
            $installation = new \itsmng\Database\Entity\ItemSoftwareVersion();
            $installation->itemtype = 'Computer';
            $installation->entities = $root;
            $installation->computer = $computer;
            $installation->softwareversions = $version;
            $manager->persist($installation);
            $manager->flush();
            $changeFrame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            try {
                // Deterministic actual writer interleaving before the second
                // observation. This is not claimed as a two-session race test.
                $logger->beforeCurrentInstallations = static function () use ($connection, $version, $owners): void {
                    $connection->update('glpi_softwareversions', ['softwares_id' => $owners[1]->id], ['id' => $version->id]);
                };
                $this->exception(static fn () => (new \itsmng\Domain\SoftwareAssignmentService($database))->lockTransferSubject('Computer', $computer->id))
                    ->isInstanceOf(\itsmng\Domain\SoftwareAssignmentCancelled::class)
                    ->hasMessage('Transfer installation membership changed before locking; retry the command.');
                $this->variable($logger->beforeCurrentInstallations)->isNull();
                $this->integer((int)$connection->fetchOne('SELECT softwares_id FROM glpi_softwareversions WHERE id=?', [$version->id]))->isIdenticalTo($owners[1]->id);
            } finally {
                $changeFrame->rollBack();
            }
            $this->integer((int)$connection->fetchOne('SELECT softwares_id FROM glpi_softwareversions WHERE id=?', [$version->id]))->isIdenticalTo($owners[0]->id);
        });
    }

}
