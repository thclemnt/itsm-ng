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

    public function installationsForTransfer(string $itemtype, int $item, array $excludedVersions, bool $currentRead = false): array
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->em->createQueryBuilder()->select('i.id AS id', 'IDENTITY(i.softwareversions) AS softwareversions_id')
            ->from(Entity\ItemSoftwareVersion::class, 'i')->where('IDENTITY(i.' . Entity\ItemSoftwareVersion::referenceAssociation($itemtype) . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)
            ->orderBy('i.id');
        if ($excludedVersions) {
            $query->andWhere('i.softwareversions NOT IN (:excluded)')->setParameter('excluded', array_map('intval', array_values($excludedVersions)));
        }
        return $query->getQuery()->setLockMode($currentRead ? \Doctrine\DBAL\LockMode::PESSIMISTIC_READ : \Doctrine\DBAL\LockMode::NONE)->getScalarResult();
    }

    public function licenseAssignmentsForTransfer(string $itemtype, int $item, bool $currentRead = false): array
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()->select('i.id AS id')->from(Entity\ItemSoftwareLicense::class, 'i')
            ->where('IDENTITY(i.' . Entity\ItemSoftwareLicense::referenceAssociation($itemtype) . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)->orderBy('i.id')->getQuery()
            ->setLockMode($currentRead ? \Doctrine\DBAL\LockMode::PESSIMISTIC_READ : \Doctrine\DBAL\LockMode::NONE)->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function itemTypes(bool $licenses, int $parent, bool $software = false, bool $currentRead = false): array
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->em->createQueryBuilder()->select(($currentRead ? '' : 'DISTINCT ') . 'i.itemtype AS itemtype')
            ->from($licenses ? Entity\ItemSoftwareLicense::class : Entity\ItemSoftwareVersion::class, 'i');
        $this->parent($query, $licenses, $parent, $software);
        $query->orderBy('i.itemtype');
        if ($currentRead) {
            $query->addOrderBy('i.id');
        }
        return array_values(array_unique(array_column($query->getQuery()
            ->setLockMode($currentRead ? \Doctrine\DBAL\LockMode::PESSIMISTIC_READ : \Doctrine\DBAL\LockMode::NONE)->getScalarResult(), 'itemtype')));
    }

    /** Asset criteria retain the caller's entity and recursive-visibility policy. */
    public function count(bool $licenses, int $parent, bool $software, string $itemtype, string $assetTable, array $assetCriteria, bool $currentRead = false): int
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->assets($licenses, $parent, $software, $itemtype, $assetTable, $assetCriteria);
        if ($currentRead) {
            // Lock actual eligible rows: PostgreSQL cannot lock an aggregate,
            // and a plain COUNT can reuse a caller's older MySQL RR snapshot.
            return count($query->select('i.id AS id')->orderBy('i.id')->getQuery()
                ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_READ)->getScalarResult());
        }
        return (int)$query->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
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
        $assignment = $licenses ? Entity\ItemSoftwareLicense::class : Entity\ItemSoftwareVersion::class;
        $subject = $assignment::referenceAssociation($itemtype);
        $mapping = $this->em->getClassMetadata($assignment)->getAssociationMapping($subject);
        if ($mapping->targetEntity !== $class) {
            throw new \InvalidArgumentException('Software assignment kind and asset mapping disagree.');
        }
        $query = $this->em->createQueryBuilder()->from($class, 'r')
            ->innerJoin($assignment, 'i', 'WITH', 'i.' . $subject . ' = r.id');
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

    /** Selected owner's UI projection; software scope/category are caller policies. */
    public function forSubject(string $kind, int $id, array $softwareScope, bool $excludeDeleted, ?int $category): array
    {
        $query = $this->subjectVersions($kind, $id)
            ->select('i.id AS id, IDENTITY(s.softwarecategories) AS softwarecategories_id, s.name AS softname')
            ->addSelect('st.name AS state, v.id AS verid, s.id AS softwares_id, v.name AS version')
            ->addSelect('s.is_valid AS softvalid, i.date_install AS dateinstall, i.is_dynamic AS is_dynamic')
            ->leftJoin('v.states', 'st');
        $criteria = (new RecordCriteria($query, $this->em->getClassMetadata(Entity\ItemSoftwareVersion::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(Entity\Software::class), 's');
        $query->andWhere($criteria->where($softwareScope));
        if ($excludeDeleted) {
            $query->andWhere('i.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        }
        if ($category !== null) {
            // Legacy zero means an unselected category.
            $query->andWhere($category === 0 ? 's.softwarecategories IS NULL' : 'IDENTITY(s.softwarecategories) = :category');
            if ($category !== 0) {
                $query->setParameter('category', $category, Types::BIGINT);
            }
        }
        $rows = $query->orderBy('s.name')->addOrderBy('v.name')->addOrderBy('i.id')->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            if ($row['dateinstall'] instanceof \DateTimeInterface) {
                $row['dateinstall'] = $row['dateinstall']->format('Y-m-d');
            }
            $row['softwarecategories_id'] ??= 0;
            $row['softvalid'] = (int)$row['softvalid'];
            $row['is_dynamic'] = (int)$row['is_dynamic'];
        }
        return $rows;
    }

    /** API expansion already has an authorized owner; it has no UI category filter. */
    public function apiForSubject(string $kind, int $id): array
    {
        $rows = $this->subjectVersions($kind, $id)
            ->select('IDENTITY(s.softwarecategories) AS softwarecategories_id, s.id AS softwares_id, v.id AS softwareversions_id')
            ->addSelect('i.is_dynamic AS is_dynamic, IDENTITY(v.states) AS states_id, s.is_valid AS is_valid')
            ->andWhere('i.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)
            ->orderBy('s.name')->addOrderBy('v.name')->addOrderBy('i.id')->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['is_dynamic'] = (int)$row['is_dynamic'];
            $row['is_valid'] = (int)$row['is_valid'];
        }
        return $rows;
    }

    /**
     * Display fields for the installation rows already scoped by the caller.
     *
     * @param list<array{itemtype: string, items_id: int, softwareversions_id: int}> $installations
     * @return array<string, array<int, array<int, array<int, array{id: int, name: ?string, serial: ?string, type: ?string}>>>>
     */
    public function licensesForInstallations(array $installations): array
    {
        if ($installations === []) {
            return [];
        }
        $query = $this->em->createQueryBuilder()
            ->select('DISTINCT i.itemtype AS itemtype, i.items_id AS owner, v.id AS version')
            ->addSelect('l.id AS id, l.name AS name, l.serial AS serial, t.name AS type')
            ->from(Entity\ItemSoftwareLicense::class, 'i')
            ->innerJoin('i.softwarelicenses', 'l')
            ->innerJoin(Entity\SoftwareVersion::class, 'v', 'WITH', 'l.useVersion = v.id OR l.buyVersion = v.id')
            ->leftJoin('l.softwarelicensetypes', 't')
            ->orderBy('l.id');
        $requested = [];
        $predicates = [];
        foreach ($installations as $installation) {
            $kind = $installation['itemtype'];
            $owner = (int)$installation['items_id'];
            $version = (int)$installation['softwareversions_id'];
            if (isset($requested[$kind][$owner][$version])) {
                continue;
            }
            $requested[$kind][$owner][$version] = true;
            $association = Entity\ItemSoftwareLicense::referenceAssociation($kind);
            $index = count($predicates);
            $predicates[] = '(IDENTITY(i.' . $association . ') = :owner' . $index . ' AND v.id = :version' . $index . ')';
            $query->setParameter('owner' . $index, $owner, Types::BIGINT)
                ->setParameter('version' . $index, $version, Types::BIGINT);
        }
        $rows = [];
        foreach ($query->where(implode(' OR ', $predicates))->getQuery()->getScalarResult() as $row) {
            $rows[$row['itemtype']][(int)$row['owner']][(int)$row['version']][(int)$row['id']] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'serial' => $row['serial'],
                'type' => $row['type'],
            ];
        }
        return $rows;
    }

    /** Presentation deduplicates licence IDs while persisted assignments retain multiplicity. */
    public function licensesForInstallation(string $kind, int $id, int $version): array
    {
        $query = $this->em->createQueryBuilder()->select('l, t')->from(Entity\SoftwareLicense::class, 'l')
            ->innerJoin(Entity\ItemSoftwareLicense::class, 'i', 'WITH', 'i.softwarelicenses = l.id')
            ->leftJoin('l.softwarelicensetypes', 't')
            ->where('IDENTITY(i.' . Entity\ItemSoftwareLicense::referenceAssociation($kind) . ') = :owner')
            ->andWhere('IDENTITY(l.useVersion) = :version OR IDENTITY(l.buyVersion) = :version')
            ->setParameter('owner', $id, Types::BIGINT)->setParameter('version', $version, Types::BIGINT)
            ->orderBy('l.id');
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->getResult() as $license) {
            $rows[$license->id] = $records->toRow($license) + ['type' => $license->softwarelicensetypes?->name];
        }
        return $rows;
    }

    /** Snapshot every active or locked assignment for the existing public clone lifecycle. */
    public function assignmentsForClone(bool $licenses, string $kind, int $id): array
    {
        $class = $licenses ? Entity\ItemSoftwareLicense::class : Entity\ItemSoftwareVersion::class;
        $query = $this->em->createQueryBuilder()->select('i')->from($class, 'i')
            ->where('IDENTITY(i.' . $class::referenceAssociation($kind) . ') = :owner')
            ->setParameter('owner', $id, Types::BIGINT)->orderBy('i.id');
        $records = new RecordRepository($this->em);
        return array_map($records->toRow(...), $query->getQuery()->getResult());
    }

    private function subjectVersions(string $kind, int $id): QueryBuilder
    {
        return $this->em->createQueryBuilder()->from(Entity\ItemSoftwareVersion::class, 'i')
            ->innerJoin('i.softwareversions', 'v')->innerJoin('v.softwares', 's')
            ->where('IDENTITY(i.' . Entity\ItemSoftwareVersion::referenceAssociation($kind) . ') = :owner')
            ->setParameter('owner', $id, Types::BIGINT);
    }
}
