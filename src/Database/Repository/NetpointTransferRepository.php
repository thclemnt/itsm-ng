<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\ReferenceValues;

/** A completed outlet identity on the transfer's already selected physical connection. */
final class NetpointTransferRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** Native names are literal text; only real PHPnull asks for a nameless outlet. */
    public function destinationIdentity(int $entity, ?string $name, ?int $location): int|string|null
    {
        $location = ReferenceValues::normalizeLegacy('glpi_netpoints', ['locations_id' => $location])['locations_id'];
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select($quote('id'))
            ->from($quote('glpi_netpoints'))
            ->where($quote('entities_id') . ' = :entity')
            ->setParameter('entity', $entity, Types::BIGINT)
            ->setMaxResults(1);
        if ($name === null) {
            $query->andWhere($quote('name') . ' IS NULL');
        } else {
            $query->andWhere($quote('name') . ' = :name')->setParameter('name', $name, Types::STRING);
        }
        if ($location === null) {
            $query->andWhere($quote('locations_id') . ' IS NULL');
        } else {
            $query->andWhere($quote('locations_id') . ' = :location')->setParameter('location', $location, Types::BIGINT);
        }
        $id = $query->executeQuery()->fetchOne();
        if ($id === false) {
            return null;
        }
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? RecordRepository::legacyScalarValue($id, Types::BIGINT) : $id;
    }
}
