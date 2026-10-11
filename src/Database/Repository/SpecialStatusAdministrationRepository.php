<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\EntityRegistry;

/** Completed configuration and ticket-usage values for special-status administration. */
final class SpecialStatusAdministrationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** All five persisted SpecialStatus fields, without adding an ordering or scope. */
    public function editableStatusRows(): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $types = EntityRegistry::fieldTypes('glpi_specialstatuses');
        $rows = $this->connection->createQueryBuilder()
            ->select($quote('id'), $quote('name'), $quote('weight'), $quote('is_active'), $quote('color'))
            ->from($quote('glpi_specialstatuses'))
            ->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            $active = Type::getType($types['is_active'])->convertToPHPValue($row['is_active'], $platform);
            $row['is_active'] = RecordRepository::legacyScalarValue($active, $types['is_active']);
            if ($platform instanceof PostgreSQLPlatform) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $types['id']);
            }
        }
        unset($row);
        return $rows;
    }

    /** Ticket.status is the active weighted ordinal, not the SpecialStatus row ID. */
    public function ticketUsageCount(array $ordinals): int
    {
        if ($ordinals === []) {
            return 0;
        }
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($quote('glpi_tickets'))
            ->where($quote('status') . ' IN (:ordinals)')
            ->setParameter('ordinals', $ordinals, ArrayParameterType::INTEGER)
            ->executeQuery()->fetchOne();
    }
}
