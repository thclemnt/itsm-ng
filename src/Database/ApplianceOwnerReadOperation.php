<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ApplianceAssetRepository;

/** One reverse appliance count retains the caller's selected route and scope. */
final class ApplianceOwnerReadOperation
{
    use PrivateReadOwnership;

    public function ownerCount(string $kind, int $asset, array $criteria): int
    {
        $repository = new ApplianceAssetRepository($this->manager);
        if (!$this->ownedMapping) {
            return $repository->ownerCount($kind, $asset, $criteria);
        }
        $entities = null;
        if ($criteria !== []) {
            // Only direct membership is native; recursive and custom predicates
            // retain the existing authoritative RecordCriteria implementation.
            if (array_keys($criteria) !== ['glpi_appliances.entities_id']) {
                return $repository->ownerCount($kind, $asset, $criteria);
            }
            $value = $criteria['glpi_appliances.entities_id'];
            $entities = is_array($value) ? array_values($value) : [$value];
            if (!$entities) {
                return $repository->ownerCount($kind, $asset, $criteria);
            }
            foreach ($entities as $entity) {
                if (!is_int($entity) && !(is_string($entity) && ctype_digit($entity))) {
                    return $repository->ownerCount($kind, $asset, $criteria);
                }
            }
        }
        foreach (['glpi_appliances', 'glpi_appliances_items'] as $table) {
            $metadata = $this->metadata($table);
            if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                return $repository->ownerCount($kind, $asset, $criteria);
            }
        }
        return $repository->nativeOwnerCount($kind, $asset, $entities);
    }
}
