<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ComponentRepository;
use ReflectionMethod;

/** A core component tab owns one route and counts its declared families in order. */
final class ComponentCountReadOperation
{
    use PrivateReadOwnership {
        __construct as private initializeEagerRead;
    }

    public function __construct(Connection $connection)
    {
        // An overridable platform getter or external EventManager keeps the
        // original eager callback ordering and independently mutable manager.
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')
            || !Orm::ownsReadMapping($connection)) {
            $this->initializeEagerRead($connection);
            return;
        }
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

    public function close(): void
    {
        if (isset($this->manager)) {
            $this->manager->clear();
        }
    }

    public function countForAsset(array $tables, string $type, int $id): int
    {
        if (!$this->ownedMapping) {
            return $this->repository()->countForAsset($tables, $type, $id);
        }
        // Metadata loading invokes this selected compiler callback. An override
        // must retain that invocation before its live Type conversions.
        $project = (new ReflectionMethod($this->connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() === Connection::class;
        $count = 0;
        foreach ($tables as $table) {
            $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
            if ($reference !== null && !isset($reference['selections'][$type])) {
                continue;
            }
            $mapping = $project ? EntityRegistry::componentCountMapping($table) : null;
            if ($mapping !== null) {
                $count += ComponentRepository::projectedCountForAsset($this->connection, $table, $type, $id, $mapping);
                continue;
            }
            $repository = $this->repository();
            $metadata = $this->metadata($table);
            $count += $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()
                ? $repository->nativeCountForAsset($table, $type, $id)
                : $repository->countForAsset([$table], $type, $id);
        }
        return $count;
    }
}
