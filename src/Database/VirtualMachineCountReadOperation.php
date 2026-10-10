<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\InventoryRepository;

/** One virtualization tab count owns its selected route, without retaining rows. */
final class VirtualMachineCountReadOperation
{
    private ?EntityManager $manager = null;
    private bool $project = false;

    public static function forDatabase(DBAdapter $database): self
    {
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return new self($connection);
    }

    public function __construct(private readonly Connection $connection)
    {
        // Resolve extension callbacks and types before caller arguments are evaluated.
        $this->project = Orm::prepareReadProjection($connection);
        if (!$this->project) {
            $this->manager = Orm::forConnection($connection);
        }
    }

    public function forComputer(int $computer): int
    {
        $mapping = $this->project ? EntityRegistry::virtualMachineCountMapping() : null;
        if ($mapping !== null) {
            return InventoryRepository::projectedVirtualMachineCount($this->connection, $mapping, $computer);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new InventoryRepository($this->manager))->countVirtualMachines($computer);
    }
}
