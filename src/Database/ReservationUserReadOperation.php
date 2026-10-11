<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\ReservationRepository;

/** One display partition owns its captured route; no rows survive the call. */
final class ReservationUserReadOperation
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

    public function forUser(int $user, string $now, bool $past, ?array $entities): array
    {
        $mapping = EntityRegistry::reservationUserMapping();
        if ($mapping !== null) {
            return ReservationRepository::projectedForUser($this->connection, $mapping, $user, $now, $past, $entities);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new ReservationRepository($this->manager))->nativeForUser($user, $now, $past, $entities);
    }
}
