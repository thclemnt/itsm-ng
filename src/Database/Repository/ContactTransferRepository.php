<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

/** Fresh contact decisions on the transfer coordinator's selected writer. */
final class ContactTransferRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** Retained recursive contacts stay linked; other links keep database iteration order. */
    public function supplierLinkIdentities(int $supplier, array $retained): array
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select($quote('id'), $quote('suppliers_id'), $quote('contacts_id'))
            ->from($quote('glpi_contacts_suppliers'))
            ->where($quote('suppliers_id') . ' = :supplier')
            ->setParameter('supplier', $supplier, Types::BIGINT);
        if ($retained !== []) {
            $query->andWhere($quote('contacts_id') . ' NOT IN (:retained)')
                ->setParameter('retained', array_values($retained), ArrayParameterType::INTEGER);
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                foreach (['id', 'suppliers_id', 'contacts_id'] as $field) {
                    $row[$field] = RecordRepository::legacyScalarValue($row[$field], Types::BIGINT);
                }
            }
        }
        return $rows;
    }

    public function isSharedOutsideSuppliers(int $contact, array $suppliers): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($quote('glpi_contacts_suppliers'))
            ->where($quote('contacts_id') . ' = :contact')
            ->setParameter('contact', $contact, Types::BIGINT);
        if ($suppliers !== []) {
            $query->andWhere($quote('suppliers_id') . ' NOT IN (:suppliers)')
                ->setParameter('suppliers', array_values($suppliers), ArrayParameterType::INTEGER);
        }
        return (int)$query->executeQuery()->fetchOne() > 0;
    }

    /** Names are domain text, including literal NULL; deleted destination contacts remain eligible. */
    public function reusableDestinationContact(int $entity, string $name, string $firstname): int|string|null
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        $id = $this->connection->createQueryBuilder()
            ->select($quote('id'))
            ->from($quote('glpi_contacts'))
            ->where($quote('entities_id') . ' = :entity')
            ->andWhere($quote('name') . ' = :name')
            ->andWhere($quote('firstname') . ' = :firstname')
            ->setParameter('entity', $entity, Types::BIGINT)
            ->setParameter('name', $name, Types::STRING)
            ->setParameter('firstname', $firstname, Types::STRING)
            ->executeQuery()->fetchOne();
        if ($id === false) {
            return null;
        }
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? RecordRepository::legacyScalarValue($id, Types::BIGINT) : $id;
    }

    /** Resolve after link writes and callbacks, before deciding to delete or purge the old contact. */
    public function hasSupplierLinks(int $contact): bool
    {
        $quote = $this->connection->getDatabasePlatform()->quoteIdentifier(...);
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($quote('glpi_contacts_suppliers'))
            ->where($quote('contacts_id') . ' = :contact')
            ->setParameter('contact', $contact, Types::BIGINT)
            ->executeQuery()->fetchOne() > 0;
    }
}
