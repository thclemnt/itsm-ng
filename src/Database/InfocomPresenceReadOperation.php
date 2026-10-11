<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\InfocomRepository;

/** One activation presence read owns its selected route, without retaining rows. */
final class InfocomPresenceReadOperation
{
    private ?EntityManager $manager = null;

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

    public function forItem(string $itemtype, int $id): bool
    {
        $mapping = EntityRegistry::infocomPresenceMapping();
        if ($mapping !== null) {
            return InfocomRepository::projectedPresence($this->connection, $mapping, $itemtype, $id);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new InfocomRepository($this->manager))->isActivatedFor($itemtype, $id);
    }
}
