<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ReservationRepository;

/** One display partition owns its captured route; no rows survive the call. */
final class ReservationUserReadOperation
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

    public function forUser(int $user, string $now, bool $past, ?array $entities): array
    {
        return ReservationRepository::projectedForUser(
            $this->connection,
            EntityRegistry::reservationUserMapping(),
            $user,
            $now,
            $past,
            $entities
        );
    }
}
