<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;

final class InventoryMetadataReferences
{
    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'INVENTORY_METADATA'), 'inventory metadata'))->plan($connection);
        array_push($plan['sql'], ...(new InventoryUniqueness())->plan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection); // Audit references and uniqueness before either migration writes.
        $apply = static function () use ($connection): array {
            $counts = (new NullableReferences(ReferenceHistory::get('optional', 'INVENTORY_METADATA'), 'inventory metadata'))->apply($connection);
            foreach ((new InventoryUniqueness())->plan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
            ? $connection->transactional($apply) : $apply();
    }
}
