<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

final class AssetRepository
{
    public const TYPES = [
        'Computer' => Entity\Computer::class, 'Monitor' => Entity\Monitor::class,
        'NetworkEquipment' => Entity\NetworkEquipment::class, 'Peripheral' => Entity\Peripheral::class,
        'Phone' => Entity\Phone::class, 'Printer' => Entity\Printer::class,
        'SoftwareLicense' => Entity\SoftwareLicense::class, 'Certificate' => Entity\Certificate::class,
    ];

    public function __construct(private EntityManager $em)
    {
    }

    /** Connection identities, including locked/deleted links, as required by lifecycle callers. */
    public function linkedItems(string $itemtype, int $id): array
    {
        $query = $this->em->createQueryBuilder()->from(Entity\ComputerItem::class, 'link')
            ->setParameter('id', $id, Types::INTEGER)->orderBy('link.id');
        if ($itemtype === 'Computer') {
            $query->select('link.itemtype AS itemtype', 'link.items_id AS item_id')->where('link.computers = :id');
        } else {
            $query->select('IDENTITY(link.computers) AS item_id')->where('link.itemtype = :type AND link.items_id = :id')
                ->setParameter('type', $itemtype, Types::STRING);
        }
        $items = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $target = $itemtype === 'Computer' ? $row['itemtype'] : 'Computer';
            $targetId = (int)$row['item_id'];
            $items[$target][$targetId] = $targetId;
        }
        return $items;
    }

    /** null = all authorized entities; an empty list deliberately matches none. */
    public function count(string $itemtype, ?array $entities): int
    {
        $class = self::TYPES[$itemtype] ?? throw new \InvalidArgumentException('Unmapped asset type');
        $query = $this->em->createQueryBuilder()->select('COUNT(a.id)')->from($class, 'a');
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($this->em->getClassMetadata($class)->hasField($flag)) {
                $query->andWhere('a.' . $flag . ' = :false')->setParameter('false', false, Types::BOOLEAN);
            }
        }
        if ($entities !== null) {
            $query->andWhere('a.entities_id IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }
}
