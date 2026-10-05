<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\Query\AutoNameNumber;

/** Read the occupied numbering range without hydrating assets or changing owners. */
final class AutoNameRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function assetMaximum(string $class, string $field, string $pattern, int $position, int $width, ?int $entity): ?string
    {
        return $this->maximum($class, $field, $pattern, $position, $width, true, $entity);
    }

    /** Financial numbers span all kinds/entities, including plugin-owned assets. */
    public function financialMaximum(string $field, string $pattern, int $position, int $width): ?string
    {
        return $this->maximum(Entity\Infocom::class, $field, $pattern, $position, $width, false, null);
    }

    public function globalAssetMaximum(string $field, string $pattern, int $position, int $width, ?int $entity): ?string
    {
        $maximum = null;
        // This is the public global numbering scope, not a schema/relationship catalogue.
        foreach ([Entity\Computer::class, Entity\Monitor::class, Entity\NetworkEquipment::class,
            Entity\Peripheral::class, Entity\Phone::class, Entity\Printer::class] as $class) {
            $candidate = $this->assetMaximum($class, $field, $pattern, $position, $width, $entity);
            // Compare exact unsigned decimal values, including numbers above PHP_INT_MAX.
            if ($candidate !== null && ($maximum === null || strlen($candidate) > strlen($maximum)
                || (strlen($candidate) === strlen($maximum) && strcmp($candidate, $maximum) > 0))) {
                $maximum = $candidate;
            }
        }
        return $maximum;
    }

    private function maximum(string $class, string $field, string $pattern, int $position, int $width, bool $asset, ?int $entity): ?string
    {
        $this->assertMask($position, $width);
        $metadata = $this->em->getClassMetadata($class);
        if (!$metadata->hasField($field) || !in_array($metadata->getTypeOfField($field), [Types::STRING, Types::TEXT], true)) {
            throw new \InvalidArgumentException('Automatic numbering requires a mapped text field.');
        }
        $fieldExpression = 'r.' . $field;
        $predicate = $this->em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'LOWER(' . $fieldExpression . ') LIKE LOWER(:pattern)' : $fieldExpression . ' LIKE :pattern';
        $query = $this->em->createQueryBuilder()->from($class, 'r')
            ->select('MAX(AUTO_NAME_NUMBER(SUBSTRING(r.' . $field . ', :position, :width)))')
            ->where($predicate . " ESCAPE '!'")
            ->setParameter('position', $position, Types::INTEGER)->setParameter('width', $width, Types::INTEGER)
            ->setParameter('pattern', $pattern, Types::STRING);
        if ($asset) {
            $query->andWhere('r.is_deleted = :deleted')->andWhere('r.is_template = :template')
                ->setParameter('deleted', false, Types::BOOLEAN)->setParameter('template', false, Types::BOOLEAN);
            if ($entity !== null) {
                $query->andWhere('IDENTITY(r.entities) = :entity')->setParameter('entity', $entity, Types::BIGINT);
            }
        }
        $maximum = $query->getQuery()->getSingleScalarResult();
        return $maximum === null ? null : (string)$maximum;
    }

    /** Plugins without Doctrine metadata keep their public numbering extension. */
    public function pluginAssetMaximum(string $table, string $field, string $pattern, int $position, int $width, ?int $entity): ?string
    {
        $this->assertMask($position, $width);
        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager()->introspectTable($table);
        if (!$schema->hasColumn($field) || !in_array(Type::lookupName($schema->getColumn($field)->getType()), [Types::STRING, Types::TEXT], true)) {
            throw new \InvalidArgumentException('Plugin automatic numbering requires an existing text field.');
        }
        foreach (['is_deleted', 'is_template', ...($entity === null ? [] : ['entities_id'])] as $column) {
            if (!$schema->hasColumn($column)) {
                throw new \InvalidArgumentException('Plugin automatic numbering requires its asset scope columns.');
            }
        }
        $flagTypes = [];
        foreach (['is_deleted', 'is_template'] as $column) {
            $type = Type::lookupName($schema->getColumn($column)->getType());
            if (!in_array($type, [Types::BOOLEAN, Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                throw new \InvalidArgumentException('Plugin automatic numbering requires boolean or integer asset flags.');
            }
            $flagTypes[$column] = $type;
        }
        $platform = $connection->getDatabasePlatform();
        $column = $platform->quoteIdentifier($field);
        $number = AutoNameNumber::expression($platform->getSubstringExpression($column, (string)$position, (string)$width), $platform);
        $predicate = $platform instanceof PostgreSQLPlatform
            ? 'LOWER(' . $column . ') LIKE LOWER(:pattern)' : $column . ' LIKE :pattern';
        $query = $connection->createQueryBuilder()->select('MAX(' . $number . ')')->from($platform->quoteIdentifier($table))
            ->where($predicate . " ESCAPE '!'")
            ->andWhere($platform->quoteIdentifier('is_deleted') . ' = :deleted')
            ->andWhere($platform->quoteIdentifier('is_template') . ' = :template')
            ->setParameter('pattern', $pattern, Types::STRING)
            ->setParameter('deleted', $flagTypes['is_deleted'] === Types::BOOLEAN ? false : 0, $flagTypes['is_deleted'])
            ->setParameter('template', $flagTypes['is_template'] === Types::BOOLEAN ? false : 0, $flagTypes['is_template']);
        if ($entity !== null) {
            $query->andWhere($platform->quoteIdentifier('entities_id') . ' = :entity')->setParameter('entity', $entity, Types::BIGINT);
        }
        $maximum = $query->executeQuery()->fetchOne();
        return $maximum === null ? null : (string)$maximum;
    }

    private function assertMask(int $position, int $width): void
    {
        if ($position < 1 || $width < 1 || $width > 10) {
            throw new \InvalidArgumentException('Automatic numbering requires a one-to-ten-character mask.');
        }
    }
}
