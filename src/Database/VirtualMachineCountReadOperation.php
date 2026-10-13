<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\InventoryRepository;

/** One virtualization tab count owns its selected route, without retaining rows. */
final class VirtualMachineCountReadOperation
{
    public static function forDatabase(DBAdapter $database): self
    {
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return new self($connection);
    }

    public function __construct(private readonly Connection $connection)
    {
        // Initialize application types before caller arguments are evaluated.
        Orm::registerTypes();
    }

    public function forComputer(int $computer): int
    {
        return InventoryRepository::projectedVirtualMachineCount(
            $this->connection,
            EntityRegistry::virtualMachineCountMapping(),
            $computer
        );
    }
}
