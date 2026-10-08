<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\ReservationRepository;
use ReflectionMethod;

/** One display partition owns its captured route; no rows survive the call. */
final class ReservationUserReadOperation
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
        // before caller arguments (including current entity scope) are evaluated.
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')) {
            $this->manager = Orm::forConnection($connection);
            return;
        }
        $platform = $connection->getDatabasePlatform();
        $this->project = Orm::ownsReadMapping($connection);
        if ($this->project) {
            // Keep the previous eager Type registration before argument callbacks.
            Orm::configuration($platform);
        } else {
            $this->manager = Orm::forConnection($connection);
        }
    }

    public function forUser(int $user, string $now, bool $past, ?array $entities): array
    {
        $mapping = $this->project ? EntityRegistry::reservationUserMapping() : null;
        if ($mapping !== null) {
            return ReservationRepository::projectedForUser($this->connection, $mapping, $user, $now, $past, $entities);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new ReservationRepository($this->manager))->nativeForUser($user, $now, $past, $entities);
    }
}
