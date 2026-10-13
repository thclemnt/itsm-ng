<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\RegisteredID;

/** Registered identifiers attached to a component or manufacturer form. */
final class RegisteredIDRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function formOptions(string $table, string $kind, int|string|null $identity): array
    {
        $metadata = $this->em->getClassMetadata(RegisteredID::class);
        $connection = $this->em->getConnection();
        $quote = $connection->quoteIdentifier(...);
        $query = $connection->createQueryBuilder()
            ->select(
                $quote($metadata->getColumnName('id')) . ' AS ' . $quote('id'),
                $quote($metadata->getColumnName('device_type')) . ' AS ' . $quote('_registeredID_type'),
                $quote($metadata->getColumnName('name')) . ' AS ' . $quote('_registeredID')
            )
            ->from($quote($table))
            ->where($quote($metadata->getColumnName('itemtype')) . ' = :kind')
            ->setParameter('kind', $kind, Types::STRING);
        $parent = $quote($metadata->getColumnName('items_id'));
        if ($identity === null) {
            $query->andWhere($parent . ' IS NULL');
        } else {
            $query->andWhere($parent . ' = :parent')->setParameter('parent', $identity, Types::BIGINT);
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $metadata->getTypeOfField('id'));
            }
            unset($row);
        }
        return $rows;
    }
}
