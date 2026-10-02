<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Event;

final class EventRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function append(array $values): int
    {
        return (new RecordWriter($this->em))->insert('glpi_events', $values);
    }

    public function count(): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_events', []);
    }

    public function page(int $offset, int $limit, string $sort = 'date', string $direction = 'DESC', ?string $user = null): array
    {
        if (!in_array($sort, ['type', 'items_id', 'date', 'service', 'level', 'message'], true)) {
            $sort = 'date';
        }
        $direction = $direction === 'ASC' ? 'ASC' : 'DESC';
        $query = $this->em->createQueryBuilder()->select('e')->from(Event::class, 'e');
        if ($user !== null) {
            // Usernames are literal prefixes; wildcard characters must not select another user's events.
            $prefix = $user === '' ? '' : $user . ' ';
            $query->where("LOWER(e.message) LIKE LOWER(:prefix) ESCAPE '!'")
                ->setParameter('prefix', strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        if (!in_array($sort, ['items_id', 'level'], true)) {
            $query->addOrderBy('CASE WHEN e.' . $sort . ' IS NULL THEN 0 ELSE 1 END', $direction);
        }
        $query->addOrderBy('e.' . $sort, $direction)->addOrderBy('e.id', $direction)
            ->setFirstResult(max(0, $offset))->setMaxResults(max(0, $limit));
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    /** Preserve the database clock and strict retention boundary; NULL dates survive. */
    public function deleteOlderThan(float $seconds): int
    {
        return $this->em->createQueryBuilder()->delete(Event::class, 'e')
            ->where('EPOCH_SECONDS(e.date) < CURRENT_EPOCH_SECONDS() - :seconds')
            ->setParameter('seconds', $seconds)->getQuery()->execute();
    }
}
