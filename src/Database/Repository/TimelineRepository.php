<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ITILFollowup;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Counts the event identities used by CommonITILObject's timeline. */
final class TimelineRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Requester edit/delete gates count private activity too; public totals exclude every private author. */
    public function subjectActivityCount(string $kind, int|string|null $item, bool $publicOnly): int|string
    {
        $metadata = $this->em->getClassMetadata(ITILFollowup::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $connection->createQueryBuilder()->select('COUNT(*)')
            ->from($quote->getTableName($metadata, $platform), 'r')
            ->where('r.' . $quote->getColumnName('itemtype', $metadata, $platform) . ' = '
                . Type::getType($metadata->getTypeOfField('itemtype'))->convertToDatabaseValueSQL(':kind', $platform))
            ->setParameter('kind', $kind, $metadata->getTypeOfField('itemtype'));
        $column = 'r.' . $quote->getColumnName('items_id', $metadata, $platform);
        if ($item === null || (is_string($item) && strtolower($item) === 'null')) {
            $query->andWhere($column . ' IS NULL');
        } else {
            $query->andWhere($column . ' = '
                . Type::getType($metadata->getTypeOfField('items_id'))->convertToDatabaseValueSQL(':parent', $platform))
                ->setParameter('parent', $item, $metadata->getTypeOfField('items_id'));
        }
        if ($publicOnly) {
            $query->andWhere('r.' . $quote->getColumnName('is_private', $metadata, $platform) . ' = '
                . Type::getType($metadata->getTypeOfField('is_private'))->convertToDatabaseValueSQL(':private', $platform))
                ->setParameter('private', false, $metadata->getTypeOfField('is_private'));
        }
        $count = $query->executeQuery()->fetchOne();
        return $platform instanceof PostgreSQLPlatform ? (int)$count : $count;
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
