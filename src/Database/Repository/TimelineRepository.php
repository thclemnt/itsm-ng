<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Counts the event identities used by CommonITILObject's timeline. */
final class TimelineRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function countValidations(string $table, array $criteria): int
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        // An answer at the submission timestamp replaces that event's key.
        $query = $this->em->createQueryBuilder()
            ->select("COUNT(r.id) + COALESCE(SUM(CASE WHEN r.validation_date IS NOT NULL AND (r.submission_date IS NULL OR TEMPORAL_TEXT(r.validation_date, 'datetime') <> TEMPORAL_TEXT(r.submission_date, 'datetime')) THEN 1 ELSE 0 END), 0)")
            ->from($metadata->name, 'r');
        $query->where((new RecordCriteria($query, $metadata))->where($criteria));
        return (int)$query->getQuery()->getSingleScalarResult();
    }
}
