<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Log;
use itsmng\Database\RecordCriteria;

final class HistoryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function append(array $values): int
    {
        return (new RecordWriter($this->em))->insert('glpi_logs', $values);
    }

    public function count(array $criteria = []): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_logs', $criteria, legacyValues: false);
    }

    public function forItem(string $type, int $id, array $filters = [], int $offset = 0, int $limit = 0, string $sort = 'id', string $direction = 'DESC'): array
    {
        if (!in_array($sort, ['id', 'date_mod', 'user_name', 'id_search_option', 'linked_action'], true)) {
            $sort = 'id';
            $direction = 'DESC';
        }
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $query = $this->em->createQueryBuilder()->select('r')->from(Log::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Log::class), false))->where(['itemtype' => $type, 'items_id' => $id] + $filters));
        if (in_array($sort, ['date_mod', 'user_name'], true)) {
            $query->addOrderBy('CASE WHEN r.' . $sort . ' IS NULL THEN 0 ELSE 1 END', $direction);
        }
        $query->addOrderBy('r.' . $sort, $direction);
        if ($sort !== 'id') {
            $query->addOrderBy('r.id', $direction);
        }
        if ($limit > 0) {
            $query->setMaxResults($limit)->setFirstResult(max(0, $offset));
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    /** Distinct facet tuples ordered by their most recent occurrence. */
    public function facets(string $type, int $id, array $fields): array
    {
        $allowed = ['user_name', 'linked_action', 'itemtype_link', 'id_search_option'];
        if (!$fields || array_diff($fields, $allowed)) {
            throw new \InvalidArgumentException('Invalid history facet');
        }
        $columns = array_map(static fn ($field) => 'l.' . $field, $fields);
        return $this->em->createQueryBuilder()->select(...$columns)->addSelect('MAX(l.id) AS HIDDEN latest')
            ->from(Log::class, 'l')->where('l.itemtype = :type AND l.items_id = :id')
            ->setParameter('type', $type)->setParameter('id', $id)
            ->groupBy(...$columns)->orderBy('latest', 'DESC')->getQuery()->getArrayResult();
    }

    /** History rows have no delete hooks; execute retention as one mapped bulk operation. */
    public function deleteMatching(array $criteria): int
    {
        $query = $this->em->createQueryBuilder()->delete(Log::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Log::class), false))->where($criteria));
        return $query->getQuery()->execute();
    }

    /** Calendar-month subtraction clamps the day, matching SQL retention semantics. */
    public static function cutoff(int $months, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        if ($months <= 0) {
            throw new \InvalidArgumentException('Retention months must be positive');
        }
        $now ??= new \DateTimeImmutable();
        $target = $now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1)->modify('-' . $months . ' months');
        return $target->setDate((int)$target->format('Y'), (int)$target->format('m'), min((int)$now->format('d'), (int)$target->format('t')));
    }

    /** Called only by an item's purge lifecycle; history records have no child hooks. */
    public function deleteForItem(string $type, int $id): void
    {
        $this->em->createQueryBuilder()->delete(Log::class, 'l')
            ->where('l.itemtype = :type')->setParameter('type', $type)
            ->andWhere('l.items_id = :id')->setParameter('id', $id)
            ->getQuery()->execute();
    }
}
