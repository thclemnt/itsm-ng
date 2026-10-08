<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\ReferenceValues;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Counts the event identities used by CommonITILObject's timeline. */
final class TimelineRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** The discriminator columns retain the original RecordCriteria scalar types. */
    public function nativeSubjectCount(ClassMetadata $metadata, string $kind, mixed $item, bool $restricted = false, mixed $author = null): int
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $connection->createQueryBuilder()->select('COUNT(r.' . $quote->getColumnName('id', $metadata, $platform) . ')')
            ->from($quote->getTableName($metadata, $platform), 'r');
        $position = 0;
        // Restricted followups bind visibility before discriminator/item, as before.
        if ($restricted) {
            $query->andWhere($this->visibility($query, $metadata, $author, $position));
        }
        foreach (['itemtype' => $kind, 'items_id' => $item] as $field => $value) {
            $query->andWhere($this->comparison(
                $query,
                'r.' . $quote->getColumnName($field, $metadata, $platform),
                $value,
                $metadata->getTypeOfField($field),
                $position
            ));
        }
        return (int)$query->executeQuery()->fetchOne();
    }

    public function nativeTaskCount(ClassMetadata $metadata, string $parent, mixed $item, bool $restricted, mixed $author): int
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $connection->createQueryBuilder()->select('COUNT(r.' . $quote->getColumnName('id', $metadata, $platform) . ')')
            ->from($quote->getTableName($metadata, $platform), 'r');
        $position = 0;
        $mapping = $metadata->associationMappings[$parent];
        $query->where($this->comparison(
            $query,
            'r.' . $quote->getJoinColumnName($mapping->joinColumns[0], $metadata, $platform),
            $item,
            Types::INTEGER,
            $position
        ));
        if ($restricted) {
            $query->andWhere($this->visibility($query, $metadata, $author, $position));
        }
        return (int)$query->executeQuery()->fetchOne();
    }

    private function visibility(QueryBuilder $query, ClassMetadata $metadata, mixed $author, int &$position): string
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $public = $this->comparison($query, 'r.' . $quote->getColumnName('is_private', $metadata, $platform), false, Types::BOOLEAN, $position);
        $mapping = $metadata->associationMappings['author'];
        $join = $mapping->joinColumns[0];
        $column = 'r.' . $quote->getJoinColumnName($join, $metadata, $platform);
        // The renderer's helpdesk author0 denotes an empty optional reference.
        $owned = EntityRegistry::hasPolicy($metadata->getTableName(), $join->name, ReferenceKind::EmptySelection)
            && ReferenceValues::isEmptySelection($author)
            ? $column . ' IS NULL'
            : $this->comparison($query, $column, $author, Types::INTEGER, $position);
        return '(' . $public . ' OR ' . $owned . ')';
    }

    /** Fixed scalar operands only; arbitrary legacy predicates remain in RecordCriteria. */
    private function comparison(QueryBuilder $query, string $column, mixed $value, string $type, int &$position): string
    {
        if ($value === null || (is_string($value) && strtolower($value) === 'null')) {
            return $column . ' IS NULL';
        }
        $value = match ($type) {
            Types::BOOLEAN => (bool)(int)$value,
            Types::INTEGER, Types::SMALLINT => (int)$value,
            default => (string)$value,
        };
        $query->setParameter($position++, $value, $type);
        return $column . ' = ' . Type::getType($type)->convertToDatabaseValueSQL('?', $this->em->getConnection()->getDatabasePlatform());
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
