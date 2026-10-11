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
use DeviceNetworkCard as DeviceNetworkCardModel;
use DevicePci as DevicePciModel;
use DeviceControl as DeviceControlModel;
use DeviceGraphicCard as DeviceGraphicCardModel;
use DeviceSoundCard as DeviceSoundCardModel;
use Manufacturer as ManufacturerModel;
use RegisteredID as RegisteredIDModel;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use itsmng\Database\Entity\RegisteredID as RegisteredIDEntity;
use itsmng\Database\Orm;
use mock\DBmysql as RegisteredOptionAdapterProbe;
use Stringable;
use Plugin;
use ReflectionProperty;
use Doctrine\DBAL\Exception as DatabaseException;
use itsmng\Database\EntityRegistry;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Database\OwnershipUpdateUnit;
use CommonDBTM;
use PluginRegisteredIdentifierOwnershipParent;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';
require_once dirname(__DIR__) . '/fixtures/PluginRegisteredIdentifierOwnershipParent.php';

class DeviceNetworkCard extends DbTestCase
{
    /** @dataProvider registeredIdentifierFormParents */
    public function testRegisteredIdentifiersOwnContainingLifecycle(string $parentType, string $nameField): void
    {
        global $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $source = $this->createItem($parentType, [$nameField => 'identifier-source-' . $this->getUniqueString()]);
            $target = $this->createItem($parentType, [$nameField => 'identifier-target-' . $this->getUniqueString()]);
            $other = $this->createItem($parentType, [$nameField => 'identifier-other-' . $this->getUniqueString()]);
            $first = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $source->getID(), 'name' => 'duplicate', 'device_type' => 'PCI']);
            $second = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $source->getID(), 'name' => 'duplicate', 'device_type' => 'PCI']);
            $unrelated = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $other->getID(), 'name' => 'NULL', 'device_type' => '']);
            $connection = $DB->getDoctrineConnection();
            $policy = EntityRegistry::discriminatedReferences(RegisteredIDModel::getTable())['items_id'];
            $owner = $policy['selections'][$parentType]['column'];
            $row = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$first->getID()]);
            $this->integer((int)$row[$owner])->isIdenticalTo((int)$source->getID());
            $this->variable($row['opaque_parent_id'])->isNull();
            foreach ($policy['selections'] as $selection) {
                if ($selection['column'] !== $owner) {
                    $this->variable($row[$selection['column']])->isNull();
                }
            }
            $orphan = (int)$connection->fetchOne('SELECT COALESCE(MAX(id),0)+1 FROM ' . $connection->quoteIdentifier($policy['selections'][$parentType]['target']));
            foreach ([[$owner => $orphan], [$owner => 0], [$owner => -1], ['itemtype' => 'UnknownRegisteredIdentifierParent'], ['opaque_parent_id' => $source->getID()]] as $invalid) {
                $this->exception(static fn () => $connection->transactional(static fn () => $connection->update('glpi_registeredids', $invalid, ['id' => $first->getID()])))->isInstanceOf(DatabaseException::class);
                $this->array($connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$first->getID()]))->isIdenticalTo($row);
            }
            $this->array(RegisteredIDModel::getFormOptions($parentType, $source->getID()))->hasSize(2);
            $parentClone = $source->clone([$nameField => 'identifier-parent-clone-' . $this->getUniqueString()]);
            $this->integer((int)$parentClone)->isGreaterThan(0);
            $this->array(RegisteredIDModel::getFormOptions($parentType, $parentClone))->isEmpty('Containing parent clones retain their existing omission of identifiers');
            $cloneName = 'direct-child-clone-' . $this->getUniqueString();
            $expectedCloneName = $first->computeCloneName($cloneName, 1);
            $childClone = $first->clone(RegisteredIDEntity::withReference(['name' => $cloneName], $parentType, (int)$target->getID()));
            $this->integer((int)$childClone)->isGreaterThan(0);
            $clone = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$childClone]);
            $this->integer((int)$clone[$owner])->isIdenticalTo((int)$target->getID());
            $this->string($clone['name'])->isIdenticalTo($expectedCloneName);
            $this->string($clone['device_type'])->isIdenticalTo('PCI');
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$first->getID()]))->isIdenticalTo($row);
            $writer = Orm::create($DB);
            $dirty = $writer->find(RegisteredIDEntity::class, (int)$first->getID());
            $dirty->name = 'independent pending identifier';
            $untouched = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$unrelated->getID()]);
            $this->boolean($source->delete(['id' => $source->getID(), '_replace_by' => $target->getID()], true))->isTrue();
            $this->array(RegisteredIDModel::getFormOptions($parentType, $target->getID()))->hasSize(3);
            foreach ([$first->getID(), $second->getID()] as $id) {
                $moved = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$id]);
                $this->integer((int)$moved[$owner])->isIdenticalTo((int)$target->getID());
                $this->string($moved['itemtype'])->isIdenticalTo($parentType);
                $this->string($moved['name'])->isIdenticalTo('duplicate');
                $this->string($moved['device_type'])->isIdenticalTo('PCI');
            }
            $this->boolean($writer->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('independent pending identifier');
            $this->boolean($target->delete(['id' => $target->getID()], true))->isTrue();
            foreach ([$first->getID(), $second->getID(), $childClone] as $id) {
                $this->boolean($connection->fetchOne('SELECT id FROM glpi_registeredids WHERE id=?', [$id]))->isFalse();
            }
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$unrelated->getID()]))->isIdenticalTo($untouched);
            $this->boolean((new RegisteredIDModel())->add(['itemtype' => $parentType, 'items_id' => 0, 'name' => 'not attached']))->isFalse();
        } finally {
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    public function testRegisteredIdentifierFinalAddAndNestedInstanceOwnAdmission(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $database = $DB;
        try {
            $this->login();
            $source = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'identifier-admission-source-' . $this->getUniqueString()]);
            $target = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'identifier-admission-target-' . $this->getUniqueString()]);
            $plugins->setValue(null, [...$active, 'registered_identifier_admission']);
            $deny = true;
            $redirect = false;
            $mutateReceiver = false;
            $mutationTarget = null;
            $denials = 0;
            $rewrite = (int)$target->getID();
            $PLUGIN_HOOKS['item_can']['registered_identifier_admission'][DeviceNetworkCardModel::class] =
                static function (DeviceNetworkCardModel $parent) use ($target, &$deny, &$redirect, &$mutateReceiver, &$mutationTarget, &$denials, $database): void {
                    global $DB;
                    if ($redirect && (int)$parent->getID() === (int)$target->getID()) {
                        $DB = clone $database;
                    }
                    if ($mutateReceiver && (int)$parent->getID() === (int)$target->getID()) {
                        $mutationTarget->fields['name'] = 'callback-substituted receiver';
                    }
                    if ($deny && (int)$parent->getID() === (int)$target->getID()) {
                        ++$denials;
                        $parent->right = false;
                    }
                };
            $PLUGIN_HOOKS['post_prepareadd']['registered_identifier_admission'][RegisteredIDModel::class] =
                static function (RegisteredIDModel $child) use (&$rewrite): void {
                    if (($child->input['name'] ?? null) === 'late-substitution') {
                        $child->input = RegisteredIDEntity::withReference($child->input, DeviceNetworkCardModel::class, $rewrite);
                    }
                };
            $model = new RegisteredIDModel();
            $input = ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'late-substitution', 'device_type' => 'PCI'];
            $this->boolean($model->add($input))->isFalse();
            $this->integer($denials)->isGreaterThan(0);
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, $target->getID()))->isEmpty();
            $deny = false;
            $redirect = true;
            try {
                $this->exception(static fn () => $model->add($input))->isInstanceOf(TransactionOwnershipMismatch::class);
            } finally {
                $DB = $database;
                $redirect = false;
            }
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, $target->getID()))->isEmpty();
            $id = $model->add($input);
            $this->integer((int)$id)->isGreaterThan(0);
            $this->integer((int)$model->fields['items_id'])->isIdenticalTo((int)$target->getID());
            $this->integer((int)$model->fields['devicenetworkcards_id'])->isIdenticalTo((int)$target->getID());
            $rewrite = 0;
            $this->boolean($model->add($input))->isFalse();
            $rewrite = (int)$DB->getDoctrineConnection()->fetchOne('SELECT COALESCE(MAX(id),0)+1 FROM glpi_devicenetworkcards');
            $this->boolean($model->add($input))->isFalse('A positive final identity must resolve to an existing containing target');
            $rewrite = (int)$target->getID();
            $this->integer((int)$model->add($input))->isGreaterThan(0);
            $initial = $this->createItem(RegisteredIDModel::class, ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'prepared-retarget-source', 'device_type' => 'PCI']);
            $prepared = new RegisteredIdentifierPreparedWrite();
            $this->boolean($prepared->getFromDB($initial->getID()))->isTrue();
            $before = $DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$initial->getID()]);
            $prepared->preparedParent = (int)$target->getID();
            $deny = true;
            $beforeDenial = $denials;
            $this->boolean($prepared->update(['id' => $initial->getID(), 'name' => 'prepared-retarget-denied']))->isFalse();
            $this->hasSessionMessages(ERROR, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            $this->integer($denials)->isGreaterThan($beforeDenial);
            $this->array($DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$initial->getID()]))->isIdenticalTo($before);
            $deny = false;
            $mutateReceiver = true;
            $mutationTarget = $prepared;
            $this->boolean($prepared->update(['id' => $initial->getID(), 'name' => 'prepared-receiver-substitution']))->isFalse();
            $this->array($DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$initial->getID()]))->isIdenticalTo($before);
            $this->string($prepared->fields['name'])->isIdenticalTo($before['name']);
            $mutateReceiver = false;
            $mutationTarget = null;
            $this->boolean($prepared->update(['id' => $initial->getID(), 'name' => 'prepared-retarget-admitted']))->isTrue();
            $this->integer((int)$prepared->fields['devicenetworkcards_id'])->isIdenticalTo((int)$target->getID());
            $this->string($prepared->fields['name'])->isIdenticalTo('prepared-retarget-admitted');
            $prepared->preparedParent = null;
            $prepared->cancelParent = true;
            $this->boolean($prepared->update(RegisteredIDEntity::withReference(
                ['id' => $initial->getID(), 'name' => 'cancelled-retarget-content'],
                DeviceNetworkCardModel::class,
                (int)$source->getID()
            )))->isTrue();
            $this->integer((int)$prepared->fields['devicenetworkcards_id'])->isIdenticalTo((int)$target->getID());
            $this->string($prepared->fields['name'])->isIdenticalTo('cancelled-retarget-content');
            $connection = $database->getDoctrineConnection();
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new RegisteredOptionAdapterProbe();
            $this->calling($adapter)->getProvider = $database->getProvider();
            $this->calling($adapter)->request = static fn ($criteria) => $database->request($criteria);
            $getterMutations = 0;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($connection, $model, &$getterMutations) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
                    if (($frame['class'] ?? null) === CommonDBTM::class && ($frame['function'] ?? null) === 'executePreparedAdd') {
                        ++$getterMutations;
                        $model->fields['name'] = 'writer-getter-substitution';
                        break;
                    }
                }
                return $connection;
            };
            $DB = $adapter;
            try {
                $this->boolean(OwnershipUpdateUnit::withWriterGuard(
                    $adapter,
                    $connection,
                    static fn () => $model->add(['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'writer-getter-original'])
                ))->isFalse();
                $this->integer($getterMutations)->isGreaterThan(0);
            } finally {
                $DB = $database;
            }
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, $source->getID()))->isEmpty();
            $assigned = (int)$connection->fetchOne('SELECT COALESCE(MAX(id),0) FROM glpi_registeredids') + 100;
            $earlyMutations = 0;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($connection, $model, $assigned, &$earlyMutations) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
                    if ($earlyMutations === 0 && ($frame['class'] ?? null) === RegisteredIDModel::class
                        && ($frame['function'] ?? null) === 'executePreparedAdd') {
                        ++$earlyMutations;
                        $model->fields['id'] = $assigned + 1;
                        $model->input['name'] = 'early-writer-getter-substitution';
                        break;
                    }
                }
                return $connection;
            };
            $DB = $adapter;
            try {
                $this->exception(static fn () => $model->addWithAssignedIdentifier(
                    $assigned,
                    ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'early-writer-getter-original']
                ))->isInstanceOf(TransactionOwnershipMismatch::class);
                $this->integer($earlyMutations)->isIdenticalTo(1);
            } finally {
                $DB = $database;
            }
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_registeredids WHERE id IN (?,?)', [$assigned, $assigned + 1]))->isIdenticalTo(0);
            $this->calling($adapter)->getDoctrineConnection = $connection;
            $this->integer((int)$model->addWithAssignedIdentifier(
                $assigned,
                ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'early-writer-getter-recovery']
            ))->isIdenticalTo($assigned);
            $this->boolean($model->delete(['id' => $assigned], 1))->isTrue();
            $wrongParent = $this->createItem(DevicePciModel::class, ['designation' => 'identifier-wrong-physical-parent-' . $this->getUniqueString()]);
            $tables = new ReflectionProperty(CommonDBTM::class, 'tables_of');
            $savedTables = $tables->getValue();
            $probe = new ScalarReadProbe($connection);
            $this->calling($adapter)->getDoctrineConnection = $probe;
            DeviceNetworkCardModel::forceTable(DevicePciModel::getTable());
            $DB = $adapter;
            try {
                $this->boolean($model->add(['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $wrongParent->getID(), 'name' => 'wrong-physical-parent']))->isFalse();
                $this->array(array_values(array_filter(
                    $probe->queries,
                    static fn (array $query): bool => (bool)preg_match('/\bFROM\s+glpi_devicepcis\b/i', str_replace(['`', '"'], '', $query['sql']))
                )))->isEmpty('The adopted physical target is checked before loading a foreign parent row');
            } finally {
                $DB = $database;
                $tables->setValue(null, $savedTables);
            }
            $nested = false;
            $inner = null;
            $PLUGIN_HOOKS['pre_item_add']['registered_identifier_admission'][RegisteredIDModel::class] =
                static function (RegisteredIDModel $child) use ($source, &$nested, &$inner): void {
                    if (!$nested && ($child->input['name'] ?? null) === 'outer-identifier') {
                        $nested = true;
                        try {
                            $inner = $child->add(['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'inner-identifier', 'device_type' => 'USB']);
                        } finally {
                            $nested = false;
                        }
                    }
                };
            $observed = [];
            $PLUGIN_HOOKS['item_add']['registered_identifier_admission'][RegisteredIDModel::class] =
                static function (RegisteredIDModel $child) use (&$observed): void {
                    $observed[(int)$child->getID()] = [$child->fields['name'], (int)$child->fields['items_id']];
                };
            $outer = $model->add(['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'outer-identifier', 'device_type' => 'PCI']);
            $this->integer((int)$inner)->isGreaterThan(0);
            $this->integer((int)$outer)->isGreaterThan(0)->isNotIdenticalTo((int)$inner);
            $this->array($observed[(int)$inner])->isIdenticalTo(['inner-identifier', (int)$source->getID()]);
            $this->array($observed[(int)$outer])->isIdenticalTo(['outer-identifier', (int)$source->getID()]);
            $this->integer((int)$model->getID())->isIdenticalTo((int)$outer);
            $rows = RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, $source->getID());
            $byId = array_column($rows, '_registeredID', 'id');
            $expected = [$inner => 'inner-identifier', $outer => 'outer-identifier'];
            ksort($byId);
            ksort($expected);
            $this->array($byId)->isIdenticalTo($expected);
        } finally {
            $DB = $database;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }

    public function testRegisteredIdentifierAliasesAndCustomContainingRoles(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $database = $DB;
        try {
            $this->login();
            $source = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'identifier-alias-source-' . $this->getUniqueString()]);
            $target = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'identifier-alias-target-' . $this->getUniqueString()]);
            $canonical = $this->createItem(RegisteredIDModel::class, ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $source->getID(), 'name' => 'canonical', 'device_type' => 'PCI']);
            $aliasKind = strtolower(DeviceNetworkCardModel::class);
            $alias = $this->createItem(RegisteredIDModel::class, ['itemtype' => $aliasKind, 'items_id' => $source->getID(), 'name' => 'alias', 'device_type' => 'USB']);
            $connection = $DB->getDoctrineConnection();
            $before = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$alias->getID()]);
            $this->string($before['itemtype'])->isIdenticalTo($aliasKind);
            $this->variable($before['devicenetworkcards_id'])->isNull();
            $this->integer((int)$before['opaque_parent_id'])->isIdenticalTo((int)$source->getID());
            $mysql = !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, $source->getID()))->hasSize($mysql ? 2 : 1);
            $plugins->setValue(null, [...$active, 'registered_identifier_cleanup']);
            $PLUGIN_HOOKS['pre_item_update']['registered_identifier_cleanup'][RegisteredIDModel::class] =
                static function (RegisteredIDModel $child) use ($canonical): void {
                    if ((int)$child->getID() === (int)$canonical->getID()) {
                        $child->input = false;
                    }
                };
            $this->boolean($source->delete(['id' => $source->getID(), '_replace_by' => $target->getID()], true))->isFalse();
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$alias->getID()]))->isIdenticalTo($before);
            $this->boolean($source->getFromDB($source->getID()))->isTrue();
            unset($PLUGIN_HOOKS['pre_item_update']['registered_identifier_cleanup']);
            $this->boolean($source->delete(['id' => $source->getID(), '_replace_by' => $target->getID()], true))->isTrue();
            $moved = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$alias->getID()]);
            $this->string($moved['itemtype'])->isIdenticalTo($aliasKind);
            $this->variable($moved['devicenetworkcards_id'])->isNull();
            $this->integer((int)$moved['opaque_parent_id'])->isIdenticalTo((int)($mysql ? $target->getID() : $source->getID()));
            $custom = $this->createItem(PluginRegisteredIdentifierOwnershipParent::class, ['designation' => 'identifier-custom-' . $this->getUniqueString()]);
            PluginRegisteredIdentifierOwnershipParent::$tableCallback = static function () use ($database): void {
                global $DB;
                $DB = clone $database;
            };
            $model = new RegisteredIDModel();
            try {
                $this->exception(static fn () => $model->add(['itemtype' => PluginRegisteredIdentifierOwnershipParent::class, 'items_id' => $custom->getID(), 'name' => 'redirected']))->isInstanceOf(TransactionOwnershipMismatch::class);
            } finally {
                $DB = $database;
                PluginRegisteredIdentifierOwnershipParent::$tableCallback = null;
            }
            $this->array(RegisteredIDModel::getFormOptions(PluginRegisteredIdentifierOwnershipParent::class, $custom->getID()))->isEmpty();
            $this->integer((int)$model->add(['itemtype' => PluginRegisteredIdentifierOwnershipParent::class, 'items_id' => $custom->getID(), 'name' => 'recovered']))->isGreaterThan(0);
            $child = $this->createItem(RegisteredIDModel::class, ['itemtype' => PluginRegisteredIdentifierOwnershipParent::class, 'items_id' => $custom->getID(), 'name' => 'custom', 'device_type' => '']);
            $row = $connection->fetchAssociative('SELECT * FROM glpi_registeredids WHERE id=?', [$child->getID()]);
            $this->string($row['itemtype'])->isIdenticalTo(PluginRegisteredIdentifierOwnershipParent::class);
            $factory = getItemForItemtype($row['itemtype']);
            $this->object($factory)->isInstanceOf(PluginRegisteredIdentifierOwnershipParent::class);
            $this->boolean($factory->getFromDB($row['items_id']))->isTrue();
            $this->integer((int)$factory->getID())->isIdenticalTo((int)$custom->getID());
            $this->variable($row['devicenetworkcards_id'])->isNull();
            $this->integer((int)$row['opaque_parent_id'])->isIdenticalTo((int)$custom->getID());
            $this->array(RegisteredIDModel::getFormOptions(PluginRegisteredIdentifierOwnershipParent::class, $custom->getID()))->hasSize(2);
        } finally {
            $DB = $database;
            PluginRegisteredIdentifierOwnershipParent::$tableCallback = null;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }

    /** @dataProvider registeredIdentifierFormParents */
    public function testRegisteredIdentifierFormOptionsKeepValuesAndLiveOwners(string $parentType, string $nameField): void
    {
        global $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $parent = $this->createItem($parentType, [$nameField => 'registered-options-' . $this->getUniqueString()]);
            $other = $this->createItem($parentType, [$nameField => 'other-options-' . $this->getUniqueString()]);
            $first = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $parent->getID(), 'device_type' => 'PCI', 'name' => '1234:abcd']);
            $second = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $parent->getID(), 'device_type' => 'USB', 'name' => '2345:bcde']);
            $unrelated = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $other->getID(), 'device_type' => 'PCI', 'name' => '3456:cdef']);
            $formValues = static function () use ($parent): array {
                $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
                return $fields[0]['values'];
            };
            $values = $formValues();
            $this->array($values)->hasSize(2);
            $byId = array_column($values, null, 'id');
            $this->array(array_keys($byId[$first->getID()]))->isIdenticalTo(['id', '_registeredID_type', '_registeredID']);
            $this->string($byId[$first->getID()]['_registeredID_type'])->isIdenticalTo('PCI');
            $this->string($byId[$first->getID()]['_registeredID'])->isIdenticalTo('1234:abcd');
            $this->array(array_keys($byId))->notContains((int)$unrelated->getID());
            $connection = $DB->getDoctrineConnection();
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                foreach ($values as $value) {
                    $this->integer($value['id']);
                }
            }
            $writer = Orm::create($DB);
            $retained = $writer->find(RegisteredIDEntity::class, (int)$first->getID());
            $retained->name = 'Independent pending identifier';
            $this->boolean($parent->update(['id' => $parent->getID(), '_registeredID' => [$first->getID() => '4567:def0'], '_registeredID_type' => [$first->getID() => 'USB']]))->isTrue();
            $connection->update('glpi_registeredids', ['name' => null], ['id' => $second->getID()]);
            $fresh = array_column($formValues(), null, 'id');
            $this->string($fresh[$first->getID()]['_registeredID'])->isIdenticalTo('4567:def0');
            $this->string($fresh[$first->getID()]['_registeredID_type'])->isIdenticalTo('USB');
            $this->variable($fresh[$second->getID()]['_registeredID'])->isNull();
            $this->string($byId[$first->getID()]['_registeredID'])->isIdenticalTo('1234:abcd');
            $this->boolean($writer->contains($retained))->isTrue();
            $this->string($retained->name)->isIdenticalTo('Independent pending identifier');
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($first, $formValues): void {
                    $owned = $outer->find(RegisteredIDEntity::class, (int)$first->getID());
                    $owned->name = 'Outer pending identifier';
                    $fresh = array_column($formValues(), null, 'id');
                    $this->string($fresh[$first->getID()]['_registeredID'])->isIdenticalTo('4567:def0');
                    $this->boolean($outer->contains($owned))->isTrue();
                    $this->string($owned->name)->isIdenticalTo('Outer pending identifier');
                }
            );
            $this->boolean($first->delete(['id' => $first->getID()], true))->isTrue();
            $this->array($formValues())->hasSize(1);
        } finally {
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    protected function registeredIdentifierFormParents(): array
    {
        return [
            [DeviceNetworkCardModel::class, 'designation'], [DevicePciModel::class, 'designation'],
            [DeviceControlModel::class, 'designation'], [DeviceGraphicCardModel::class, 'designation'],
            [DeviceSoundCardModel::class, 'designation'], [ManufacturerModel::class, 'name'],
        ];
    }

    public function testRegisteredIdentifierOptionsSelectRouteBeforeIdentityCallbacks(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $parent = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'route-options-' . $this->getUniqueString()]);
            $identifier = $this->createItem(RegisteredIDModel::class, ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $parent->getID(), 'device_type' => 'PCI', 'name' => '5678:ef01']);
            $listener = new class () {
                public int $loads = 0;
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof RegisteredIDEntity) {
                        ++$this->loads;
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events = new EventManager();
            $events->addEventListener(['postLoad', 'onClear'], $listener);
            $probe = new class ($original->getDoctrineConnection()) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $probe->events = $events;
            $writer = Orm::forConnection($probe);
            $live = $writer->find(RegisteredIDEntity::class, (int)$identifier->getID());
            $live->name = 'Custom pending identifier';
            $listener->loads = 0;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new RegisteredOptionAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $identity = new class ((int)$parent->getID(), $original, $listener) implements Stringable {
                public int $calls = 0;
                public int $clearsBeforeProjection = -1;
                public function __construct(private int $id, private object $original, private object $listener)
                {
                }
                public function __toString(): string
                {
                    ++$this->calls;
                    // Earlier form dropdowns own separate managers and cleanup.
                    $this->clearsBeforeProjection = $this->listener->clears;
                    $GLOBALS['DB'] = $this->original;
                    return (string)$this->id;
                }
            };
            $parent->fields['id'] = $identity;
            $DB = $adapter;
            $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
            $this->string($fields[0]['values'][0]['_registeredID'])->isIdenticalTo('5678:ef01');
            $this->integer($identity->calls)->isIdenticalTo(1);
            $this->integer($listener->clears)->isIdenticalTo($identity->clearsBeforeProjection);
            $this->object($DB)->isIdenticalTo($original);
            $this->array(array_values(array_filter($probe->queries, static fn (array $query): bool => isset($query['params']['parent']) && ($query['params']['kind'] ?? null) === DeviceNetworkCardModel::class)))->hasSize(1);
            foreach (['null', 'NULL', 'Null', 'nUlL'] as $sentinel) {
                $parent->fields['id'] = $sentinel;
                $DB = $adapter;
                $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
                $this->array($fields[0]['values'])->isEmpty();
                $query = end($probe->queries);
                $this->string($query['sql'])->contains(' IS NULL');
                $this->array($query['params'])->notHasKey('parent');
            }
            $parent->fields['id'] = null;
            $DB = $adapter;
            $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
            $this->array($fields[0]['values'])->isEmpty();
            $query = end($probe->queries);
            $this->integer($query['params']['parent'])->isIdenticalTo(-1);
            $clearsBeforeNullProjection = $listener->clears;
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, null))->isEmpty();
            $query = end($probe->queries);
            $this->string($query['sql'])->contains(' IS NULL');
            $this->array($query['params'])->notHasKey('parent');
            $this->integer($listener->loads)->isIdenticalTo(0);
            $this->integer($listener->clears)->isIdenticalTo($clearsBeforeNullProjection);
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Custom pending identifier');
        } finally {
            $DB = $original;
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    public function testImportUsesDesignationAndBandwidth()
    {
        $this->login();

        $obj = new \DeviceNetworkCard();
        $designation = 'nic-' . $this->getUniqueString();

        $id_1 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '1000',
        ]);
        $this->integer((int)$id_1)->isGreaterThan(0);

        $id_2 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '1000',
        ]);
        $this->integer((int)$id_2)->isEqualTo((int)$id_1);

        $id_3 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '100',
        ]);
        $this->integer((int)$id_3)->isGreaterThan(0);
        $this->integer((int)$id_3)->isNotEqualTo((int)$id_1);
    }
}

/** A real prepared-write mutation exercises the production finalizer. */
class RegisteredIdentifierPreparedWrite extends RegisteredIDModel
{
    public ?int $preparedParent = null;
    public bool $cancelParent = false;

    public static function getTable($classname = null)
    {
        return RegisteredIDModel::getTable();
    }

    public function pre_updateInDB()
    {
        parent::pre_updateInDB();
        if ($this->preparedParent !== null) {
            $this->fields['items_id'] = $this->preparedParent;
            $this->updates[] = 'items_id';
        }
        if ($this->cancelParent) {
            $this->updates = array_values(array_diff($this->updates, ['items_id', 'devicenetworkcards_id']));
        }
    }
}
