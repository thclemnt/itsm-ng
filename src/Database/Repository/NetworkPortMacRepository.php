<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LegacyValues;

/** Complete MAC identity selection before public port and parent loading. */
final class NetworkPortMacRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** Preserve native collation, duplicates and iteration order, with no owner visibility filter. */
    public function matchingPortIdentities(string $mac, bool $wildcard): array
    {
        $types = EntityRegistry::fieldTypes('glpi_networkports');
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select($quote('id'))
            ->from($quote('glpi_networkports'));
        if (!$wildcard && $mac === 'null') {
            // This lookup retains the original query NULL sentinel, unlike stored text.
            $query->where($quote('mac') . ' IS NULL');
        } else {
            $operator = $wildcard ? ($platform instanceof PostgreSQLPlatform ? 'ILIKE' : 'LIKE') : '=';
            $query->where($quote('mac') . ' ' . $operator . ' :mac')
                ->setParameter('mac', LegacyValues::decodeString($mac), $types['mac']);
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        if ($platform instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $types['id']);
            }
            unset($row);
        }
        return $rows;
    }
}
