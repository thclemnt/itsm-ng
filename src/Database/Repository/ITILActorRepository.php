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
        $query = $this->em->createQueryBuilder()->from($record, 'a')
            ->where('IDENTITY(a.' . $parent . ') = :item')->setParameter('item', $item, Types::BIGINT)
            ->orderBy('a.id');
        $columns = [];
        // Keep the complete relationship-row contract, including generated compatibility keys.
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $query->addSelect('a.' . $property . ' AS value' . count($columns));
            $columns[] = [$mapping->columnName, $mapping->type];
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            $query->addSelect('IDENTITY(a.' . $property . ') AS value' . count($columns));
            $columns[] = [$mapping->joinColumns[0]->name, Types::BIGINT];
        }
        $rows = [];
        foreach ($query->getQuery()->getScalarResult() as $values) {
            $row = [];
            foreach ($columns as $index => [$column, $type]) {
                $row[$column] = RecordRepository::legacyScalarValue($values['value' . $index], $type);
            }
            $rows[] = ReferenceValues::legacyRow($metadata->getTableName(), $row);
        }
        return $rows;
    }
}
