<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

use function getTableForItemType;

final class LocationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Network outlet labels on the authorized location's tab. */
    public function networkOutlets(int|string|null $location, ?int $limit, int $offset): array
    {
        $metadata = $this->em->getClassMetadata(Entity\Netpoint::class);
        $connection = $this->em->getConnection();
        $quote = $connection->quoteIdentifier(...);
        $query = $connection->createQueryBuilder()
            ->select(
                $quote($metadata->getColumnName('id')) . ' AS ' . $quote('id'),
                $quote($metadata->getColumnName('name')) . ' AS ' . $quote('name'),
                $quote($metadata->getColumnName('comment')) . ' AS ' . $quote('comment')
            )
            ->from($quote($metadata->getTableName()))
            ->orderBy($quote($metadata->getColumnName('name')));
        $mapping = $metadata->getAssociationMapping('locations');
        if (!$mapping->isToOneOwningSide() || count($mapping->joinColumns) !== 1) {
            throw new LogicException('Network outlets require one owning location column.');
        }
        $parent = $quote($mapping->joinColumns[0]->name);
        if ($location === null) {
            $query->where($parent . ' IS NULL');
        } else {
            $query->where($parent . ' = :location')->setParameter('location', $location, Types::BIGINT);
        }
        if ($limit !== null) {
            $query->setMaxResults($limit)->setFirstResult($offset);
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

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::tables()[getTableForItemType($itemtype)]);
    }

    /** Fetch mapped item rows and their entity labels without a per-item lookup. */
    public function items(string $itemtype, int $location, array $scope, ?string $language = null): array
    {
        $class = EntityRegistry::tables()[getTableForItemType($itemtype)] ?? throw new InvalidArgumentException('Unmapped location item type');
        $metadata = $this->em->getClassMetadata($class);
        $criteria = ['locations_id' => $location] + $scope;
        if ($metadata->hasField('is_deleted')) {
            $criteria['is_deleted'] = 0;
        }
        $query = $this->em->createQueryBuilder()->select('r', 'entity.completename AS entity_name')
            ->from($class, 'r')->leftJoin('r.entities', 'entity')
            ->orderBy('r.id');
        if ($language !== null) {
            $query->addSelect('translation.value AS translated_entity')
                ->leftJoin(Entity\DropdownTranslation::class, 'translation', 'WITH', 'translation.items_id = entity.id AND translation.itemtype = :entity_type AND translation.field = :name_field AND translation.language = :language')
                ->setParameter('entity_type', 'Entity')->setParameter('name_field', 'completename')->setParameter('language', $language);
        }
        $query->where((new RecordCriteria($query, $metadata))->where($criteria));
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = $result[0];
            $rows[] = ['fields' => $records->toRow($record), 'entity_name' => !empty($result['translated_entity']) ? $result['translated_entity'] : ($result['entity_name'] ?? '')];
        }
        return $rows;
    }
}
