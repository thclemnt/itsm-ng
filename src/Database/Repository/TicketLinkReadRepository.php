<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

/** A completed incident-link snapshot before legacy Ticket reload and rendering. */
final class TicketLinkReadRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** Both endpoints own Ticket identities; preserve direction and native row scalar types. */
    public function linkedIdentities(int|string $ticket): array
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $rows = $this->connection->createQueryBuilder()
            ->select($quote('id'), $quote('tickets_id_1'), $quote('tickets_id_2'), $quote('link'))
            ->from($quote('glpi_tickets_tickets'))
            ->where($quote('tickets_id_1') . ' = :ticket OR ' . $quote('tickets_id_2') . ' = :ticket')
            ->setParameter('ticket', $ticket, Types::BIGINT)
            ->executeQuery()->fetchAllAssociative();
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                foreach (['id', 'tickets_id_1', 'tickets_id_2'] as $field) {
                    $row[$field] = RecordRepository::legacyScalarValue($row[$field], Types::BIGINT);
                }
                $row['link'] = (int)$row['link'];
            }
            unset($row);
        }
        return $rows;
    }
}
