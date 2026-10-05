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
use Doctrine\DBAL\Connection;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\ContractAlertOutcome;
use itsmng\Domain\ContractAlertPublisher;

/* Test for inc/contract.class.php */

class Contract extends DbTestCase
{
    public function testClone()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new \Contract();
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

        $link_supplier = new \Contract_Supplier();
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
    }

    public function testContractSupplierLinkUnlink()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new \Contract();
        $contract_id = $contract->add([
           'name'        => 'contract-supplier-link',
           'entities_id' => 0,
        ]);
        $this->integer((int)$contract_id)->isGreaterThan(0);

        $supplier_id = getItemByTypeName('Supplier', '_suplier01_name', true);
        $this->integer((int)$supplier_id)->isGreaterThan(0);

        $relation = new \Contract_Supplier();
        $relation_id = $relation->add([
           'contracts_id' => $contract_id,
           'suppliers_id' => $supplier_id,
        ]);
        $this->integer((int)$relation_id)->isGreaterThan(0);
        $this->boolean($relation->getFromDB($relation_id))->isTrue();

        $this->boolean($relation->delete(['id' => $relation_id]))->isTrue();
        $this->integer((int)countElementsInTable(
            \Contract_Supplier::getTable(),
            [
                'contracts_id' => $contract_id,
                'suppliers_id' => $supplier_id,
            ]
        ))->isEqualTo(0);
    }

    public function testUpdateClearsOutdatedAlerts()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $contract = new \Contract();
        $contract_id = $contract->add([
           'name'        => 'contract-alert-clear-' . $this->getUniqueString(),
           'entities_id' => 0,
           'begin_date'  => '2025-01-01',
           'duration'    => 12,
           'notice'      => 2,
        ]);
        $this->integer((int)$contract_id)->isGreaterThan(0);

        $alert = new \Alert();
        $end_alert_id = $alert->add([
           'itemtype' => 'Contract',
           'items_id' => $contract_id,
           'type'     => \Alert::END,
           'date'     => '2025-12-31 00:00:00',
        ]);
        $notice_alert_id = $alert->add([
           'itemtype' => 'Contract',
           'items_id' => $contract_id,
           'type'     => \Alert::NOTICE,
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

        $this->boolean((bool)\Alert::alertExists('Contract', $contract_id, \Alert::END))->isFalse();
        $this->boolean((bool)\Alert::alertExists('Contract', $contract_id, \Alert::NOTICE))->isFalse();
    }

    public function testAlertPublisherKeepsCallerSavepointAndQueuedDelivery()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Published);
            $caller->assertActive();
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isTrue();
            // Ajax send() must never be called, including after the savepoint release.
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Skipped);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
            $caller->assertActive();
        });
    }

    public function testAlertPublisherRejectsCommittedAndReopenedHookFrame()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $depth = $connection->getTransactionNestingLevel();
            $calls = 0;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($contract, $template, $connection, $depth, &$replacement, &$calls): void {
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
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload);
            } catch (\Throwable $failure) {
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
            $stored = new \Contract();
            $this->boolean($stored->getFromDB($contract->getID()))->isTrue();
            $this->string($stored->fields['comment'])->isIdenticalTo('Hook released publisher savepoint');
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(1);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherDoesNotRollbackReopenedRefusalFrame()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $depth = $connection->getTransactionNestingLevel();
            $calls = 0;
            $witness = null;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($contract, $template, $connection, $depth, &$replacement, &$witness, &$calls): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                ++$calls;
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
                $connection->rollBack();
                $replacement = OwnedMutationFrame::begin($connection);
                $witness = $this->createItem(\Contract::class, ['name' => 'Replacement frame witness', 'entities_id' => 0]);
                $_SESSION['contract_frame_marker'] = 'rollback-reopen';
                $queue->input = false;
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload);
            } catch (\Throwable $failure) {
                $error = $failure;
            }
            $this->integer($calls)->isIdenticalTo(1);
            $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($error->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->boolean($error->rollbackUnproven)->isTrue();
            $replacement->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth + 1);
            $this->string($_SESSION['contract_frame_marker'])->isIdenticalTo('rollback-reopen');
            $stored = new \Contract();
            $this->boolean($stored->getFromDB($witness->getID()))->isTrue();
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherPreservesPrimaryWhenHookReplacesItsFrame()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller, ?OwnedMutationFrame &$replacement): void {
            global $PLUGIN_HOOKS;
            $primary = new \RuntimeException('Contract hook primary failure');
            $depth = $connection->getTransactionNestingLevel();
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($template, $connection, $depth, $primary, &$replacement): void {
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
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload);
            } catch (\Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($error->primary)->isIdenticalTo($primary);
            $this->object($error->cleanup)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->boolean($error->rollbackUnproven)->isTrue();
            $replacement->assertActive();
            $this->string($_SESSION['contract_frame_marker'])->isIdenticalTo('primary-preserved');
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRewindsModelsAndSessionOnlyAfterOwnedVetoRollback()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $before = $contract->fields['comment'];
            $calls = 0;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($contract, $template, &$calls): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                ++$calls;
                $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Owned veto mutation']))->isTrue();
                $_SESSION['contract_frame_marker'] = 'must-rewind';
                \Session::addMessageAfterRedirect('Contract frame veto warning', true, WARNING, false);
                $queue->input = false;
            };
            $this->variable((new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload))
                ->isIdenticalTo(ContractAlertOutcome::Refused);
            $this->integer($calls)->isIdenticalTo(1);
            $caller->assertActive();
            $this->array($_SESSION)->notHasKey('contract_frame_marker');
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING])->contains('Contract frame veto warning');
            $this->variable($contract->fields['comment'])->isIdenticalTo($before);
            $stored = new \Contract();
            $this->boolean($stored->getFromDB($contract->getID()))->isTrue();
            $this->variable($stored->fields['comment'])->isIdenticalTo($before);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRetainsPrimaryAfterProvenRollback()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $before = $contract->fields['comment'];
            $primary = new \RuntimeException('Owned notification hook failure');
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($contract, $template, $primary): void {
                if ((int)$queue->input['notificationtemplates_id'] !== $template) {
                    return;
                }
                $this->boolean($contract->update(['id' => $contract->getID(), 'comment' => 'Owned error mutation']))->isTrue();
                $_SESSION['contract_frame_marker'] = 'must-rewind';
                throw $primary;
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload);
            } catch (\Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isIdenticalTo($primary);
            $caller->assertActive();
            $this->array($_SESSION)->notHasKey('contract_frame_marker');
            $this->variable($contract->fields['comment'])->isIdenticalTo($before);
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
    }

    public function testAlertPublisherRefusesChangedGlobalWriterBeforeAlertMutation()
    {
        $this->withAlertNotification(function (\Contract $contract, array $payload, int $template, Connection $connection, OwnedMutationFrame $caller): void {
            global $PLUGIN_HOOKS;
            $PLUGIN_HOOKS['pre_item_add']['contractframe'][\QueuedNotification::class] = function ($queue) use ($template): void {
                if ((int)$queue->input['notificationtemplates_id'] === $template) {
                    $GLOBALS['DB'] = clone $GLOBALS['DB'];
                    $queue->input = false;
                }
            };
            $error = null;
            try {
                (new ContractAlertPublisher($GLOBALS['DB']))->publish('end', \Alert::END, 0, $payload);
            } catch (\Throwable $failure) {
                $error = $failure;
            }
            $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->string($error->getMessage())->contains('supplied active writer');
            $caller->assertActive();
            $this->integer($this->alertQueueCount($template))->isIdenticalTo(0);
            $this->boolean((bool)\Alert::alertExists('Contract', $contract->getID(), \Alert::END))->isFalse();
        });
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
        $pluginProperty = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $plugins = $pluginProperty->getValue();
        $caller = $replacement = null;
        $failure = null;
        try {
            $CFG_GLPI['use_notifications'] = false;
            $this->login();
            $contract = $this->createItem(\Contract::class, [
                'name' => 'Contract frame ' . $this->getUniqueString(),
                'entities_id' => 0,
                'begin_date' => '2025-01-01',
                'duration' => 12,
                'notice' => 1,
            ]);
            $this->boolean($contract->getFromDB($contract->getID()))->isTrue();
            $template = $this->createItem(\NotificationTemplate::class, ['name' => 'Contract frame template', 'itemtype' => 'Contract']);
            $this->createItem(\NotificationTemplateTranslation::class, [
                'notificationtemplates_id' => $template->getID(),
                'language' => '',
                'subject' => 'Contract frame',
                'content_text' => '##FOREACHcontracts####contract.name####ENDFOREACHcontracts##',
            ]);
            $notification = $this->createItem(\Notification::class, [
                'name' => 'Contract frame notification',
                'itemtype' => 'Contract',
                'event' => 'end',
                'entities_id' => 0,
                'is_active' => 1,
            ]);
            $this->createItem(\Notification_NotificationTemplate::class, [
                'notifications_id' => $notification->getID(),
                'notificationtemplates_id' => $template->getID(),
                'mode' => \Notification_NotificationTemplate::MODE_AJAX,
            ]);
            $recipients = $this->createItem(\Group::class, [
                'name' => 'Contract frame recipients ' . $this->getUniqueString(),
                'entities_id' => 0,
            ]);
            $this->createItem(\Group_User::class, [
                'groups_id' => $recipients->getID(),
                'users_id' => getItemByTypeName('User', TU_USER, true),
            ]);
            $this->createItem(\NotificationTarget::class, [
                'notifications_id' => $notification->getID(),
                'type' => \Notification::GROUP_TYPE,
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
        } catch (\Throwable $primary) {
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
                } catch (\Throwable $cleanup) {
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
        return (int)countElementsInTable(\QueuedNotification::getTable(), ['notificationtemplates_id' => $template]);
    }
}
