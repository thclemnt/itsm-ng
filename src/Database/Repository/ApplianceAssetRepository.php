<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Appliance as LegacyAppliance;
use Appliance_Item as LegacyApplianceItem;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\Appliance;
use itsmng\Database\Entity\ApplianceItem;
use itsmng\Database\Entity\ApplianceItemRelation;
use itsmng\Database\RecordCriteria;

/** Appliance composition and nested context retain individual binding identities. */
final class ApplianceAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function assetKinds(int $appliance, array $criteria = []): array
    {
        return $this->kinds(ApplianceItem::class, 'appliances', $appliance, $criteria);
    }

    public function relationKinds(int $binding, array $criteria = []): array
    {
        return $this->kinds(ApplianceItemRelation::class, 'appliances_items', $binding, $criteria);
    }

    public function assets(int $appliance, string $kind, array $criteria, string $name): array
    {
        return $this->subjectRows($this->subjects(ApplianceItem::class, 'appliances', $appliance, $kind, $criteria), $name, ApplianceItem::class, $kind);
    }

    public function relations(int $binding, string $kind, array $criteria, string $name): array
    {
        return $this->subjectRows($this->subjects(ApplianceItemRelation::class, 'appliances_items', $binding, $kind, $criteria), $name, ApplianceItemRelation::class, $kind);
    }

    public function assetCount(int $appliance, string $kind, array $criteria): int
    {
        $query = $this->subjects(ApplianceItem::class, 'appliances', $appliance, $kind, $criteria);
        return $query === null ? 0 : (int)$query->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    public function relationCount(int $binding, string $kind, array $criteria): int
    {
        $query = $this->subjects(ApplianceItemRelation::class, 'appliances_items', $binding, $kind, $criteria);
        return $query === null ? 0 : (int)$query->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    /** The reverse view selects appliance owners, never another asset with an overlapping identifier. */
    public function owners(string $kind, int $asset, array $criteria): array
    {
        $query = $this->ownerQuery($kind, $asset, $criteria);
        if ($query === null) {
            return [];
        }
        $query->select('r, l.id AS linkid, IDENTITY(r.entities) AS entity')->leftJoin('r.entities', 'e')
            ->addSelect('CASE WHEN e.completename IS NULL THEN 0 ELSE 1 END AS HIDDEN entity_missing')
            ->orderBy('entity_missing')->addOrderBy('e.completename')
            ->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN name_missing')
            ->addOrderBy('name_missing')->addOrderBy('r.name')->addOrderBy('r.id')->addOrderBy('l.id');
        return $this->rows($query);
    }

    public function ownerCount(string $kind, int $asset, array $criteria): int
    {
        $query = $this->ownerQuery($kind, $asset, $criteria);
        return $query === null ? 0 : (int)$query->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    /** Fixed private reverse count; direct entity membership retains each binding. */
    public function nativeOwnerCount(string $kind, int $asset, ?array $entities, array $ancestors = [], bool $entityList = true): int
    {
        if ($asset <= 0) {
            return 0;
        }
        try {
            $subject = ApplianceItem::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            return 0;
        }
        $owner = $this->em->getClassMetadata(Appliance::class);
        $link = $this->em->getClassMetadata(ApplianceItem::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $linkColumn = static fn (string $field): string => 'l.' . $quote->getJoinColumnName($link->associationMappings[$field]->joinColumns[0], $link, $platform);
        $query = $connection->createQueryBuilder()
            ->select('COUNT(l.' . $quote->getColumnName('id', $link, $platform) . ')')
            ->from($quote->getTableName($owner, $platform), 'r')
            ->innerJoin('r', $quote->getTableName($link, $platform), 'l', $linkColumn('appliances') . ' = r.' . $quote->getColumnName('id', $owner, $platform))
            ->where($linkColumn($subject) . ' = ' . Type::getType(Types::BIGINT)->convertToDatabaseValueSQL('?', $platform))
            ->setParameter(0, $asset, Types::BIGINT);
        if ($entities !== null) {
            $parameters = [];
            foreach ($entities as $index => $entity) {
                // RecordCriteria binds each association-list element as INTEGER.
                $parameters[] = Type::getType(Types::INTEGER)->convertToDatabaseValueSQL('?', $platform);
                $query->setParameter($index + 1, (int)$entity, Types::INTEGER);
            }
            $entity = 'r.' . $quote->getJoinColumnName($owner->associationMappings['entities']->joinColumns[0], $owner, $platform);
            $scope = $parameters
                ? $entity . ($entityList ? ' IN (' . implode(', ', $parameters) . ')' : ' = ' . $parameters[0])
                : '1 = 0';
            if ($ancestors) {
                $position = count($entities) + 1;
                $recursive = 'r.' . $quote->getColumnName('is_recursive', $owner, $platform)
                    . ' = ' . Type::getType(Types::BOOLEAN)->convertToDatabaseValueSQL('?', $platform);
                $query->setParameter($position++, true, Types::BOOLEAN);
                $parameters = [];
                foreach ($ancestors as $ancestor) {
                    $parameters[] = Type::getType(Types::INTEGER)->convertToDatabaseValueSQL('?', $platform);
                    $query->setParameter($position++, (int)$ancestor, Types::INTEGER);
                }
                $scope = '(' . $scope . ' OR (' . $recursive . ' AND ' . $entity . ' IN (' . implode(', ', $parameters) . ')))';
            }
            $query->andWhere($scope);
        }
        return (int)$query->executeQuery()->fetchOne();
    }

    public function hasAsset(int $appliance, string $kind, int $asset): bool
    {
        $query = $this->ownerQuery($kind, $asset, []);
        return $query !== null && (int)$query->andWhere('r.id = :owner')->setParameter('owner', $appliance, Types::BIGINT)
            ->select('COUNT(l.id)')->getQuery()->getSingleScalarResult() > 0;
    }

    public function assetRelationships(string $kind, int $id): array
    {
        return $this->relationships(ApplianceItem::class, 'appliances', LegacyAppliance::class, $kind, $id);
    }

    public function relationRelationships(string $kind, int $id): array
    {
        return $this->relationships(ApplianceItemRelation::class, 'appliances_items', LegacyApplianceItem::class, $kind, $id);
    }

    private function relationships(string $link, string $owner, string $ownerKind, string $kind, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        if ($kind === $ownerKind) {
            $association = $owner;
        } else {
            try {
                $association = $link::referenceAssociation($kind);
            } catch (InvalidArgumentException) {
                return [];
            }
        }
        $query = $this->em->createQueryBuilder()->select('r')->from($link, 'r')
            ->where('IDENTITY(r.' . $association . ') = :item')->setParameter('item', $id, Types::BIGINT)->orderBy('r.id');
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $subject = $link::referenceAssociation($binding->itemtype);
            $rows[] = ['id' => $binding->id, 'itemtype_1' => $ownerKind, 'items_id_1' => $binding->{$owner}->id,
                'itemtype_2' => $binding->itemtype, 'items_id_2' => $binding->{$subject}->id,
                'is_1' => (int)($kind === $ownerKind), 'is_2' => (int)($kind !== $ownerKind)];
        }
        return $rows;
    }

    private function ownerQuery(string $kind, int $asset, array $criteria): ?QueryBuilder
    {
        if ($asset <= 0) {
            return null;
        }
        try {
            $association = ApplianceItem::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            return null;
        }
        $query = $this->em->createQueryBuilder()->from(Appliance::class, 'r')->join(ApplianceItem::class, 'l', 'WITH', 'l.appliances = r')
            ->where('IDENTITY(l.' . $association . ') = :asset')->setParameter('asset', $asset, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Appliance::class)))->where($criteria));
        }
        return $query;
    }

    private function kinds(string $link, string $owner, int $id, array $criteria): array
    {
        if ($id <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.itemtype AS itemtype')->from($link, 'r')
            ->where('IDENTITY(r.' . $owner . ') = :owner')->setParameter('owner', $id, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($link)))->where($criteria));
        }
        return $query->orderBy('r.itemtype')->getQuery()->getScalarResult();
    }

    private function subjects(string $link, string $owner, int $id, string $kind, array $criteria): ?QueryBuilder
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $association = $link::referenceAssociation($kind);
        } catch (InvalidArgumentException) {
            return null;
        }
        $target = $this->em->getClassMetadata($link)->getAssociationTargetClass($association);
        $query = $this->em->createQueryBuilder()->from($link, 'l')->join('l.' . $association, 'r')
            ->where('IDENTITY(l.' . $owner . ') = :owner')->setParameter('owner', $id, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($target)))->where($criteria));
        }
        return $query;
    }

    private function subjectRows(?QueryBuilder $query, string $name, string $link, string $kind): array
    {
        if ($query === null) {
            return [];
        }
        $association = $link::referenceAssociation($kind);
        $target = $this->em->getClassMetadata($link)->getAssociationTargetClass($association);
        $metadata = $this->em->getClassMetadata($target);
        $field = $metadata->getFieldName($name);
        // Bindings are the aggregate root: several nested links may own the
        // same context, and hydrating the context as root would merge them.
        $query->select('l, r, l.id AS linkid');
        if ($metadata->hasAssociation('entities')) {
            $query->addSelect('IDENTITY(r.entities) AS entity')->leftJoin('r.entities', 'e')
                ->addSelect('CASE WHEN e.completename IS NULL THEN 0 ELSE 1 END AS HIDDEN entity_missing')
                ->orderBy('entity_missing')->addOrderBy('e.completename');
        }
        $query->addSelect('CASE WHEN r.' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN name_missing')
            ->addOrderBy('name_missing')->addOrderBy('r.' . $field)->addOrderBy('r.id')->addOrderBy('l.id');
        return $this->rows($query, $association);
    }

    private function rows(QueryBuilder $query, ?string $subject = null): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $root = is_array($result) ? $result[0] : $result;
            $record = $subject === null ? $root : $root->{$subject};
            $scalars = is_array($result) ? array_diff_key($result, [0 => true]) : [];
            $rows[] = $records->toRow($record) + $scalars;
        }
        return $rows;
    }
}
