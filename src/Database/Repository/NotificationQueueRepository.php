<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\LockMode;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Queue persistence only: delivery and channel policy remain in their services. */
final class NotificationQueueRepository
{
    private const KINDS = [
        'notification' => Entity\QueuedNotification::class,
        'chat' => Entity\QueuedChat::class,
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function duplicateIds(string $kind, array $criteria): array
    {
        $class = $this->entityClass($kind);
        $query = $this->em->createQueryBuilder()->select('r.id')->from($class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata($class)))->where(['is_deleted' => false] + $criteria));
        return array_column($query->orderBy('r.id')->getQuery()->getScalarResult(), 'id');
    }

    /** @return list<Entity\QueuedNotification> */
    public function browserInbox(int $recipient): array
    {
        if ($recipient <= 0) {
            return [];
        }
        return $this->browserSelection($recipient)->orderBy('r.id')->getQuery()->getResult();
    }

    /** Caller owns a writer transaction; the lock closes a concurrent presentation race. */
    public function browserMessageForAcknowledgement(int $id, int $recipient): ?Entity\QueuedNotification
    {
        if ($id <= 0 || $recipient <= 0) {
            return null;
        }
        return $this->browserSelection($recipient)->andWhere('r.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    private function browserSelection(int $recipient): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('r')->from(Entity\QueuedNotification::class, 'r')
            ->where('r.mode = :mode AND r.recipient = :recipient AND r.is_deleted = :deleted')
            ->setParameter('mode', 'ajax', Types::STRING)->setParameter('recipient', (string)$recipient, Types::STRING)
            ->setParameter('deleted', false, Types::BOOLEAN);
    }

    public function pending(string $kind, string $mode, \DateTimeImmutable $before, int $limit, array $extra = []): array
    {
        $class = $this->entityClass($kind);
        $query = $this->em->createQueryBuilder()->select('r')->from($class, 'r')
            ->where('r.is_deleted = :pending AND r.mode = :mode AND r.send_time < :before')
            ->setParameter('pending', false, Types::BOOLEAN)->setParameter('mode', $mode)
            ->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)->orderBy('r.send_time')->addOrderBy('r.id');
        // Match the public API's protected base predicates; extra filters cannot override them.
        $extra = array_diff_key($extra, array_flip(['is_deleted', 'mode', 'send_time']));
        if ($extra) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($class)))->where($extra));
        }
        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function purgeExpired(string $kind, \DateTimeImmutable $before): int
    {
        return $this->em->createQueryBuilder()->delete($this->entityClass($kind), 'r')
            ->where('r.is_deleted = :deleted AND r.send_time < :before')->setParameter('deleted', true, Types::BOOLEAN)
            ->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)->getQuery()->execute();
    }

    /** Template deletion cancels pending delivery and retains the rendered queue history. */
    public function detachTemplate(int $template): void
    {
        foreach (self::KINDS as $class) {
            $this->em->createQueryBuilder()->update($class, 'r')->set('r.notificationtemplates', ':none')->set('r.is_deleted', ':deleted')
                ->where('IDENTITY(r.notificationtemplates) = :template')->setParameter('template', $template, Types::INTEGER)
                ->setParameter('none', null, Types::INTEGER)->setParameter('deleted', true, Types::BOOLEAN)->getQuery()->execute();
        }
    }

    private function entityClass(string $kind): string
    {
        return self::KINDS[$kind] ?? throw new \InvalidArgumentException('Unsupported notification queue');
    }
}
