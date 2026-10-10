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

    public function forItem(string $itemtype, int $id): bool
    {
        $mapping = $this->project ? EntityRegistry::infocomPresenceMapping() : null;
        if ($mapping !== null) {
            return InfocomRepository::projectedPresence($this->connection, $mapping, $itemtype, $id);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new InfocomRepository($this->manager))->isActivatedFor($itemtype, $id);
    }
}
