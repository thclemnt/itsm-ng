<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonITILActor;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\Supplier;
use itsmng\Database\Entity\SupplierTicket;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ReferenceValues;
use itsmng\Database\RecordCriteria;
use LogicException;

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

    /** Binding membership is complete before any public supplier lifecycle callback. */
    public function suppliersForTransfer(CommonITILActor $link, string $parentType, mixed $parent): array
    {
        $table = $link::getTable();
        $class = EntityRegistry::tables()[$table] ?? throw new LogicException('Unmapped supplier transfer relation');
        $metadata = $this->em->getClassMetadata($class);
        $expected = EntityRegistry::legacyTables()[$parentType] ?? throw new LogicException('Unmapped supplier transfer parent');
        $relation = $this->parentRelation(get_class($link), $metadata);
        $mapping = $metadata->associationMappings[$relation];
        $declared = $mapping !== null && $mapping->isToOneOwningSide() && count($mapping->joinColumns) === 1
            ? (EntityRegistry::relations()[$table][$mapping->joinColumns[0]->name] ?? null) : null;
        $supplier = $metadata->associationMappings['actor'] ?? null;
        $supplierTable = array_search(Supplier::class, EntityRegistry::tables(), true);
        if ($metadata->getTableName() !== $table || $mapping === null || !$mapping->isToOneOwningSide()
            || count($mapping->joinColumns) !== 1 || $declared !== $expected
            || $mapping->targetEntity !== EntityRegistry::tables()[$expected]
            || $supplier === null || !$supplier->isToOneOwningSide() || count($supplier->joinColumns) !== 1
            || $supplier->targetEntity !== Supplier::class
            || (EntityRegistry::relations()[$table][$supplier->joinColumns[0]->name] ?? null) !== $supplierTable) {
            throw new LogicException('Incoherent supplier transfer relation');
        }
        $field = $mapping->joinColumns[0]->name;
        $query = $this->em->createQueryBuilder()->select('r.id AS id, IDENTITY(r.actor) AS suppliers_id')->from($class, 'r');
        $query->where((new RecordCriteria($query, $metadata))->where([$field => $parent]));
        $rows = $query->getQuery()->getScalarResult();
        $supplierMetadata = $this->em->getClassMetadata(Supplier::class);
        foreach ($rows as &$row) {
            $row['id'] = RecordRepository::legacyScalarValue($row['id'], $metadata->getTypeOfField('id'));
            $row['suppliers_id'] = RecordRepository::legacyScalarValue($row['suppliers_id'], $supplierMetadata->getTypeOfField('id'));
        }
        return [$field, $rows];
    }

    /** Current destination equality uses human text, including literal NULL and empty names. */
    public function destinationSupplier(int $entity, string $name): int|string|null
    {
        $metadata = $this->em->getClassMetadata(Supplier::class);
        $table = array_search(Supplier::class, EntityRegistry::tables(), true);
        $owner = $metadata->associationMappings['entities'] ?? null;
        if ($owner === null || !$owner->isToOneOwningSide() || count($owner->joinColumns) !== 1) {
            throw new LogicException('Incoherent destination supplier ownership');
        }
        $entityMetadata = $this->em->getClassMetadata($owner->targetEntity);
        if ($metadata->getTableName() !== $table
            || (EntityRegistry::relations()[$table][$owner->joinColumns[0]->name] ?? null) !== $entityMetadata->getTableName()) {
            throw new LogicException('Incoherent destination supplier ownership');
        }
        $row = $this->em->createQueryBuilder()->select('s.id')->from(Supplier::class, 's')
            ->where('IDENTITY(s.entities) = :entity')->andWhere('s.name = :name')
            ->setParameter('entity', $entity, $entityMetadata->getTypeOfField('id'))
            ->setParameter('name', $name, $metadata->getTypeOfField('name'))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : RecordRepository::legacyScalarValue($row['id'], $metadata->getTypeOfField('id'));
    }

    /** Mail replies recognize the linked supplier's primary address, regardless of actor role. */
    public function hasSupplierEmailForTicket(int $ticket, ?string $email): bool
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(SupplierTicket::class, 'r')
            ->leftJoin('r.actor', 'supplier')->where('IDENTITY(r.tickets) = :ticket')
            ->setParameter('ticket', $ticket, Types::BIGINT);
        if ($email === null || strtolower($email) === 'null') {
            $query->andWhere('supplier.email IS NULL');
        } else {
            $query->andWhere('supplier.email = :email')->setParameter('email', $email, Types::STRING);
        }
        return $query->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    public function groupName(int $id): ?string
    {
        $row = $this->em->createQueryBuilder()
            ->select('g.name')
            ->from(Group::class, 'g')
            ->where('g.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->getOneOrNullResult();
        return $row['name'] ?? null;
    }

    public function supplierDisplayData(int $id): ?array
    {
        return $this->em->createQueryBuilder()
            ->select('s.name, s.email')
            ->from(Supplier::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Preserve the user existence gate before selecting their preferred address. */
    public function userDefaultEmail(int $id): ?string
    {
        $row = $this->em->createQueryBuilder()
            ->select('u.id AS user_id, e.email AS email')
            ->from(User::class, 'u')
            ->leftJoin(UserEmail::class, 'e', 'WITH', 'IDENTITY(e.users) = u.id')
            ->where('u.id = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->orderBy('e.is_default', 'DESC')
            ->addOrderBy('e.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    private function parentRelation(string $actorClass, ClassMetadata $metadata): string
    {
        $parents = [];
        foreach ($metadata->associationMappings as $property => $mapping) {
            if (!$mapping->isToOneOwningSide() || count($mapping->joinColumns) !== 1) {
                throw new LogicException('ITIL actor relation requires single-column owning references');
            }
            if ($mapping->joinColumns[0]->name === $actorClass::getItilObjectForeignKey()) {
                $parents[] = $property;
            }
        }
        if (count($parents) !== 1) {
            throw new LogicException('ITIL actor relation requires exactly one mapped parent reference');
        }
        return $parents[0];
    }

    private function readRows(string $actorClass, int $item, bool $native): array
    {
        if (!self::supports($actorClass)) {
            throw new InvalidArgumentException('Unsupported ITIL actor relation');
        }
        $record = EntityRegistry::tables()[$actorClass::getTable()]
            ?? throw new LogicException('ITIL actor relation has no mapped entity');
        $metadata = $this->em->getClassMetadata($record);
        $parent = $this->parentRelation($actorClass, $metadata);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $native
            ? $connection->createQueryBuilder()->from($quote->getTableName($metadata, $platform), 'a')
            : $this->em->createQueryBuilder()->from($record, 'a');
        $parentExpression = $native
            ? 'a.' . $quote->getJoinColumnName($metadata->associationMappings[$parent]->joinColumns[0], $metadata, $platform)
            : 'IDENTITY(a.' . $parent . ')';
        $parameter = $native ? Type::getType(Types::BIGINT)->convertToDatabaseValueSQL(':item', $platform) : ':item';
        $query->where($parentExpression . ' = ' . $parameter)
            ->setParameter('item', $item, Types::BIGINT)
            ->orderBy($native ? 'a.' . $quote->getColumnName('id', $metadata, $platform) : 'a.id');
        $columns = [];
        // Keep the complete relationship-row contract, including generated compatibility keys.
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $expression = 'a.' . $property;
            if ($native) {
                $expression = 'a.' . $quote->getColumnName($property, $metadata, $platform);
                $expression = Type::getType($mapping->type)->convertToPHPValueSQL($expression, $platform);
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
            $resultRows = $query->executeQuery()
                ->fetchAllAssociative();
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
