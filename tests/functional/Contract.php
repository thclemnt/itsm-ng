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

use Alert;
use Closure;
use Contract as ContractModel;
use ContractCost as ContractCostModel;
use Contract_Supplier;
use DBAdapter;
use DBmysql;
use DBpgsql;
use DbTestCase;
use Doctrine\DBAL\Connection;
use Group;
use Group_User;
use Notification;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;
use Notification_NotificationTemplate;
use Session;
use itsmng\Database\Entity\Contract as ContractRecord;
use itsmng\Database\Entity\ContractCost as ContractCostRecord;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\NotificationTemplate as TemplateRecord;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\PostgresConnection;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\ContractAlertOutcome;
use itsmng\Domain\ContractAlertPublisher;
use Plugin;
use QueuedNotification;
use ReflectionProperty;
use RuntimeException;
use tests\fixtures\DisconnectedSchemaConnection;
use Throwable;

require_once dirname(__DIR__) . '/fixtures/DisconnectedSchemaConnection.php';

/* Test for inc/contract.class.php */

class Contract extends DbTestCase
{
    public function testClone()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new ContractModel();
        $input = [
           'name' => 'A test contract',
           'entities_id'  => 0
        ];
        $cid = $contract->add($input);
        $this->integer($cid)->isGreaterThan(0);

        $cost = new \ContractCost();
        $cost_id = $cost->add([
           'contracts_id' => $cid,
           'name'         => 'Test cost'
        ]);
        $this->integer($cost_id)->isGreaterThan(0);

        $suppliers_id = getItemByTypeName('Supplier', '_suplier01_name', true);
        $this->integer($suppliers_id)->isGreaterThan(0);

        $link_supplier = new Contract_Supplier();
        $link_id = $link_supplier->add([
           'suppliers_id' => $suppliers_id,
           'contracts_id' => $cid
        ]);
        $this->integer($link_id)->isGreaterThan(0);

        $this->boolean($link_supplier->getFromDB($link_id))->isTrue();
        $relation_items = $link_supplier->getItemsAssociatedTo($contract->getType(), $cid);
        $this->array($relation_items)->hasSize(1, 'Original Contract_Supplier not found!');

        $citem = new \Contract_Item();
        $citems_id = $citem->add([
           'contracts_id' => $cid,
           'itemtype'     => 'Computer',
           'items_id'     => getItemByTypeName('Computer', '_test_pc01', true)
        ]);
        $this->integer($citems_id)->isGreaterThan(0);

        $this->boolean($citem->getFromDB($citems_id))->isTrue();
        $relation_items = $citem->getItemsAssociatedTo($contract->getType(), $cid);
        $this->array($relation_items)->hasSize(1, 'Original Contract_Item not found!');

        $cloned = $contract->clone();
        $this->integer($cloned)->isGreaterThan($cid);

