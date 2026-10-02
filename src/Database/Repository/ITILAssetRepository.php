<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;

/** Active ITIL objects linked through the selected owning asset association. */
final class ITILAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function active(string $type, string $kind, int $asset, array $finished): array
    {
        if ($asset <= 0) {
            return [];
        }
        [, $parent, , , , , $links] = ITILStatisticsType::definition($this->em, $type);
        try {
            $association = $links::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r.id, r.name, r.priority')->from($links, 'i')
            ->join('i.' . $parent, 'r')->where('IDENTITY(i.' . $association . ') = :asset')
            ->setParameter('asset', $asset, Types::BIGINT)->andWhere('r.is_deleted = :no')->setParameter('no', false, Types::BOOLEAN);
        if ($finished) {
            $query->andWhere('r.status NOT IN (:finished)')->setParameter('finished', $finished);
        }
        $rows = $query->orderBy('r.id')->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['priority'] = (int)$row['priority'];
        }
        return $rows;
    }
}
