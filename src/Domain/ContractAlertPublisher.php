<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Alert as AlertModel;
use Contract as ContractModel;
use DateTimeInterface;
use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use itsmng\Database\Entity\Alert as AlertRecord;
use itsmng\Database\Entity\Contract as ContractRecord;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\LifecycleNotifications;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;
use LogicException;
use NotificationEvent;
use Throwable;

/** Public dispatch and Alert lifecycle share the active writer transaction; transport stays queued. */
final class ContractAlertPublisher
{
    public function __construct(private DBAdapter $database)
    {
    }

    /** @param array<int, array<string, mixed>> $contracts Selected contract payloads, including the previous periodic alert date. */
    public function publish(string $event, int $alertType, int $entity, array $contracts, bool $replacePrevious = false): ContractAlertOutcome
    {
        global $DB;
        if ($DB !== $this->database) {
            throw new LogicException('Contract notification hooks must use the supplied active connection');
        }
        if ($this->database->isSlave() || !$contracts) {
            return ContractAlertOutcome::Refused;
        }
        $connection = $this->database->getDoctrineConnection();
        TransactionOwnership::assertManaged($connection);
        $frame = null;
        $rolledBack = false;
        $rollbackAttempted = false;
        $journal = new LifecycleModelJournal();
        $delivery = LifecycleNotifications::begin($connection);
        $failure = null;
        $outcome = ContractAlertOutcome::Refused;
        $session = $_SESSION;
        $restoreFeedback = static function () use ($session): void {
            $feedback = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
            $_SESSION = $session;
            foreach ([WARNING, ERROR] as $type) {
                foreach (array_diff($feedback[$type] ?? [], $session['MESSAGE_AFTER_REDIRECT'][$type] ?? []) as $message) {
                    $_SESSION['MESSAGE_AFTER_REDIRECT'][$type][] = $message;
                }
            }
        };
        $accepted = false;
        try {
            $frame = OwnedMutationFrame::begin($connection);
            $outcome = $journal->observe(
                $connection,
                fn (): ContractAlertOutcome => OwnershipUpdateUnit::withWriterGuard(
                    $this->database,
                    $connection,
                    function () use ($frame, $connection, $event, $alertType, $entity, $contracts, $replacePrevious): ContractAlertOutcome {
                        $manager = Orm::create($this->database);
                        try {
                            $ids = array_keys($contracts);
                            sort($ids, SORT_NUMERIC);
                            foreach ($ids as $id) {
                                $contract = $manager->find(ContractRecord::class, (int)$id, LockMode::PESSIMISTIC_WRITE);
                                if ($contract === null || $contract->entities?->id !== $entity) {
                                    return ContractAlertOutcome::Refused;
                                }
                                $selected = $contracts[$id];
                                $current = (new RecordRepository($manager))->toRow($contract);
                                foreach (['begin_date', 'duration', 'notice', 'periodicity', 'alert', 'is_deleted'] as $field) {
                                    if (($selected[$field] ?? null) !== $current[$field]) {
                                        return ContractAlertOutcome::Skipped;
                                    }
                                }
                                $previous = $manager->createQueryBuilder()
                                    ->select('a')
                                    ->from(AlertRecord::class, 'a')
                                    ->where('a.contract = :contract AND a.type = :type')
                                    ->setParameter('contract', $contract)
                                    ->setParameter('type', $alertType)
                                    ->getQuery()
                                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                                    ->getOneOrNullResult();
                                if (!$replacePrevious && $previous !== null) {
                                    return ContractAlertOutcome::Skipped;
                                }
                                if ($replacePrevious) {
                                    $expected = $contracts[$id][$alertType === AlertModel::NOTICE ? 'last_notice' : 'last_period'] ?? null;
                                    $expected = $expected instanceof DateTimeInterface ? $expected->format('Y-m-d H:i:s') : $expected;
                                    if ($previous?->date?->format('Y-m-d H:i:s') !== $expected) {
                                        return ContractAlertOutcome::Skipped;
                                    }
                                }
                            }
                        } finally {
                            $manager->clear();
                        }
                        $this->assertActive($frame, $connection);
                        $notified = NotificationEvent::raiseEvent($event, new ContractModel(), ['entities_id' => $entity, 'items' => $contracts]);
                        // Refusal is not permission to unwind a replacement callback frame.
                        $this->assertActive($frame, $connection);
                        if (!$notified) {
                            return ContractAlertOutcome::Refused;
                        }
                        foreach ($contracts as $id => $contract) {
                            $alert = new AlertModel();
                            $this->assertActive($frame, $connection);
                            if ($replacePrevious) {
                                $cleared = $alert->clear('Contract', $id, $alertType);
                                $this->assertActive($frame, $connection);
                                if (!$cleared) {
                                    return ContractAlertOutcome::Refused;
                                }
                            }
                            $added = $alert->add(['itemtype' => 'Contract', 'items_id' => $id, 'type' => $alertType]);
                            $this->assertActive($frame, $connection);
                            if (!$added) {
                                return ContractAlertOutcome::Refused;
                            }
                        }
                        return ContractAlertOutcome::Published;
                    }
                )
            );
            $this->assertActive($frame, $connection);
            if ($outcome === ContractAlertOutcome::Published) {
                $frame->commit();
                $accepted = true;
            } else {
                $rollbackAttempted = true;
                $frame->rollBack();
                $rolledBack = true;
            }
        } catch (Throwable $primary) {
            $failure = $primary;
            if ($frame !== null && !$rollbackAttempted) {
                try {
                    $rollbackAttempted = true;
                    $frame->rollBack();
                    $rolledBack = true;
                } catch (Throwable $cleanup) {
                    $failure = new MutationRollbackFailure($primary, $cleanup);
                }
            }
        } finally {
            try {
                // Contract alarms retain queue-only transport, including when
                // this frame released a savepoint in a caller-owned transaction.
                $delivery->finish($accepted);
            } catch (Throwable $cleanup) {
                $failure = self::preserveFailure($failure, $cleanup);
            }
            if ($rolledBack) {
                try {
                    $journal->restore();
                } catch (Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
                try {
                    $restoreFeedback();
                } catch (Throwable $cleanup) {
                    $failure = self::preserveFailure($failure, $cleanup);
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $outcome;
    }

    private function assertActive(OwnedMutationFrame $frame, Connection $connection): void
    {
        if (($GLOBALS['DB'] ?? null) !== $this->database || $this->database->getDoctrineConnection() !== $connection) {
            throw new TransactionOwnershipMismatch('A contract notification hook changed the supplied active writer.');
        }
        $frame->assertActive();
    }

    private static function preserveFailure(?Throwable $primary, Throwable $cleanup): Throwable
    {
        return $primary === null ? $cleanup : new MutationCleanupFailure(
            $primary,
            $cleanup,
            $primary instanceof MutationCleanupFailure && $primary->rollbackUnproven
        );
    }
}
