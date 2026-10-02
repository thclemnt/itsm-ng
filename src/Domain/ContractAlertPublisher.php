<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\DBAL\LockMode;
use itsmng\Database\Entity\Contract;
use itsmng\Database\Orm;

/** Public dispatch and Alert lifecycle share the active writer transaction; transport stays queued. */
final class ContractAlertPublisher
{
    public function __construct(private \DBAdapter $database)
    {
    }

    /** @param array<int, array<string, mixed>> $contracts Selected contract payloads, including the previous periodic alert date. */
    public function publish(string $event, int $alertType, int $entity, array $contracts, bool $replacePrevious = false): ContractAlertOutcome
    {
        global $DB;
        if ($DB !== $this->database) {
            throw new \LogicException('Contract notification hooks must use the supplied active connection');
        }
        if ($this->database->isSlave() || !$contracts) {
            return ContractAlertOutcome::Refused;
        }
        $connection = $this->database->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
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
            $connection->beginTransaction();
            $manager = Orm::create($this->database);
            try {
                $ids = array_keys($contracts);
                sort($ids, SORT_NUMERIC);
                foreach ($ids as $id) {
                    $contract = $manager->find(Contract::class, (int)$id, LockMode::PESSIMISTIC_WRITE);
                    if ($contract === null || $contract->entities?->id !== $entity) {
                        return ContractAlertOutcome::Refused;
                    }
                    $selected = $contracts[$id];
                    $current = (new \itsmng\Database\Repository\RecordRepository($manager))->toRow($contract);
                    foreach (['begin_date', 'duration', 'notice', 'periodicity', 'alert', 'is_deleted'] as $field) {
                        if (($selected[$field] ?? null) !== $current[$field]) {
                            return ContractAlertOutcome::Skipped;
                        }
                    }
                    $previous = $manager->createQueryBuilder()->select('a')->from(\itsmng\Database\Entity\Alert::class, 'a')
                        ->where('a.contract = :contract AND a.type = :type')->setParameter('contract', $contract)
                        ->setParameter('type', $alertType)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
                    if (!$replacePrevious && $previous !== null) {
                        return ContractAlertOutcome::Skipped;
                    }
                    if ($replacePrevious) {
                        $expected = $contracts[$id][$alertType === \Alert::NOTICE ? 'last_notice' : 'last_period'] ?? null;
                        $expected = $expected instanceof \DateTimeInterface ? $expected->format('Y-m-d H:i:s') : $expected;
                        if ($previous?->date?->format('Y-m-d H:i:s') !== $expected) {
                            return ContractAlertOutcome::Skipped;
                        }
                    }
                }
            } finally {
                $manager->clear();
            }
            if (!\NotificationEvent::raiseEvent($event, new \Contract(), ['entities_id' => $entity, 'items' => $contracts])) {
                return ContractAlertOutcome::Refused;
            }
            foreach ($contracts as $id => $contract) {
                $alert = new \Alert();
                if ($replacePrevious && !$alert->clear('Contract', $id, $alertType)) {
                    return ContractAlertOutcome::Refused;
                }
                if (!$alert->add(['itemtype' => 'Contract', 'items_id' => $id, 'type' => $alertType])) {
                    return ContractAlertOutcome::Refused;
                }
            }
            if ($connection->getTransactionNestingLevel() !== $level + 1) {
                throw new \LogicException('A contract notification hook changed transaction ownership');
            }
            $connection->commit();
            $accepted = true;
            return ContractAlertOutcome::Published;
        } finally {
            if (!$accepted) {
                while ($connection->getTransactionNestingLevel() > $level) {
                    $connection->rollBack();
                }
                $restoreFeedback();
            }
        }
    }
}
