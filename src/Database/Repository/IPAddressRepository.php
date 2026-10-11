<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Entity\IPAddress;

/** Address identities; parent/model traversal remains with the legacy caller. */
final class IPAddressRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Address labels for the parent's form, including deleted/dynamic rows as before. */
    public function formRows(string $table, string $kind, int|string|null $identity): array
    {
        $metadata = $this->em->getClassMetadata(IPAddress::class);
        $connection = $this->em->getConnection();
        $quote = $connection->quoteIdentifier(...);
        $query = $connection->createQueryBuilder()
            ->select(
                $quote($metadata->getColumnName('id')) . ' AS ' . $quote('id'),
                $quote($metadata->getColumnName('name')) . ' AS ' . $quote('name')
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

    /**
     * @param array{int, int, int, int} $binary Validated, normalized address words.
     * @return list<int|string>
     */
    public function identifiersForParsedAddress(int $version, array $binary): array
    {
        $metadata = $this->em->getClassMetadata(IPAddress::class);
        $query = $this->em->createQueryBuilder()->select('a.id')->from(IPAddress::class, 'a')
            ->where('a.version = :version')->setParameter('version', $version, $metadata->getTypeOfField('version'));
        // IPv4 uses its final word; IPv6 identity requires all four words.
        for ($word = $version === 4 ? 3 : 0; $word < 4; ++$word) {
            $field = 'binary_' . $word;
            $query->andWhere('a.' . $field . ' = :word' . $word)
                ->setParameter('word' . $word, $binary[$word], $metadata->getTypeOfField($field));
        }
        // No new order, visibility, deletion or parent filter is imposed here.
        return array_column($query->getQuery()->getScalarResult(), 'id');
    }
}
