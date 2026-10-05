<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\Entity\QueuedNotification;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationQueueRepository;

/** Recipient-owned presentation is distinct from queue admission and transport delivery. */
final class BrowserNotificationInbox
{
    public function __construct(private \DBAdapter $database)
    {
    }

    /** @return list<QueuedNotification> */
    public function pending(int $recipient): array
    {
        return (new NotificationQueueRepository(Orm::create($this->database)))->browserInbox($recipient);
    }

    public function acknowledge(int $message, int $recipient): bool
    {
        if ($message <= 0 || $recipient <= 0) {
            return false;
        }
        if ($this->database->isSlave()) {
            throw new \LogicException('Browser notification acknowledgement requires the supplied writer.');
        }
        return $this->database->getDoctrineConnection()->transactional(function () use ($message, $recipient): bool {
            // The manager starts inside this transaction and cannot retain a prior replica/snapshot row.
            $em = Orm::create($this->database);
            $record = (new NotificationQueueRepository($em))->browserMessageForAcknowledgement($message, $recipient);
            if ($record === null || !$record->acknowledgeBrowserMessage($recipient, new \DateTimeImmutable())) {
                return false;
            }
            $em->flush();
            return true;
        });
    }
}
