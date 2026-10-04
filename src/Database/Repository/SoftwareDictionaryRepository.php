<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Domain\SoftwareAssignmentCancelled;
use itsmng\Domain\SoftwareAssignmentService;
use itsmng\Domain\SoftwareMutation;

/** Dictionary replay selections use the owning software associations. */
final class SoftwareDictionaryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function groups(?int $manufacturer): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->select(
            's.name AS name',
            'm.name AS manufacturer',
            'IDENTITY(s.manufacturers) AS manufacturers_id',
            'IDENTITY(s.entities) AS entities_id',
            's.is_helpdesk_visible AS helpdesk',
            'IDENTITY(s.softwarecategories) AS softwarecategories_id',
            'MIN(s.id) AS HIDDEN first_id'
        )->from(Entity\Software::class, 's')->leftJoin('s.manufacturers', 'm')
            ->where('s.is_deleted = :inactive AND s.is_template = :inactive')
            ->setParameter('inactive', false, Types::BOOLEAN)
            ->groupBy('s.name, m.name, s.manufacturers, s.entities, s.is_helpdesk_visible, s.softwarecategories')
            ->orderBy('first_id');
        if ($manufacturer !== null) {
            $query->andWhere('s.manufacturers = :manufacturer')->setParameter('manufacturer', $manufacturer, Types::INTEGER);
        }
        return $query;
    }

    public function groupCount(?int $manufacturer): int
    {
        // DQL cannot count a composite DISTINCT tuple. Count the scalar stream
        // without building a second full copy of the replay input in PHP.
        $count = 0;
        foreach ($this->groups($manufacturer)->getQuery()->toIterable() as $row) {
            ++$count;
        }
        return $count;
    }

    public function replayGroups(?int $manufacturer, int $offset): iterable
    {
        return $this->groups($manufacturer)->setFirstResult(max(0, $offset))->getQuery()->toIterable();
    }

    public function matchingSoftware(?string $name, ?int $manufacturer): array
    {
        $query = $this->em->createQueryBuilder()->select('s.id AS id')->from(Entity\Software::class, 's')->orderBy('s.id');
        $query->where($name === null ? 's.name IS NULL' : 's.name = :name');
        if ($name !== null) {
            $query->setParameter('name', $name, Types::STRING);
        }
        $query->andWhere($manufacturer === null ? 's.manufacturers IS NULL' : 's.manufacturers = :manufacturer');
        if ($manufacturer !== null) {
            $query->setParameter('manufacturer', $manufacturer, Types::INTEGER);
        }
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'));
    }

    public function replaySoftware(int $id): ?array
    {
        return $this->em->createQueryBuilder()->select('s.id AS id', 's.name AS name', 'IDENTITY(s.entities) AS entities_id', 'm.name AS manufacturer')
            ->from(Entity\Software::class, 's')->leftJoin('s.manufacturers', 'm')
            ->where('s.id = :id AND s.is_template = :inactive')->setParameter('id', $id, Types::INTEGER)
            ->setParameter('inactive', false, Types::BOOLEAN)->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SCALAR);
    }

    public function unusedSoftware(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $versions = $this->em->createQueryBuilder()->select('v.id')->from(Entity\SoftwareVersion::class, 'v')->where('v.softwares = s.id');
        $query = $this->em->createQueryBuilder()->select('s.id AS id')->from(Entity\Software::class, 's')
            ->where('s.id IN (:ids) AND s.is_deleted = :inactive')->setParameter('ids', array_map('intval', $ids))
            ->setParameter('inactive', false, Types::BOOLEAN)->andWhere('NOT EXISTS (' . $versions->getDQL() . ')')->orderBy('s.id');
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'));
    }

    public function versionId(int $software, ?string $name): int
    {
        $query = $this->em->createQueryBuilder()->select('v.id AS id')->from(Entity\SoftwareVersion::class, 'v')
            ->where('v.softwares = :software')->setParameter('software', $software, Types::INTEGER)->orderBy('v.id')->setMaxResults(1);
        $query->andWhere($name === null ? 'v.name IS NULL' : 'v.name = :name');
        if ($name !== null) {
            $query->setParameter('name', $name, Types::STRING);
        }
        $rows = $query->getQuery()->getScalarResult();
        return $rows ? (int)$rows[0]['id'] : -1;
    }

    public function moveLicenses(int $source, int $target): bool
    {
        global $DB;

        try {
            $service = SoftwareAssignmentService::forConnection($this->em->getConnection());
        } catch (SoftwareAssignmentCancelled) {
            return false;
        }
        $software = new \Software();
        if ($source <= 0 || $target <= 0 || !$software->getFromDB($target)) {
            return false;
        }
        return SoftwareMutation::run($DB, $software, LifecycleModelJournal::state($software), fn () => $service->withSoftwareHierarchy([$source, $target], function () use ($DB, $source, $target, $service): bool {
            SoftwareMutation::assertTransactionalStorage($DB, [\Software::getTable(), \SoftwareLicense::getTable()]);
            $service->lockSoftwareAssignments([$source, $target]);
            if ($source === $target) {
                return true;
            }
            // Preserve the dictionary's intentional bulk ownership change:
            // quantities, licence metadata and per-licence hooks stay intact.
            $this->em->createQueryBuilder()->update(Entity\SoftwareLicense::class, 'l')
                ->set('l.softwares', ':target')->setParameter('target', $target, Types::BIGINT)
                ->where('l.softwares = :source')->setParameter('source', $source, Types::BIGINT)->getQuery()->execute();
            foreach (SoftwareAssignmentRepository::identifiers([$source, $target]) as $id) {
                SoftwareAssignmentCancelled::requireSuccess(
                    $service->refreshSoftwareValidity($id),
                    'Dictionary owning software validity'
                );
            }
            return true;
        }));
    }
}
