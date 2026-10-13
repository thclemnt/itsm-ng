<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity;

/** Scoped endpoint projection followed by bounded, independent address hydration. */
final class NetworkReportRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** @param ?int[] $entities NULL grants all entities; [] grants none. */
    public function rows(string $kind, array $ids, ?array $entities): array
    {
        if (!in_array($kind, ['equipment', 'outlet', 'location'], true)) {
            throw new InvalidArgumentException('Unknown network report kind');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if (!$ids || $entities === []) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->from(Entity\NetworkPort::class, 'port')
            ->where('port.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        $remote = 'remote.id = CASE WHEN IDENTITY(wire.networkports_id_1) = port.id THEN IDENTITY(wire.networkports_id_2) ELSE IDENTITY(wire.networkports_id_1) END AND remote.is_deleted = :deleted';
        if ($entities !== null) {
            $query->andWhere('IDENTITY(port.entities) IN (:entities)')->setParameter('entities', array_map('intval', $entities), ArrayParameterType::INTEGER);
            $remote .= ' AND IDENTITY(remote.entities) IN (:entities)';
        }
        $query->leftJoin(Entity\NetworkPortNetworkPort::class, 'wire', 'WITH', 'IDENTITY(wire.networkports_id_1) = port.id OR IDENTITY(wire.networkports_id_2) = port.id')
            ->leftJoin(Entity\NetworkPort::class, 'remote', 'WITH', $remote);
        foreach (['port' => 1, 'remote' => 2] as $alias => $suffix) {
            foreach (['id' => 'id', 'itemtype' => 'itemtype', 'items_id' => 'items_id', 'name' => 'port', 'mac' => 'mac', 'logical_number' => 'logical'] as $field => $label) {
                $query->addSelect($alias . '.' . $field . ' AS ' . $label . '_' . $suffix);
            }
        }
        if ($kind === 'equipment') {
            $query->innerJoin(Entity\NetworkEquipment::class, 'equipment', 'WITH', 'equipment.id = port.items_id AND port.itemtype = :equipmentType')
                ->andWhere('equipment.id IN (:ids)')->setParameter('equipmentType', 'NetworkEquipment', Types::STRING);
        } else {
            $query->innerJoin(Entity\NetworkPortEthernet::class, 'ethernet', 'WITH', 'IDENTITY(ethernet.networkports_id) = port.id')
                ->innerJoin('ethernet.netpoints_id', 'outlet');
            if ($kind === 'location') {
                $query->innerJoin('outlet.locations', 'location')->andWhere('location.id IN (:ids)')
                    ->addSelect('outlet.name AS extra')->addOrderBy('location.completename');
            } else {
                $query->leftJoin('outlet.locations', 'location')->andWhere('outlet.id IN (:ids)')
                    ->addSelect('location.completename AS extra');
            }
        }
        $query->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->addOrderBy('port.name')->addOrderBy('port.id')->addOrderBy('remote.id');
        $rows = $query->getQuery()->getScalarResult();
        $portIds = [];
        foreach ($rows as $row) {
            foreach ([1, 2] as $suffix) {
                if ($row['id_' . $suffix] !== null) {
                    $portIds[(int)$row['id_' . $suffix]] = (int)$row['id_' . $suffix];
                }
            }
        }
        $addresses = $this->addresses(array_values($portIds));
        foreach ($rows as &$row) {
            foreach ([1, 2] as $suffix) {
                $row['ip_' . $suffix] = $addresses[$row['id_' . $suffix]] ?? null;
            }
        }
        return $rows;
    }

    /** Address rows never participate in the endpoint join, avoiding fan-out. */
    private function addresses(array $portIds): array
    {
        $addresses = [];
        foreach (array_chunk($portIds, 500) as $chunk) {
            $rows = $this->em->createQueryBuilder()
                ->select('name.items_id AS port_id', 'address.name AS address_value')->distinct()
                ->from(Entity\NetworkName::class, 'name')
                ->innerJoin(Entity\IPAddress::class, 'address', 'WITH', 'address.items_id = name.id AND address.itemtype = :addressType AND address.is_deleted = :deleted')
                ->where('name.items_id IN (:ports) AND name.itemtype = :nameType AND name.is_deleted = :deleted')
                ->setParameter('ports', $chunk, ArrayParameterType::INTEGER)
                ->setParameter('nameType', 'NetworkPort', Types::STRING)
                ->setParameter('addressType', 'NetworkName', Types::STRING)
                ->setParameter('deleted', false, Types::BOOLEAN)->getQuery()->getScalarResult();
            foreach ($rows as $row) {
                if ($row['address_value'] !== null) {
                    $addresses[(int)$row['port_id']][] = $row['address_value'];
                }
            }
        }
        foreach ($addresses as &$values) {
            sort($values, SORT_STRING);
            $values = implode(',', $values);
        }
        return $addresses;
    }
}
