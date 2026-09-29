<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Software inventory queries; permissions and lifecycle hooks remain with callers. */
final class SoftwareRepository
{
    public function __construct(private EntityManager $em)
    {
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
            ->andWhere('NOT EXISTS (SELECT a.id FROM ' . Entity\Alert::class . ' a WHERE a.items_id = r.id AND a.itemtype = :type AND a.date IS NOT NULL)')
            ->setParameter('type', 'SoftwareLicense', Types::STRING)->orderBy('r.id');
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
        $this->em->getConnection()->transactional(function () use ($target, $entity, $sources, $trash, $progress): void {
            $ids = [...$sources, $target];
            $lock = $this->em->createQueryBuilder()->select('s.id AS id')->from(Entity\Software::class, 's')
                ->where('s.id IN (:ids)')->setParameter('ids', $ids)->orderBy('s.id')->getQuery();
            $lock->setLockMode(LockMode::PESSIMISTIC_WRITE);
            if (count($lock->getScalarResult()) !== count($ids)) {
                throw new \RuntimeException('A software selected for merging no longer exists.');
            }
            $records = new RecordRepository($this->em);
            $versions = $records->matching('glpi_softwareversions', ['softwares_id' => $sources], ['id']);
            $done = 0;
            foreach ($versions as $from) {
                $matches = $records->matching('glpi_softwareversions', ['softwares_id' => $target, 'name' => $from['name']], ['id'], 1, legacyValues: false);
                if ($matches) {
                    $destination = (int)$matches[0]['id'];
                    foreach (['buyVersion', 'useVersion'] as $field) {
                        $this->em->createQueryBuilder()->update(Entity\SoftwareLicense::class, 'l')
                            ->set('l.' . $field, ':destination')->setParameter('destination', $destination, Types::INTEGER)
                            ->where('l.' . $field . ' = :source')->setParameter('source', $from['id'], Types::INTEGER)
                            ->getQuery()->execute();
                    }
                    $this->moveInstallations((int)$from['id'], $destination);
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
            foreach ($sources as $source) {
                if (!$trash($source)) {
                    throw new \RuntimeException('Unable to trash software after merging.');
                }
            }
            if ($progress !== null) {
                $progress(count($versions) + 1, count($versions) + 1);
            }
        });
    }

    private function moveInstallations(int $source, int $destination): void
    {
        // Snapshot collisions before moving links to respect the installation unique key.
        $duplicates = $this->em->createQueryBuilder()->select('i.id AS id')->from(Entity\ItemSoftwareVersion::class, 'i')
            ->innerJoin(Entity\ItemSoftwareVersion::class, 'd', 'WITH', 'd.itemtype = i.itemtype AND d.items_id = i.items_id AND d.softwareversions = :destination')
            ->setParameter('destination', $destination, Types::INTEGER)
            ->where('i.softwareversions = :source')->setParameter('source', $source, Types::INTEGER)
            ->getQuery()->getScalarResult();
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
