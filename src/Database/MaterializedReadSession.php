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
            // A reentrant session retains its independent owner across every stage.
            return $operation($this->manager, $prepared);
        }
        return Orm::withReadConnection($this->connection, function (?EntityManager $manager) use ($operation, $prepared): mixed {
            // The non-application connection capability remains separate.
            $manager ??= $this->manager ??= Orm::forConnection($this->connection);
            return $operation($manager, $prepared);
        });
    }
}
