<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\InfocomRepository;
use ReflectionMethod;

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
        // Extension routes retain exactly the original bare manager creation,
        // before the caller evaluates its current item type and identifier.
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')) {
            $this->manager = Orm::forConnection($connection);
            return;
        }
        $connection->getDatabasePlatform();
        $this->project = Orm::ownsReadMapping($connection);
        if ($this->project) {
            // Keep the previous eager Type registration before argument callbacks.
            Orm::registerTypes();
        } else {
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
