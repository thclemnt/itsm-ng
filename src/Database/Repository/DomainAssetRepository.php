<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Domain lists join the selected asset while keeping the relation category independent. */
final class DomainAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Target visibility is resolved before the caller selects its read route. */
    public function countForDomain(?int $domain, array $types): int
    {
        if ($types === []) {
            return 0;
        }
        $query = $this->em->createQueryBuilder()->select('COUNT(l.id)')->from(Entity\DomainItem::class, 'l')
            ->where('l.itemtype IN (:types)')->setParameter('types', array_values($types), ArrayParameterType::STRING);
        if ($domain === null) {
            $query->andWhere('l.domains IS NULL');
        } else {
            $query->andWhere('IDENTITY(l.domains) = :domain')->setParameter('domain', $domain, Types::BIGINT);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    public function countForItem(string $kind, ?int $item, bool $relationCategory = false): int
    {
        try {
            $association = $relationCategory ? 'domainrelations' : Entity\DomainItem::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            // Unsupported targets cannot own a row in this native relationship.
            return 0;
        }
        $query = $this->em->createQueryBuilder()->select('COUNT(l.id)')->from(Entity\DomainItem::class, 'l');
        if (!$relationCategory) {
            $query->andWhere('l.itemtype = :kind')->setParameter('kind', $kind, Types::STRING);
        }
        if ($item === null) {
            $query->andWhere('l.' . $association . ' IS NULL');
        } else {
            $query->andWhere('IDENTITY(l.' . $association . ') = :item')->setParameter('item', $item, Types::BIGINT);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    public function types(int $domain, int $limit): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT l.itemtype AS itemtype')->from(Entity\DomainItem::class, 'l')
            ->where('IDENTITY(l.domains) = :domain')->setParameter('domain', $domain, Types::BIGINT)
            ->orderBy('l.itemtype')->setMaxResults($limit)->getQuery()->getScalarResult();
    }

    public function assets(int $domain, string $kind, array $scope): array
    {
        try {
            $association = Entity\DomainItem::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            return [];
        }
        $class = $this->em->getClassMetadata(Entity\DomainItem::class)->getAssociationTargetClass($association);
        $query = $this->em->createQueryBuilder()->select('r', 'l.id AS linkid', 'IDENTITY(l.domainrelations) AS domainrelations_id', 'IDENTITY(r.entities) AS entity')
            ->from($class, 'r')->join(Entity\DomainItem::class, 'l', 'WITH', 'l.' . $association . ' = r')
            ->where('IDENTITY(l.domains) = :domain')->setParameter('domain', $domain, Types::BIGINT);
        if ($scope) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($class)))->where($scope));
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->orderBy('l.id')->getQuery()->getResult() as $result) {
            $record = $result[0];
            $rows[] = array_replace($records->toRow($record), ['items_id' => $result['linkid'], 'domainrelations_id' => $result['domainrelations_id'], 'entity' => $result['entity']]);
        }
        return $rows;
    }

    /** A relation-category tab selects its category, rather than treating it as an asset. */
    public function domains(string $kind, int $id, array $scope, bool $relationCategory = false): array
    {
        if ($relationCategory) {
            $association = 'domainrelations';
        } else {
            try {
                $association = Entity\DomainItem::referenceAssociation($kind);
            } catch (InvalidArgumentException) {
                return [];
            }
        }
        $query = $this->em->createQueryBuilder()->select('l', 'r', 'l.id AS assocID', 'IDENTITY(l.domainrelations) AS domainrelations_id', 'IDENTITY(r.entities) AS entity')
            ->from(Entity\DomainItem::class, 'l')->join('l.domains', 'r')
            ->where('IDENTITY(l.' . $association . ') = :item')->setParameter('item', $id, Types::BIGINT);
        if ($scope) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Domain::class)))->where($scope));
        }
        $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN named')->orderBy('named')->addOrderBy('r.name')->addOrderBy('l.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $record = $result[0]->domains;
            $rows[] = $records->toRow($record) + ['assocID' => $result['assocID'], 'domainrelations_id' => $result['domainrelations_id'], 'entity' => $result['entity'], 'assocName' => $record->name];
        }
        return $rows;
    }
}
