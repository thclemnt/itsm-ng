<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\EntityRegistry;

/** Origin choices for an alias remain on the owning asset and preserve native port IDs. */
final class NetworkPortOriginChoiceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** One completed origin-kind snapshot; label callbacks belong to the caller. */
    public function availableForAlias(string $parentType, int|string $parentID, string $instantiation): array
    {
        $types = EntityRegistry::fieldTypes('glpi_networkports');
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $rows = $this->connection->createQueryBuilder()
            ->select($quote('id'), $quote('name'), $quote('mac'))
            ->from($quote('glpi_networkports'))
            ->where($quote('items_id') . ' = :parent')
            ->andWhere($quote('itemtype') . ' = :type')
            ->andWhere($quote('instantiation_type') . ' = :instantiation')
            ->setParameter('parent', $parentID, $types['items_id'])
            ->setParameter('type', $parentType, $types['itemtype'])
            ->setParameter('instantiation', $instantiation, $types['instantiation_type'])
            ->orderBy($quote('logical_number'))
            ->addOrderBy($quote('name'))
            ->executeQuery()->fetchAllAssociative();
        if ($platform instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $types['id']);
            }
            unset($row);
        }
        return $rows;
    }
}
