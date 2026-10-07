<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\SoftwareInstallationRepository;

/** One hook-free software table rendering owns its license and display reads. */
final class SoftwareRenderingReadOperation
{
    use PrivateReadOwnership;

    /** The installation list uses the same selected route and a single calculated scope. */
    public function forSubject(string $kind, int $id, EntityRestriction $scope, bool $excludeDeleted, ?int $category): array
    {
        $repository = new SoftwareInstallationRepository($this->manager);
        if (!$this->ownedMapping || !$scope->hasEntityMembership
            || $scope->table !== 'glpi_softwares' || $scope->field !== 'entities_id') {
            return $repository->forSubject($kind, $id, $scope->wrappedCriteria(), $excludeDeleted, $category);
        }
        foreach (['glpi_items_softwareversions', 'glpi_softwareversions', 'glpi_softwares', 'glpi_states'] as $table) {
            $metadata = $this->metadata($table);
            if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                return $repository->forSubject($kind, $id, $scope->wrappedCriteria(), $excludeDeleted, $category);
            }
        }
        return $repository->nativeForSubject($kind, $id, $scope, $excludeDeleted, $category);
    }

    public function rendering(string $kind, int $owner, array $installations): array
    {
        if ($installations === []) {
            return ['licenses' => [], 'display' => ['softwares' => [], 'versions' => [], 'categories' => []]];
        }
        $repository = new SoftwareInstallationRepository($this->manager);
        $versions = array_column($installations, 'verid');
        $native = $this->ownedMapping;
        if ($native) {
            foreach ([
                'glpi_items_softwarelicenses',
                'glpi_softwarelicenses',
                'glpi_softwares',
                'glpi_softwareversions',
                'glpi_softwarecategories',
            ] as $table) {
                $metadata = $this->metadata($table);
                if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                    $native = false;
                    break;
                }
            }
        }
        $licenses = $native
            ? $repository->nativeEffectiveLicenseIdsForVersions($kind, $owner, $versions)
            : $repository->effectiveLicenseIdsForVersions($kind, $owner, $versions);
        $display = $native
            ? $repository->nativeDisplayDataForInstallations($installations)
            : $repository->displayDataForInstallations($installations);
        return ['licenses' => $licenses, 'display' => $display];
    }
}
