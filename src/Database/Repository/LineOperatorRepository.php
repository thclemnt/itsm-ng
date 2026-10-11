<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/** Fresh code-pair decisions for the selected line-operator writer and physical table. */
final class LineOperatorRepository
{
    public function __construct(private Connection $connection, private string $table)
    {
    }

    public function codePairExists(?int $country, ?int $network): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select('1')
            ->from($quote($this->table))
            ->setMaxResults(1);
        foreach (['mcc' => $country, 'mnc' => $network] as $field => $code) {
            if ($code === null) {
                $query->andWhere($quote($field) . ' IS NULL');
            } else {
                $query->andWhere($quote($field) . ' = :' . $field)
                    ->setParameter($field, $code, Types::INTEGER);
            }
        }
        return $query->executeQuery()->fetchOne() !== false;
    }
}
