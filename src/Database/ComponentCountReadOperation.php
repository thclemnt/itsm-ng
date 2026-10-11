<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ComponentRepository;

/** A core component tab owns one route and counts its declared families in order. */
final class ComponentCountReadOperation
{
    use PrivateReadOwnership;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->ownedMapping = true;
        // Keep type registration, cache selection and immutable registry loading
        // at construction time; a scalar count itself needs no ORM manager.
        $this->initializeReadCaches(Orm::configuration($connection->getDatabasePlatform()));
    }

    private function repository(): ComponentRepository
    {
        $this->manager ??= Orm::forConnection($this->connection);
        return new ComponentRepository($this->manager);
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
            if ($mapping !== null) {
                $queries[] = ComponentRepository::projectedCountQueryForAsset($this->connection, $table, $type, $id, $mapping);
                continue;
            }
            $repository = $this->repository();
            $metadata = $this->metadata($table);
            if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                return $repository->countForAsset($tables, $type, $id);
            }
            $queries[] = $repository->nativeCountQueryForAsset($table, $type, $id);
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
