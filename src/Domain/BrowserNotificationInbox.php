<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DBAdapter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManager;
use LogicException;
use itsmng\Database\Entity\QueuedNotification;
use itsmng\Database\Orm;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\NotificationQueueRepository;

/** Recipient-owned presentation is distinct from queue admission and transport delivery. */
final class BrowserNotificationInbox
{
    public function __construct(private DBAdapter $database)
    {
    }

    /** @return list<BrowserNotificationMessage> */
    public function pending(int $recipient): array
    {
        return Orm::read($this->database, static fn (EntityManager $manager): array => array_map(
            static fn (QueuedNotification $message): BrowserNotificationMessage => new BrowserNotificationMessage(
                $message->id, $message->itemtype, $message->items_id, $message->name, $message->body_text
            ),
            (new NotificationQueueRepository($manager))->browserInbox($recipient)
        ));
    }

    public function acknowledge(int $message, int $recipient): bool
    {
        if ($message <= 0 || $recipient <= 0) {
            return false;
        }
        if ($this->database->isSlave()) {
            throw new LogicException('Browser notification acknowledgement requires the supplied writer.');
        }
        $database = $this->database;
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return $connection->transactional(static fn (): bool => Orm::withConnection(
            $connection,
            static function (EntityManager $manager) use ($database, $connection, $message, $recipient): bool {
                OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
                $record = (new NotificationQueueRepository($manager))->browserMessageForAcknowledgement($message, $recipient);
                if ($record === null || !$record->acknowledgeBrowserMessage($recipient, new DateTimeImmutable())) {
                    return false;
                }
                $manager->flush();
                return true;
            }
        ));
    }
}
