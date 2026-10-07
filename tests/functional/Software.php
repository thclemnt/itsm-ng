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

/* Test for inc/software.class.php */

class Software extends DbTestCase
{
    public function testSoftwareDeletePreloadsKeepTheirOriginalWriter(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $caller = $connection->captureManagedTransactionScope();
        $depth = $connection->getTransactionNestingLevel();
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $software = $this->createItem(\Software::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
        $version = $this->createItem(\SoftwareVersion::class, ['name' => $this->getUniqueString(),
            'softwares_id' => $software->getID(), 'entities_id' => $entity]);
        $license = $this->createItem(\SoftwareLicense::class, ['name' => $this->getUniqueString(),
            'softwares_id' => $software->getID(), 'entities_id' => $entity, 'number' => -1]);
        $computer = $this->createItem(\Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
        $allocation = $this->createItem(\Item_SoftwareLicense::class, ['itemtype' => 'Computer',
            'items_id' => $computer->getID(), 'softwarelicenses_id' => $license->getID()]);
        $installation = $this->createItem(\Item_SoftwareVersion::class, ['itemtype' => 'Computer',
            'items_id' => $computer->getID(), 'softwareversions_id' => $version->getID()]);
        $witness = $this->createItem(\DomainType::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'comment' => 'before']);
        $fixtures = [$computer, $software, $license, $allocation, $installation, $version];
        $rows = static function () use ($connection, $fixtures): array {
            $result = [];
            foreach ($fixtures as $fixture) {
                $result[$fixture->getTable()] = $connection->fetchAssociative('SELECT * FROM '
                    . $connection->quoteIdentifier($fixture->getTable()) . ' WHERE id = ?', [(int)$fixture->fields['id']]);
            }
            return $result;
        };
        $before = $rows();
        $models = [
            new class extends \Computer { use SoftwarePreloadObserver; },
            new class extends \Software { use SoftwarePreloadObserver; },
            new class extends \SoftwareLicense { use SoftwarePreloadObserver; },
            new class extends \Item_SoftwareLicense { use SoftwarePreloadObserver; },
            new class extends \Item_SoftwareVersion { use SoftwarePreloadObserver; },
        ];
        foreach ($models as $index => $model) {
            foreach (['replace', 'replace-false', 'replace-throw', 'writer', 'false', 'throw', 'valid'] as $mode) {
                $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
                $replacement = null;
                $session = $_SESSION;
                $calls = 0;
                $marker = new \RuntimeException('Owned preload callback marker');
                $primary = null;
                $model->preloadCallback = function ($loadedModel, bool $loaded) use (
                    &$calls, &$replacement, $connection, $database, $witness, $mode, $marker
                ): bool {
                    global $DB;
                    if (++$calls !== 1) {
                        return $loaded;
                    }
                    if (str_starts_with($mode, 'replace')) {
                        $connection->rollBack();
                        $replacement = \itsmng\Database\OwnedMutationFrame::begin($connection);
                        $connection->update($witness->getTable(), ['comment' => 'replacement witness'], ['id' => $witness->getID()]);
                    } elseif ($mode === 'writer') {
                        $DB = clone $database;
                    }
                    if (str_ends_with($mode, 'throw')) {
                        throw $marker;
                    }
                    return str_ends_with($mode, 'false') ? false : $loaded;
                };
                try {
                    $error = null;
                    $result = null;
                    try {
                        $result = $model->delete(['id' => $fixtures[$index]->getID(), '_no_message' => 1, '_no_history' => 1], false, false);
                    } catch (\Throwable $failure) {
                        $error = $failure;
                    }
                    if ($mode === 'replace-throw') {
                        $this->object($error)->isInstanceOf(\itsmng\Database\MutationCleanupFailure::class);
                        $this->object($error->primary)->isIdenticalTo($marker);
                        $this->object($error->cleanup)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
                        $this->boolean($error->rollbackUnproven)->isTrue();
                    } elseif ($mode === 'throw') {
                        $this->object($error)->isIdenticalTo($marker);
                    } elseif ($mode === 'false' || $mode === 'valid') {
                        $this->variable($error)->isNull();
                        $this->boolean($result)->isIdenticalTo($mode === 'valid');
                    } else {
                        $this->boolean($error instanceof \itsmng\Database\TransactionOwnershipMismatch)
                            ->isTrue($model->getType() . ': preload cannot replace its original mutation writer');
                    }
                    if ($mode !== 'valid') {
                        $this->integer($calls)->isIdenticalTo(1, 'Refusal must precede the next selected model load');
                        $this->array($rows())->isIdenticalTo($before, 'No software graph writes after a refused preload');
                    }
                    if ($mode === 'writer') {
                        $this->object($DB)->isNotIdenticalTo($database);
                        $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
                    }
                    if ($replacement !== null) {
                        $replacement->assertActive();
                        $this->string($connection->fetchOne('SELECT comment FROM glpi_domaintypes WHERE id = ?', [$witness->getID()]))
                            ->isIdenticalTo('replacement witness');
                    } else {
                        $frame->assertActive();
                    }
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                } catch (\Throwable $failure) {
                    $primary = $failure;
                    throw $failure;
                } finally {
                    $DB = $database;
                    $model->preloadCallback = null;
                    $_SESSION = $session;
                    try {
                        ($replacement ?? $frame)->rollBack();
                    } catch (\Throwable $cleanup) {
                        throw $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
                    }
                }
                $caller->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                $this->array($rows())->isIdenticalTo($before);
                $this->string($connection->fetchOne('SELECT comment FROM glpi_domaintypes WHERE id = ?', [$witness->getID()]))->isIdenticalTo('before');
            }
        }
        // A fresh independent probe also exercises ordinary missing-row deletes
        // at depth zero, without ending or borrowing the DbTestCase caller.
        $parameters = $connection->getParams();
        $probeConnection = $database->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($parameters)
            : \itsmng\Database\MySQLConnection::create($parameters);
        $probe = clone $database;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $probeConnection);
        $primary = null;
        try {
            $DB = $probe;
            foreach ($models as $model) {
                $this->integer($probeConnection->getTransactionNestingLevel())->isIdenticalTo(0);
                $this->integer((int)$probeConnection->fetchOne('SELECT COUNT(*) FROM '
                    . $probeConnection->quoteIdentifier($model->getTable()) . ' WHERE id = ?', [PHP_INT_MAX]))->isIdenticalTo(0);
                $this->boolean($model->delete(['id' => PHP_INT_MAX, '_no_message' => 1], false, false))->isFalse();
                $this->integer($probeConnection->getTransactionNestingLevel())->isIdenticalTo(0);
            }
        } catch (\Throwable $failure) {
            $primary = $failure;
            throw $failure;
        } finally {
            $DB = $database;
            try {
                $probe->close();
            } catch (\Throwable $cleanup) {
                throw $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
            }
        }
        $caller->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
    }

