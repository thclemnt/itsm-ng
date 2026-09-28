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
