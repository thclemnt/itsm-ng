<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ComponentRepository;

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
        $count = 0;
        foreach ($tables as $table) {
            $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
            if ($reference !== null && !isset($reference['selections'][$type])) {
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
