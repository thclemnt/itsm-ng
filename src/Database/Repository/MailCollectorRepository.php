<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;

/** Local mail-collection state; mailbox connections and delivery stay in the model. */
final class MailCollectorRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function collectors(bool $activeOnly = false, bool $errorsOnly = false): array
    {
        $query = $this->collectorQuery($activeOnly)->select('c')->orderBy('c.id');
        if ($errorsOnly) {
            $query->andWhere('c.errors > 0');
        }
        return $this->rows($query);
    }

    public function countCollectors(bool $activeOnly = false): int
    {
        return (int)$this->collectorQuery($activeOnly)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    /** Missing collectors are retained in the log, but cannot be retried against a mailbox. */
    public function rejectedEmails(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        return $this->rows($this->em->createQueryBuilder()->select('r')->from(Entity\NotImportedEmail::class, 'r')
            ->where('r.id IN (:ids)')->andWhere('r.mailcollectors IS NOT NULL')->setParameter('ids', $ids)
            ->orderBy('r.mailcollectors')->addOrderBy('r.id'));
    }

    public function blacklistedContents(): array
    {
        return $this->em->createQueryBuilder()->select('b.content')->from(Entity\BlacklistedMailContent::class, 'b')
            ->orderBy('b.id')->getQuery()->getScalarResult();
    }

    /** A transactional delete, without TRUNCATE's implicit commit or identity reset. */
    public function clearRejectedEmails(): int
    {
        return $this->em->createQueryBuilder()->delete(Entity\NotImportedEmail::class, 'r')->getQuery()->execute();
    }

    private function collectorQuery(bool $activeOnly): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\MailCollector::class, 'c');
        if ($activeOnly) {
            $query->where('c.is_active = :active')->setParameter('active', true, Types::BOOLEAN);
        }
        return $query;
    }

    private function rows(QueryBuilder $query): array
    {
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }
}
