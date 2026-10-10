<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\ITILOriginRepository;

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
        // Resolve extension callbacks and types before caller arguments are evaluated.
        $this->project = Orm::prepareReadProjection($connection);
        if (!$this->project) {
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
