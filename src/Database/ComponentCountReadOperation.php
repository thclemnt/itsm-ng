<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ComponentRepository;
use LogicException;

/** A core component tab owns one route and counts its declared families in order. */
final class ComponentCountReadOperation
{
    public function __construct(private readonly Connection $connection)
    {
        // Register application types and load the immutable declarations before
        // public model argument evaluation; this projection owns no ORM manager.
        Orm::registerTypes();
        EntityRegistry::scalarIdentifiers();
    }

    /** Kept for callers that close completed operations; no resource is owned. */
    public function close(): void
    {
    }

    public function countForAsset(array $tables, string $type, int $id): int
    {
        $queries = [];
        foreach ($tables as $table) {
            $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
            if ($reference !== null && !isset($reference['selections'][$type])) {
                continue;
            }
            $mapping = EntityRegistry::componentCountMapping($table);
            if ($mapping === null) {
                throw new LogicException('No declared component count mapping: ' . $table);
            }
            $queries[] = ComponentRepository::projectedCountQueryForAsset($this->connection, $table, $type, $id, $mapping);
        }
        $queries = array_filter($queries);
        if ($queries === []) {
            return 0;
        }
        $sql = [];
        $parameters = [];
        $types = [];
        foreach ($queries as $query) {
            $sql[] = $query->getSQL();
            array_push($parameters, ...$query->getParameters());
            array_push($types, ...$query->getParameterTypes());
        }
        // One component-tab total observes one statement snapshot, not one per family.
        $count = 0;
        $values = $this->connection->executeQuery(
            implode(' UNION ALL ', $sql),
            $parameters,
            $types,
        )->fetchFirstColumn();
        foreach ($values as $value) {
            $count += (int)$value;
        }
        return $count;
    }
}
