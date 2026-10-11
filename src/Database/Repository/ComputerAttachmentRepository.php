<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use itsmng\Database\EntityRegistry;
use itsmng\Database\EntityRestriction;

/** Final attachment decisions use the selected writer after public parent preparation. */
final class ComputerAttachmentRepository
{
    public function __construct(private Connection $connection, private string $table)
    {
    }

    /** A deleted link still prevents the same Computer/other pair from being added twice. */
    public function hasDuplicate(int|string $computer, string $kind, int|string $item): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $types = EntityRegistry::fieldTypes($this->table);
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')->from($quote($this->table))
            ->where($quote('computers_id') . ' = :computer')
            ->andWhere($quote('itemtype') . ' = :kind')
            ->andWhere($quote('items_id') . ' = :item')
            ->setParameter('computer', $computer, EntityRegistry::fieldTypes(EntityRegistry::relations()[$this->table]['computers_id'])['id'])
            ->setParameter('kind', $kind, $types['itemtype'])
            ->setParameter('item', $item, $types['items_id'])
            ->executeQuery()->fetchOne() > 0;
    }

    /** Nonglobal sharing observes active links and the existing Computer entity/recursive scope. */
    public function hasActiveConnection(string $kind, int|string $item, ?EntityRestriction $scope): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $types = EntityRegistry::fieldTypes($this->table);
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')->from($quote($this->table), 'link')
            ->where('link.' . $quote('itemtype') . ' = :kind')
            ->andWhere('link.' . $quote('items_id') . ' = :item')
            ->andWhere('link.' . $quote('is_deleted') . ' = :deleted')
            ->setParameter('kind', $kind, $types['itemtype'])
            ->setParameter('item', $item, $types['items_id'])
            ->setParameter('deleted', false, $types['is_deleted']);
        if ($scope !== null) {
            $computerTable = EntityRegistry::relations()[$this->table]['computers_id'];
            $computerTypes = EntityRegistry::fieldTypes($computerTable);
            $entityTable = EntityRegistry::relations()[$computerTable]['entities_id'];
            $query->leftJoin(
                'link',
                $quote($computerTable),
                'computer',
                'link.' . $quote('computers_id') . ' = computer.' . $quote('id')
            )->innerJoin(
                'computer',
                $quote($entityTable),
                'entity',
                'computer.' . $quote('entities_id') . ' = entity.' . $quote('id')
            );
            if ($scope->entities !== null) {
                $membership = $scope->entities
                    ? 'computer.' . $quote('entities_id') . ' IN (:entities)' : '1 = 0';
                if ($scope->entities) {
                    $query->setParameter('entities', $scope->entities, ArrayParameterType::INTEGER);
                }
                if ($scope->ancestors) {
                    $membership = '(' . $membership . ' OR (computer.' . $quote('is_recursive')
                        . ' = :recursive AND computer.' . $quote('entities_id') . ' IN (:ancestors)))';
                    $query->setParameter('recursive', true, $computerTypes['is_recursive'])
                        ->setParameter('ancestors', $scope->ancestors, ArrayParameterType::INTEGER);
                }
                $query->andWhere($membership);
            }
        }
        return (int)$query->executeQuery()->fetchOne() > 0;
    }
}
