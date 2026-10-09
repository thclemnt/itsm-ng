<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\ITILOriginRepository;
use ReflectionMethod;

/** One historical link read owns the route selected after timeline callbacks. */
final class PromotionSourceReadOperation
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
        // before the caller evaluates its current ticket identifier.
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')) {
            $this->manager = Orm::forConnection($connection);
            return;
        }
        $this->project = Orm::ownsReadMapping($connection);
        if ($this->project) {
            // Keep the previous eager Type registration before argument callbacks.
            Orm::registerTypes();
        } else {
            $this->manager = Orm::forConnection($connection);
        }
    }

    public function forTicket(int $ticket): ?array
    {
        $mapping = $this->project ? EntityRegistry::promotionSourceMapping() : null;
        if ($mapping !== null) {
            return ITILOriginRepository::projectedPromotionSource($this->connection, $mapping, $ticket);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new ITILOriginRepository($this->manager))->promotionSource($ticket);
    }
}