        foreach ($contract->getCloneRelations() as $rel_class) {
            $this->integer(
                countElementsInTable(
                    $rel_class::getTable(),
                    ['contracts_id' => $cloned]
                )
            )->isIdenticalTo(1, 'Missing relation with ' . $rel_class);
        }
        $this->assertEntityForwardingPreservesSelectedWriter();
    }

    /** The generic forwarding unit must guard its actual parent/child producers. */
    private function assertEntityForwardingPreservesSelectedWriter(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $original = $DB;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $originalConnection = $original->getDoctrineConnection();
        $originalScope = $originalConnection->captureManagedTransactionScope();
        $originalDepth = $originalConnection->getTransactionNestingLevel();
        $source = $foreign = $sourceFrame = $foreignFrame = null;
        $fixture = null;
        $committedBefore = null;
        $failure = null;
        $cleanup = static function (callable $operation) use (&$failure): void {
            try {
                $operation();
            } catch (Throwable $error) {
                $failure = $failure === null ? $error : new MutationCleanupFailure($failure, $error);
            }
        };
        try {
            // This regression starts without another command's opt-in guard.
            $guards = new ReflectionProperty(OwnershipUpdateUnit::class, 'writerGuards');
            $this->array($guards->getValue())->isEmpty();
            $parameters = $originalConnection->getParams();
            $source = $original->getProvider() === 'pgsql'
                ? PostgresConnection::create($parameters) : MySQLConnection::create($parameters);
            $foreign = $original->getProvider() === 'pgsql'
                ? PostgresConnection::create($parameters) : MySQLConnection::create($parameters);
            foreach ([$source, $foreign] as $connection) {
                if ($original->getProvider() === 'pgsql') {
                    $connection->executeStatement("SET SESSION lock_timeout = '5s'");
                    $connection->executeStatement("SET SESSION statement_timeout = '20s'");
                } else {
                    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
                }
            }
            $snapshot = static function (Connection $reader): array {
                $rows = [];
                foreach (['glpi_contracts', 'glpi_contractcosts', 'glpi_logs', 'glpi_alerts', 'glpi_queuednotifications'] as $table) {
                    $rows[$table] = $reader->fetchAllAssociative('SELECT * FROM ' . $reader->quoteIdentifier($table) . ' ORDER BY id');
                }
                return $rows;
            };
            $committedBefore = $snapshot($source);
            // Both routes must see the same real parent/child without waiting on
            // an uncommitted foreign key. Only these named fixture rows commit.
            $name = 'forwarding-writer-' . $this->getUniqueString();
            $fixture = OwnedMutationFrame::run($source, static function () use ($source, $name): array {
                $manager = Orm::forConnection($source);
                try {
                    $parent = new ContractRecord();
                    $parent->name = $name;
                    $parent->entities = $manager->getReference(Entity::class, 0);
                    $child = new ContractCostRecord();
                    $child->name = $name;
                    $child->contracts = $parent;
                    $child->entities = $parent->entities;
                    $manager->persist($parent);
                    $manager->persist($child);
                    $manager->flush();
                    return ['parent' => $parent->id, 'child' => $child->id, 'name' => $name];
                } finally {
                    $manager->clear();
                }
            });
            $sourceFrame = OwnedMutationFrame::begin($source);
            $foreignFrame = OwnedMutationFrame::begin($foreign);
            $writer = clone $original;
            $routed = clone $original;
            (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($writer, $source);
            (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($routed, $foreign);
            $DB = $writer;
            $contract = new ContractModel();
            $this->boolean($contract->getFromDB($fixture['parent']))->isTrue();
            $stored = $contract->fields;
            $before = $snapshot($source);
            $foreignBefore = $snapshot($foreign);
            $prepared = $switched = $children = 0;
            $plugins->setValue(null, [...$activePlugins, 'forwarding_writer_fixture']);
            $PLUGIN_HOOKS['pre_item_update']['forwarding_writer_fixture'][ContractModel::class] =
                static function (ContractModel $item) use ($fixture, &$prepared): void {
                    if ((int)$item->getID() === $fixture['parent']) {
                        ++$prepared;
                        $item->input['comment'] = 'Ordinary prepared input';
                    }
                };
            $PLUGIN_HOOKS['item_update']['forwarding_writer_fixture'][ContractModel::class] =
                static function (ContractModel $item) use ($fixture, $routed, &$switched): void {
                    if ((int)$item->getID() === $fixture['parent']) {
                        ++$switched;
                        // No veto, identity rewrite, raw SQL or restoring hook:
                        // the real subsequent forwarding producer sees this route.
                        $GLOBALS['DB'] = $routed;
                    }
                };
            $PLUGIN_HOOKS['item_update']['forwarding_writer_fixture'][ContractCostModel::class] =
                static function (ContractCostModel $item) use ($fixture, &$children): void {
                    if ((int)$item->getID() === $fixture['child']) {
                        ++$children;
                    }
                };
            $error = null;
            try {
                $contract->update(['id' => $fixture['parent'], 'is_recursive' => 1]);
            } catch (Throwable $caught) {
                $error = $caught;
            } finally {
                $DB = $writer;
            }
            // On the unguarded source, the real foreign child changes first.
            $this->boolean($snapshot($foreign) === $foreignBefore)->isTrue('Required forwarding must not write through the independent physical route');
            $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->integer($prepared)->isIdenticalTo(1);
            $this->integer($switched)->isIdenticalTo(1);
            $this->integer($children)->isIdenticalTo(0);
            $this->boolean($snapshot($source) === $before)->isTrue();
            $this->array($contract->fields)->isIdenticalTo($stored);
            $sourceFrame->assertActive();
            $foreignFrame->assertActive();
            $this->integer($source->getTransactionNestingLevel())->isIdenticalTo(1);
            $this->integer($foreign->getTransactionNestingLevel())->isIdenticalTo(1);
            $this->array($guards->getValue())->isEmpty();

            // A real child veto still rolls back the already-written parent.
            unset($PLUGIN_HOOKS['item_update']['forwarding_writer_fixture'][ContractModel::class]);
            $PLUGIN_HOOKS['pre_item_update']['forwarding_writer_fixture'][ContractCostModel::class] =
                static function (ContractCostModel $item) use ($fixture): void {
                    if ((int)$item->getID() === $fixture['child']) {
                        $item->input = false;
                    }
                };
            $this->boolean($contract->update(['id' => $fixture['parent'], 'is_recursive' => 1]))->isFalse();
            $this->boolean($snapshot($source) === $before)->isTrue();
            $this->array($contract->fields)->isIdenticalTo($stored);
            unset($PLUGIN_HOOKS['pre_item_update']['forwarding_writer_fixture'][ContractCostModel::class]);
            $this->boolean($contract->update(['id' => $fixture['parent'], 'is_recursive' => 1]))->isTrue();
            $this->string($source->fetchOne('SELECT comment FROM glpi_contracts WHERE id = ?', [$fixture['parent']]))
                ->isIdenticalTo('Ordinary prepared input');
            $child = new ContractCostModel();
            $this->boolean($child->getFromDB($fixture['child']))->isTrue();
            $this->boolean((bool)$child->fields['is_recursive'])->isTrue();
            $this->integer($children)->isIdenticalTo(1);
            // Legitimate existing guards and descendant units remain composable.
            $this->boolean(OwnershipUpdateUnit::withWriterGuard(
                $writer,
                $source,
                static fn (): bool => $contract->update(['id' => $fixture['parent'], 'is_recursive' => 0])
            ))
                ->isTrue();
            $this->boolean($child->getFromDB($fixture['child']))->isTrue();
            $this->boolean((bool)$child->fields['is_recursive'])->isFalse();
            $this->integer($children)->isIdenticalTo(2);
            $this->array($guards->getValue())->isEmpty();
            $sourceFrame->assertActive();
            $foreignFrame->assertActive();
            $originalScope->assertActive();
            $this->integer($originalConnection->getTransactionNestingLevel())->isIdenticalTo($originalDepth);
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $DB = $original;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $activePlugins);
            $_SESSION = $session;
            if ($foreignFrame !== null) {
                $cleanup(static fn () => $foreignFrame->rollBack());
            }
            if ($sourceFrame !== null) {
                $cleanup(static fn () => $sourceFrame->rollBack());
            }
            if ($fixture !== null) {
                $cleanup(static fn () => OwnedMutationFrame::run($source, static function () use ($source, $fixture): void {
                    $source->delete('glpi_contractcosts', ['id' => $fixture['child'], 'name' => $fixture['name']]);
                    $source->delete('glpi_contracts', ['id' => $fixture['parent'], 'name' => $fixture['name']]);
                }));
            }
            if ($committedBefore !== null) {
                $cleanup(function () use ($source, $snapshot, $committedBefore): void {
                    $this->boolean($snapshot($source) === $committedBefore)->isTrue('Committed fixture cleanup preserves all five observed row bags');
                });
            }
            if ($foreign !== null) {
                $cleanup(static fn () => $foreign->close());
            }
            if ($source !== null) {
                $cleanup(static fn () => $source->close());
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testContractSupplierLinkUnlink()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new ContractModel();
        $contract_id = $contract->add([
           'name'        => 'contract-supplier-link',
           'entities_id' => 0,
        ]);
        $this->integer((int)$contract_id)->isGreaterThan(0);

        $supplier_id = getItemByTypeName('Supplier', '_suplier01_name', true);
        $this->integer((int)$supplier_id)->isGreaterThan(0);

        $relation = new Contract_Supplier();
        $relation_id = $relation->add([
           'contracts_id' => $contract_id,
           'suppliers_id' => $supplier_id,
        ]);
        $this->integer((int)$relation_id)->isGreaterThan(0);
        $this->boolean($relation->getFromDB($relation_id))->isTrue();

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $session = $_SESSION;
        $originalName = $connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id = ?', [$supplier_id]);
        $translation = ['itemtype' => 'Supplier', 'items_id' => $supplier_id,
            'language' => 'x_' . bin2hex(random_bytes(4)), 'field' => 'name'];
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $created = 0;
        $read = static function () use ($contract, $factories, &$created): string {
            $before = $factories->getValue();
            $html = $contract->getSuppliersNames();
            $created += $factories->getValue() - $before;
            return $html;
        };
        try {
            unset($_SESSION['glpi_dropdowntranslations']['Supplier']['name']);
            // Warm outside the measured reads: either provider may allocate its first private manager.
            $this->string($contract->getSuppliersNames())->isIdenticalTo($originalName . '<br>');
            $this->string($read())->isIdenticalTo($originalName . '<br>');
            $connection->update('glpi_suppliers', ['name' => 'Fresh contract supplier'], ['id' => $supplier_id]);
            $this->string($read())->isIdenticalTo('Fresh contract supplier<br>');
            $connection->insert('glpi_dropdowntranslations', $translation + ['value' => 'Translated contract supplier']);
            $_SESSION['glpilanguage'] = $translation['language'];
            $_SESSION['glpi_dropdowntranslations']['Supplier']['name'] = true;
            $this->string($read())->isIdenticalTo('Translated contract supplier<br>');
            $connection->update('glpi_dropdowntranslations', ['value' => ''], $translation);
            $this->string($read())->isIdenticalTo('Fresh contract supplier<br>');
            $connection->update('glpi_suppliers', ['name' => ''], ['id' => $supplier_id]);
            $this->string($read())->isIdenticalTo('&nbsp;<br>');
            $this->boolean($relation->delete(['id' => $relation_id]))->isTrue();
            $this->string($read())->isIdenticalTo('');
        } finally {
            $connection->delete('glpi_dropdowntranslations', $translation);
            $connection->update('glpi_suppliers', ['name' => $originalName], ['id' => $supplier_id]);
            $_SESSION = $session;
        }
        $this->integer((int)countElementsInTable(
            Contract_Supplier::getTable(),
            [
                'contracts_id' => $contract_id,
                'suppliers_id' => $supplier_id,
            ]
        ))->isEqualTo(0);
        // Keep the genuine old-runtime failure after every positive output and persisted unlink check.
        $this->integer($created)->isIdenticalTo(0, 'Supplier labels reuse the warm application read manager');
    }

    public function testUpdateClearsOutdatedAlerts()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new ContractModel();
        $contract_id = $contract->add([
           'name'        => 'contract-alert-clear-' . $this->getUniqueString(),
           'entities_id' => 0,
           'begin_date'  => '2025-01-01',
           'duration'    => 12,
           'notice'      => 2,
        ]);
        $this->integer((int)$contract_id)->isGreaterThan(0);

        $alert = new Alert();
        $end_alert_id = $alert->add([
           'itemtype' => 'Contract',
           'items_id' => $contract_id,
           'type'     => Alert::END,
           'date'     => '2025-12-31 00:00:00',
        ]);
        $notice_alert_id = $alert->add([
           'itemtype' => 'Contract',
           'items_id' => $contract_id,
           'type'     => Alert::NOTICE,
           'date'     => '2025-11-30 00:00:00',
        ]);
        $this->integer((int)$end_alert_id)->isGreaterThan(0);
        $this->integer((int)$notice_alert_id)->isGreaterThan(0);

        $this->boolean($contract->update([
           'id'         => $contract_id,
           'begin_date' => '2025-02-01',
           'duration'   => 14,
           'notice'     => 1,
        ]))->isTrue();

        $this->boolean((bool)Alert::alertExists('Contract', $contract_id, Alert::END))->isFalse();
        $this->boolean((bool)Alert::alertExists('Contract', $contract_id, Alert::NOTICE))->isFalse();
    }

    public function testAlertPublisherKeepsCallerSavepointAndQueuedDelivery()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Published);
            $caller->assertActive();
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isTrue();
            // Ajax send() must never be called, including after the savepoint release.
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Skipped);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
            $caller->assertActive();
        });
    }

    public function testAlertPublisherRejectsCommittedAndReopenedHookFrame()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $depth = $connection->getTransactionNestingLevel();
            $calls = 0;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($contract, $template, $connection, $depth, &$replacement, &$calls): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                ++$calls;
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Hook released publisher savepoint']))->isTrue();
                $_SESSION['contract_frame_marker'] = 'commit-reopen';
                // This releases ONLY the publisher savepoint, never DbTestCase's physical transaction.
                $connection->commit();
                $replacement = OwnedMutationFrame::begin($connection);
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload);
            } catch (Throwable $failure) {
                $error = $failure;
            }
            $this->integer($calls)->isIdenticalTo(1);
            $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($error->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->boolean($error->rollbackUnproven)->isTrue();
            $replacement->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
            $this->string($_SESSION['contract_frame_marker'])->isIdenticalTo('commit-reopen');
            $this->string($contract->fields['comment'])->isIdenticalTo('Hook released publisher savepoint');
            $stored = new ContractModel();
            $this->boolean($stored->getFromDB($contract->getID()))->isTrue();
            $this->string($stored->fields['comment'])->isIdenticalTo('Hook released publisher savepoint');
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0, 'A retired publisher scope cannot insert into its replacement');
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherDoesNotRollbackReopenedRefusalFrame()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $depth = $connection->getTransactionNestingLevel();
            $calls = 0;
            $witness = null;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($contract, $template, $connection, $depth, &$replacement, &$witness, &$calls): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                ++$calls;
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                $connection->rollBack();
                $replacement = OwnedMutationFrame::begin($connection);
                // The replacement capability owns this direct ORM fixture.
                $manager = Orm::forConnection($connection);
                try {
                    $witness = new ContractRecord();
                    $witness->name = 'Replacement frame witness';
                    $witness->entities = $manager->getReference(Entity::class, 0);
                    $manager->persist($witness);
                    $manager->flush();
                    $this->integer($witness->id)->isGreaterThan(0);
                } finally {
                    $manager->clear();
                }
                $_SESSION['contract_frame_marker'] = 'rollback-reopen';
                $queue->input = false;
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload);
            } catch (Throwable $failure) {
                $error = $failure;
            }
            $this->integer($calls)->isIdenticalTo(1);
            $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($error->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->boolean($error->rollbackUnproven)->isTrue();
            $replacement->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
            $this->string($_SESSION['contract_frame_marker'])->isIdenticalTo('rollback-reopen');
            $stored = new ContractModel();
            $this->boolean($stored->getFromDB($witness->id))->isTrue();
            $this->checkInput($stored, $witness->id, ['name' => 'Replacement frame witness', 'entities_id' => 0]);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherPreservesPrimaryWhenHookReplacesItsFrame()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $primary = new RuntimeException('Contract hook primary failure');
            $depth = $connection->getTransactionNestingLevel();
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($template, $connection, $depth, $primary, &$replacement): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                $connection->commit();
                $replacement = OwnedMutationFrame::begin($connection);
                $_SESSION['contract_frame_marker'] = 'primary-preserved';
                throw $primary;
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload);
            } catch (Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($error->primary)->isIdenticalTo($primary);
            $this->object($error->cleanup)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->boolean($error->rollbackUnproven)->isTrue();
            $replacement->assertActive();
            $this->string($_SESSION['contract_frame_marker'])->isIdenticalTo('primary-preserved');
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRewindsModelsAndSessionOnlyAfterOwnedVetoRollback()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $before = $contract->fields['comment'];
            $calls = 0;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($contract, $template, &$calls): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                ++$calls;
                $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Owned veto mutation']))->isTrue();
                $_SESSION['contract_frame_marker'] = 'must-rewind';
                Session::addMessageAfterRedirect('Contract frame veto warning', true, WARNING, false);
                $queue->input = false;
            };
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Refused);
            $this->integer($calls)->isIdenticalTo(1);
            $caller->assertActive();
            $this->array($_SESSION)->notHasKey('contract_frame_marker');
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING])->contains('Contract frame veto warning');
            $this->variable($contract->fields['comment'])->isIdenticalTo($before);
            $stored = new ContractModel();
            $this->boolean($stored->getFromDB($contract->getID()))->isTrue();
            $this->variable($stored->fields['comment'])->isIdenticalTo($before);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRetainsPrimaryAfterProvenRollback()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $before = $contract->fields['comment'];
            $primary = new RuntimeException('Owned notification hook failure');
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($contract, $template, $primary): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Owned error mutation']))->isTrue();
                $_SESSION['contract_frame_marker'] = 'must-rewind';
                throw $primary;
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload);
            } catch (Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isIdenticalTo($primary);
            $caller->assertActive();
            $this->array($_SESSION)->notHasKey('contract_frame_marker');
            $this->variable($contract->fields['comment'])->isIdenticalTo($before);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRefusesChangedGlobalWriterBeforeAlertMutation()
    {
        $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][QueuedNotification::class] = function ($queue) use ($template): void {
                if ((int)$queue->input['notificationtemplates_id'] === $template) {
                    $GLOBALS['DB'] = clone $GLOBALS['DB'];
                    $queue->input = false;
                }
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', Alert::END, 0, $payload);
            } catch (Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->string($error->getMessage())->isIdenticalTo('The owned lifecycle changed its supplied writer.');
            $caller->assertActive();
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRejectsForeignWriterBeforeNonVetoedPersistence(): void
    {
        foreach ([QueuedNotification::class, Alert::class] as $phase) {
            // Each trial owns its notification fixtures as well as its dispatch.
            $fixture = OwnedMutationFrame::begin($GLOBALS['DB']->getDoctrineConnection());
            try {
                $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller) use ($phase): void {
                    global $DB, $PLUGIN_HOOKS;
                    $writer = $DB;
                    // The real foreign DBAL driver refuses every native connection.
                    // A missed producer boundary therefore cannot contact another database.
                    $foreign = new DisconnectedSchemaConnection($connection->getDatabasePlatform());
                    $routed = clone $writer;
                    (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($routed, $foreign);
                    $depth = $connection->getTransactionNestingLevel();
                    $stored = $contract->fields;
                    $snapshot = static function () use ($writer): array {
                        $rows = [];
                        foreach (['glpi_contracts', 'glpi_alerts', 'glpi_queuednotifications', 'glpi_logs'] as $table) {
                            $rows[$table] = iterator_to_array($writer->request(['FROM' => $table, 'ORDER' => 'id']));
                        }
                        return $rows;
                    };
                    $before = $snapshot();
                    $calls = 0;
                    $PLUGIN_HOOKS['pre_item_add']['contractframe'][$phase] = function ($item) use ($phase, $template, $contract, $routed, &$calls): void {
                        $selected = $phase === QueuedNotification::class
                            ? (int)$item->input['notificationtemplates_id'] === $template
                            : $item->input['itemtype'] === 'Contract' && (int)$item->input['items_id'] === (int)$contract->getID();
                        if (!$selected) {
                            return;
                        }
                        ++$calls;
                        $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Owned change before foreign route']))->isTrue();
                        $GLOBALS['DB'] = $routed;
                        // Keep the valid public add input intact: this is not a veto.
                    };
                    $error = null;
                    try {
                        (new ContractAlertPublisher($writer))->publish('end', Alert::END, 0, $payload);
                    } catch (Throwable $failure) {
                        $error = $failure;
                    } finally {
                        $DB = $writer;
                        unset($PLUGIN_HOOKS['pre_item_add']['contractframe'][$phase]);
                    }
                    $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                    $this->integer($calls)->isIdenticalTo(1);
                    $this->boolean($foreign->isConnected())->isFalse();
                    $caller->assertActive();
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                    $this->boolean($snapshot() === $before)->isTrue();
                    $this->array($contract->fields)->isIdenticalTo($stored);
                    // A subsequent ordinary dispatch proves the scoped guard was removed.
                    $this->variable((new ContractAlertPublisher($writer))->publish('end', Alert::END, 0, $payload))
                        ->isIdenticalTo(ContractAlertOutcome::Published);
                    $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
                    $this->boolean((bool)Alert::alertExists('Contract', $contract->getID(), Alert::END))->isTrue();
                    $caller->assertActive();
                });
            } finally {
                $fixture->rollBack();
            }
        }
    }

    public function testAlertPublisherPreservesIndependentPhysicalWriterRows(): void
    {
        foreach ([QueuedNotification::class, Alert::class] as $phase) {
            $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller) use ($phase): void {
                global $DB, $PLUGIN_HOOKS;
                $writer = $DB;
                $foreign = $writer->getProvider() === 'pgsql'
                    ? PostgresConnection::create($connection->getParams())
                    : MySQLConnection::create($connection->getParams());
                $foreignFrame = null;
                $manager = null;
                try {
                    // This independent writer owns only its rollback-only fixture.
                    // References are local to it, so no cross-connection FK wait is needed.
                    $foreignFrame = OwnedMutationFrame::begin($foreign);
                    $manager = Orm::forConnection($foreign);
                    $foreignContract = new ContractRecord();
                    $foreignContract->entities = $manager->getReference(Entity::class, 0);
                    $foreignContract->name = 'Independent contract alert route';
                    $foreignTemplate = new TemplateRecord();
                    $foreignTemplate->name = 'Independent contract alert template';
                    $foreignTemplate->itemtype = 'Contract';
                    $manager->persist($foreignContract);
                    $manager->persist($foreignTemplate);
                    $manager->flush();
                    $routed = clone $writer;
                    (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($routed, $foreign);
                    $snapshot = static function (Connection $reader): array {
                        $rows = [];
                        foreach (['glpi_contracts', 'glpi_notificationtemplates', 'glpi_alerts', 'glpi_queuednotifications', 'glpi_logs'] as $table) {
                            $rows[$table] = $reader->fetchAllAssociative('SELECT * FROM ' . $reader->quoteIdentifier($table) . ' ORDER BY id');
                        }
                        return $rows;
                    };
                    $before = $snapshot($connection);
                    $foreignBefore = $snapshot($foreign);
                    $stored = $contract->fields;
                    $depth = $connection->getTransactionNestingLevel();
                    $calls = 0;
                    $PLUGIN_HOOKS['pre_item_add']['contractframe'][$phase] = function ($item) use ($phase, $template, $contract, $foreignContract, $foreignTemplate, $routed, &$calls): void {
                        $selected = $phase === QueuedNotification::class
                            ? (int)$item->input['notificationtemplates_id'] === $template
                            : $item->input['itemtype'] === 'Contract' && (int)$item->input['items_id'] === (int)$contract->getID();
                        if (!$selected) {
                            return;
                        }
                        ++$calls;
                        $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Original writer must roll back']))->isTrue();
                        if ($phase === QueuedNotification::class) {
                            $item->input['notificationtemplates_id'] = $foreignTemplate->id;
                        }
                        $item->input['items_id'] = $foreignContract->id;
                        $GLOBALS['DB'] = $routed;
                    };
                    $error = null;
                    try {
                        (new ContractAlertPublisher($writer))->publish('end', Alert::END, 0, $payload);
                    } catch (Throwable $failure) {
                        $error = $failure;
                    } finally {
                        $DB = $writer;
                        unset($PLUGIN_HOOKS['pre_item_add']['contractframe'][$phase]);
                    }
                    $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                    $this->integer($calls)->isIdenticalTo(1);
                    $caller->assertActive();
                    $foreignFrame->assertActive();
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                    $this->boolean($snapshot($connection) === $before)->isTrue();
                    $this->array($contract->fields)->isIdenticalTo($stored);
                    $this->boolean($snapshot($foreign) === $foreignBefore)->isTrue();
                } finally {
                    $DB = $writer;
                    unset($PLUGIN_HOOKS['pre_item_add']['contractframe'][$phase]);
                    $manager?->clear();
                    try {
                        $foreignFrame?->rollBack();
                    } finally {
                        $foreign->close();
                    }
                }
            });
        }
    }

    public function testAlertPublisherJournalsGuardGetterMutationsAndRemovesFailedGuard(): void
    {
        foreach (['foreign', 'throw'] as $mode) {
            // Each trial owns its notification fixtures as well as its dispatch.
            $fixture = OwnedMutationFrame::begin($GLOBALS['DB']->getDoctrineConnection());
            try {
                $this->withAlertNotification(function (ContractModel $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller) use ($mode): void {
                    global $DB;
                    $writer = $DB;
                    $foreign = new DisconnectedSchemaConnection($connection->getDatabasePlatform());
                    $routed = clone $writer;
                    (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($routed, $foreign);
                    $probe = (object)['armed' => true, 'calls' => 0];
                    $primary = new RuntimeException('Guard getter refused after owned model change');
                    $route = function () use ($probe, $contract, $connection, $routed, $mode, $primary): Connection {
                        $guardCheck = false;
                        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
                            if (($frame['class'] ?? null) === OwnershipUpdateUnit::class
                                && in_array($frame['function'] ?? null, ['registerWriterGuard', 'withWriterGuard'], true)) {
                                $guardCheck = true;
                            }
                        }
                        if ($probe->armed && $guardCheck) {
                            $probe->armed = false;
                            ++$probe->calls;
                            $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Guard getter model change']))->isTrue();
                            if ($mode === 'throw') {
                                throw $primary;
                            }
                            $GLOBALS['DB'] = $routed;
                            // The getter invokes an inherited producer, not raw plugin SQL.
                            (new Alert())->add(['itemtype' => 'Contract', 'items_id' => $contract->getID(), 'type' => Alert::END]);
                        }
                        return $connection;
                    };
                    $owner = $writer->getProvider() === 'pgsql'
                        ? new class ($route) extends DBpgsql {
                            public function __construct(private Closure $route)
                            {
                                $this->connected = true;
                            }

                            public function getDoctrineConnection(): PostgresConnection
                            {
                                return ($this->route)();
                            }
                        }
                    : new class ($route) extends DBmysql {
                        public function __construct(private Closure $route)
                        {
                        }

                        public function getDoctrineConnection(): Connection
                        {
                            return ($this->route)();
                        }
                    };
                    $snapshot = static function () use ($connection): array {
                        $rows = [];
                        foreach (['glpi_contracts', 'glpi_alerts', 'glpi_queuednotifications', 'glpi_logs'] as $table) {
                            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
                        }
                        return $rows;
                    };
                    $before = $snapshot();
                    $stored = $contract->fields;
                    $depth = $connection->getTransactionNestingLevel();
                    $error = null;
                    try {
                        $DB = $owner;
                        (new ContractAlertPublisher($owner))->publish('end', Alert::END, 0, $payload);
                    } catch (Throwable $failure) {
                        $error = $failure;
                    } finally {
                        $DB = $writer;
                    }
                    if ($mode === 'throw') {
                        $this->variable($error)->isIdenticalTo($primary);
                    } else {
                        $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                    }
                    $this->integer($probe->calls)->isIdenticalTo(1);
                    $this->boolean($foreign->isConnected())->isFalse();
                    $this->boolean($snapshot() === $before)->isTrue();
                    $this->array($contract->fields)->isIdenticalTo($stored);
                    $caller->assertActive();
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                    $this->variable((new ContractAlertPublisher($writer))->publish('end', Alert::END, 0, $payload))
                        ->isIdenticalTo(ContractAlertOutcome::Published);
                    $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
                    $caller->assertActive();
                });
            } finally {
                $fixture->rollBack();
            }
        }
    }

    /** A real registered Ajax queue hook runs inside a caller-owned SAVEPOINT. */
    private function withAlertNotification(callable $test): void
    {
        global $DB, $CFG_GLPI, $PLUGIN_HOOKS;
        $writer = $DB;
        $connection = $writer->getDoctrineConnection();
        // DbTestCase owns the physical transaction: these tests never commit it.
        $this->integer($connection->getTransactionNestingLevel())->isGreaterThan(0);
        $outer = $connection->captureManagedTransactionScope();
        $depth = $connection->getTransactionNestingLevel();
        $configuration = $CFG_GLPI;
        $hooks = $PLUGIN_HOOKS;
        $session = $_SESSION;
        $pluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $plugins = $pluginProperty->getValue();
        $caller = $replacement = null;
        $failure = null;
        try {
            $CFG_GLPI['use_notifications'] = false;
            $this->login();
            $contract = $this->createItem(ContractModel::class, [
                'name' => 'Contract frame ' . $this->getUniqueString(),
                'entities_id' => 0,
                'begin_date' => '2025-01-01',
                'duration' => 12,
                'notice' => 1,
            ]);
            $this->boolean($contract->getFromDB($contract->getID()))->isTrue();
            $template = $this->createItem(NotificationTemplate::class, ['name' => 'Contract frame template', 'itemtype' => 'Contract']);
            $this->createItem(NotificationTemplateTranslation::class, [
                'notificationtemplates_id' => $template->getID(),
                'language' => '',
                'subject' => 'Contract frame',
                'content_text' => '##FOREACHcontracts####contract.name####ENDFOREACHcontracts##',
                'content_html' => '',
            ]);
            $notification = $this->createItem(Notification::class, [
                'name' => 'Contract frame notification',
                'itemtype' => 'Contract',
                'event' => 'end',
                'entities_id' => 0,
                'is_active' => 1,
            ]);
            $this->createItem(Notification_NotificationTemplate::class, [
                'notifications_id' => $notification->getID(),
                'notificationtemplates_id' => $template->getID(),
                'mode' => Notification_NotificationTemplate::MODE_AJAX,
            ]);
            $recipients = $this->createItem(Group::class, [
                'name' => 'Contract frame recipients ' . $this->getUniqueString(),
                'entities_id' => 0,
            ]);
            $this->createItem(Group_User::class, [
                'groups_id' => $recipients->getID(),
                'users_id' => getItemByTypeName('User', TU_USER, true),
            ]);
            $this->createItem(NotificationTarget::class, [
                'notifications_id' => $notification->getID(),
                'type' => Notification::GROUP_TYPE,
                'items_id' => $recipients->getID(),
            ]);
            $CFG_GLPI['use_notifications'] = true;
            $CFG_GLPI['notifications_mailing'] = false;
            $CFG_GLPI['notifications_chat'] = false;
            $CFG_GLPI['notifications_ajax'] = true;
            $_SESSION['glpinotification_to_myself'] = true;
            unset($_SESSION['contract_frame_marker']);
            $pluginProperty->setValue(null, [...$plugins, 'contractframe']);
            $caller = OwnedMutationFrame::begin($connection);
            $test($contract, [$contract->getID() => $contract->fields], (int)$template->getID(), $connection, $caller, $replacement);
        } catch (Throwable $primary) {
            $failure = $primary;
        } finally {
            // Replacement and caller capabilities are cleaned independently;
            // no depth loop may roll back DbTestCase or somebody else's frame.
            foreach ([$replacement, $caller] as $frame) {
                if ($frame === null) {
                    continue;
                }
                try {
                    $frame->rollBack();
                } catch (Throwable $cleanup) {
                    $failure = $failure === null ? $cleanup : new MutationRollbackFailure($failure, $cleanup);
                }
            }
            $DB = $writer;
            $CFG_GLPI = $configuration;
            $PLUGIN_HOOKS = $hooks;
            $_SESSION = $session;
            $pluginProperty->setValue(null, $plugins);
        }
        if ($failure !== null) {
            throw $failure;
        }
        $outer->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        // Actual inserted identities are consumed normally; no sequence reset.
    }

    private function alertQueueCount(int $template): int
    {
        return (int)countElementsInTable(QueuedNotification::getTable(), ['notificationtemplates_id' => $template]);
    }
}
