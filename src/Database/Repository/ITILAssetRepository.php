<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use LogicException;
use ReflectionProperty;

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
        return $this->linked($links, $parent, $kind, $asset, $finished);
    }

    /** A known asset-link domain needs only its own live owning association. */
    public function activeForLink(string $links, string $kind, int $asset, array $finished): array
    {
        if ($asset <= 0) {
            return [];
        }
        $metadata = $this->em->getClassMetadata($links);
        $parents = [];
        foreach ($metadata->associationMappings as $property => $association) {
            if (!$association->isToOneOwningSide()) {
                continue;
            }
            foreach ((new ReflectionProperty($metadata->name, $property))->getAttributes(ITILStatisticsRelation::class) as $attribute) {
                if ($attribute->newInstance()->role === ITILStatisticsRole::Items) {
                    $parents[] = $property;
                }
            }
        }
        if (count($parents) !== 1) {
            throw new LogicException('Expected one ITIL asset parent association: ' . $links);
        }
        return $this->linked($metadata->name, $parents[0], $kind, $asset, $finished);
    }

    private function linked(string $links, string $parent, string $kind, int $asset, array $finished): array
    {
        try {
            $association = $links::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
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
