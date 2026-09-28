<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

final class LocationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::TABLES[\getTableForItemType($itemtype)]);
    }

    /** Fetch mapped item rows and their entity labels without a per-item lookup. */
    public function items(string $itemtype, int $location, array $scope, ?string $language = null): array
    {
        $class = EntityRegistry::TABLES[\getTableForItemType($itemtype)] ?? throw new \InvalidArgumentException('Unmapped location item type');
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
            $this->em->detach($record);
        }
        return $rows;
    }
}
