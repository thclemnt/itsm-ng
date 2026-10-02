<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class ActorReferences
{
    public const VERSION = '20260929_nullable_itil_actors_references';

    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'ITIL_ACTORS'), 'ITIL actor'))->plan($connection);
        array_push($plan['sql'], ...(new ActorUniqueness())->plan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection); // Audit references and uniqueness before either migration writes.
        if ($plan['sql'] && $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            throw new \RuntimeException('MySQL ITIL actor DDL must run outside an application transaction.');
        }
        $apply = static function () use ($connection): array {
            $counts = (new NullableReferences(ReferenceHistory::get('optional', 'ITIL_ACTORS'), 'ITIL actor'))->apply($connection);
            foreach ((new ActorUniqueness())->plan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
            ? $connection->transactional($apply) : $apply();
    }
}