    public function testRemoveMergedSourceKeepsItsPreloadWriterAndRemovalBehavior(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $caller = $connection->captureManagedTransactionScope();
        $depth = $connection->getTransactionNestingLevel();
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $source = $this->createItem(\Software::class, ['name' => $this->getUniqueString(),
            'entities_id' => $entity, 'comment' => 'Existing source comment']);
        $category = $this->createItem(\SoftwareCategory::class, ['name' => $this->getUniqueString()]);
        $witness = $this->createItem(\DomainType::class, ['name' => $this->getUniqueString(),
            'entities_id' => $entity, 'comment' => 'before']);
        $readSource = static fn () => $connection->fetchAssociative('SELECT * FROM glpi_softwares WHERE id = ?', [$source->getID()]);
        $before = $readSource();
        $configuration = $CFG_GLPI;
        $model = new class extends \Software { use SoftwarePreloadObserver; };
        try {
            $CFG_GLPI['softwarecategories_id_ondelete'] = $category->getID();
            foreach (['replace', 'replace-false', 'replace-throw', 'writer', 'false', 'throw', 'valid'] as $mode) {
                $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
                $replacement = null;
                $session = $_SESSION;
                $calls = 0;
                $marker = new \RuntimeException('Merged source preload marker');
                $primary = null;
                $model->preloadCallback = function ($loadedModel, bool $loaded) use (
                    &$calls, &$replacement, $connection, $database, $witness, $mode, $marker
                ): bool {
                    global $DB;
                    if (++$calls !== 1) {
                        return $loaded;
                    }
                    if (str_starts_with($mode, 'replace')) {
                        $connection->rollBack();
                        $replacement = \itsmng\Database\OwnedMutationFrame::begin($connection);
                        $connection->update($witness->getTable(), ['comment' => 'replacement witness'], ['id' => $witness->getID()]);
                    } elseif ($mode === 'writer') {
                        $DB = clone $database;
                    }
                    if (str_ends_with($mode, 'throw')) {
                        throw $marker;
                    }
                    return str_ends_with($mode, 'false') ? false : $loaded;
                };
                try {
                    $error = null;
                    $result = null;
                    try {
                        $result = $model->removeMergedSource((int)$source->getID(), 'Merged by regression');
                    } catch (\Throwable $failure) {
                        $error = $failure;
                    }
                    if ($mode === 'replace-throw') {
                        $this->object($error)->isInstanceOf(\itsmng\Database\MutationCleanupFailure::class);
                        $this->object($error->primary)->isIdenticalTo($marker);
                        $this->object($error->cleanup)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
                        $this->boolean($error->rollbackUnproven)->isTrue();
                    } elseif ($mode === 'throw') {
                        $this->object($error)->isIdenticalTo($marker);
                    } elseif ($mode === 'false' || $mode === 'valid') {
                        $this->variable($error)->isNull();
                        $this->boolean($result)->isIdenticalTo($mode === 'valid');
                    } else {
                        $this->object($error)->isInstanceOf(\itsmng\Database\TransactionOwnershipMismatch::class);
                    }
                    if ($mode === 'valid') {
                        $stored = $readSource();
                        $this->array($stored)->isNotEmpty();
                        $this->integer((int)$stored['is_deleted'])->isIdenticalTo(1);
                        $this->integer((int)$stored['is_template'])->isIdenticalTo(0);
                        $this->integer((int)$stored['softwarecategories_id'])->isIdenticalTo((int)$category->getID());
                        $this->string($stored['comment'])->isIdenticalTo("\nMerged by regression");
                    } else {
                        $this->integer($calls)->isIdenticalTo(1, 'Refuse before the merged source delete/update callbacks');
                        $this->array($readSource())->isIdenticalTo($before);
                    }
                    if ($mode === 'writer') {
                        $this->object($DB)->isNotIdenticalTo($database);
                        $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
                    }
                    if ($replacement !== null) {
                        $replacement->assertActive();
                        $this->string($connection->fetchOne('SELECT comment FROM glpi_domaintypes WHERE id = ?', [$witness->getID()]))
                            ->isIdenticalTo('replacement witness');
                    } else {
                        $frame->assertActive();
                    }
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                } catch (\Throwable $failure) {
                    $primary = $failure;
                    throw $failure;
                } finally {
                    $DB = $database;
                    $model->preloadCallback = null;
                    $_SESSION = $session;
                    try {
                        ($replacement ?? $frame)->rollBack();
                    } catch (\Throwable $cleanup) {
                        throw $primary === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup);
                    }
                }
                $caller->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                $this->array($readSource())->isIdenticalTo($before);
                $this->string($connection->fetchOne('SELECT comment FROM glpi_domaintypes WHERE id = ?', [$witness->getID()]))->isIdenticalTo('before');
            }
        } finally {
            $CFG_GLPI = $configuration;
        }
    }

    public function testTypeName()
    {
        $this->string(\Software::getTypeName(1))->isIdenticalTo('Software');
        $this->string(\Software::getTypeName(0))->isIdenticalTo('Software');
        $this->string(\Software::getTypeName(10))->isIdenticalTo('Software');
    }

    public function testGetMenuShorcut()
    {
        $this->string(\Software::getMenuShorcut())->isIdenticalTo('s');
    }

    public function testGetTabNameForItem()
    {
        $this->login();

        $software = new \Software();
        $input    = ['name'         => 'soft1',
                     'entities_id'  => 0,
                     'is_recursive' => 1
                    ];
        $softwares_id = $software->add($input);
        $this->integer((int)$softwares_id)->isGreaterThan(0);
        $software->getFromDB($softwares_id);
        $this->string($software->getTabNameForItem($software, 1))->isEmpty();
    }

    public function defineTabs()
    {
        $this->login();

        $software = new \Software();
        $tabs     = $software->defineTabs();
        $this->array($tabs)->hasSize(16);

        $_SESSION['glpiactiveprofile']['license'] = 0;
        $tabs = $software->defineTabs();
        $this->array($tabs)->hasSize(15);

        $_SESSION['glpiactiveprofile']['link'] = 0;
        $tabs = $software->defineTabs();
        $this->array($tabs)->hasSize(14);

        $_SESSION['glpiactiveprofile']['infocom'] = 0;
        $tabs = $software->defineTabs();
        $this->array($tabs)->hasSize(13);

        $_SESSION['glpiactiveprofile']['document'] = 0;
        $tabs = $software->defineTabs();
        $this->array($tabs)->hasSize(12);

    }

    public function testPrepareInputForUpdate()
    {
        $software = new \Software();
        $result   = $software->prepareInputForUpdate(['is_update' => 0]);
        $this->array($result)->isIdenticalTo(['is_update' => 0, 'softwares_id' => 0]);
    }

    public function testPrepareInputForAdd()
    {
        $software = new \Software();

        $input    = ['name' => 'A name', 'is_update' => 0, 'id' => 3, 'withtemplate' => 0];
        $result   = $software->prepareInputForAdd($input);
        $expected = ['name' => 'A name', 'is_update' => 0, 'softwares_id' => 0, '_oldID' => 3];

        $this->array($result)->isIdenticalTo($expected);

        $input    = ['name' => 'A name', 'is_update' => 0, 'withtemplate' => 0];
        $result   = $software->prepareInputForAdd($input);
        $expected = ['name' => 'A name', 'is_update' => 0, 'softwares_id' => 0];

        $this->array($result)->isIdenticalTo($expected);

        $input    = ['is_update'             => 0,
                     'withtemplate'          => 0,
                     'softwarecategories_id' => 3
                    ];
        $result   = $software->prepareInputForAdd($input);
        $expected = ['is_update'             => 0,
                     'softwarecategories_id' => 3,
                     'softwares_id'          => 0];

        $this->array($result)->isIdenticalTo($expected);

    }

    public function testPrepareInputForAddWithCategory()
    {
        $rule     = new \Rule();
        $criteria = new \RuleCriteria();
        $action   = new \RuleAction();

        //Create a software category
        $category      = new \SoftwareCategory();
        $categories_id = $category->importExternal('Application');

        $rules_id = $rule->add(['name'        => 'Add application category',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleSoftwareCategory',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                             ]);
        $this->integer((int)$rules_id)->isGreaterThan(0);

        $this->integer(
            (int)$criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::PATTERN_IS,
              'pattern'   => 'MySoft'
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$action->add(['rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => 'softwarecategories_id',
              'value'       => $categories_id
         ])
        )->isGreaterThan(0);

        $input    = ['name'             => 'MySoft',
                     'is_update'        => 0,
                     'entities_id'      => 0,
                     'comment'          => 'Comment'
                    ];

        $software = new \Software();
        $result   = $software->prepareInputForAdd($input);
        $expected = [
           'name'                  => 'MySoft',
           'is_update'             => 0,
           'entities_id'           => 0,
           'comment'               => 'Comment',
           'softwares_id'          => 0,
           'softwarecategories_id' => "$categories_id"
        ];

        $this->array($result)->isIdenticalTo($expected);
    }

    public function testPost_addItem()
    {
        global $CFG_GLPI;

        $this->login();

        $software     = new \Software();
        $softwares_id = $software->add([
           'name'         => 'MySoft',
           'is_template'  => 0,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);

        $this->variable($software->fields['is_template'])->isEqualTo(0);
        $this->string($software->fields['name'])->isIdenticalTo('MySoft');

        $query = ['itemtype' => 'Software', 'items_id' => $softwares_id];
        $this->integer((int)countElementsInTable('glpi_infocoms', $query))->isIdenticalTo(0);
        $this->integer((int)countElementsInTable('glpi_contracts_items', $query))->isIdenticalTo(0);

        //Force creation of infocom when an asset is added
        $CFG_GLPI['auto_create_infocoms'] = 1;

        $softwares_id = $software->add([
           'name'         => 'MySoft2',
           'is_template'  => 0,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);

        $query = ['itemtype' => 'Software', 'items_id' => $softwares_id];
        $this->integer((int)countElementsInTable('glpi_infocoms', $query))->isIdenticalTo(1);
    }

    public function testPost_addItemWithTemplate()
    {
        $this->login();

        $software     = new \Software();
        $softwares_id = $software->add([
           'name'          => 'MyTemplate',
           'is_template'   => 1,
           'template_name' => 'template'
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);

        $infocom = new \Infocom();
        $this->integer(
            (int)$infocom->add([
              'itemtype' => 'Software',
              'items_id' => $softwares_id,
              'value'    => '500'
         ])
        )->isGreaterThan(0);

        $contract     = new \Contract();
        $contracts_id = $contract->add([
           'name'         => 'contract01',
           'entities_id'  => 0
        ]);
        $this->integer((int)$contracts_id)->isGreaterThan(0);

        $contract_item = new \Contract_Item();
        $this->integer(
            (int)$contract_item->add([
              'itemtype'     => 'Software',
              'items_id'     => $softwares_id,
              'contracts_id' => $contracts_id
         ])
        )->isGreaterThan(0);

        $softwares_id_2 = $software->add([
           'name'         => 'MySoft',
           'id'           => $softwares_id,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id_2)->isGreaterThan(0);

        $this->boolean($software->getFromDB($softwares_id_2))->isTrue();
        $this->variable($software->fields['is_template'])->isEqualTo(0);
        $this->string($software->fields['name'])->isIdenticalTo('MySoft (copy)');

        $query = ['itemtype' => 'Software', 'items_id' => $softwares_id_2];
        $this->integer((int)countElementsInTable('glpi_infocoms', $query))->isIdenticalTo(1);
        $this->integer((int)countElementsInTable('glpi_contracts_items', $query))->isIdenticalTo(1);
    }

    public function testCleanDBonPurge()
    {
        global $CFG_GLPI;
        $this->login();

        //Force creation of infocom when an asset is added
        $CFG_GLPI['auto_create_infocoms'] = 1;

        $software     = new \Software();
        $softwares_id = $software->add([
           'name'         => 'MySoft',
           'is_template'  => 0,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);

        $contract     = new \Contract();
        $contracts_id = $contract->add([
           'name'         => 'contract02',
           'entities_id'  => 0
        ]);
        $this->integer((int)$contracts_id)->isGreaterThan(0);

        $contract_item = new \Contract_Item();
        $this->integer(
            (int)$contract_item->add([
              'itemtype'     => 'Software',
              'items_id'     => $softwares_id,
              'contracts_id' => $contracts_id
         ])
        )->isGreaterThan(0);

        $this->boolean($software->delete(['id' => $softwares_id], true))->isTrue();
        $query = ['itemtype' => 'Software', 'items_id' => $softwares_id];
        $this->integer((int)countElementsInTable('glpi_infocoms', $query))->isIdenticalTo(0);
        $this->integer((int)countElementsInTable('glpi_contracts_items', $query))->isIdenticalTo(0);

        //TODO : test Change_Item, Item_Problem, Item_Project
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

    public function testUpdateValidityIndicatorIncreaseDecrease()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $software = $this->createSoft((int)$_SESSION['glpiactive_entity'], true);

        //create a license with 3 installations
        $license = new \SoftwareLicense();
        $license_id = $license->add([
           'name'         => 'a_software_license',
           'softwares_id' => $software->getID(),
           'entities_id'  => $software->getEntityID(),
           'is_recursive' => 1,
           'number'       => 3
        ]);
        $this->integer((int)$license_id)->isGreaterThan(0);
        $this->boolean($license->can($license->getID(), UPDATE))->isTrue();

        //attach 2 licenses
        $license_computer = new \Item_SoftwareLicense();
        foreach (['_test_pc01', '_test_pc02'] as $pcid) {
            $computer = getItemByTypeName('Computer', $pcid);
            $input_comp = [
               'softwarelicenses_id'   => $license_id,
               'items_id'              => $computer->getID(),
               'itemtype'              => 'Computer',
               'is_deleted'            => 0,
               'is_dynamic'            => 0
            ];
            $this->integer((int)$license_computer->add($input_comp))->isGreaterThan(0);
        }

        $this->boolean($software->getFromDB($software->getID()))->isTrue();
        $this->variable($software->fields['is_valid'])->isEqualTo(1);

        //Descrease number to one
        $this->boolean(
            $license->update(['id' => $license->getID(), 'number' => 1])
        )->isTrue();
        \Software::updateValidityIndicator($software->getID());

        $software->getFromDB($software->getID());
        $this->variable($software->fields['is_valid'])->isEqualTo(0);

        //Increase number to ten
        $this->boolean(
            $license->update(['id' => $license->getID(), 'number' => 10])
        )->isTrue();
        \Software::updateValidityIndicator($software->getID());

        $software->getFromDB($software->getID());
        $this->variable($software->fields['is_valid'])->isEqualTo(1);
    }

    public function testGetEmpty()
    {
        global $CFG_GLPI;

        $software = new \Software();
        $CFG_GLPI['default_software_helpdesk_visible'] = 0;
        $software->getEmpty();
        $this->variable($software->fields['is_helpdesk_visible'])->isEqualTo(0);

        $CFG_GLPI['default_software_helpdesk_visible'] = 1;

        $software->getEmpty();
        $this->variable($software->fields['is_helpdesk_visible'])->isEqualTo(1);

    }

    public function testGetSpecificMassiveActions()
    {
        $this->login();

        $software = new \Software();
        $result = $software->getSpecificMassiveActions();
        $this->array($result)->hasSize(5);

        $all_rights = $_SESSION['glpiactiveprofile']['software'];

        $_SESSION['glpiactiveprofile']['software'] = 0;
        $result = $software->getSpecificMassiveActions();
        $this->array($result)->isEmpty();

        $_SESSION['glpiactiveprofile']['software'] = READ;
        $result = $software->getSpecificMassiveActions();
        $this->array($result)->isEmpty();

        $_SESSION['glpiactiveprofile']['software'] = $all_rights;
        $_SESSION['glpiactiveprofile']['knowbase'] = 0;
        $result = $software->getSpecificMassiveActions();
        $this->array($result)->hasSize(4);

        $_SESSION['glpiactiveprofile']['rule_dictionnary_software'] = 0;
        $result = $software->getSpecificMassiveActions();
        $this->array($result)->hasSize(4);
    }

    public function testGetSearchOptionsNew()
    {
        $software = new \Software();
        $result   = $software->rawSearchOptions();
        $this->array($result)->hasSize(41);

        $this->login();
        $result   = $software->rawSearchOptions();
        $this->array($result)->hasSize(57);
    }
}

/** Actual legacy model identity with a public load seam for the owned fixture. */
trait SoftwarePreloadObserver
{
    public $preloadCallback = null;

    public static function getType()
    {
        return get_parent_class(static::class);
    }

    public static function getTable($classname = null)
    {
        return parent::getTable($classname ?? get_parent_class(static::class));
    }

    public function getFromDB($id)
    {
        $loaded = parent::getFromDB($id);
        return $this->preloadCallback === null ? $loaded : ($this->preloadCallback)($this, $loaded);
    }
}
