<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

/** One selected route; public preparation runs between completed value-only stages. */
final class MaterializedReadSession
{
    /** @internal Only Orm::readSession supplies an independently owned custom/reentrant manager. */
    public function __construct(private readonly Connection $connection, private ?EntityManager $manager)
    {
    }

    /** @internal Neither stage returns entities, repositories or lazy iterators. */
    public function readPrepared(callable $prepare, callable $operation): mixed
    {
        $prepared = $prepare();
        if ($this->manager !== null) {
            // Keep original custom configuration/events for the entire operation.
            // Such readers had no explicit clear or onClear lifecycle callback.
            return $operation($this->manager, $prepared);
        }
        return Orm::withReadConnection($this->connection, function (?EntityManager $manager) use ($operation, $prepared): mixed {
            // Admission may change after public callbacks mutate custom types.
            // Retain that independent owner for this and every later stage.
            $manager ??= $this->manager ??= Orm::forConnection($this->connection);
            return $operation($manager, $prepared);
        });
    }
}
