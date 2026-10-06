<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\ReferenceValues;

/** Actor relationship rows without hydrating the associated ITIL object or recipient. */
final class ITILActorRepository
{
    private const RELATIONS = [
        'Ticket_User' => [Entity\TicketUser::class, 'tickets'],
        'Group_Ticket' => [Entity\GroupTicket::class, 'tickets'],
        'Supplier_Ticket' => [Entity\SupplierTicket::class, 'tickets'],
        'Change_User' => [Entity\ChangeUser::class, 'changes'],
        'Change_Group' => [Entity\ChangeGroup::class, 'changes'],
        'Change_Supplier' => [Entity\ChangeSupplier::class, 'changes'],
        'Problem_User' => [Entity\ProblemUser::class, 'problems'],
        'Group_Problem' => [Entity\GroupProblem::class, 'problems'],
        'Problem_Supplier' => [Entity\ProblemSupplier::class, 'problems'],
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $actorClass): bool
    {
        // Subclasses retain their find()/getActors() dispatch, including plugin overrides.
        return isset(self::RELATIONS[$actorClass]);
    }

    public function rows(string $actorClass, int $item): array
    {
        [$record, $parent] = self::RELATIONS[$actorClass]
            ?? throw new \InvalidArgumentException('Unsupported ITIL actor relation');
        $metadata = $this->em->getClassMetadata($record);
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
