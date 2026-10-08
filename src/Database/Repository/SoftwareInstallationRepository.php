<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\ItemSoftwareLicense;
use itsmng\Database\Entity\ItemSoftwareVersion;
use itsmng\Database\Entity\Software;
use itsmng\Database\Entity\SoftwareCategory;
use itsmng\Database\Entity\SoftwareLicense;
use itsmng\Database\Entity\SoftwareVersion;
use itsmng\Database\Entity\State;
use itsmng\Database\EntityRegistry;
use itsmng\Database\EntityRestriction;
use itsmng\Database\MySQLConnection;
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
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->em->createQueryBuilder()
            ->select('i.id AS id', 'IDENTITY(i.softwareversions) AS softwareversions_id')
            ->from(ItemSoftwareVersion::class, 'i')
            ->where('IDENTITY(i.' . ItemSoftwareVersion::referenceAssociation($itemtype) . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)
            ->orderBy('i.id');
        if ($excludedVersions) {
            $query->andWhere('i.softwareversions NOT IN (:excluded)')
                ->setParameter('excluded', array_map('intval', array_values($excludedVersions)));
        }
        return $query->getQuery()
            ->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
    }

    public function licenseAssignmentsForTransfer(string $itemtype, int $item, bool $currentRead = false): array
    {
        if ($currentRead) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()
            ->select('i.id AS id')
            ->from(ItemSoftwareLicense::class, 'i')
            ->where('IDENTITY(i.' . ItemSoftwareLicense::referenceAssociation($itemtype) . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)
            ->orderBy('i.id')
            ->getQuery()
            ->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function itemTypes(bool $licenses, int $parent, bool $software = false, bool $currentRead = false): array
    {
        if ($currentRead) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->em->createQueryBuilder()
            ->select(($currentRead ? '' : 'DISTINCT ') . 'i.itemtype AS itemtype')
            ->from($licenses ? ItemSoftwareLicense::class : ItemSoftwareVersion::class, 'i');
        $this->parent($query, $licenses, $parent, $software);
        $query->orderBy('i.itemtype');
        if ($currentRead) {
            $query->addOrderBy('i.id');
        }
        return array_values(array_unique(array_column(
            $query->getQuery()
                ->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
                ->getScalarResult(),
            'itemtype'
        )));
    }

    /** Asset criteria retain the caller's entity and recursive-visibility policy. */
    public function count(bool $licenses, int $parent, bool $software, string $itemtype, string $assetTable, array $assetCriteria, bool $currentRead = false): int
    {
        if ($currentRead) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->assets($licenses, $parent, $software, $itemtype, $assetTable, $assetCriteria);
        if ($currentRead) {
            // Lock actual eligible rows: PostgreSQL cannot lock an aggregate,
            // and a plain COUNT can reuse a caller's older MySQL RR snapshot.
            return count(
                $query->select('i.id AS id')
                    ->orderBy('i.id')
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_READ)
                    ->getScalarResult()
            );
        }
        return (int)$query->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countsByEntity(bool $licenses, int $parent, string $itemtype, string $assetTable, array $assetCriteria): array
    {
        $rows = $this->assets($licenses, $parent, false, $itemtype, $assetTable, $assetCriteria)
            ->select('IDENTITY(r.entities) AS entity_id', 'COUNT(i.id) AS quantity')
            ->groupBy('entity_id')
            ->getQuery()
            ->getScalarResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['entity_id']] = (int)$row['quantity'];
        }
        return $counts;
    }

    private function assets(bool $licenses, int $parent, bool $software, string $itemtype, string $assetTable, array $assetCriteria): QueryBuilder
    {
        $class = EntityRegistry::tables()[$assetTable] ?? throw new UnsupportedCriteria('Unmapped software asset requires an ORM entity.');
        $assignment = $licenses ? ItemSoftwareLicense::class : ItemSoftwareVersion::class;
        $subject = $assignment::referenceAssociation($itemtype);
        $mapping = $this->em->getClassMetadata($assignment)->getAssociationMapping($subject);
        if ($mapping->targetEntity !== $class) {
            throw new InvalidArgumentException('Software assignment kind and asset mapping disagree.');
        }
        $query = $this->em->createQueryBuilder()
            ->from($class, 'r')
            ->innerJoin($assignment, 'i', 'WITH', 'i.' . $subject . ' = r.id');
        $metadata = $this->em->getClassMetadata($class);
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($metadata->hasField($flag)) {
                $assetCriteria[$flag] = false;
            }
        }
        $query->where((new RecordCriteria($query, $metadata))->where($assetCriteria))
            ->andWhere('i.is_deleted = :deleted')
            ->setParameter('deleted', false, Types::BOOLEAN);
        $this->parent($query, $licenses, $parent, $software);
        return $query;
    }

    private function parent(QueryBuilder $query, bool $licenses, int $parent, bool $software): void
    {
        $association = $licenses ? 'softwarelicenses' : 'softwareversions';
        if ($software) {
            $query->innerJoin('i.' . $association, 'p')
                ->andWhere('p.softwares = :parent');
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
        $criteria = (new RecordCriteria($query, $this->em->getClassMetadata(ItemSoftwareVersion::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(Software::class), 's');
        $query->andWhere($criteria->where($softwareScope));
        if ($excludeDeleted) {
            $query->andWhere('i.is_deleted = :deleted')
                ->setParameter('deleted', false, Types::BOOLEAN);
        }
        if ($category !== null) {
            // Legacy zero means an unselected category.
            $query->andWhere($category === 0 ? 's.softwarecategories IS NULL' : 'IDENTITY(s.softwarecategories) = :category');
            if ($category !== 0) {
                $query->setParameter('category', $category, Types::BIGINT);
            }
        }
        $rows = $query->orderBy('s.name')
            ->addOrderBy('v.name')
            ->addOrderBy('i.id')
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as &$row) {
            if ($row['dateinstall'] instanceof DateTimeInterface) {
                $row['dateinstall'] = $row['dateinstall']->format('Y-m-d');
            }
            $row['softwarecategories_id'] ??= 0;
            $row['softvalid'] = (int)$row['softvalid'];
            $row['is_dynamic'] = (int)$row['is_dynamic'];
        }
        return $rows;
    }

    /** Fixed installation projection; all arbitrary caller criteria retain forSubject(). */
    public function nativeForSubject(string $kind, int $id, EntityRestriction $scope, bool $excludeDeleted, ?int $category): array
    {
        $installation = $this->em->getClassMetadata(ItemSoftwareVersion::class);
        $version = $this->em->getClassMetadata(SoftwareVersion::class);
        $software = $this->em->getClassMetadata(Software::class);
        $state = $this->em->getClassMetadata(State::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $owner = $installation->associationMappings[ItemSoftwareVersion::referenceAssociation($kind)];
        $versionReference = $installation->associationMappings['softwareversions'];
        $softwareReference = $version->associationMappings['softwares'];
        $stateReference = $version->associationMappings['states'];
        $categoryColumn = 's.' . $quote->getJoinColumnName($software->associationMappings['softwarecategories']->joinColumns[0], $software, $platform);
        $select = [];
        // Keep the original alias order and ScalarHydrator SQL-only conversions.
        foreach ([
            [$installation, 'i', 'id', 'id'],
            [null, '', '', 'softwarecategories_id'],
            [$software, 's', 'name', 'softname'],
            [$state, 'st', 'name', 'state'],
            [$version, 'v', 'id', 'verid'],
            [$software, 's', 'id', 'softwares_id'],
            [$version, 'v', 'name', 'version'],
            [$software, 's', 'is_valid', 'softvalid'],
            [$installation, 'i', 'date_install', 'dateinstall'],
            [$installation, 'i', 'is_dynamic', 'is_dynamic'],
        ] as [$metadata, $alias, $field, $output]) {
            $expression = $metadata === null ? $categoryColumn
                : Type::getType($metadata->getTypeOfField($field))->convertToPHPValueSQL(
                    $alias . '.' . $quote->getColumnName($field, $metadata, $platform),
                    $platform
                );
            $select[] = $expression . ' AS ' . $platform->quoteSingleIdentifier($output);
        }
        $query = $connection->createQueryBuilder()
            ->select(...$select)
            ->from($quote->getTableName($installation, $platform), 'i')
            ->innerJoin(
                'i',
                $quote->getTableName($version, $platform),
                'v',
                'i.' . $quote->getJoinColumnName($versionReference->joinColumns[0], $installation, $platform)
                . ' = v.' . $quote->getReferencedJoinColumnName($versionReference->joinColumns[0], $installation, $platform)
            )
            ->innerJoin(
                'v',
                $quote->getTableName($software, $platform),
                's',
                'v.' . $quote->getJoinColumnName($softwareReference->joinColumns[0], $version, $platform)
                . ' = s.' . $quote->getReferencedJoinColumnName($softwareReference->joinColumns[0], $version, $platform)
            )
            ->leftJoin(
                'v',
                $quote->getTableName($state, $platform),
                'st',
                'v.' . $quote->getJoinColumnName($stateReference->joinColumns[0], $version, $platform)
                . ' = st.' . $quote->getReferencedJoinColumnName($stateReference->joinColumns[0], $version, $platform)
            )
            ->where('i.' . $quote->getJoinColumnName($owner->joinColumns[0], $installation, $platform)
                . ' = ' . Type::getType(Types::BIGINT)->convertToDatabaseValueSQL('?', $platform))
            ->setParameter(0, $id, Types::BIGINT);
        $position = 1;
        if ($scope->entities !== null) {
            $values = [];
            foreach ($scope->entities as $entity) {
                $values[] = Type::getType(Types::INTEGER)->convertToDatabaseValueSQL('?', $platform);
                $query->setParameter($position++, $entity, Types::INTEGER);
            }
            $column = 's.' . $quote->getJoinColumnName($software->associationMappings['entities']->joinColumns[0], $software, $platform);
            $predicate = $values ? $column . ($scope->entityList ? ' IN (' . implode(', ', $values) . ')' : ' = ' . $values[0]) : '1 = 0';
            if ($scope->ancestors) {
                $recursive = 's.' . $quote->getColumnName('is_recursive', $software, $platform)
                    . ' = ' . Type::getType(Types::BOOLEAN)->convertToDatabaseValueSQL('?', $platform);
                $query->setParameter($position++, true, Types::BOOLEAN);
                $values = [];
                foreach ($scope->ancestors as $ancestor) {
                    $values[] = Type::getType(Types::INTEGER)->convertToDatabaseValueSQL('?', $platform);
                    $query->setParameter($position++, $ancestor, Types::INTEGER);
                }
                $predicate = '(' . $predicate . ' OR (' . $recursive . ' AND ' . $column . ' IN (' . implode(', ', $values) . ')))';
            }
            $query->andWhere($predicate);
        }
        if ($excludeDeleted) {
            $query->andWhere('i.' . $quote->getColumnName('is_deleted', $installation, $platform)
                . ' = ' . Type::getType(Types::BOOLEAN)->convertToDatabaseValueSQL('?', $platform))
                ->setParameter($position++, false, Types::BOOLEAN);
        }
        if ($category !== null) {
            $query->andWhere($category === 0 ? $categoryColumn . ' IS NULL'
                : $categoryColumn . ' = ' . Type::getType(Types::BIGINT)->convertToDatabaseValueSQL('?', $platform));
            if ($category !== 0) {
                $query->setParameter($position, $category, Types::BIGINT);
            }
        }
        $rows = $query->orderBy('s.' . $quote->getColumnName('name', $software, $platform))
            ->addOrderBy('v.' . $quote->getColumnName('name', $version, $platform))
            ->addOrderBy('i.' . $quote->getColumnName('id', $installation, $platform))
            ->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            if ($row['dateinstall'] instanceof DateTimeInterface) {
                $row['dateinstall'] = $row['dateinstall']->format('Y-m-d');
            }
            $row['softwarecategories_id'] ??= 0;
            $row['softvalid'] = (int)$row['softvalid'];
            $row['is_dynamic'] = (int)$row['is_dynamic'];
        }
        return $rows;
    }

    /**
     * Current link fields for the exact targets selected before the installation table's form callbacks.
     * These renderer-only rows do not replace getLink(), its item rights, or version parent checks.
     * The private read owner admits the native path; supplied managers default to ORM.
     */
    public function displayDataForInstallations(array $installations, bool $native = false): array
    {
        $data = ['softwares' => [], 'versions' => [], 'categories' => []];
        foreach (array_chunk(array_values(array_unique(array_column($installations, 'softwares_id'))), 250) as $ids) {
            $rows = $native
                ? $this->nativeDisplayRows(Software::class, ['id', 'name', 'is_recursive', 'is_template'], ['entities_id' => 'entities'], $ids)
                : $this->em->createQueryBuilder()
                    ->select('s.id, s.name, IDENTITY(s.entities) AS entities_id, s.is_recursive, s.is_template')
                    ->from(Software::class, 's')
                    ->where('s.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->getScalarResult();
            foreach ($rows as $row) {
                $row['is_recursive'] = (int)$row['is_recursive'];
                $row['is_template'] = (int)$row['is_template'];
                $data['softwares'][(int)$row['id']] = $native ? [
                    'id' => $row['id'], 'name' => $row['name'], 'entities_id' => $row['entities_id'],
                    'is_recursive' => $row['is_recursive'], 'is_template' => $row['is_template'],
                ] : $row;
            }
        }
        foreach (array_chunk(array_values(array_unique(array_column($installations, 'verid'))), 250) as $ids) {
            $rows = $native
                ? $this->nativeDisplayRows(SoftwareVersion::class, ['id', 'name'], ['softwares_id' => 'softwares'], $ids)
                : $this->em->createQueryBuilder()
                    ->select('v.id, v.name, IDENTITY(v.softwares) AS softwares_id')
                    ->from(SoftwareVersion::class, 'v')
                    ->where('v.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->getScalarResult();
            foreach ($rows as $row) {
                $data['versions'][(int)$row['id']] = $row;
            }
        }
        $categories = array_filter(array_unique(array_column($installations, 'softwarecategories_id')));
        foreach (array_chunk(array_values($categories), 250) as $ids) {
            $rows = $native
                ? $this->nativeDisplayRows(SoftwareCategory::class, ['id', 'name', 'completename'], [], $ids)
                : $this->em->createQueryBuilder()
                    ->select('c.id, c.name, c.completename')
                    ->from(SoftwareCategory::class, 'c')
                    ->where('c.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->getScalarResult();
            foreach ($rows as $row) {
                $data['categories'][(int)$row['id']] = $row;
            }
        }
        return $data;
    }

    /** Fixed display columns only; scalar hydration deliberately skips PHP type conversion. */
    private function nativeDisplayRows(string $class, array $fields, array $references, array $ids): array
    {
        $metadata = $this->em->getClassMetadata($class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $select = [];
        foreach ($fields as $field) {
            $select[] = Type::getType($metadata->getTypeOfField($field))->convertToPHPValueSQL(
                'r.' . $quote->getColumnName($field, $metadata, $platform),
                $platform
            ) . ' AS ' . $field;
        }
        foreach ($references as $alias => $field) {
            $reference = $metadata->associationMappings[$field];
            $select[] = 'r.' . $quote->getJoinColumnName($reference->joinColumns[0], $metadata, $platform)
            . ' AS ' . $alias;
        }
        return $connection->createQueryBuilder()
            ->select(...$select)
            ->from($quote->getTableName($metadata, $platform), 'r')
            ->where('r.' . $quote->getColumnName('id', $metadata, $platform) . ' IN (?)')
            // Match ORM inference: an array is a binding enum, not per-element mapped SQL conversion.
            ->setParameter(0, $ids, is_int(reset($ids)) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** Effective license IDs retain assignment multiplicity and their original ordering. */
    public function nativeEffectiveLicenseIdsForVersions(string $kind, int $owner, array $versions): array
    {
        $rows = [];
        if ($versions === []) {
            return $rows;
        }
        $assignment = $this->em->getClassMetadata(ItemSoftwareLicense::class);
        $license = $this->em->getClassMetadata(SoftwareLicense::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $ownerReference = $assignment->associationMappings[ItemSoftwareLicense::referenceAssociation($kind)];
        $licenseReference = $assignment->associationMappings['softwarelicenses'];
        $useReference = $license->associationMappings['useVersion'];
        $buyReference = $license->associationMappings['buyVersion'];
        $use = 'l.' . $quote->getJoinColumnName($useReference->joinColumns[0], $license, $platform);
        $buy = 'l.' . $quote->getJoinColumnName($buyReference->joinColumns[0], $license, $platform);
        $licenseId = 'l.' . $quote->getColumnName('id', $license, $platform);
        $ownerType = Type::getType(Types::BIGINT);
        foreach (array_chunk(array_values(array_unique(array_map('intval', $versions))), 250) as $batch) {
            $result = $connection->createQueryBuilder()
                ->select(
                    Type::getType($license->getTypeOfField('id'))->convertToPHPValueSQL($licenseId, $platform) . ' AS id',
                    $use . ' AS use_version',
                    $buy . ' AS buy_version'
                )
                ->from($quote->getTableName($assignment, $platform), 'i')
                ->innerJoin(
                    'i',
                    $quote->getTableName($license, $platform),
                    'l',
                    'i.' . $quote->getJoinColumnName($licenseReference->joinColumns[0], $assignment, $platform) . ' = ' . $licenseId
                )
                ->where(
                    'i.' . $quote->getJoinColumnName($ownerReference->joinColumns[0], $assignment, $platform)
                    . ' = ' . $ownerType->convertToDatabaseValueSQL('?', $platform)
                )
                ->andWhere($use . ' IN (?) OR (' . $use . ' IS NULL AND ' . $buy . ' IN (?))')
                ->setParameter(0, $owner, Types::BIGINT)
                ->setParameter(1, $batch, ArrayParameterType::INTEGER)
                ->setParameter(2, $batch, ArrayParameterType::INTEGER)
                ->orderBy('i.' . $quote->getColumnName('id', $assignment, $platform))
                ->executeQuery();
            foreach ($result->fetchAllAssociative() as $row) {
                $rows[(int)($row['use_version'] ?? $row['buy_version'])][] = (int)$row['id'];
            }
        }
        return $rows;
    }

    /** API expansion already has an authorized owner; it has no UI category filter. */
    public function apiForSubject(string $kind, int $id): array
    {
        $rows = $this->subjectVersions($kind, $id)
            ->select('IDENTITY(s.softwarecategories) AS softwarecategories_id, s.id AS softwares_id, v.id AS softwareversions_id')
            ->addSelect('i.is_dynamic AS is_dynamic, IDENTITY(v.states) AS states_id, s.is_valid AS is_valid')
            ->andWhere('i.is_deleted = :deleted')
            ->setParameter('deleted', false, Types::BOOLEAN)
            ->orderBy('s.name')
            ->addOrderBy('v.name')
            ->addOrderBy('i.id')
            ->getQuery()
            ->getScalarResult();
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
        $requested = [];
        $tuples = [];
        foreach ($installations as $installation) {
            $kind = $installation['itemtype'];
            $owner = (int)$installation['items_id'];
            $version = (int)$installation['softwareversions_id'];
            if (isset($requested[$kind][$owner][$version])) {
                continue;
            }
            $requested[$kind][$owner][$version] = true;
            $tuples[] = [ItemSoftwareLicense::referenceAssociation($kind), $owner, $version];
        }
        $rows = [];
        // This view has no row limit. Bound both the parameter count and OR expression size.
        foreach (array_chunk($tuples, 250) as $batch) {
            $query = $this->em->createQueryBuilder()
                ->select('DISTINCT i.itemtype AS itemtype, i.items_id AS owner, v.id AS version')
                ->addSelect('l.id AS id, l.name AS name, l.serial AS serial, t.name AS type')
                ->from(ItemSoftwareLicense::class, 'i')
                ->innerJoin('i.softwarelicenses', 'l')
                ->innerJoin(SoftwareVersion::class, 'v', 'WITH', 'l.useVersion = v.id OR l.buyVersion = v.id')
                ->leftJoin('l.softwarelicensetypes', 't')
                ->orderBy('l.id');
            $predicates = [];
            foreach ($batch as $index => [$association, $owner, $version]) {
                $predicates[] = '(IDENTITY(i.' . $association . ') = :owner' . $index . ' AND v.id = :version' . $index . ')';
                $query->setParameter('owner' . $index, $owner, Types::BIGINT)
                    ->setParameter('version' . $index, $version, Types::BIGINT);
            }
            foreach ($query->where(implode(' OR ', $predicates))
                ->getQuery()
                ->getScalarResult() as $row) {
                // Each unique tuple belongs to one batch, retaining its license-ID order.
                $rows[$row['itemtype']][(int)$row['owner']][(int)$row['version']][(int)$row['id']] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'serial' => $row['serial'],
                    'type' => $row['type'],
                ];
            }
        }
        return $rows;
    }

    /**
     * Assigned license IDs for the selected owner's installed versions, in allocation order.
     * A use version overrides the purchase version; duplicate allocations remain visible.
     *
     * @return array<int, list<int>>
     */
    public function effectiveLicenseIdsForVersions(string $kind, int $owner, array $versions): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $versions))), 250) as $batch) {
            $query = $this->em->createQueryBuilder()
                ->select('l.id AS id, IDENTITY(l.useVersion) AS use_version, IDENTITY(l.buyVersion) AS buy_version')
                ->from(ItemSoftwareLicense::class, 'i')
                ->innerJoin('i.softwarelicenses', 'l')
                ->where('IDENTITY(i.' . ItemSoftwareLicense::referenceAssociation($kind) . ') = :owner')
                ->andWhere('IDENTITY(l.useVersion) IN (:versions) OR (l.useVersion IS NULL AND IDENTITY(l.buyVersion) IN (:versions))')
                ->setParameter('owner', $owner, Types::BIGINT)
                ->setParameter('versions', $batch)
                ->orderBy('i.id');
            foreach ($query->getQuery()->getScalarResult() as $row) {
                $rows[(int)($row['use_version'] ?? $row['buy_version'])][] = (int)$row['id'];
            }
        }
        return $rows;
    }

    /** Presentation deduplicates licence IDs while persisted assignments retain multiplicity. */
    public function licensesForInstallation(string $kind, int $id, int $version): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('l, t')
            ->from(SoftwareLicense::class, 'l')
            ->innerJoin(ItemSoftwareLicense::class, 'i', 'WITH', 'i.softwarelicenses = l.id')
            ->leftJoin('l.softwarelicensetypes', 't')
            ->where('IDENTITY(i.' . ItemSoftwareLicense::referenceAssociation($kind) . ') = :owner')
            ->andWhere('IDENTITY(l.useVersion) = :version OR IDENTITY(l.buyVersion) = :version')
            ->setParameter('owner', $id, Types::BIGINT)
            ->setParameter('version', $version, Types::BIGINT)
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
        $class = $licenses ? ItemSoftwareLicense::class : ItemSoftwareVersion::class;
        $query = $this->em->createQueryBuilder()
            ->select('i')
            ->from($class, 'i')
            ->where('IDENTITY(i.' . $class::referenceAssociation($kind) . ') = :owner')
            ->setParameter('owner', $id, Types::BIGINT)
            ->orderBy('i.id');
        $records = new RecordRepository($this->em);
        return array_map($records->toRow(...), $query->getQuery()->getResult());
    }

    private function subjectVersions(string $kind, int $id): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(ItemSoftwareVersion::class, 'i')
            ->innerJoin('i.softwareversions', 'v')
            ->innerJoin('v.softwares', 's')
            ->where('IDENTITY(i.' . ItemSoftwareVersion::referenceAssociation($kind) . ') = :owner')
            ->setParameter('owner', $id, Types::BIGINT);
    }
}
