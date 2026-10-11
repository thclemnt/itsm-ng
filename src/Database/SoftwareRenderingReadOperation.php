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
        return $repository->forSubject($kind, $id, $scope->wrappedCriteria(), $excludeDeleted, $category);
    }

    public function rendering(string $kind, int $owner, array $installations): array
    {
        if ($installations === []) {
            return ['licenses' => [], 'display' => ['softwares' => [], 'versions' => [], 'categories' => []]];
        }
        $repository = new SoftwareInstallationRepository($this->manager);
        $versions = array_column($installations, 'verid');
        $licenses = $repository->effectiveLicenseIdsForVersions($kind, $owner, $versions);
        $display = $repository->displayDataForInstallations($installations);
        return ['licenses' => $licenses, 'display' => $display];
    }
}
