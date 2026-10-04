<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;
use itsmng\Domain\SoftwareAssignmentCancelled;
use itsmng\Domain\SoftwareAssignmentService;

/** Software inventory queries; permissions and lifecycle hooks remain with callers. */
final class SoftwareRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function softwareForTransfer(int $entity, string $name, ?int $manufacturer): ?int
    {
        $query = $this->em->createQueryBuilder()->select('s.id AS id')->from(Entity\Software::class, 's')
            ->where('s.entities = :entity AND s.name = :name')
            ->setParameter('entity', $entity, Types::INTEGER)->setParameter('name', $name, Types::STRING);
        // An unselected source manufacturer historically does not constrain reuse.
        if ($manufacturer !== null) {
            $query->andWhere('s.manufacturers = :manufacturer')->setParameter('manufacturer', $manufacturer, Types::INTEGER);
        }
        $rows = $query->orderBy('s.id')->setMaxResults(1)->getQuery()->getScalarResult();
        return $rows ? (int)$rows[0]['id'] : null;
    }

    public function versionForTransfer(int $software, ?string $name, bool $currentRead = false): ?int
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $query = $this->em->createQueryBuilder()->select('v.id AS id')->from(Entity\SoftwareVersion::class, 'v')
            ->where('v.softwares = :software')->andWhere($name === null ? 'v.name IS NULL' : 'v.name = :name')
            ->setParameter('software', $software, Types::INTEGER)
            ->orderBy('v.id')->setMaxResults(1);
        if ($name !== null) {
            $query->setParameter('name', $name, Types::STRING);
        }
        $rows = $query->getQuery()->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)->getScalarResult();
        return $rows ? (int)$rows[0]['id'] : null;
    }

    /** Include templates and trashed licenses, matching the internal transfer selection. */
    public function licenseForTransfer(int $software, string $name, string $serial, bool $currentRead = false): ?array
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->em->createQueryBuilder()->select('l.id AS id', 'l.number AS number')->from(Entity\SoftwareLicense::class, 'l')
            ->where('l.softwares = :software AND l.name = :name AND l.serial = :serial')
            ->setParameter('software', $software, Types::INTEGER)->setParameter('name', $name, Types::STRING)
            ->setParameter('serial', $serial, Types::STRING)->orderBy('l.id')->setMaxResults(1)->getQuery()
            ->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)->getScalarResult();
        return $rows ? ['id' => (int)$rows[0]['id'], 'number' => (int)$rows[0]['number']] : null;
    }

    public function licensesForTransfer(int $software): array
    {
        $rows = $this->em->createQueryBuilder()->select('l.id AS id')->from(Entity\SoftwareLicense::class, 'l')
            ->where('l.softwares = :software')->setParameter('software', $software, Types::INTEGER)
            ->orderBy('l.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function versionsForTransfer(int $software): array
    {
        $rows = $this->em->createQueryBuilder()->select('v.id AS id')->from(Entity\SoftwareVersion::class, 'v')
            ->where('v.softwares = :software')->setParameter('software', $software, Types::INTEGER)
            ->orderBy('v.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    /** Deleted installations and template licenses still retain their references. */
    public function isVersionReferenced(int $version): bool
    {
        $license = $this->em->createQueryBuilder()->select('l.id')->from(Entity\SoftwareLicense::class, 'l')
            ->where('l.buyVersion = :version OR l.useVersion = :version')->setParameter('version', $version, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult();
        if ($license) {
            return true;
        }
        return (bool)$this->em->createQueryBuilder()->select('i.id')->from(Entity\ItemSoftwareVersion::class, 'i')
            ->where('i.softwareversions = :version')->setParameter('version', $version, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult();
    }

    public function hasInventory(int $software): bool
    {
        $license = $this->em->createQueryBuilder()->select('l.id')->from(Entity\SoftwareLicense::class, 'l')
            ->where('l.softwares = :software')->setParameter('software', $software, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult();
        if ($license) {
            return true;
        }
        return (bool)$this->em->createQueryBuilder()->select('v.id')->from(Entity\SoftwareVersion::class, 'v')
            ->where('v.softwares = :software')->setParameter('software', $software, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult();
    }

    public function hasInvalidLicense(int $software, bool $currentRead = false): bool
    {
        if ($currentRead) {
            \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        return (bool)$this->em->createQueryBuilder()->select('l.id')->from(Entity\SoftwareLicense::class, 'l')
            ->where('l.softwares = :software AND l.is_valid = :invalid')
            ->setParameter('software', $software, Types::INTEGER)->setParameter('invalid', false, Types::BOOLEAN)
            ->orderBy('l.id')->setMaxResults(1)->getQuery()
            ->setLockMode($currentRead ? LockMode::PESSIMISTIC_READ : LockMode::NONE)->getScalarResult();
    }

    public function versions(int $software, array $excluded = []): array
    {
        $query = $this->em->createQueryBuilder()->select('v', 's.name AS sname')
            ->from(Entity\SoftwareVersion::class, 'v')
            ->leftJoin('v.states', 's')
            ->where('v.softwares = :software')->setParameter('software', $software, Types::INTEGER)
            ->orderBy('v.name')->addOrderBy('v.id');
        if ($excluded) {
            $query->andWhere('v.id NOT IN (:excluded)')->setParameter('excluded', array_map('intval', $excluded));
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['sname' => $result['sname']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    /** Each software appears once even when several accessible licenses reference it. */
    public function withLicenses(array $licenseScope): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT s.id AS id', 's.name AS name')
            ->from(Entity\SoftwareLicense::class, 'r')->innerJoin('r.softwares', 's');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\SoftwareLicense::class)))->where($licenseScope))
            ->andWhere('s.is_deleted = :inactive AND s.is_template = :inactive')->setParameter('inactive', false, Types::BOOLEAN)
            ->orderBy('s.name')->addOrderBy('s.id');
        return $query->getQuery()->getScalarResult();
    }

    public function mergeCandidates(int $software, string $name, array $entityScope): array
    {
        $query = $this->em->createQueryBuilder()->select('r.id AS id', 'r.name AS name', 'e.completename AS entity')
            ->from(Entity\Software::class, 'r')->leftJoin('r.entities', 'e');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Software::class), false))->where([
            'id' => ['!=', $software], 'name' => $name, 'is_deleted' => false, 'is_template' => false,
        ] + $entityScope))->orderBy('e.completename')->addOrderBy('r.id');
        return $query->getQuery()->getScalarResult();
    }

    /** Unlimited licenses take precedence over the sum of finite quantities. */
    public function licenseQuantity(int $software, array $entityScope): int
    {
        $query = $this->em->createQueryBuilder()->select(
            'SUM(CASE WHEN r.number = -1 THEN 1 ELSE 0 END) AS unlimited',
            'SUM(CASE WHEN r.number > 0 THEN r.number ELSE 0 END) AS quantity'
        )->from(Entity\SoftwareLicense::class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata(Entity\SoftwareLicense::class));
        $query->where($criteria->where(['softwares_id' => $software, 'is_template' => false] + $entityScope));
        $row = $query->getQuery()->getSingleResult();
        return (int)$row['unlimited'] > 0 ? -1 : (int)$row['quantity'];
    }

    /** License list labels come from mapped associations, with stable cross-provider ordering. */
    public function licenses(int $software, array $scope, string $sort, string $direction, int $limit, int $offset): array
    {
        $columns = ['name' => 'r.name', 'serial' => 'r.serial', 'number' => 'r.number',
            'entity' => 'e.completename', 'typename' => 't.name', 'buyname' => 'b.name',
            'usename' => 'u.name', 'expire' => 'r.expire', 'statename' => 's.name'];
        $direction = $direction === 'DESC' ? 'DESC' : 'ASC';
        $query = $this->em->createQueryBuilder()->select(
            'r',
            'b.name AS buyname',
            'u.name AS usename',
            'e.completename AS entity',
            't.name AS typename',
            's.name AS statename'
        )
            ->from(Entity\SoftwareLicense::class, 'r')->leftJoin('r.buyVersion', 'b')->leftJoin('r.useVersion', 'u')
            ->leftJoin('r.entities', 'e')->leftJoin('r.softwarelicensetypes', 't')->leftJoin('r.states', 's');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\SoftwareLicense::class)))->where([
            'softwares_id' => $software, 'is_template' => false,
        ] + $scope));
        foreach (isset($columns[$sort]) ? [$columns[$sort]] : ['e.completename', 'r.name'] as $index => $column) {
            $nullOrder = 'null_order_' . $index;
            $query->addSelect('CASE WHEN ' . $column . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN ' . $nullOrder)
                ->addOrderBy($nullOrder, $direction)->addOrderBy($column, $direction);
        }
        $query->addOrderBy('r.id', $direction)->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit));
        return $this->licenseRows($query);
    }

    /** Calendar-day cutoff; an existing dated license alert suppresses repeat selection. */
    public function expiringLicenses(int $entity, int $days, ?\DateTimeImmutable $today = null): array
    {
        $cutoff = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0)->modify(sprintf('%+d days', $days));
        $query = $this->em->createQueryBuilder()->select('r', 's.name AS softname')
            ->from(Entity\SoftwareLicense::class, 'r')->innerJoin('r.softwares', 's')
            ->where('IDENTITY(s.entities) = :entity AND s.is_deleted = :inactive AND s.is_template = :inactive')
            ->setParameter('entity', $entity, Types::INTEGER)->setParameter('inactive', false, Types::BOOLEAN)
            ->andWhere('r.expire < :cutoff')->setParameter('cutoff', $cutoff, Types::DATE_IMMUTABLE)
            ->andWhere('NOT EXISTS (SELECT a.id FROM ' . Entity\Alert::class . ' a WHERE a.softwareLicense = r AND a.date IS NOT NULL)')
            ->orderBy('r.id');
        return $this->licenseRows($query);
    }

    private function licenseRows(\Doctrine\ORM\QueryBuilder $query): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $license = $result[0];
            unset($result[0]);
            $rows[] = $records->toRow($license) + $result;
            $this->em->detach($license);
        }
        return $rows;
    }

    public function updateAssetFlags(string $itemtype, int $item, bool $template, bool $deleted): void
    {
        $this->em->createQueryBuilder()->update(Entity\ItemSoftwareVersion::class, 'i')
            ->set('i.is_template_item', ':template')->setParameter('template', $template, Types::BOOLEAN)
            ->set('i.is_deleted_item', ':deleted')->setParameter('deleted', $deleted, Types::BOOLEAN)
            ->where('i.itemtype = :type')->setParameter('type', $itemtype, Types::STRING)
            ->andWhere('i.items_id = :item')->setParameter('item', $item, Types::INTEGER)
            ->getQuery()->execute();
    }

    /**
     * Move versions and licenses atomically, then run the source software lifecycle.
     * An installation already present at the destination keeps its metadata.
     * Bulk changes intentionally preserve the former merge's no-per-version-hook behavior.
     */
    public function merge(int $target, int $entity, array $sources, callable $trash, ?callable $progress = null): void
    {
        $sources = array_values(array_diff(array_unique(array_map('intval', $sources)), [$target]));
        if (!$sources) {
            return;
        }
        $assignments = SoftwareAssignmentService::forConnection($this->em->getConnection());
        \itsmng\Domain\SoftwareMutation::assertTransactionalStorage($GLOBALS['DB'], [
            \Software::getTable(), \SoftwareVersion::getTable(), \SoftwareLicense::getTable(), \Item_SoftwareVersion::getTable(),
            \Log::getTable(), \QueuedNotification::getTable(),
        ]);
        \itsmng\Database\OwnedMutationFrame::run($this->em->getConnection(), fn () => $assignments->withSoftwareHierarchy(
            [...$sources, $target],
            function () use ($target, $entity, $sources, $trash, $progress, $assignments): void {
                $ids = [...$sources, $target];
                $lock = $this->em->createQueryBuilder()->select('s.id AS id', 's.is_template AS is_template')->from(Entity\Software::class, 's')
                    ->where('s.id IN (:ids)')->setParameter('ids', $ids)->orderBy('s.id')->getQuery();
                \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
                $lock->setLockMode(LockMode::PESSIMISTIC_WRITE);
                $locked = $lock->getScalarResult();
                if (count($locked) !== count($ids)) {
                    throw new \RuntimeException('A software selected for merging no longer exists.');
                }
                foreach ($locked as $software) {
                    if (in_array((int)$software['id'], $sources, true) && $software['is_template']) {
                        throw new SoftwareAssignmentCancelled('A software template cannot be removed by merging.');
                    }
                }
                $assignments->lockSoftwareAssignments($ids);
                $records = new RecordRepository($this->em);
                \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
                $versionRows = $this->em->createQueryBuilder()->select('v')->from(Entity\SoftwareVersion::class, 'v')
                    ->where('v.softwares IN (:sources)')->setParameter('sources', $sources)->orderBy('v.id')->getQuery()
                    ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_READ)->getResult();
                $versions = array_map($records->toRow(...), $versionRows);
                $done = 0;
                foreach ($versions as $from) {
                    $destination = $this->versionForTransfer($target, $from['name'], currentRead: true);
                    if ($destination !== null) {
                        $this->moveVersionReferences((int)$from['id'], $destination);
                        $this->em->createQueryBuilder()->delete(Entity\SoftwareVersion::class, 'v')
                            ->where('v.id = :id')->setParameter('id', $from['id'], Types::INTEGER)->getQuery()->execute();
                    } else {
                        $this->em->createQueryBuilder()->update(Entity\SoftwareVersion::class, 'v')
                            ->set('v.softwares', ':target')->setParameter('target', $target, Types::INTEGER)
                            ->set('v.entities', ':entity')->setParameter('entity', $entity, Types::INTEGER)
                            ->where('v.id = :id')->setParameter('id', $from['id'], Types::INTEGER)->getQuery()->execute();
                    }
                    ++$done;
                    if ($progress !== null) {
                        $progress($done, count($versions) + 1);
                    }
                }
                $this->em->createQueryBuilder()->update(Entity\SoftwareLicense::class, 'l')
                    ->set('l.softwares', ':target')->setParameter('target', $target, Types::INTEGER)
                    ->where('l.softwares IN (:sources)')->setParameter('sources', $sources)->getQuery()->execute();
                // Licence ownership changed without per-licence hooks. Reconcile
                // every old/new Software on this same writer before trashing sources.
                foreach ($locked as $software) {
                    SoftwareAssignmentCancelled::requireSuccess(
                        $assignments->refreshSoftwareValidity((int)$software['id']),
                        'Merged Software validity refresh'
                    );
                }
                foreach ($sources as $source) {
                    if (!$trash($source)) {
                        throw new SoftwareAssignmentCancelled('Unable to trash software after merging.');
                    }
                }
                if ($progress !== null) {
                    $progress(count($versions) + 1, count($versions) + 1);
                }
            }
        ));
    }

    /** Dictionary renames retain entity ownership; a merged version uses its lifecycle. */
    public function moveDictionaryVersion(int $target, int $version, ?string $name, callable $remove): void
    {
        $assignments = SoftwareAssignmentService::forConnection($this->em->getConnection());
        $source = (new SoftwareAssignmentRepository($this->em))->softwareForVersion($version);
        \itsmng\Database\OwnedMutationFrame::run($this->em->getConnection(), fn () => $assignments->withSoftwareHierarchy(
            [$source, $target],
            function () use ($target, $version, $name, $remove): void {
                $destination = (new SoftwareDictionaryRepository($this->em))->versionId($target, $name);
                if ($destination === $version) {
                    return;
                }
                if ($destination === -1) {
                    $this->em->createQueryBuilder()->update(Entity\SoftwareVersion::class, 'v')
                        ->set('v.name', ':name')->setParameter('name', $name, Types::STRING)
                        ->set('v.softwares', ':target')->setParameter('target', $target, Types::INTEGER)
                        ->where('v.id = :id')->setParameter('id', $version, Types::INTEGER)->getQuery()->execute();
                    return;
                }
                $this->moveVersionReferences($version, $destination);
                if (!$remove($version)) {
                    throw new \RuntimeException('Unable to delete software version after dictionary merging.');
                }
            }
        ));
    }

    private function moveVersionReferences(int $source, int $destination): void
    {
        \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        foreach (['buyVersion', 'useVersion'] as $field) {
            $this->em->createQueryBuilder()->update(Entity\SoftwareLicense::class, 'l')
                ->set('l.' . $field, ':destination')->setParameter('destination', $destination, Types::INTEGER)
                ->where('l.' . $field . ' = :source')->setParameter('source', $source, Types::INTEGER)->getQuery()->execute();
        }
        $this->moveInstallations($source, $destination);
    }

    private function moveInstallations(int $source, int $destination): void
    {
        \itsmng\Database\MySQLConnection::assertCurrentReads($this->em->getConnection());
        $subjects = [];
        foreach (\itsmng\Database\EntityRegistry::discriminatedReferences('glpi_items_softwareversions')['items_id']['selections'] as $kind => $selection) {
            $association = Entity\ItemSoftwareVersion::referenceAssociation($kind);
            $subjects[] = 'IDENTITY(d.' . $association . ') = IDENTITY(i.' . $association . ')';
        }
        // Snapshot collisions before moving links to respect the installation unique key.
        $duplicates = $this->em->createQueryBuilder()->select('i.id AS id')->from(Entity\ItemSoftwareVersion::class, 'i')
            ->innerJoin(Entity\ItemSoftwareVersion::class, 'd', 'WITH', '(' . implode(' OR ', $subjects) . ') AND d.softwareversions = :destination')
            ->setParameter('destination', $destination, Types::INTEGER)
            ->where('i.softwareversions = :source')->setParameter('source', $source, Types::INTEGER)
            ->orderBy('i.id')->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)->getScalarResult();
        foreach (array_chunk(array_map('intval', array_column($duplicates, 'id')), 1000) as $ids) {
            $this->em->createQueryBuilder()->delete(Entity\ItemSoftwareVersion::class, 'i')
                ->where('i.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
        }
        $this->em->createQueryBuilder()->update(Entity\ItemSoftwareVersion::class, 'i')
            ->set('i.softwareversions', ':destination')->setParameter('destination', $destination, Types::INTEGER)
            ->where('i.softwareversions = :source')->setParameter('source', $source, Types::INTEGER)
            ->getQuery()->execute();
    }
}
