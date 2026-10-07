<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ReferenceValues;

/** Actor relationship rows without hydrating the associated ITIL object or recipient. */
final class ITILActorRepository
{
    private const ACTOR_CLASSES = [
        'Ticket_User', 'Group_Ticket', 'Supplier_Ticket',
        'Change_User', 'Change_Group', 'Change_Supplier',
        'Problem_User', 'Group_Problem', 'Problem_Supplier',
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function groupName(int $id): ?string
    {
        $row = $this->em->createQueryBuilder()->select('g.name')->from(Entity\Group::class, 'g')
            ->where('g.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->getOneOrNullResult();
        return $row['name'] ?? null;
    }

    public function supplierDisplayData(int $id): ?array
    {
        return $this->em->createQueryBuilder()->select('s.name, s.email')->from(Entity\Supplier::class, 's')
            ->where('s.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->getOneOrNullResult();
    }

    /** Preserve the user existence gate before selecting their preferred address. */
    public function userDefaultEmail(int $id): ?string
    {
        $row = $this->em->createQueryBuilder()->select('u.id AS user_id, e.email AS email')->from(Entity\User::class, 'u')
            ->leftJoin(Entity\UserEmail::class, 'e', 'WITH', 'IDENTITY(e.users) = u.id')->where('u.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->orderBy('e.is_default', 'DESC')->addOrderBy('e.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $row === null ? null : (string)($row['email'] ?? '');
    }

    public static function supports(string $actorClass): bool
    {
        // Subclasses retain their find()/getActors() dispatch, including plugin overrides.
        return in_array($actorClass, self::ACTOR_CLASSES, true);
    }

    public function actors(string $actorClass, int $item): array
    {
        $actors = [];
        foreach ($this->rows($actorClass, $item) as $row) {
            $actors[$row['type']][] = $row;
        }
        return $actors;
    }

    public function rows(string $actorClass, int $item): array
    {
        return $this->readRows($actorClass, $item, false);
    }

    /** Explicit private-owner scalar route; public/supplied-manager reads remain ordinary ORM. */
    public function nativeRows(string $actorClass, int $item): array
    {
        return $this->readRows($actorClass, $item, true);
    }

    private function readRows(string $actorClass, int $item, bool $native): array
    {
        if (!self::supports($actorClass)) {
            throw new \InvalidArgumentException('Unsupported ITIL actor relation');
        }
        $record = EntityRegistry::tables()[$actorClass::getTable()]
            ?? throw new \LogicException('ITIL actor relation has no mapped entity');
        $metadata = $this->em->getClassMetadata($record);
        $parents = [];
        foreach ($metadata->associationMappings as $property => $mapping) {
            if (!$mapping->isToOneOwningSide() || count($mapping->joinColumns) !== 1) {
                throw new \LogicException('ITIL actor relation requires single-column owning references');
            }
            if ($mapping->joinColumns[0]->name === $actorClass::getItilObjectForeignKey()) {
                $parents[] = $property;
            }
        }
        if (count($parents) !== 1) {
            throw new \LogicException('ITIL actor relation requires exactly one mapped parent reference');
        }
        $parent = $parents[0];
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $native ? $connection->createQueryBuilder()->from($quote->getTableName($metadata, $platform), 'a')
            : $this->em->createQueryBuilder()->from($record, 'a');
        $parentExpression = $native
            ? 'a.' . $quote->getJoinColumnName($metadata->associationMappings[$parent]->joinColumns[0], $metadata, $platform)
            : 'IDENTITY(a.' . $parent . ')';
        $parameter = $native ? \Doctrine\DBAL\Types\Type::getType(Types::BIGINT)->convertToDatabaseValueSQL(':item', $platform) : ':item';
        $query->where($parentExpression . ' = ' . $parameter)->setParameter('item', $item, Types::BIGINT)
            ->orderBy($native ? 'a.' . $quote->getColumnName('id', $metadata, $platform) : 'a.id');
        $columns = [];
        // Keep the complete relationship-row contract, including generated compatibility keys.
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $expression = 'a.' . $property;
            if ($native) {
                $expression = 'a.' . $quote->getColumnName($property, $metadata, $platform);
                $expression = \Doctrine\DBAL\Types\Type::getType($mapping->type)->convertToPHPValueSQL($expression, $platform);
            }
            $query->addSelect($expression . ' AS value' . count($columns));
            $columns[] = [$mapping->columnName, $mapping->type];
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            $expression = $native ? 'a.' . $quote->getJoinColumnName($mapping->joinColumns[0], $metadata, $platform)
                : 'IDENTITY(a.' . $property . ')';
            $query->addSelect($expression . ' AS value' . count($columns));
            $columns[] = [$mapping->joinColumns[0]->name, Types::BIGINT];
        }
        $rows = [];
        if ($native) {
            $resultRows = $query->executeQuery()->fetchAllAssociative();
        } else {
            $compiled = $query->getQuery();
            $resultRows = $compiled->getScalarResult();
        }
        foreach ($resultRows as $values) {
            $row = [];
            foreach ($columns as $index => [$column, $type]) {
                $row[$column] = RecordRepository::legacyScalarValue($values['value' . $index], $type);
            }
            $rows[] = ReferenceValues::legacyRow($metadata->getTableName(), $row);
        }
        return $rows;
    }
}
