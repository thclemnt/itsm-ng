<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use LogicException;
use ReflectionProperty;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\RackModel;

/** Placement exclusions are global: an asset cannot occupy two physical locations. */
final class PlacementRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * Physical occupancy includes reserved assets in every entity. Read dimensions
     * once per represented mapped type, without hydrating assets or their models.
     * Unmapped extension types retain the legacy caller's model-loading path.
     */
    public function rackOccupancy(int $rack): array
    {
        return $this->rackDimensions($rack, false);
    }

    /** Weight/power totals use the same physical placements and model ownership. */
    public function rackStatistics(int $rack): array
    {
        return $this->rackDimensions($rack, true);
    }

    private function rackDimensions(int $rack, bool $statistics): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('i.itemtype', 'i.items_id', 'i.position', 'i.orientation', 'i.hpos')
            ->from(Entity\ItemRack::class, 'i')->where('i.racks = :rack')
            ->setParameter('rack', $rack)->getQuery()->getArrayResult();
        $groups = [];
        foreach ($rows as $index => $row) {
            $groups[$row['itemtype']][$index] = $row['items_id'];
        }
        $selections = EntityRegistry::discriminatedReferences('glpi_items_racks')['items_id']['selections'];
        foreach ($groups as $kind => $identifiers) {
            $target = $selections[$kind]['target'] ?? null;
            if ($target === null) {
                continue;
            }
            $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$target]);
            $models = [];
            foreach ($metadata->associationMappings as $property => $association) {
                if ((new ReflectionProperty($metadata->name, $property))->getAttributes(RackModel::class)) {
                    if (!$association->isToOneOwningSide()) {
                        throw new LogicException('Rack dimensions require an owning model association: ' . $metadata->name);
                    }
                    $models[] = $property;
                }
            }
            if (!$models) {
                continue;
            }
            if (count($models) !== 1) {
                throw new LogicException('Ambiguous rack model association: ' . $metadata->name);
            }
            $query = $this->em->createQueryBuilder()
                ->select('a.id AS asset_id', 'm.id AS model_id', 'm.required_units', 'm.depth')
                ->from($metadata->name, 'a')
                ->leftJoin('a.' . $models[0], 'm')
                ->where('a.id IN (:assets)')
                ->setParameter('assets', array_values(array_unique($identifiers)), ArrayParameterType::INTEGER);
            if ($statistics) {
                $query->addSelect('m.weight');
                $modelMetadata = $this->em->getClassMetadata($metadata->getAssociationTargetClass($models[0]));
                // Some physical models supply power rather than consuming it.
                if ($modelMetadata->hasField('power_consumption')) {
                    $query->addSelect('m.power_consumption');
                }
            }
            $byAsset = array_column($query->getQuery()->getArrayResult(), null, 'asset_id');
            foreach ($identifiers as $index => $id) {
                $dimension = $byAsset[$id] ?? null;
                // Occupancy defaults a missing model to one full unit; statistics
                // retains its absent-model branch, independent of orientation.
                $rows[$index]['dimensions'] = $dimension === null || ($statistics && $dimension['model_id'] === null) ? null : [
                    'required_units' => $dimension['model_id'] === null ? 1 : $dimension['required_units'],
                    'depth' => $dimension['model_id'] === null ? 1 : $dimension['depth'],
                ];
                if ($statistics && $rows[$index]['dimensions'] !== null) {
                    $rows[$index]['dimensions'] += [
                        'weight' => $dimension['weight'],
                        'power_consumption' => $dimension['power_consumption'] ?? 0,
                    ];
                }
            }
        }
        return $rows;
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
        foreach (EntityRegistry::discriminatedReferences('glpi_items_clusters')['items_id']['selections'] as $kind => $selection) {
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
