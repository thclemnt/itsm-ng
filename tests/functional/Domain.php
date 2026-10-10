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

use atoum\atoum\mock\controller as MockController;
use Appliance;
use ApplianceEnvironment;
use ApplianceType;
use Appliance_Item;
use Appliance_Item_Relation;
use DateTimeImmutable;
use DbTestCase;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Domain as LegacyDomain;
use DomainType;
use Domain_Item;
use Entity;
use Infocom;
use Log;
use Plugin;
use Profile;
use ProfileRight;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use itsmng\Appliance\AppliancePluginImport;
use itsmng\Appliance\PluginApplianceSource;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\DomainPluginImport;
use itsmng\Domain\DomainPluginSource;

use function exportArrayToDB;
use function getItemTypeForTable;
use function getTableForItemType;

/* Test for inc/software.class.php */

class Domain extends DbTestCase
{
    private array $importSourceTables = [];

    public function beforeTestMethod($method)
    {
        if ($method === 'testPluginImportRejectsReplacedCallbackFrames') {
            $connection = $GLOBALS['DB']->getDoctrineConnection();
            TransactionOwnership::assertManaged($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo(0, 'Plugin source DDL cannot release a caller transaction');
            $manager = $connection->createSchemaManager();
            $sources = [
                'glpi_plugin_domains_domaintypes' => 'entities_id name comment is_recursive',
                'glpi_plugin_domains_domains' => 'entities_id is_recursive name plugin_domains_domaintypes_id date_creation date_expiration users_id_tech groups_id_tech suppliers_id comment others is_helpdesk_visible date_mod is_deleted',
                'glpi_plugin_domains_domains_items' => 'plugin_domains_domains_id items_id itemtype',
                'glpi_plugin_domains_configs' => 'delay_expired delay_whichexpire',
                'glpi_plugin_appliances_appliancetypes' => 'entities_id is_recursive name comment',
                'glpi_plugin_appliances_environments' => 'name comment',
                'glpi_plugin_appliances_appliances' => 'entities_id is_recursive name is_deleted plugin_appliances_appliancetypes_id comment locations_id plugin_appliances_environments_id users_id users_id_tech groups_id groups_id_tech relationtype date_mod states_id externalid serial otherserial',
                'glpi_plugin_appliances_appliances_items' => 'plugin_appliances_appliances_id items_id itemtype',
                'glpi_plugin_appliances_relations' => 'plugin_appliances_appliances_items_id relations_id',
            ];
            $this->array(array_intersect($manager->listTableNames(), array_keys($sources)))
                ->isEmpty('Import fixtures must never adopt existing plugin source tables');
            try {
                // MySQL DDL belongs before DbTestCase's physical rollback frame.
                foreach ($sources as $name => $fields) {
                    $table = new Table($name);
                    $table->addColumn('id', Types::INTEGER);
                    $table->setPrimaryKey(['id']);
                    foreach (explode(' ', $fields) as $field) {
                        $table->addColumn($field, Types::STRING, ['length' => 255, 'notnull' => false]);
                    }
                    $manager->createTable($table);
                    $this->importSourceTables[] = $name;
                }
            } catch (Throwable $primary) {
                $this->dropImportSources($primary);
                throw $primary;
            }
        }
        try {
            parent::beforeTestMethod($method);
        } catch (Throwable $primary) {
            $this->dropImportSources($primary);
            throw $primary;
        }
    }

    public function afterTestMethod($method)
    {
        $primary = null;
        try {
            parent::afterTestMethod($method);
        } catch (Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            // Only the tables successfully created by this test are ours to remove.
            $this->dropImportSources($primary);
        }
    }

    private function dropImportSources(?Throwable $primary = null): void
    {
        if (!$this->importSourceTables) {
            return;
        }
        $connection = $GLOBALS['DB']->getDoctrineConnection();
        try {
            TransactionOwnership::assertManaged($connection);
            if ($connection->getTransactionNestingLevel() !== 0) {
                throw new TransactionOwnershipMismatch('Plugin source cleanup requires the test frame to be closed.');
            }
        } catch (Throwable $cleanup) {
            throw $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
        }
        $manager = $connection->createSchemaManager();
        $failure = $primary;
        foreach (array_reverse($this->importSourceTables) as $table) {
            try {
                $manager->dropTable($table);
            } catch (Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new MutationCleanupFailure($failure, $cleanup);
            }
        }
        $this->importSourceTables = [];
        if ($failure !== $primary) {
            throw $failure;
        }
    }

    public function testPluginImportRejectsReplacedCallbackFrames(): void
    {
        global $DB, $PLUGIN_HOOKS, $CFG_GLPI;
        $this->login();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $caller = $connection->captureManagedTransactionScope();
        // Class/table mappings populate lazy application caches.
        // Warm the supported aggregate and lifecycle participants before taking the full snapshot.
        foreach ([DomainType::class, LegacyDomain::class, Domain_Item::class,
            ApplianceType::class, ApplianceEnvironment::class, Appliance::class,
            Appliance_Item::class, Appliance_Item_Relation::class,
            Profile::class, ProfileRight::class, Infocom::class, Log::class, Entity::class] as $model) {
            $table = getTableForItemType($model);
            $this->string($model::getTable())->isIdenticalTo($table);
            $this->string(getItemTypeForTable($table))->isIdenticalTo($model);
        }
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        $config = $CFG_GLPI;
        $profileId = (int)$connection->fetchOne('SELECT MIN(id) FROM glpi_profiles');
        $this->integer($profileId)->isGreaterThan(0);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [$profileId, 'plugin_domains_open_ticket']))->isIdenticalTo(0);
        $connection->insert('glpi_profilerights', ['profiles_id' => $profileId, 'name' => 'plugin_domains_open_ticket', 'rights' => 1]);
        $connection->insert('glpi_plugin_domains_configs', ['id' => 1, 'delay_expired' => '0', 'delay_whichexpire' => '0']);
        $connection->executeStatement('UPDATE glpi_entities SET send_domains_alert_expired_delay = -2, send_domains_alert_close_expiries_delay = -2, use_domains_alert = -2');
        $imports = [
            [DomainPluginImport::class, DomainType::class, 'glpi_plugin_domains_domaintypes'],
            [AppliancePluginImport::class, ApplianceType::class, 'glpi_plugin_appliances_appliancetypes'],
        ];
        foreach ($imports as [$importClass, $modelClass, $source]) {
            $session = $_SESSION;
            $sourceItemtype = $importClass === DomainPluginImport::class
                ? DomainPluginSource::ITEMTYPE : PluginApplianceSource::ITEMTYPE;
            $profileTypes = exportArrayToDB([$sourceItemtype]);
            $connection->update('glpi_profiles', ['helpdesk_item_type' => $profileTypes], ['id' => $profileId]);
            // Explicit IDs/sequence synchronization can advance native high-water marks even after rollback.
            // Use the nearest unused type ID rather than consuming a distant fixture range.
            $id = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM ' . $connection->quoteIdentifier($modelClass::getTable()));
            $connection->insert($source, ['id' => $id, 'entities_id' => '0', 'name' => 'Owned import type', 'comment' => '', 'is_recursive' => '0']);
            foreach (['created', 'adopted', 'complete', 'refused-add-hook', 'throwing-add-hook', 'profile-update-hook', 'owned-failure', 'writer-swap'] as $seam) {
                $trial = OwnedMutationFrame::begin($connection);
                $replacement = null;
                $primary = new RuntimeException('Import callback primary failure');
                $observed = null;
                $calls = 0;
                $context = $importClass . ' / ' . $seam;
                $PLUGIN_HOOKS['item_add']['importobserver'][$modelClass] = static function ($model) use (&$observed): void {
                    $observed = $model;
                };
                $callback = function () use ($database, $connection, $seam, $primary, &$replacement, &$calls): void {
                    ++$calls;
                    $_SESSION['plugin_import_frame_marker'] = $seam;
                    if ($seam === 'owned-failure') {
                        throw $primary;
                    }
                    if ($seam === 'writer-swap') {
                        $GLOBALS['DB'] = clone $database; // Same connection and nesting depth, different current adapter.
                        return;
                    }
                    $connection->rollBack();
                    $replacement = OwnedMutationFrame::begin($connection);
                    $connection->update('glpi_entities', ['comment' => 'Replacement import frame witness'], ['id' => 0]);
                };
                if (str_ends_with($seam, 'add-hook')) {
                    $PLUGIN_HOOKS['pre_item_add']['importframe'][$modelClass] = function ($model) use ($callback, $seam, $primary): void {
                        $callback();
                        if ($seam === 'throwing-add-hook') {
                            throw $primary;
                        }
                        $model->input = false;
                    };
                }
                if ($seam === 'profile-update-hook') {
                    $PLUGIN_HOOKS['pre_item_update']['importprofile'][Profile::class] = static function ($model) use ($callback, $profileId): void {
                        if ((int)$model->getID() === $profileId) {
                            $callback();
                            $model->input = false;
                        }
                    };
                }
                $failure = null;
                try {
                    // Plugin::doHook skips fixture handlers unless their plugin keys are active.
                    $plugins->setValue(null, [...$savedPlugins, 'importframe', 'importobserver', 'importprofile']);
                    try {
                        (new $importClass($DB))->import(static function (string $phase) use ($seam, $callback): void {
                            if ($phase === $seam || (in_array($seam, ['owned-failure', 'writer-swap'], true) && $phase === 'created')) {
                                $callback();
                            }
                        });
                    } catch (Throwable $error) {
                        $failure = $error;
                    }
                    $DB = $database;
                    $this->integer($calls)->isIdenticalTo(1, $context . ': the selected callback must execute exactly once');
                    if (in_array($seam, ['owned-failure', 'writer-swap'], true)) {
                        if ($seam === 'owned-failure') {
                            $this->object($failure)->isIdenticalTo($primary);
                        } else {
                            $this->object($failure)->isInstanceOf(TransactionOwnershipMismatch::class);
                        }
                        $this->object($observed)->isInstanceOf($modelClass, $context . ': item_add must expose the participating model');
                        $this->array($observed->fields)->isEmpty($context . ': an owned rollback restores the participating model');
                        $this->array($_SESSION)->isIdenticalTo($session);
                    } else {
                        $this->object($failure)->isInstanceOf(MutationRollbackFailure::class, $context . ': replacing the callback frame must refuse owned rollback');
                        if ($seam === 'throwing-add-hook') {
                            $this->object($failure->primary)->isIdenticalTo($primary);
                        } else {
                            $this->object($failure->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
                        }
                        $this->boolean($failure->rollbackUnproven)->isTrue();
                        $replacement->assertActive();
                        $this->string($_SESSION['plugin_import_frame_marker'])->isIdenticalTo($seam);
                        $this->string($connection->fetchOne('SELECT comment FROM glpi_entities WHERE id = 0'))->isIdenticalTo('Replacement import frame witness');
                    }
                    $this->string($connection->fetchOne('SELECT helpdesk_item_type FROM glpi_profiles WHERE id = ?', [$profileId]))->isIdenticalTo($profileTypes, 'Profile adoption must not escape into the replacement transaction');
                    $this->variable(Ledger::state($connection, $importClass::RECEIPT))->isNull();
                    $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($modelClass::getTable()) . ' WHERE id = ?', [$id]))->isIdenticalTo(0);
                    $replacement?->rollBack();
                    $replacement = null;
                    $trial->assertActive();
                    $PLUGIN_HOOKS = $hooks;
                    $_SESSION = $session;
                    $this->array($CFG_GLPI)->isIdenticalTo($config);
                } finally {
                    $DB = $database;
                    $PLUGIN_HOOKS = $hooks;
                    $plugins->setValue(null, $savedPlugins);
                    $_SESSION = $session;
                    $replacement?->rollBack();
                    $trial->rollBack();
                    $caller->assertActive();
                }
            }
            // The failed attempt must remain resumable through the actual public importer.
            $plan = (new $importClass($DB))->import();
            $this->boolean($plan->alreadyImported)->isFalse();
            $this->boolean(Ledger::state($connection, $importClass::RECEIPT)['complete'])->isTrue();
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($modelClass::getTable()) . ' WHERE id = ?', [$id]))->isIdenticalTo(1);
            $this->boolean((new $importClass($DB))->import()->alreadyImported)->isTrue();
            $this->array($CFG_GLPI)->isIdenticalTo($config);
            $caller->assertActive();
        }
    }

    public function testTypeName()
    {
        $this->string(\Domain::getTypeName(1))->isIdenticalTo('Domain');
        $this->string(\Domain::getTypeName(0))->isIdenticalTo('Domains');
        $this->string(\Domain::getTypeName(10))->isIdenticalTo('Domains');
    }

    public function testPrepareInput()
    {
        $domain = new \Domain();
        $expected = ['date_creation' => 'NULL', 'date_expiration' => null];
        $result   = $domain->prepareInputForUpdate(['date_creation' => '', 'date_expiration' => null]);
        $this->array($result)->isIdenticalTo($expected);
        $result   = $domain->prepareInputForAdd(['date_creation' => '', 'date_expiration' => null]);
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testCleanDBonPurge()
    {
        $this->login();

        $domain = new \Domain();
        $domains_id = (int)$domain->add([
           'name'   => 'glpi-project.org'
        ]);
        $this->integer($domains_id)->isGreaterThan(0);

        $domain_item = new \Domain_Item();
        $this->integer(
            $domain_item->add([
              'domains_id'   => $domains_id,
              'itemtype'     => 'Computer',
              'items_id'     => getItemByTypeName('Computer', '_test_pc01', true)
         ])
        )->isGreaterThan(0);

        $record = new \DomainRecord();
        foreach (['www', 'ftp', 'mail'] as $sub) {
            $this->integer(
                (int)$record->add([
                  'name'         => $sub,
                  'data'         => 'glpi-project.org.',
                  'domains_id'   => $domains_id
            ])
            )->isGreaterThan(0);
        }

        $this->integer((int)countElementsInTable($domain_item->getTable(), ['domains_id' => $domains_id]))->isIdenticalTo(1);
        $this->integer((int)countElementsInTable($record->getTable(), ['domains_id' => $domains_id]))->isIdenticalTo(3);
        $this->boolean($domain->delete(['id' => $domains_id], true))->isTrue();
        $this->integer((int)countElementsInTable($domain_item->getTable(), ['domains_id' => $domains_id]))->isIdenticalTo(0);
        $this->integer((int)countElementsInTable($record->getTable(), ['domains_id' => $domains_id]))->isIdenticalTo(0);
    }

    public function testGetEntitiesToNotify()
    {
        global $DB;
        $this->login();

        $this->array(\Entity::getEntitiesToNotify('use_domains_alert'))->isEmpty();

        $connection = $DB->getDoctrineConnection();
        $this->variable($connection->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = 0'))->isNull();
        $rootValue = $connection->fetchOne('SELECT use_domains_alert FROM glpi_entities WHERE id = 0');
        $callerManager = Orm::create($DB);
        try {
            $staleRoot = $callerManager->find(EntityRecord::class, 0);
            $this->object($staleRoot)->isInstanceOf(EntityRecord::class);
            $originalSetting = $staleRoot->use_domains_alert;
            // Root has no parent; an inherited setting cannot index a NULL owner.
            $connection->update('glpi_entities', ['use_domains_alert' => Entity::CONFIG_PARENT], ['id' => 0]);
            $this->array(Entity::getEntitiesToNotify('use_domains_alert'))->isEmpty();
            $connection->update('glpi_entities', ['use_domains_alert' => 1], ['id' => 0]);
            $inherited = Entity::getEntitiesToNotify('use_domains_alert');
            foreach ([0, getItemByTypeName('Entity', '_test_root_entity', true),
                getItemByTypeName('Entity', '_test_child_1', true), getItemByTypeName('Entity', '_test_child_2', true)] as $id) {
                $this->integer((int)$inherited[$id])->isIdenticalTo(1);
            }
            $this->boolean($callerManager->contains($staleRoot))->isTrue();
            $this->integer($staleRoot->use_domains_alert)->isIdenticalTo($originalSetting);
            $connection->update('glpi_entities', ['use_domains_alert' => 0], ['id' => 0]);
            $this->array(Entity::getEntitiesToNotify('use_domains_alert'))->isEmpty();
        } finally {
            $callerManager->clear();
            $connection->update('glpi_entities', ['use_domains_alert' => $rootValue], ['id' => 0]);
        }

        $entity = getItemByTypeName('Entity', '_test_root_entity');
        $this->boolean(
            $entity->update([
              'id'                                      => $entity->fields['id'],
              'use_domains_alert'                       => 1,
              'send_domains_alert_close_expiries_delay' => 7,
              'send_domains_alert_expired_delay'        => 1
         ])
        )->isTrue();
        $this->boolean($entity->getFromDB($entity->fields['id']))->isTrue();

        $this->array(\Entity::getEntitiesToNotify('use_domains_alert'))->isIdenticalTo([
           getItemByTypeName('Entity', '_test_root_entity', true)   => 1,
           getItemByTypeName('Entity', '_test_child_1', true)       => 1,
           getItemByTypeName('Entity', '_test_child_2', true)       => 1,
        ]);

        $today = new DateTimeImmutable('2026-09-28 16:30:00');
        $this->array(LegacyDomain::expiredDomainsCriteria($entity->fields['id'], $today)['WHERE'])->isEqualTo([
            'entities_id' => $entity->fields['id'], 'is_deleted' => false,
            'date_expiration' => ['<', '2026-09-27 00:00:00'],
        ]);
        $this->array(LegacyDomain::closeExpiriesDomainsCriteria($entity->fields['id'], $today)['WHERE'])->isEqualTo([
            'entities_id' => $entity->fields['id'], 'is_deleted' => false,
            'date_expiration' => ['>=', '2026-09-29 00:00:00'],
            ['date_expiration' => ['<', '2026-10-05 00:00:00']],
        ]);

        $expected = Entity::getEntitiesToNotify('use_domains_alert');
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeFactories = $factories->getValue();
        for ($repeat = 0; $repeat < 16; ++$repeat) {
            $this->array(Entity::getEntitiesToNotify('use_domains_alert'))->isIdenticalTo($expected);
        }
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
    }

    public function testTransfer()
    {
        $this->login();
        $domain = new \Domain();
        $domains_id = (int)$domain->add([
           'name'   => 'glpi-project.org'
        ]);
        $this->integer($domains_id)->isGreaterThan(0);

        $record = new \DomainRecord();
        foreach (['www', 'ftp', 'mail'] as $sub) {
            $this->integer(
                (int)$record->add([
                  'name'         => $sub,
                  'data'         => 'glpi-project.org.',
                  'domains_id'   => $domains_id
            ])
            )->isGreaterThan(0);
        }

        $entities_id = getItemByTypeName('Entity', '_test_child_2', true);

        //transer to another entity
        $transfer = new \Transfer();

        $controller = new MockController();
        $controller->__construct = function () {
            // void
        };

        $ma = new \mock\MassiveAction([], [], 'process', $controller);

        \MassiveAction::processMassiveActionsForOneItemtype(
            $ma,
            $domain,
            [$domains_id]
        );
        $transfer->moveItems(['Domain' => [$domains_id]], $entities_id, [$domains_id]);
        unset($_SESSION['glpitransfer_list']);

        $this->boolean($domain->getFromDB($domains_id))->isTrue();
        $this->integer((int)$domain->fields['entities_id'])->isidenticalTo($entities_id);

        global $DB;
        $records = $DB->request([
           'FROM'   => \DomainRecord::getTable(),
           'WHERE'  => ['domains_id' => $domains_id]
        ]);
        while ($row = $records->next()) {
            $this->integer((int)$row['entities_id'])->isidenticalTo($entities_id);
        }
    }
}
