<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ItemKanban;
use itsmng\Database\Entity\User;

final class KanbanRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function load(string $type, int $item, int $user, ?string $timestamp = null): ?array
    {
        $record = $this->em->getRepository(ItemKanban::class)->findOneBy($this->identity($type, $item, $user));
        if ($record === null) {
            return [];
        }
        if ($timestamp !== null && strtotime($timestamp) >= ($record->date_mod?->getTimestamp() ?? 0)) {
            return null;
        }
        return json_decode((string)$record->state, true);
    }

    /** Exact board identity; shared and private state have distinct owners. */
    public function statesForItem(string $type, int $item): array
    {
        $query = $this->em->createQueryBuilder()->select('s.id AS id', 'IDENTITY(s.owner) AS owner')
            ->from(ItemKanban::class, 's')->where('s.itemtype = :type AND s.items_id = :item')
            ->setParameter('type', $type, Types::STRING)->setParameter('item', $item, Types::BIGINT)
            ->orderBy('s.id')->getQuery();
        if ($this->em->getConnection()->isTransactionActive()) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        return $query->getScalarResult();
    }

    public function stateIdentity(int $id): ?array
    {
        $query = $this->em->createQueryBuilder()->select('s.itemtype AS kind', 's.items_id AS item', 'IDENTITY(s.owner) AS owner')
            ->from(ItemKanban::class, 's')->where('s.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery();
        if ($this->em->getConnection()->isTransactionActive()) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $rows = $query->getScalarResult();
        return $rows[0] ?? null;
    }

    /** Replacement must not overwrite an owner's already configured state. */
    public function hasPrivateStateForOwners(string $type, int $item, array $owners): bool
    {
        if (!$owners) {
            return false;
        }
        $query = $this->em->createQueryBuilder()->select('s.id AS id')->from(ItemKanban::class, 's')
            ->where('s.itemtype = :type AND s.items_id = :item AND IDENTITY(s.owner) IN (:owners)')
            ->setParameter('type', $type, Types::STRING)->setParameter('item', $item, Types::BIGINT)
            ->setParameter('owners', $owners)->orderBy('s.id')->setMaxResults(1)->getQuery();
        if ($this->em->getConnection()->isTransactionActive()) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        return $query->getScalarResult() !== [];
    }

    /** The database enforces one state per board/owner, including the shared owner. */
    public function save(string $type, int $item, int $user, array $state, \DateTimeImmutable $modified): void
    {
        $identity = $this->identity($type, $item, $user);
        $json = json_encode($state, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
        $connection = $this->em->getConnection();
        try {
            $connection->transactional(function () use ($identity, $json, $modified, $type, $item, $user): void {
                $record = $this->em->getRepository(ItemKanban::class)->findOneBy($identity);
                if ($record === null) {
                    $record = new ItemKanban();
                    $record->itemtype = $type;
                    $record->items_id = $item;
                    $record->owner = $user > 0 ? $this->em->getReference(User::class, $user) : null;
                    $record->date_creation = \DateTime::createFromImmutable($modified);
                    $this->em->persist($record);
                }
                $record->state = $json;
                $record->date_mod = \DateTime::createFromImmutable($modified);
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException $error) {
            // Two first saves may race. The failed unit of work has been rolled
            // back; update the winning row using the same last-save-wins policy.
            $this->em = new EntityManager($connection, $this->em->getConfiguration());
            $query = $this->em->createQueryBuilder()->update(ItemKanban::class, 's')
                ->set('s.state', ':state')->set('s.date_mod', ':modified')
                ->where('s.itemtype = :type AND s.items_id = :item')
                ->andWhere($user > 0 ? 'IDENTITY(s.owner) = :owner' : 's.owner IS NULL')
                ->setParameter('type', $type)->setParameter('item', $item, Types::INTEGER)
                ->setParameter('state', $json)->setParameter('modified', $modified, Types::DATETIMETZ_IMMUTABLE);
            if ($user > 0) {
                $query->setParameter('owner', $user, Types::INTEGER);
            }
            if ($query->getQuery()->execute() === 0 && $this->em->getRepository(ItemKanban::class)->findOneBy($identity) === null) {
                throw $error;
            }
        }
    }

    private function identity(string $type, int $item, int $user): array
    {
        if ($user < 0) {
            throw new \InvalidArgumentException('Invalid Kanban owner');
        }
        return ['itemtype' => $type, 'items_id' => $item, 'owner' => $user > 0 ? $user : null];
    }
}
