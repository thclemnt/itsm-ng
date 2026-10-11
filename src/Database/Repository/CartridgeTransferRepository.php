<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

/** Fresh cartridge decisions on the transfer coordinator's selected writer. */
final class CartridgeTransferRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** Installed transfer rows include ended cartridges and retain database iteration order. */
    public function installedTransferIdentities(int $printer): array
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $rows = $this->connection->createQueryBuilder()
            ->select($quote('id'), $quote('cartridgeitems_id'))
            ->from($quote('glpi_cartridges'))
            ->where($quote('printers_id') . ' = :printer')
            ->setParameter('printer', $printer, Types::BIGINT)
            ->executeQuery()->fetchAllAssociative();
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], Types::BIGINT);
                $row['cartridgeitems_id'] = RecordRepository::legacyScalarValue($row['cartridgeitems_id'], Types::BIGINT);
            }
        }
        return $rows;
    }

    /** Unassigned stock does not make a cartridge model shared with another printer. */
    public function hasInstalledOutsideTransfer(int $model, array $printers): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($quote('glpi_cartridges'))
            ->where($quote('cartridgeitems_id') . ' = :model')
            ->andWhere($quote('printers_id') . ' > 0')
            ->andWhere($quote('printers_id') . ' NOT IN (:printers)')
            ->setParameter('model', $model, Types::BIGINT)
            ->setParameter('printers', $printers, ArrayParameterType::INTEGER)
            ->executeQuery()->fetchOne() > 0;
    }

    /** Reuse an unordered same-name model, including deleted models, in the destination. */
    public function reusableTransferModel(int $entity, ?string $name): int|string|null
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select($quote('id'))
            ->from($quote('glpi_cartridgeitems'))
            ->where($quote('entities_id') . ' = :entity')
            ->setParameter('entity', $entity, Types::BIGINT);
        if ($name === null) {
            $query->andWhere($quote('name') . ' IS NULL');
        } else {
            $query->andWhere($quote('name') . ' = :name')->setParameter('name', $name, Types::STRING);
        }
        $id = $query->executeQuery()->fetchOne();
        if ($id === false) {
            return null;
        }
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? RecordRepository::legacyScalarValue($id, Types::BIGINT) : $id;
    }

    /** Fresh cleanup decision counts every cartridge, including unassigned stock. */
    public function remainingForTransferModel(int $model): int
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($quote('glpi_cartridges'))
            ->where($quote('cartridgeitems_id') . ' = :model')
            ->setParameter('model', $model, Types::BIGINT)
            ->executeQuery()->fetchOne();
    }

    /** Materialize only the owning printer-model identities before public add callbacks. */
    public function compatibleTransferModelIds(int $cartridge): array
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $models = $this->connection->createQueryBuilder()
            ->select($quote('printermodels_id'))
            ->from($quote('glpi_cartridgeitems_printermodels'))
            ->where($quote('cartridgeitems_id') . ' = :cartridge')
            ->setParameter('cartridge', $cartridge, Types::BIGINT)
            ->executeQuery()->fetchFirstColumn();
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? array_map(static fn ($id) => RecordRepository::legacyScalarValue($id, Types::BIGINT), $models) : $models;
    }

}
