<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Inventory projections keep polymorphic item identity separate from dropdown associations. */
final class InventoryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function operatingSystems(string $itemtype, int $id, string $sort = 'glpi_items_operatingsystems.id', string $order = 'ASC'): array
    {
        $columns = [
            'glpi_items_operatingsystems.id' => 'r.id', 'id' => 'r.id',
            '0' => 'os.name', 'name' => 'os.name', 'glpi_operatingsystems.name' => 'os.name',
            '1' => 'v.name', 'version' => 'v.name', 'glpi_operatingsystemversions.name' => 'v.name',
            '2' => 'a.name', 'architecture' => 'a.name', 'glpi_operatingsystemarchitectures.name' => 'a.name',
            '3' => 'sp.name', 'servicepack' => 'sp.name', 'glpi_operatingsystemservicepacks.name' => 'sp.name',
        ];
        $field = $columns[$sort] ?? throw new \InvalidArgumentException('Unsupported OS sort field');
        $direction = strtoupper($order);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Unsupported OS sort direction');
        }
        return $this->em->createQueryBuilder()
            ->select('r.id AS assocID, os.name AS name, v.name AS version, a.name AS architecture, sp.name AS servicepack')
            ->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN missing')
            ->from(Entity\ItemOperatingSystem::class, 'r')
            ->leftJoin('r.operatingsystems', 'os')->leftJoin('r.operatingsystemversions', 'v')
            ->leftJoin('r.operatingsystemarchitectures', 'a')->leftJoin('r.operatingsystemservicepacks', 'sp')
            ->where('r.itemtype = :type AND r.items_id = :id')
            ->setParameter('type', $itemtype, Types::STRING)->setParameter('id', $id, Types::INTEGER)
            ->orderBy('missing', $direction)->addOrderBy($field, $direction)->addOrderBy('r.id', $direction)
            ->getQuery()->getScalarResult();
    }

    public function disks(string $itemtype, int $id): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'f.name AS fsname')
            ->from(Entity\ItemDisk::class, 'r')->leftJoin('r.filesystems', 'f')
            ->where('r.itemtype = :type AND r.items_id = :id')
            ->setParameter('type', $itemtype, Types::STRING)->setParameter('id', $id, Types::INTEGER)
            ->orderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['fsname' => $result['fsname']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    public function virtualMachinesForComputer(int $computer): array
    {
        return (new RecordRepository($this->em))->matching(
            'glpi_computervirtualmachines',
            ['computers_id' => $computer, 'is_deleted' => false],
            ['name', 'id'],
            legacyValues: false
        );
    }

    public function countVirtualMachines(int $computer): int
    {
        return (new RecordRepository($this->em))->countMatching(
            'glpi_computervirtualmachines',
            ['computers_id' => $computer, 'is_deleted' => false],
            legacyValues: false
        );
    }

    /** A host is listed once even when inventory contains duplicate UUID records. */
    public function virtualMachineHosts(array $uuids, array $scope): array
    {
        if (!$uuids) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.id AS computers_id')->from(Entity\ComputerVirtualMachine::class, 'vm')
            ->join('vm.computers', 'r')->where('LOWER(vm.uuid) IN (:uuids)')
            ->andWhere('vm.is_deleted = :false AND r.is_deleted = :false AND r.is_template = :false')
            ->setParameter('uuids', array_values(array_unique($uuids)), ArrayParameterType::STRING)
            ->setParameter('false', false, Types::BOOLEAN)->orderBy('r.id');
        $query->andWhere((new \itsmng\Database\RecordCriteria($query, $this->em->getClassMetadata(Entity\Computer::class)))->where($scope));
        return $query->getQuery()->getScalarResult();
    }

    /** Two matches suffice to reject ambiguous UUIDs without loading all duplicates. */
    public function computerIdsByUuids(array $uuids): array
    {
        if (!$uuids) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('c.id AS id')->from(Entity\Computer::class, 'c')
            ->where('LOWER(c.uuid) IN (:uuids)')->setParameter('uuids', array_values(array_unique($uuids)), ArrayParameterType::STRING)
            ->orderBy('c.id')->setMaxResults(2);
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'));
    }
}
