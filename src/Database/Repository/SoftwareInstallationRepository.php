<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;
use itsmng\Database\UnsupportedCriteria;

/** Query installations through their concrete asset mapping and owning version/license. */
final class SoftwareInstallationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function installationsForTransfer(string $itemtype, int $item, array $excludedVersions): array
    {
        $query = $this->em->createQueryBuilder()->select('i.id AS id', 'IDENTITY(i.softwareversions) AS softwareversions_id')
            ->from(Entity\ItemSoftwareVersion::class, 'i')->where('i.itemtype = :itemtype AND i.items_id = :item')
            ->setParameter('itemtype', $itemtype, Types::STRING)->setParameter('item', $item, Types::INTEGER)
            ->orderBy('i.id');
        if ($excludedVersions) {
            $query->andWhere('i.softwareversions NOT IN (:excluded)')->setParameter('excluded', array_map('intval', array_values($excludedVersions)));
        }
        return $query->getQuery()->getScalarResult();
    }

    public function retargetInstallation(int $installation, int $version): void
    {
        $this->em->createQueryBuilder()->update(Entity\ItemSoftwareVersion::class, 'i')
            ->set('i.softwareversions', ':version')->setParameter('version', $version, Types::INTEGER)
            ->where('i.id = :id')->setParameter('id', $installation, Types::INTEGER)->getQuery()->execute();
    }

    public function removeInstallation(int $installation): void
    {
        $this->em->createQueryBuilder()->delete(Entity\ItemSoftwareVersion::class, 'i')
            ->where('i.id = :id')->setParameter('id', $installation, Types::INTEGER)->getQuery()->execute();
    }

    public function licenseAssignmentsForTransfer(string $itemtype, int $item): array
    {
        $rows = $this->em->createQueryBuilder()->select('i.id AS id')->from(Entity\ItemSoftwareLicense::class, 'i')
            ->where('i.itemtype = :itemtype AND i.items_id = :item')->setParameter('itemtype', $itemtype, Types::STRING)
            ->setParameter('item', $item, Types::INTEGER)->orderBy('i.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function removeLicenseAssignments(string $itemtype, int $item): void
    {
        $this->em->createQueryBuilder()->delete(Entity\ItemSoftwareLicense::class, 'i')
            ->where('i.itemtype = :itemtype AND i.items_id = :item')->setParameter('itemtype', $itemtype, Types::STRING)
            ->setParameter('item', $item, Types::INTEGER)->getQuery()->execute();
    }

    public function itemTypes(bool $licenses, int $parent, bool $software = false): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT i.itemtype AS itemtype')
            ->from($licenses ? Entity\ItemSoftwareLicense::class : Entity\ItemSoftwareVersion::class, 'i');
        $this->parent($query, $licenses, $parent, $software);
        return array_column($query->orderBy('i.itemtype')->getQuery()->getScalarResult(), 'itemtype');
    }

    /** Asset criteria retain the caller's entity and recursive-visibility policy. */
    public function count(bool $licenses, int $parent, bool $software, string $itemtype, string $assetTable, array $assetCriteria): int
    {
        return (int)$this->assets($licenses, $parent, $software, $itemtype, $assetTable, $assetCriteria)
            ->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
    }

    public function countsByEntity(bool $licenses, int $parent, string $itemtype, string $assetTable, array $assetCriteria): array
    {
        $rows = $this->assets($licenses, $parent, false, $itemtype, $assetTable, $assetCriteria)
            ->select('IDENTITY(r.entities) AS entity_id', 'COUNT(i.id) AS quantity')->groupBy('entity_id')
            ->getQuery()->getScalarResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['entity_id']] = (int)$row['quantity'];
        }
        return $counts;
    }

    private function assets(bool $licenses, int $parent, bool $software, string $itemtype, string $assetTable, array $assetCriteria): QueryBuilder
    {
        $class = EntityRegistry::tables()[$assetTable] ?? throw new UnsupportedCriteria('Unmapped software asset requires an ORM entity.');
        $query = $this->em->createQueryBuilder()->from($class, 'r')
            ->innerJoin($licenses ? Entity\ItemSoftwareLicense::class : Entity\ItemSoftwareVersion::class, 'i', 'WITH', 'i.items_id = r.id AND i.itemtype = :itemtype')
            ->setParameter('itemtype', $itemtype, Types::STRING);
        $metadata = $this->em->getClassMetadata($class);
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($metadata->hasField($flag)) {
                $assetCriteria[$flag] = false;
            }
        }
        $query->where((new RecordCriteria($query, $metadata))->where($assetCriteria))
            ->andWhere('i.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        $this->parent($query, $licenses, $parent, $software);
        return $query;
    }

    private function parent(QueryBuilder $query, bool $licenses, int $parent, bool $software): void
    {
        $association = $licenses ? 'softwarelicenses' : 'softwareversions';
        if ($software) {
            $query->innerJoin('i.' . $association, 'p')->andWhere('p.softwares = :parent');
        } else {
            $query->andWhere('i.' . $association . ' = :parent');
        }
        $query->setParameter('parent', $parent, Types::INTEGER);
    }
}
