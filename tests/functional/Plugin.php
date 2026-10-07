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

/* Test for inc/plugin.class.php */

class Plugin extends DbTestCase
{
    private $test_plugin_directory = 'test';
    private $anothertest_plugin_directory = 'anothertest';

    public function testPluginDropdownImportAndUsageKeepTheirModelScope(): void
    {
        $this->withPluginLifecycleFixture(function ($connection, array $tables, int $entity, int $child): void {
            [$dropdownTable, $treeTable, $linkTable] = $tables;
            $name = "Plugin's \\ label";
            $connection->insert($dropdownTable, ['id' => 100, 'name' => $name, 'entities_id' => $entity]);
            $connection->insert($dropdownTable, ['id' => 101, 'name' => $name, 'entities_id' => $child]);
            $dropdown = new \PluginRecursionDropdown();
            $input = ['name' => addslashes($name), 'entities_id' => $entity];
            // Before the fix this actual public import lookup rejects the plugin table.
            $this->integer($dropdown->findID($input))->isIdenticalTo(100);
            $this->integer($dropdown->import($input))->isIdenticalTo(100);
            $this->integer(\PluginRecursionDropdown::$additions)->isIdenticalTo(0);
            $input['entities_id'] = $child;
            $this->integer($dropdown->findID($input))->isIdenticalTo(101);
            $connection->delete($dropdownTable, ['id' => 101]);
            $this->integer($dropdown->findID($input))->isIdenticalTo(-1);
            $connection->update($dropdownTable, ['is_recursive' => true], ['id' => 100], ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
            $this->integer($dropdown->findID($input))->isIdenticalTo(100, 'Recursive ancestor scope is retained');
            $input['entities_id'] = [];
            $this->integer($dropdown->findID($input))->isIdenticalTo(-1, 'Empty scope cannot import another entity row');
            $new = ['name' => 'new ' . $this->getUniqueString(), 'entities_id' => $entity, '_no_history' => 1];
            $id = $dropdown->import($new);
            $this->integer((int)$id)->isGreaterThan(0);
            $this->integer(\PluginRecursionDropdown::$additions)->isIdenticalTo(1, 'Missing rows use the public add lifecycle');
            $this->integer((int)$dropdown->import($new))->isIdenticalTo((int)$id);
            $this->integer(\PluginRecursionDropdown::$additions)->isIdenticalTo(1);
            $this->boolean($dropdown->getFromDB(100))->isTrue();
            $this->boolean($dropdown->isUsed())->isFalse();
            $connection->insert($linkTable, ['id' => 100, 'targets_id' => 100]);
            \PluginRecursionLink::$relations = [$dropdownTable => [$linkTable => 'targets_id']];
            $this->boolean($dropdown->isUsed())->isTrue();
            \PluginRecursionLink::$relations = [$dropdownTable => ['_' . $linkTable => 'targets_id']];
            $this->boolean($dropdown->isUsed())->isFalse('Managed relations are excluded');
            \PluginRecursionLink::$relations = [$dropdownTable => [$linkTable => ['items_id', 'itemtype']]];
            $connection->update($linkTable, ['items_id' => 100, 'itemtype' => \Manufacturer::class], ['id' => 100]);
            $this->boolean($dropdown->isUsed())->isFalse('A discriminator mismatch is not a use');
            $connection->update($linkTable, ['itemtype' => \PluginRecursionDropdown::class], ['id' => 100]);
            $this->boolean($dropdown->isUsed())->isTrue();
            $manufacturer = $this->createItem(\Manufacturer::class, ['name' => $this->getUniqueString()]);
            \PluginRecursionLink::$relations = [\Manufacturer::getTable() => [$linkTable => 'targets_id']];
            $connection->update($linkTable, ['targets_id' => $manufacturer->getID()], ['id' => 100]);
            $this->boolean($manufacturer->isUsed())->isTrue('Core dropdowns include their declared plugin children');
            $connection->update($linkTable, ['targets_id' => 0], ['id' => 100]);
            $this->boolean($manufacturer->isUsed())->isFalse();
            // CommonTreeDropdown has its own import lookup. Exercise that existing path too.
            $tree = new \PluginRecursionOwner();
            $connection->insert($treeTable, ['id' => 100, 'name' => 'tree', 'completename' => 'tree', 'entities_id' => $entity]);
            $treeInput = ['name' => 'tree', 'entities_id' => $entity];
            $this->integer((int)$tree->findID($treeInput))->isIdenticalTo(100);
            $this->integer((int)$tree->import($treeInput))->isIdenticalTo(100);
            $this->boolean($tree->getFromDB(100))->isTrue();
            \PluginRecursionLink::$relations = [$treeTable => [$linkTable => 'targets_id']];
            $connection->update($linkTable, ['targets_id' => 100], ['id' => 100]);
            $this->boolean($tree->isUsed())->isTrue();
            \PluginRecursionLink::$relations = [$dropdownTable => [$linkTable => 'missing_column']];
            $this->exception(static fn () => $dropdown->isUsed())->isInstanceOf(\InvalidArgumentException::class);
            $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
            $plugins->setValue(null, []);
            $this->exception(static fn () => $dropdown->findID($input))->isInstanceOf(\InvalidArgumentException::class);
            $plugins->setValue(null, ['recursion']);
        });
    }

    public function testPluginPurgeKeepsDeclaredChildUpdateLifecycle(): void
    {
        $this->withPluginLifecycleFixture(function ($connection, array $tables, int $entity): void {
            [$dropdownTable, , $linkTable] = $tables;
            $connection->insert($linkTable, ['id' => 100]);
            $plain = new \PluginRecursionLink();
            // A plain plugin model with no children must also be purgeable.
            $this->boolean($plain->delete(['id' => 100], true, false))->isTrue();
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($linkTable) . ' WHERE id = 100'))->isIdenticalTo(0);
            $manufacturer = $this->createItem(\Manufacturer::class, ['name' => $this->getUniqueString()]);
            $replacement = $this->createItem(\Manufacturer::class, ['name' => $this->getUniqueString()]);
            $connection->insert($linkTable, ['id' => 101, 'targets_id' => $manufacturer->getID()]);
            $connection->insert($linkTable, ['id' => 102, 'targets_id' => $replacement->getID()]);
            \PluginRecursionLink::$relations = [\Manufacturer::getTable() => [$linkTable => 'targets_id']];
            \PluginRecursionLink::$refuseUpdate = true;
            $this->boolean($manufacturer->delete(['id' => $manufacturer->getID(), '_replace_by' => $replacement->getID()], true, false))->isFalse();
            $this->boolean($manufacturer->getFromDB($manufacturer->getID()))->isTrue('A child veto preserves the core parent');
            $this->integer((int)$connection->fetchOne('SELECT targets_id FROM ' . $connection->quoteIdentifier($linkTable) . ' WHERE id = 101'))->isIdenticalTo((int)$manufacturer->getID());
            \PluginRecursionLink::$refuseUpdate = false;
            \PluginRecursionLink::$updates = [];
            $this->boolean($manufacturer->delete(['id' => $manufacturer->getID(), '_replace_by' => $replacement->getID()], true, false))->isTrue();
            $this->array(\PluginRecursionLink::$updates)->hasSize(1);
            $this->integer((int)\PluginRecursionLink::$updates[0]['id'])->isIdenticalTo(101);
            $this->integer((int)$connection->fetchOne('SELECT targets_id FROM ' . $connection->quoteIdentifier($linkTable) . ' WHERE id = 101'))->isIdenticalTo((int)$replacement->getID());
            \PluginRecursionLink::$updates = [];
            $this->boolean($replacement->delete(['id' => $replacement->getID()], true, false))->isTrue();
            $this->array(\PluginRecursionLink::$updates)->hasSize(2);
            $this->array(array_map('intval', $connection->fetchFirstColumn('SELECT targets_id FROM ' . $connection->quoteIdentifier($linkTable) . ' ORDER BY id')))->isIdenticalTo([0, 0]);
            $connection->insert($dropdownTable, ['id' => 100, 'name' => 'plugin parent', 'entities_id' => $entity]);
            $connection->insert($dropdownTable, ['id' => 101, 'name' => 'replacement', 'entities_id' => $entity]);
            $connection->update($linkTable, ['items_id' => 100, 'itemtype' => \PluginRecursionDropdown::class], ['id' => 101]);
            $connection->update($linkTable, ['items_id' => 100, 'itemtype' => \Manufacturer::class], ['id' => 102]);
            \PluginRecursionLink::$relations = [$dropdownTable => [$linkTable => ['items_id', 'itemtype']]];
            \PluginRecursionLink::$updates = [];
            $parent = new \PluginRecursionDropdown();
            $this->boolean($parent->delete(['id' => 100, '_replace_by' => 101], true, false))->isTrue();
            $this->array(\PluginRecursionLink::$updates)->hasSize(1);
            $this->array(array_map('intval', $connection->fetchFirstColumn('SELECT items_id FROM ' . $connection->quoteIdentifier($linkTable) . ' ORDER BY id')))->isIdenticalTo([101, 100]);
            $this->boolean($parent->getFromDB(101))->isTrue('The replacement survives the public purge');
        });
    }

    public function testPluginPurgeKeepsDeclaredChildPublicIndex(): void
    {
        $this->withPluginLifecycleFixture(function ($connection, array $tables): void {
            $linkTable = $tables[3];
            $parent = $this->createItem(\Manufacturer::class, ['name' => $this->getUniqueString()]);
            $replacement = $this->createItem(\Manufacturer::class, ['name' => $this->getUniqueString()]);
            $connection->insert($linkTable, ['id' => 100, 'public_id' => 900, 'targets_id' => $parent->getID()]);
            $connection->insert($linkTable, ['id' => 900, 'public_id' => 100, 'targets_id' => $replacement->getID()]);
            \PluginRecursionLink::$relations = [\Manufacturer::getTable() => [$linkTable => 'targets_id']];
            $this->boolean($parent->isUsed())->isTrue();
            $this->boolean($parent->delete(['id' => $parent->getID(), '_replace_by' => $replacement->getID()], true, false))->isTrue();
            $this->array(\PluginRecursionLink::$updates)->hasSize(1);
            $this->integer((int)\PluginRecursionLink::$updates[0]['public_id'])->isIdenticalTo(900);
            $this->boolean(\PluginRecursionLink::$updates[0]['_disablenotif'])->isTrue();
            $this->integer((int)$connection->fetchOne('SELECT targets_id FROM ' . $connection->quoteIdentifier($linkTable) . ' WHERE id = 100'))
                ->isIdenticalTo((int)$replacement->getID());
            $this->integer((int)$connection->fetchOne('SELECT targets_id FROM ' . $connection->quoteIdentifier($linkTable) . ' WHERE id = 900'))
                ->isIdenticalTo((int)$replacement->getID());
        });
    }

    /** Plugin DDL and rows belong to one separate physical owner, never the caller frame. */
    private function withPluginLifecycleFixture(callable $operation): void
    {
        global $DB, $CFG_GLPI;
        require_once __DIR__ . '/../fixtures/pluginrecursion.php';
        $original = $DB;
        $config = $CFG_GLPI;
        $session = $_SESSION;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $declarations = \PluginRecursionLink::$relations;
        $updates = \PluginRecursionLink::$updates;
        $refuse = \PluginRecursionLink::$refuseUpdate;
        $additions = \PluginRecursionDropdown::$additions;
        $caller = $original->getDoctrineConnection();
        $scope = $caller->captureManagedTransactionScope();
        $level = $caller->getTransactionNestingLevel();
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($caller->getParams())
            : \itsmng\Database\MySQLConnection::create($caller->getParams());
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $schema = $connection->createSchemaManager();
        $tables = [\PluginRecursionDropdown::getTable(), \PluginRecursionOwner::getTable(), \PluginRecursionLink::getTable(), \PluginRecursionPublicLink::getTable()];
        $created = [];
        $frame = null;
        $failure = null;
        try {
            \itsmng\Database\TransactionOwnership::assertManaged($connection);
            foreach ($tables as $table) {
                $this->boolean($schema->tablesExist([$table]))->isFalse();
                $definition = new \Doctrine\DBAL\Schema\Table($table);
                $definition->addColumn('id', \Doctrine\DBAL\Types\Types::INTEGER, ['autoincrement' => true]);
                $definition->setPrimaryKey(['id']);
                if (in_array($table, [$tables[2], $tables[3]], true)) {
                    if ($table === $tables[3]) {
                        $definition->addColumn('public_id', \Doctrine\DBAL\Types\Types::INTEGER);
                        $definition->addUniqueIndex(['public_id']);
                    }
                    foreach (['targets_id', 'items_id'] as $column) {
                        $definition->addColumn($column, \Doctrine\DBAL\Types\Types::INTEGER, ['default' => 0]);
                    }
                    $definition->addColumn('itemtype', \Doctrine\DBAL\Types\Types::STRING, ['length' => 100, 'default' => '']);
                } else {
                    $definition->addColumn('name', \Doctrine\DBAL\Types\Types::STRING, ['length' => 255]);
                    $definition->addColumn('entities_id', \Doctrine\DBAL\Types\Types::INTEGER);
                    $definition->addColumn('is_recursive', \Doctrine\DBAL\Types\Types::BOOLEAN, ['default' => false]);
                    if ($table === $tables[1]) {
                        $definition->addColumn('completename', \Doctrine\DBAL\Types\Types::STRING, ['length' => 255]);
                        $definition->addColumn(\PluginRecursionOwner::getForeignKeyField(), \Doctrine\DBAL\Types\Types::INTEGER, ['default' => 0]);
                    }
                }
                $schema->createTable($definition);
                $created[] = $table;
            }
            $DB = $probe;
            $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $plugins->setValue(null, ['recursion']);
            foreach ([\PluginRecursionDropdown::class, \PluginRecursionOwner::class, \PluginRecursionLink::class, \PluginRecursionPublicLink::class] as $class) {
                $this->boolean(\Plugin::registerClass($class))->isTrue();
            }
            \PluginRecursionLink::$relations = [];
            \PluginRecursionLink::$updates = [];
            \PluginRecursionLink::$refuseUpdate = false;
            \PluginRecursionDropdown::$additions = 0;
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $operation($connection, $tables, (int)getItemByTypeName('Entity', '_test_root_entity', true), (int)getItemByTypeName('Entity', '_test_child_1', true));
            $frame->assertActive();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $DB = $original;
            $CFG_GLPI = $config;
            $_SESSION = $session;
            $plugins->setValue(null, $active);
            \PluginRecursionLink::$relations = $declarations;
            \PluginRecursionLink::$updates = $updates;
            \PluginRecursionLink::$refuseUpdate = $refuse;
            \PluginRecursionDropdown::$additions = $additions;
            try {
                $frame?->rollBack();
                \itsmng\Database\TransactionOwnership::assertManaged($connection);
                if ($connection->getTransactionNestingLevel() !== 0) {
                    throw new \itsmng\Database\TransactionOwnershipMismatch('Plugin fixture DDL requires its own frame closed.');
                }
                foreach (array_reverse($created) as $table) {
                    $schema->dropTable($table);
                }
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
            try {
                $probe->close();
                $scope->assertActive();
                $this->integer($caller->getTransactionNestingLevel())->isIdenticalTo($level);
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testRegisteredPluginUniquenessUsesItsOwnSchema(): void
    {
        global $DB, $CFG_GLPI;

        require_once __DIR__ . '/../fixtures/pluginfoobar.php';
        $original = $DB;
        $session = $_SESSION;
        $registered = $CFG_GLPI['unicity_types'];
        $caller = $original->getDoctrineConnection();
        \itsmng\Database\TransactionOwnership::assertManaged($caller);
        $scope = $caller->captureManagedTransactionScope();
        $level = $caller->getTransactionNestingLevel();
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($caller->getParams())
            : \itsmng\Database\MySQLConnection::create($caller->getParams());
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $table = \PluginFooBar::getTable();
        $schema = $connection->createSchemaManager();
        $created = false;
        $frame = null;
        $failure = null;
        try {
            // Fixture DDL belongs to a separate physical owner, never DbTestCase's frame.
            \itsmng\Database\TransactionOwnership::assertManaged($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo(0);
            $this->boolean($schema->tablesExist([$table]))->isFalse();
            $definition = new \Doctrine\DBAL\Schema\Table($table);
            $definition->addColumn('id', \Doctrine\DBAL\Types\Types::INTEGER);
            $definition->setPrimaryKey(['id']);
            foreach (['name', 'serial'] as $column) {
                $definition->addColumn($column, \Doctrine\DBAL\Types\Types::STRING, ['length' => 100, 'notnull' => false]);
            }
            $definition->addColumn('entities_id', \Doctrine\DBAL\Types\Types::INTEGER);
            $definition->addColumn('users_id', \Doctrine\DBAL\Types\Types::INTEGER, ['notnull' => false]);
            $definition->addColumn('is_template', \Doctrine\DBAL\Types\Types::BOOLEAN, ['default' => false]);
            $definition->addColumn('payload', \Doctrine\DBAL\Types\Types::JSON, ['notnull' => false]);
            $schema->createTable($definition);
            $created = true;
            $DB = $probe;
            $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $this->boolean(\Plugin::registerClass(\PluginFooBar::class, ['unicity_types' => true]))->isTrue();
            $this->array($CFG_GLPI['unicity_types'])->contains(\PluginFooBar::class);
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $rows = [
                ['duplicate', 'one', 7, $source, false], ['duplicate', 'one', 7, $source, false],
                ['duplicate', 'two', 8, $source, false], ['duplicate', 'one', 7, $source, true],
                ['child only', 'one', 9, $child, false], ['child only', 'one', 9, $child, false],
                ['', 'empty', 0, $source, false], ['', 'empty', 0, $source, false],
                [null, null, null, $source, false], [null, null, null, $source, false],
                ['singleton', 'unique', 10, $source, false],
            ];
            foreach ($rows as $index => [$name, $serial, $user, $entity, $template]) {
                $connection->insert(
                    $table,
                    ['id' => $index + 1, 'name' => $name, 'serial' => $serial,
                    'users_id' => $user, 'entities_id' => $entity, 'is_template' => $template],
                    ['is_template' => \Doctrine\DBAL\Types\Types::BOOLEAN]
                );
            }
            // Exercise the existing public API before the new repository entry point.
            $rule = new \FieldUnicity();
            $rule->fields = ['itemtype' => \PluginFooBar::class, 'fields' => 'name', 'entities_id' => $source, 'is_recursive' => 0];
            $this->output(static fn () => \FieldUnicity::showDoubles($rule))->contains('duplicate')
                ->contains("<td class='numeric'>3</td>")->notContains('child only')->notContains('singleton');
            $rule->fields['is_recursive'] = 1;
            $this->output(static fn () => \FieldUnicity::showDoubles($rule))->contains('duplicate')->contains('child only');
            $repository = new \itsmng\Database\Repository\FieldUnicityRepository(\itsmng\Database\Orm::create($probe));
            $item = new \PluginFooBar();
            $this->array($repository->duplicatesForItem($item, ['name'], [$source]))
                ->isIdenticalTo([['cpt' => 3, 'name' => 'duplicate']]);
            $this->array($repository->duplicatesForItem($item, ['name', 'serial'], [$source]))
                ->isIdenticalTo([['cpt' => 2, 'name' => 'duplicate', 'serial' => 'one']]);
            $this->array(array_column($repository->duplicatesForItem($item, ['users_id'], [$source]), 'cpt'))->isIdenticalTo([2]);
            $this->array(array_column($repository->duplicatesForItem($item, ['users_id'], [$source]), 'users_id'))
                ->isEqualTo([7]);
            $this->array(array_column($repository->duplicatesForItem($item, ['name'], null), 'name'))
                ->isIdenticalTo(['duplicate', 'child only']);
            $this->array($repository->duplicatesForItem($item, ['unknown'], []))->isEmpty();
            foreach (['unknown', 'payload', 'name) OR 1=1 --'] as $invalid) {
                $this->exception(static fn () => $repository->duplicatesForItem($item, [$invalid], [$source]))
                    ->isInstanceOf(\InvalidArgumentException::class);
            }
            // Mapped assets retain the existing ORM semantics, including root scope.
            $this->array($repository->duplicatesForItem(new \Computer(), ['name'], [0]))
                ->isIdenticalTo($repository->duplicates(\Computer::getTable(), ['name'], [0], true));
            $CFG_GLPI['unicity_types'] = array_values(array_diff($registered, [\PluginFooBar::class]));
            $this->exception(static fn () => $repository->duplicatesForItem($item, ['name'], [$source]))
                ->isInstanceOf(\InvalidArgumentException::class);
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $DB = $original;
            $CFG_GLPI['unicity_types'] = $registered;
            $_SESSION = $session;
            try {
                $frame?->rollBack();
                \itsmng\Database\TransactionOwnership::assertManaged($connection);
                if ($connection->getTransactionNestingLevel() !== 0) {
                    throw new \itsmng\Database\TransactionOwnershipMismatch('Plugin fixture DDL requires its frame to be closed.');
                }
                if ($created) {
                    $schema->dropTable($table);
                }
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
            try {
                $probe->close();
                $scope->assertActive();
                $this->integer($caller->getTransactionNestingLevel())->isIdenticalTo($level);
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testPluginRelationsKeepOutsideEntityRecursionProtection(): void
    {
        global $DB, $CFG_GLPI;

        require_once __DIR__ . '/../fixtures/pluginrecursion.php';
        $original = $DB;
        $config = $CFG_GLPI;
        $session = $_SESSION;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $declarations = \PluginRecursionLink::$relations;
        $caller = $original->getDoctrineConnection();
        \itsmng\Database\TransactionOwnership::assertManaged($caller);
        $scope = $caller->captureManagedTransactionScope();
        $level = $caller->getTransactionNestingLevel();
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public array $documentReads = [];
            public function log($level, $message, array $context = []): void
            {
                $sql = str_replace(['`', '"'], '', $context['sql'] ?? '');
                if (preg_match('/^SELECT\b.*\bFROM\s+glpi_documents_items\b.*\bJOIN\s+glpi_documents\b/is', $sql)) {
                    $this->documentReads[] = array_values($context['params'] ?? []);
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $connection = $original->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($caller->getParams(), $configuration)
            : \itsmng\Database\MySQLConnection::create($caller->getParams(), $configuration);
        $probe = clone $original;
        (new \ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $schema = $connection->createSchemaManager();
        $owner = \PluginRecursionOwner::getTable();
        $link = \PluginRecursionLink::getTable();
        $created = [];
        $frame = null;
        $failure = null;
        try {
            \itsmng\Database\TransactionOwnership::assertManaged($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo(0);
            foreach ([$owner, $link] as $table) {
                $this->boolean($schema->tablesExist([$table]))->isFalse();
                $definition = new \Doctrine\DBAL\Schema\Table($table);
                foreach (['id', 'targets_id', 'peers_id', 'items_id'] as $column) {
                    $definition->addColumn($column, \Doctrine\DBAL\Types\Types::INTEGER, ['default' => 0]);
                }
                $definition->setPrimaryKey(['id']);
                $definition->addColumn('itemtype', \Doctrine\DBAL\Types\Types::STRING, ['length' => 100, 'default' => '']);
                if ($table === $owner) {
                    $definition->addColumn('entities_id', \Doctrine\DBAL\Types\Types::INTEGER);
                    $definition->addColumn('is_recursive', \Doctrine\DBAL\Types\Types::BOOLEAN, ['default' => false]);
                    $definition->addColumn(\PluginRecursionOwner::getForeignKeyField(), \Doctrine\DBAL\Types\Types::INTEGER, ['default' => 0]);
                }
                $schema->createTable($definition);
                $created[] = $table;
            }
            $DB = $probe;
            $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
            $plugins->setValue(null, ['recursion']);
            $this->boolean(\Plugin::registerClass(\PluginRecursionOwner::class))->isTrue();
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $manager = \itsmng\Database\Orm::create($probe);
            $record = new \itsmng\Database\Entity\Supplier();
            $record->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $record->name = 'plugin-recursion-' . $this->getUniqueString();
            $record->is_recursive = true;
            $manager->persist($record);
            $manager->flush();
            $target = new \Supplier();
            $this->boolean($target->getFromDB($record->id))->isTrue();
            $connection->insert($owner, ['id' => 1, 'targets_id' => $record->id, 'entities_id' => $child]);
            \PluginRecursionLink::$relations = [\Supplier::getTable() => [$owner => 'targets_id']];
            $this->array(\Plugin::getDatabaseRelations())->isIdenticalTo(\PluginRecursionLink::$relations);
            $this->boolean($target->canUnrecurs())->isFalse('A directly scoped plugin row prevents unrecursion');
            $connection->update($owner, ['entities_id' => $source], ['id' => 1]);
            $this->boolean($target->canUnrecurs())->isTrue('A permitted plugin owner allows unrecursion');
            $connection->update($owner, ['entities_id' => $child], ['id' => 1]);
            $connection->insert($link, ['id' => 1, 'targets_id' => $record->id, 'peers_id' => 1,
                'items_id' => 1, 'itemtype' => \PluginRecursionOwner::class]);
            \PluginRecursionLink::$relations = [\Supplier::getTable() => [$link => 'targets_id'], $owner => [$link => 'peers_id']];
            $this->boolean($target->canUnrecurs())->isFalse('An unscoped plugin link keeps the other declared owner');
            $contact = new \itsmng\Database\Entity\Contact();
            $contact->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $child);
            $contact->name = 'mapped-peer-' . $this->getUniqueString();
            $manager->persist($contact);
            $manager->flush();
            $connection->update($link, ['peers_id' => $contact->id], ['id' => 1]);
            \PluginRecursionLink::$relations = [\Supplier::getTable() => [$link => 'targets_id'], \Contact::getTable() => [$link => 'peers_id']];
            $this->boolean($target->canUnrecurs())->isFalse('A plugin link also checks its declared mapped core peer');
            $contact->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $manager->flush();
            $this->boolean($target->canUnrecurs())->isTrue('A permitted mapped core peer allows unrecursion');
            \PluginRecursionLink::$relations = [\Supplier::getTable() => [$link => 'targets_id'], $owner => [$link => 'peers_id']];
            $connection->update($link, ['peers_id' => 0], ['id' => 1]);
            $this->boolean($target->canUnrecurs())->isTrue('An empty peer sentinel does not invent an outside owner');
            \PluginRecursionLink::$relations = [\Supplier::getTable() => [$link => 'targets_id'],
                '_virtual_device' => [$link => ['items_id', 'itemtype']]];
            $this->boolean($target->canUnrecurs())->isFalse('A declared virtual plugin endpoint keeps its owner');
            $connection->update($owner, ['entities_id' => $source], ['id' => 1]);
            $this->boolean($target->canUnrecurs())->isTrue('A permitted virtual endpoint allows unrecursion');
            $connection->update($link, ['itemtype' => 'UnknownPluginModel'], ['id' => 1]);
            $this->exception(static fn () => $target->canUnrecurs())->isInstanceOf(\InvalidArgumentException::class);
            foreach (['unknown', 'targets_id) OR 1=1 --', 'itemtype'] as $column) {
                \PluginRecursionLink::$relations = [\Supplier::getTable() => [$owner => $column]];
                if ($column === 'itemtype') {
                    // A declared polymorphic reference must match both target id and type.
                    $connection->update($owner, ['items_id' => $record->id, 'itemtype' => \Supplier::class, 'entities_id' => $child], ['id' => 1]);
                    $this->boolean($target->canUnrecurs())->isFalse();
                    $connection->update($owner, ['itemtype' => \Computer::class], ['id' => 1]);
                    $this->boolean($target->canUnrecurs())->isTrue();
                } else {
                    $this->exception(static fn () => $target->canUnrecurs())->isInstanceOf(\InvalidArgumentException::class);
                }
            }
            // The plugin itself may be a recursive parent; only its declared
            // incoming links are dynamic, while the mapped document tail remains.
            $connection->insert(
                $owner,
                ['id' => 2, 'entities_id' => $source, 'is_recursive' => true],
                ['is_recursive' => \Doctrine\DBAL\Types\Types::BOOLEAN]
            );
            $pluginTarget = new \PluginRecursionOwner();
            $this->boolean($pluginTarget->getFromDB(2))->isTrue();
            $connection->update($link, ['targets_id' => 2, 'peers_id' => $contact->id], ['id' => 1]);
            $contact->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $child);
            $manager->flush();
            \PluginRecursionLink::$relations = [$owner => [$link => 'targets_id'], \Contact::getTable() => [$link => 'peers_id']];
            $this->boolean($pluginTarget->canUnrecurs())->isFalse('A plugin parent retains its declared outside peer');
            $contact->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $manager->flush();
            $this->boolean($pluginTarget->canUnrecurs())->isTrue('A plugin parent with permitted peers may stop recursion');
            \PluginRecursionLink::$relations = [];
            $logger->documentReads = [];
            $this->boolean($pluginTarget->canUnrecurs())->isTrue();
            $this->array($logger->documentReads)->hasSize(1, 'The mapped document-owner tail is still queried for a plugin parent');
            $this->array(array_slice($logger->documentReads[0], 0, 2))->isIdenticalTo([2, \PluginRecursionOwner::class]);
            $connection->insert($owner, ['id' => 3, 'entities_id' => $child, \PluginRecursionOwner::getForeignKeyField() => 2]);
            $this->boolean($pluginTarget->canUnrecurs())->isFalse('A plugin tree protects its own outside child without a hook declaration');
            $connection->update($owner, ['entities_id' => $source], ['id' => 3]);
            $this->boolean($pluginTarget->canUnrecurs())->isTrue('A plugin tree permits its own same-scope child');

            $financial = new \itsmng\Database\Entity\Infocom();
            $financial->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $child);
            $financial->itemtype = \PluginRecursionOwner::class;
            $financial->items_id = 2;
            $financial->suppliers = $record;
            $manager->persist($financial);
            $manager->flush();
            \PluginRecursionLink::$relations = [$owner => [\Infocom::getTable() => ['items_id', 'itemtype']]];
            $this->boolean($pluginTarget->canUnrecurs())->isFalse('A plugin parent also checks a declared mapped child');
            $financial->itemtype = \Computer::class;
            $manager->flush();
            $this->boolean($pluginTarget->canUnrecurs())->isTrue('A polymorphic declaration binds the actual plugin type');
            $financial->itemtype = \PluginRecursionOwner::class;
            $financial->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $manager->flush();
            $this->boolean($pluginTarget->canUnrecurs())->isTrue();
            \PluginRecursionLink::$relations = [];
            $connection->update($owner, ['entities_id' => $child], ['id' => 2]);
            // Infocom already owns its entity. Its virtual plugin endpoint must
            // not replace that existing policy with the endpoint's different scope.
            $this->boolean($target->canUnrecurs())->isTrue('A scoped mapped link keeps its own entity policy');
            $financial->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $child);
            $manager->flush();
            $this->boolean($target->canUnrecurs())->isFalse('The mapped financial owner still prevents unsafe recursion');
            $financial->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $document = new \itsmng\Database\Entity\Document();
            $document->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $child);
            $document->name = 'recursion-document-' . $this->getUniqueString();
            $manager->persist($document);
            $attachment = new \itsmng\Database\Entity\DocumentItem();
            $attachment->documents = $document;
            $attachment->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $attachment->supplier = $record;
            $attachment->itemtype = \Supplier::class;
            $manager->persist($attachment);
            $manager->flush();
            $this->boolean($target->canUnrecurs())->isFalse('Document ownership remains independent of cached link scope');
            $document->entities = $manager->getReference(\itsmng\Database\Entity\Entity::class, $source);
            $manager->flush();
            $this->boolean($target->canUnrecurs())->isTrue();
            $plugins->setValue(null, []);
            $this->exception(static fn () => $pluginTarget->canUnrecurs())->isInstanceOf(\InvalidArgumentException::class);
            $plugins->setValue(null, ['recursion']);
            $repository = new \itsmng\Database\Repository\RelationshipLifecycleRepository($manager);
            $this->exception(static fn () => $repository->hasOutsideEntities($owner, 2, 2, \Computer::class, [$source]))
                ->isInstanceOf(\InvalidArgumentException::class);
            $frame->assertActive();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $DB = $original;
            $CFG_GLPI = $config;
            $_SESSION = $session;
            $plugins->setValue(null, $active);
            \PluginRecursionLink::$relations = $declarations;
            try {
                $frame?->rollBack();
                \itsmng\Database\TransactionOwnership::assertManaged($connection);
                if ($connection->getTransactionNestingLevel() !== 0) {
                    throw new \itsmng\Database\TransactionOwnershipMismatch('Plugin fixture DDL requires its frame to be closed.');
                }
                foreach (array_reverse($created) as $table) {
                    $schema->dropTable($table);
                }
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
            try {
                $probe->close();
                $scope->assertActive();
                $this->integer($caller->getTransactionNestingLevel())->isIdenticalTo($level);
            } catch (\Throwable $cleanup) {
                $failure = $failure === null ? $cleanup : new \itsmng\Database\MutationCleanupFailure($failure, $cleanup);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function afterTestMethod($method)
    {

        // Remove directory and files generated by tests
        foreach ([$this->test_plugin_directory, $this->anothertest_plugin_directory] as $directory) {
            $test_plugin_path = $this->getTestPluginPath($directory);
            if (file_exists($test_plugin_path)) {
                \Toolbox::deleteDir($test_plugin_path);
            }
        }

        parent::afterTestMethod($method);
    }

    public function testGetGlpiVersion()
    {
        $plugin = new \Plugin();
        $this->string($plugin->getGlpiVersion())->isIdenticalTo(GLPI_VERSION);
    }

    public function testGetGlpiPrever()
    {
        $plugin = new \Plugin();
        if (defined('ITSM_PREVER')) {
            $this->string($plugin->getGlpiPrever())->isIdenticalTo(\ITSM_PREVER);
        } else {
            if (version_compare(PHP_VERSION, '8.0.0-dev', '<')) {
                $this->when(
                    function () use ($plugin) {
                        $plugin->getGlpiPrever();
                    }
                )->error
                   ->exists();
            } else {
                $this->exception(
                    function () use ($plugin) {
                        $plugin->getGlpiPrever();
                    }
                )->message->contains('Undefined constant "ITSM_PREVER"');
            }
        }
    }

    public function testIsGlpiPrever()
    {
        $plugin = new \Plugin();
        if (defined('ITSM_PREVER')) {
            $this->boolean($plugin->isGlpiPrever())->isTrue();
        } else {
            $this->boolean($plugin->isGlpiPrever())->isFalse();
        }
    }


    public function testcheckGlpiVersion()
    {
        //$this->constant->GLPI_VERSION = '9.1';
        $plugin = new \mock\Plugin();

        // Test min compatibility
        $infos = ['min' => '0.90'];

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '9.2';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '9.2';
        $this->calling($plugin)->getGlpiVersion = '9.2-dev';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '0.89';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90.');

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '0.89';
        $this->calling($plugin)->getGlpiVersion = '0.89-dev';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90.');

        // Test max compatibility
        $infos = ['max' => '9.3'];

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '9.2';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '9.2';
        $this->calling($plugin)->getGlpiVersion = '9.2-dev';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '9.3';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG < 9.3.');

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '9.3';
        $this->calling($plugin)->getGlpiVersion = '9.3-dev';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG < 9.3.');

        // Test min and max compatibility
        $infos = ['min' => '0.90', 'max' => '9.3'];

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '9.2';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '9.2';
        $this->calling($plugin)->getGlpiVersion = '9.2-dev';
        $this->boolean($plugin->checkGlpiVersion($infos))->isTrue();

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '0.89';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos, true))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90 and < 9.3.');

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '0.89';
        $this->calling($plugin)->getGlpiVersion = '0.89-dev';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90 and < 9.3.');

        $this->calling($plugin)->isGlpiPrever = false;
        $this->calling($plugin)->getGlpiVersion = '9.3';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90 and < 9.3.');

        $this->calling($plugin)->isGlpiPrever = true;
        $this->calling($plugin)->getGlpiPrever = '9.3';
        $this->calling($plugin)->getGlpiVersion = '9.3-dev';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkGlpiVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG >= 0.90 and < 9.3.');
    }

    public function testcheckPhpVersion()
    {
        //$this->constant->PHP_VERSION = '7.1';
        $plugin = new \mock\Plugin();

        $infos = ['min' => '5.6'];
        $this->boolean($plugin->checkPhpVersion($infos))->isTrue();

        $this->calling($plugin)->getPhpVersion = '5.4';
        $this->output(
            function () use ($plugin, $infos) {
                $this->boolean($plugin->checkPhpVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires PHP >= 5.6.');

        $this->calling($plugin)->getPhpVersion = '7.1';
        $this->boolean($plugin->checkPhpVersion($infos))->isTrue();

        $this->output(
            function () use ($plugin) {
                $infos = ['min' => '5.6', 'max' => '7.0'];
                $this->boolean($plugin->checkPhpVersion($infos))->isFalse();
            }
        )->isIdenticalTo('This plugin requires PHP >= 5.6 and < 7.0.');

        $infos = ['min' => '5.6', 'max' => '7.2'];
        $this->boolean($plugin->checkPhpVersion($infos))->isTrue();
    }

    public function testCheckPhpExtensions()
    {
        $plugin = new \Plugin();

        $this->output(
            function () use ($plugin) {
                $exts = ['gd' => ['required' => true]];
                $this->boolean($plugin->checkPhpExtensions($exts))->isTrue();
            }
        )->isEmpty();

        $this->output(
            function () use ($plugin) {
                $exts = ['myext' => ['required' => true]];
                $this->boolean($plugin->checkPhpExtensions($exts))->isFalse();
            }
        )->isIdenticalTo('This plugin requires PHP extension myext<br/>');
    }

    public function testCheckGlpiParameters()
    {
        global $CFG_GLPI;

        $params = ['my_param'];

        $plugin = new \Plugin();

        $this->output(
            function () use ($plugin, $params) {
                $this->boolean($plugin->checkGlpiParameters($params))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG parameter my_param<br/>');

        $CFG_GLPI['my_param'] = '';
        $this->output(
            function () use ($plugin, $params) {
                $this->boolean($plugin->checkGlpiParameters($params))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG parameter my_param<br/>');

        $CFG_GLPI['my_param'] = '0';
        $this->output(
            function () use ($plugin, $params) {
                $this->boolean($plugin->checkGlpiParameters($params))->isFalse();
            }
        )->isIdenticalTo('This plugin requires ITSM-NG parameter my_param<br/>');

        $CFG_GLPI['my_param'] = 'abc';
        $this->output(
            function () use ($plugin, $params) {
                $this->boolean($plugin->checkGlpiParameters($params))->isTrue();
            }
        )->isEmpty();
    }

    public function testCheckGlpiPlugins()
    {
        $plugin = new \mock\Plugin();

        $this->calling($plugin)->isInstalled = false;
        $this->calling($plugin)->isActivated = false;

        $this->output(
            function () use ($plugin) {
                $this->boolean($plugin->checkGlpiPlugins(['myplugin']))->isFalse();
            }
        )->isIdenticalTo('This plugin requires myplugin plugin<br/>');

        $this->calling($plugin)->isInstalled = true;

        $this->output(
            function () use ($plugin) {
                $this->boolean($plugin->checkGlpiPlugins(['myplugin']))->isFalse();
            }
        )->isIdenticalTo('This plugin requires myplugin plugin<br/>');

        $this->calling($plugin)->isInstalled = true;
        $this->calling($plugin)->isActivated = true;

        $this->output(
            function () use ($plugin) {
                $this->boolean($plugin->checkGlpiPlugins(['myplugin']))->isTrue();
            }
        )->isEmpty();

    }

    /**
     * Test state checking on an invalid directory corresponding to an unknown plugin.
     * Should have no effect.
     */
    public function testCheckPluginStateForInvalidUnknownPlugin()
    {

        $this->doTestCheckPluginState(null, null, null);
    }

    /**
     * Test state checking on an invalid directory corresponding to a known plugin.
     * Should results in no change in plugin state, as "TOBECLEANED" state is realtime computed.
     */
    public function testCheckPluginStateForInvalidKnownPlugin()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $expected_data = $initial_data;

        $this->doTestCheckPluginState(
            $initial_data,
            null,
            $expected_data,
            'Unable to load plugin "' . $this->test_plugin_directory . '" information.'
        );

        // check also Plugin::isActivated method
        $plugin_inst = new \Plugin();
        $this->boolean($plugin_inst->isActivated($this->test_plugin_directory));
    }

    /**
     * Test state checking on a valid directory corresponding to an unknown plugin.
     * Should results in creating plugin with "NOTINSTALLED" state.
     */
    public function testCheckPluginStateForNewPlugin()
    {

        $setup_informations = [
           'name'      => 'Test plugin',
           'version'   => '1.0',
        ];
        $expected_data = array_merge(
            $setup_informations,
            [
              'directory' => $this->test_plugin_directory,
              'state'     => \Plugin::NOTINSTALLED,
         ]
        );

        $this->doTestCheckPluginState(
            null,
            $setup_informations,
            $expected_data
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known and installed plugin
     * with a different version.
     * Should results in changing plugin state to "NOTUPDATED".
     */
    public function testCheckPluginStateForInstalledAndUpdatablePlugin()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin NG',
           'version' => '2.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            $setup_informations,
            [
              'state' => \Plugin::NOTUPDATED,
         ]
        );

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" version changed. It has been deactivated as its update process has to be launched.'
        );

        // check also Plugin::isUpdatable method
        $plugin_inst = new \Plugin();
        $this->boolean($plugin_inst->isUpdatable($this->test_plugin_directory));
    }

    /**
     * Test state checking on a valid directory corresponding to a known and NOT installed plugin
     * with a different version.
     * Should results in keeping plugin state to "NOTINSTALLED".
     */
    public function testCheckPluginStateForNotInstalledAndUpdatablePlugin()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::NOTINSTALLED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin NG',
           'version' => '2.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            $setup_informations,
            [
              'state' => \Plugin::NOTINSTALLED,
         ]
        );

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known and NOT UPDATED plugin
     * with a different version.
     * Should results in keeping plugin state to "NOTUPDATED".
     */
    public function testCheckPluginStateForNotUpdatededAndUpdatablePlugin()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::NOTUPDATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin NG',
           'version' => '2.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            $setup_informations,
            [
              'state' => \Plugin::NOTUPDATED,
         ]
        );

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a plugin that has been renamed and is now located
     * into a different directory.
     * Should results in changing plugin directory to new value and state to "NOTUPDATED".
     */
    public function testCheckPluginStateForPluginThatHasBeenRenamed()
    {

        $plugin = new \Plugin();

        // Create files for in new directory of plugin
        $new_informations = [
           'name'    => 'Test plugin revamped',
           'oldname' => $this->test_plugin_directory,
           'version' => '2.0',
        ];
        $new_directory = $this->anothertest_plugin_directory;
        $this->createTestPluginFiles(
            true,
            $new_informations,
            $new_directory
        );

        // Create initial data in DB
        $old_directory = $this->test_plugin_directory;
        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Old plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $plugin_id = $plugin->add($initial_data);
        $this->integer((int)$plugin_id)->isGreaterThan(0);

        // Check state
        $this->when(
            function () use ($plugin, $old_directory) {
                $plugin->checkPluginState($old_directory);
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage('Plugin "' . $new_directory . '" version changed. It has been deactivated as its update process has to be launched.')
              ->exists();

        // Assert that data in DB matches expected
        $this->boolean($plugin->getFromDBByCrit(['directory' => $new_directory]))->isTrue();

        $this->string($plugin->fields['directory'])->isIdenticalTo($new_directory);
        $this->string($plugin->fields['name'])->isIdenticalTo($new_informations['name']);
        $this->string($plugin->fields['version'])->isIdenticalTo($new_informations['version']);
        $this->integer((int)$plugin->fields['state'])->isIdenticalTo(\Plugin::NOTUPDATED);
    }

    /**
     * Test state checking on a valid directory corresponding to a plugin that is known with its old name.
     * Should results in changing plugin directory to new value and state to "NOTUPDATED".
     */
    public function testCheckPluginStateForPluginKnownWithItsOldName()
    {

        $initial_data = [
           'directory' => 'oldnameofplugin',
           'name'      => 'Old plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin revamped',
           'oldname' => 'oldnameofplugin',
           'version' => '2.0',
        ];
        $expected_data = array_merge(
            $setup_informations,
            [
              'directory' => $this->test_plugin_directory,
              'state'     => \Plugin::NOTUPDATED,
         ]
        );

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" version changed. It has been deactivated as its update process has to be launched.'
        );

        // check also Plugin::isUpdatable method
        $plugin_inst = new \Plugin();
        $this->boolean($plugin_inst->isUpdatable($this->test_plugin_directory));
    }

    /**
     * Test state checking on a valid directory corresponding to a known inactive plugin with no modifications.
     * Should results in no changes.
     */
    public function testCheckPluginStateForInactiveAndNotUpdatedPlugin()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::NOTACTIVATED,
        ];
        $setup_informations = [
           'name'      => 'Test plugin',
           'version'   => '1.0',
        ];
        $expected_data = $initial_data;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known inactive plugin with no modifications
     * but not validating config.
     * Should results in changing plugin state to "TOBECONFIGURED".
     */
    public function testCheckPluginStateForInactiveAndNotUpdatedPluginNotValidatingConfig()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::NOTACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin',
           'version' => '1.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            [
              'state' => \Plugin::TOBECONFIGURED,
         ]
        );

        $this->function->plugin_test_check_config = false;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" must be configured.'
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known active plugin with no modifications
     * but not matching versions.
     * Should results in changing plugin state to "NOTACTIVATED".
     */
    public function testCheckPluginStateForActiveAndNotUpdatedPluginNotMatchingVersions()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'         => 'Test plugin',
           'version'      => '1.0',
           'requirements' => [
              'glpi' => [
                 'min' => '15.0',
              ],
           ],
        ];
        $expected_data = array_merge(
            $initial_data,
            [
              'state' => \Plugin::NOTACTIVATED,
         ]
        );

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" prerequisites are not matched. It has been deactivated.'
        );

        // check also Plugin::isUpdatable method
        $plugin_inst = new \Plugin();
        $this->boolean($plugin_inst->isUpdatable($this->test_plugin_directory));
    }

    /**
     * Test state checking on a valid directory corresponding to a known active plugin with no modifications
     * but not matching prerequisites.
     * Should results in changing plugin state to "NOTACTIVATED".
     */
    public function testCheckPluginStateForActiveAndNotUpdatedPluginNotMatchingPrerequisites()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin',
           'version' => '1.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            [
              'state' => \Plugin::NOTACTIVATED,
         ]
        );

        $this->function->plugin_test_check_prerequisites = false;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" prerequisites are not matched. It has been deactivated.'
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known active plugin with no modifications
     * but not validating config.
     * Should results in changing plugin state to "TOBECONFIGURED".
     */
    public function testCheckPluginStateForActiveAndNotUpdatedPluginNotValidatingConfig()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin',
           'version' => '1.0',
        ];
        $expected_data = array_merge(
            $initial_data,
            [
              'state' => \Plugin::TOBECONFIGURED,
         ]
        );

        $this->function->plugin_test_check_config = false;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data,
            'Plugin "' . $this->test_plugin_directory . '" must be configured.'
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known active plugin with no modifications,
     * matching prerequisites and validating config.
     * Should results in no changes.
     */
    public function testCheckPluginStateForActiveAndNotUpdatedPluginMatchingPrerequisitesAndConfig()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin',
           'version' => '1.0',
        ];
        $expected_data = $initial_data;

        $this->function->plugin_test_check_prerequisites = true;
        $this->function->plugin_test_check_config = true;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data
        );
    }

    /**
     * Test state checking on a valid directory corresponding to a known active plugin with no modifications
     * having nor check_prerequisites nor check_config function.
     * Should results in no changes.
     */
    public function testCheckPluginStateForActiveAndNotUpdatedPluginHavingNoCheckFunctions()
    {

        $initial_data = [
           'directory' => $this->test_plugin_directory,
           'name'      => 'Test plugin',
           'version'   => '1.0',
           'state'     => \Plugin::ACTIVATED,
        ];
        $setup_informations = [
           'name'    => 'Test plugin',
           'version' => '1.0',
        ];
        $expected_data = $initial_data;

        $this->doTestCheckPluginState(
            $initial_data,
            $setup_informations,
            $expected_data
        );

        // check also Plugin::isActivated method
        $plugin_inst = new \Plugin();
        $this->boolean($plugin_inst->isActivated($this->test_plugin_directory));
    }

    /**
     * Test that state checking on a plugin directory.
     *
     * /!\ Each iteration on this method has to be done on a different test method, unless you change
     * the plugin directory on each time. Not doing this will prevent updating the `init` function of
     * the plugin on each test.
     *
     * @param array|null  $initial_data       Initial data in DB, null for none.
     * @param array|null  $setup_informations Information hosted by setup file, null for none.
     * @param array|null  $expected_data      Expected data in DB, null for none.
     * @param string|null $expected_warning   Expected warning message, null for none.
     *
     * @return void
     */
    private function doTestCheckPluginState($initial_data, $setup_informations, $expected_data, $expected_warning = null)
    {

        $plugin_directory = $this->test_plugin_directory;
        $test_plugin_path = $this->getTestPluginPath($this->test_plugin_directory);
        $plugin           = new \Plugin();

        if (file_exists($test_plugin_path)) {
            \Toolbox::deleteDir($test_plugin_path);
        }

        // Fail if plugin already exists in DB or filesystem, as this is not expected
        $this->boolean($plugin->getFromDBByCrit(['directory' => $plugin_directory]))->isFalse();
        $this->boolean(file_exists($test_plugin_path))->isFalse();

        // Create initial state of plugin
        $plugin_id = null;
        if (null !== $initial_data) {
            $plugin_id = $plugin->add($initial_data);
            $this->integer((int)$plugin_id)->isGreaterThan(0);
        }

        // Create test plugin files
        $this->createTestPluginFiles(
            null !== $setup_informations,
            null !== $setup_informations ? $setup_informations : []
        );

        // Check state
        if (null !== $expected_warning) {
            $this->when(
                function () use ($plugin, $plugin_directory) {
                    $plugin->checkPluginState($plugin_directory);
                }
            )->error()
               ->withType(E_USER_WARNING)
               ->withMessage($expected_warning)
                  ->exists();
        } else {
            $plugin->checkPluginState($plugin_directory);
        }

        // Assert that data in DB matches expected
        if (null !== $expected_data) {
            $this->boolean($plugin->getFromDBByCrit(['directory' => $plugin_directory]))->isTrue();

            $this->string($plugin->fields['directory'])->isIdenticalTo($expected_data['directory']);
            $this->string($plugin->fields['name'])->isIdenticalTo($expected_data['name']);
            $this->string($plugin->fields['version'])->isIdenticalTo($expected_data['version']);
            $this->integer((int)$plugin->fields['state'])->isIdenticalTo($expected_data['state']);
        } else {
            $this->boolean($plugin->getFromDBByCrit(['directory' => $plugin_directory]))->isFalse();
        }
    }

    /**
     * Returns test plugin files path.
     *
     * @param string $directory
     *
     * @return string
     */
    private function getTestPluginPath($directory)
    {

        return implode(
            DIRECTORY_SEPARATOR,
            [GLPI_ROOT, 'plugins', $directory]
        );
    }

    /**
     * Create test plugin files.
     *
     * @param boolean     $withsetup     Include setup file ?
     * @param array       $informations  Information to put in setup files.
     * @param null|string $directory     Directory where to create files, null to use default location.
     *
     * @return void
     */
    private function createTestPluginFiles($withsetup = true, array $informations = [], $directory = null)
    {

        if (null === $directory) {
            $directory = $this->test_plugin_directory;
        }
        $plugin_path = $this->getTestPluginPath($directory);

        $this->boolean(
            mkdir($plugin_path, 0700, true)
        )->isTrue();

        if ($withsetup) {
            $informations_str = var_export($informations, true);

            $this->variable(
                file_put_contents(
                    implode(DIRECTORY_SEPARATOR, [$plugin_path, 'setup.php']),
                    <<<PHP
<?php
function plugin_version_{$directory}() {
   return {$informations_str};
}
PHP
                )
            )->isNotEqualTo(false);
        }
    }
}
