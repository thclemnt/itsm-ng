<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;

/** OS assignments are owned by an asset, independently of nullable component labels. */
final class OperatingSystemAssignmentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Deleted inventory remains an assignment: a locked row cannot be duplicated. */
    public function hasAssignment(string $itemtype, int $owner, ?int $operatingSystem, ?int $architecture, ?int $exceptId = null): bool
    {
        $query = $this->em->createQueryBuilder()
            ->select('r.id')->from(Entity\ItemOperatingSystem::class, 'r')
            ->where('IDENTITY(r.' . Entity\ItemOperatingSystem::referenceAssociation($itemtype) . ') = :owner')
            ->setParameter('owner', $owner, Types::BIGINT);
        foreach (['operatingsystems' => $operatingSystem, 'operatingsystemarchitectures' => $architecture] as $association => $id) {
            if ($id === null) {
                $query->andWhere('r.' . $association . ' IS NULL');
            } else {
                $query->andWhere('IDENTITY(r.' . $association . ') = :' . $association)->setParameter($association, $id, Types::BIGINT);
            }
        }
        if ($exceptId !== null) {
            $query->andWhere('r.id <> :except')->setParameter('except', $exceptId, Types::BIGINT);
        }
        return $query->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    public function wouldMergeOperatingSystems(int $source, ?int $replacement): bool
    {
        return $this->wouldMergeComponent('operatingsystems', 'operatingsystemarchitectures', $source, $replacement);
    }

    public function wouldMergeArchitectures(int $source, ?int $replacement): bool
    {
        return $this->wouldMergeComponent('operatingsystemarchitectures', 'operatingsystems', $source, $replacement);
    }

    /** Component removal must retain each independently licensed assignment. */
    private function wouldMergeComponent(string $component, string $other, int $source, ?int $replacement): bool
    {
        $sameOwner = [];
        foreach (EntityRegistry::discriminatedReferences('glpi_items_operatingsystems')['items_id']['selections'] as $kind => $selection) {
            $association = Entity\ItemOperatingSystem::referenceAssociation($kind);
            $sameOwner[] = 'IDENTITY(a.' . $association . ') = IDENTITY(b.' . $association . ')';
        }
        $query = $this->em->createQueryBuilder()->select('a.id')
            ->from(Entity\ItemOperatingSystem::class, 'a')
            ->join(Entity\ItemOperatingSystem::class, 'b', 'WITH', '(' . implode(' OR ', $sameOwner) . ') AND a.id <> b.id')
            ->where('IDENTITY(a.' . $component . ') = :source')->setParameter('source', $source, Types::BIGINT)
            ->andWhere('((a.' . $other . ' IS NULL AND b.' . $other . ' IS NULL) OR IDENTITY(a.' . $other . ') = IDENTITY(b.' . $other . '))');
        if ($replacement === null) {
            $query->andWhere('b.' . $component . ' IS NULL');
        } else {
            $query->andWhere('IDENTITY(b.' . $component . ') = :replacement')->setParameter('replacement', $replacement, Types::BIGINT);
        }
        return $query->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    public function forSubject(string $itemtype, int $id, string $sort = 'glpi_items_operatingsystems.id', string $order = 'ASC'): array
    {
        $columns = [
            'glpi_items_operatingsystems.id' => 'r.id', 'id' => 'r.id',
            '0' => 'os.name', 'name' => 'os.name', 'glpi_operatingsystems.name' => 'os.name',
            '1' => 'v.name', 'version' => 'v.name', 'glpi_operatingsystemversions.name' => 'v.name',
            '2' => 'a.name', 'architecture' => 'a.name', 'glpi_operatingsystemarchitectures.name' => 'a.name',
            '3' => 'sp.name', 'servicepack' => 'sp.name', 'glpi_operatingsystemservicepacks.name' => 'sp.name',
        ];
        $field = $columns[$sort] ?? throw new InvalidArgumentException('Unsupported OS sort field');
        $direction = strtoupper($order);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Unsupported OS sort direction');
        }
        return $this->em->createQueryBuilder()
            ->select('r.id AS assocID, os.name AS name, v.name AS version, a.name AS architecture, sp.name AS servicepack')
            ->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN missing')
            ->from(Entity\ItemOperatingSystem::class, 'r')
            ->leftJoin('r.operatingsystems', 'os')->leftJoin('r.operatingsystemversions', 'v')
            ->leftJoin('r.operatingsystemarchitectures', 'a')->leftJoin('r.operatingsystemservicepacks', 'sp')
            ->where('IDENTITY(r.' . Entity\ItemOperatingSystem::referenceAssociation($itemtype) . ') = :id')
            ->setParameter('id', $id, Types::BIGINT)
            ->orderBy('missing', $direction)->addOrderBy($field, $direction)->addOrderBy('r.id', $direction)
            ->getQuery()->getScalarResult();
    }
}
