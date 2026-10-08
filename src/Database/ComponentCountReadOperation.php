<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ComponentRepository;
use ReflectionMethod;

/** A core component tab owns one route and counts its declared families in order. */
final class ComponentCountReadOperation
{
    use PrivateReadOwnership;

    public function countForAsset(array $tables, string $type, int $id): int
    {
        $repository = new ComponentRepository($this->manager);
        if (!$this->ownedMapping) {
            return $repository->countForAsset($tables, $type, $id);
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
                $count += $repository->nativeCountForAsset($table, $type, $id, $mapping);
                continue;
            }
            $metadata = $this->metadata($table);
            $count += $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()
                ? $repository->nativeCountForAsset($table, $type, $id)
                : $repository->countForAsset([$table], $type, $id);
        }
        return $count;
    }
}
