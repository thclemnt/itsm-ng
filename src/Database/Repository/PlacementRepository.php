<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Placement exclusions are global: an asset cannot occupy two physical locations. */
final class PlacementRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function rackSelection(): array
    {
        $rows = $this->assignments(Entity\ItemRack::class);
        $used = $this->combine($this->group($rows), $this->group($this->assignments(Entity\ItemEnclosure::class)));
        $used = $this->combine($used, ['PDU' => $this->sidePdus()]);
        return ['used' => $used, 'reserved' => $this->group(array_filter($rows, static fn (array $row): bool => (bool)$row['reserved']))];
    }

    public function enclosureSelection(): array
    {
        return $this->combine(
            $this->group($this->assignments(Entity\ItemEnclosure::class)),
            $this->group($this->assignments(Entity\ItemRack::class, false))
        );
    }

    public function clusterSelection(): array
    {
        $items = [];
        foreach (\itsmng\Database\EntityRegistry::discriminatedReferences('glpi_items_clusters')['items_id']['selections'] as $kind => $selection) {
            $association = Entity\ItemCluster::referenceAssociation($kind);
            $rows = $this->em->createQueryBuilder()->select('IDENTITY(i.' . $association . ') AS id')
                ->from(Entity\ItemCluster::class, 'i')->where('i.' . $association . ' IS NOT NULL')
                ->orderBy('i.' . $association)->getQuery()->getScalarResult();
            if ($rows) {
                $items[$kind] = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
            }
        }
        return $items;
    }

    public function pduSelection(): array
    {
        $racked = $this->em->createQueryBuilder()->select('IDENTITY(i.assetPdu) AS id')->from(Entity\ItemRack::class, 'i')
            ->where('i.assetPdu IS NOT NULL')->orderBy('i.assetPdu')
            ->getQuery()->getScalarResult();
        return array_values(array_unique([...$this->sidePdus(), ...array_map('intval', array_column($racked, 'id'))]));
    }

    /** Select only the fields needed to build the asset selectors. */
    private function assignments(string $class, ?bool $reserved = null): array
    {
        $query = $this->em->createQueryBuilder()->select('i.itemtype AS itemtype', 'i.items_id AS items_id')->from($class, 'i')
            ->orderBy('i.itemtype')->addOrderBy('i.items_id');
        if ($class === Entity\ItemRack::class) {
            $query->addSelect('i.is_reserved AS reserved');
            if ($reserved !== null) {
                $query->where('i.is_reserved = :reserved')->setParameter('reserved', $reserved, Types::BOOLEAN);
            }
        }
        return $query->getQuery()->getScalarResult();
    }

    private function sidePdus(): array
    {
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(p.pdus) AS id')->from(Entity\PDURack::class, 'p')
            ->orderBy('p.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    private function group(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[$row['itemtype']][] = (int)$row['items_id'];
        }
        return $this->combine([], $items);
    }

    private function combine(array $left, array $right): array
    {
        foreach ($right as $type => $ids) {
            if ($ids) {
                $left[$type] = array_values(array_unique([...($left[$type] ?? []), ...$ids]));
            }
        }
        return $left;
    }
}
