<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ApplianceAssetRepository;

/** One reverse appliance count retains the caller's selected route and scope. */
final class ApplianceOwnerReadOperation
{
    use PrivateReadOwnership;

    public function ownerCount(string $kind, int $asset, array $criteria, ?EntityRestriction $scope = null): int
    {
        $repository = new ApplianceAssetRepository($this->manager);
        if (!$this->ownedMapping) {
            return $repository->ownerCount($kind, $asset, $criteria);
        }
        $entities = null;
        $ancestors = [];
        if ($scope !== null) {
            if (!$scope->hasEntityMembership || $scope->table !== 'glpi_appliances' || $scope->field !== 'entities_id'
                || $criteria !== $scope->wrappedCriteria()) {
                return $repository->ownerCount($kind, $asset, $criteria);
            }
            $entities = $scope->entities;
            $ancestors = $scope->ancestors;
        } elseif ($criteria !== []) {
            // Externally supplied legacy criteria retain their complete ORM semantics.
            return $repository->ownerCount($kind, $asset, $criteria);
        }
        foreach (['glpi_appliances', 'glpi_appliances_items'] as $table) {
            $metadata = $this->metadata($table);
            if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                return $repository->ownerCount($kind, $asset, $criteria);
            }
        }
        return $repository->nativeOwnerCount($kind, $asset, $entities, $ancestors, $scope?->entityList ?? true);
    }
}
