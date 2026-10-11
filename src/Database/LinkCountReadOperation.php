<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\LinkRepository;

/** Count visible external-link definitions on the caller's selected connection. */
final class LinkCountReadOperation
{
    use PrivateReadOwnership;

    public function countForItem(string $type, EntityRestriction $scope): int
    {
        $repository = new LinkRepository($this->manager);
        if (!$this->ownedMapping || !$scope->hasEntityMembership
            || $scope->table !== 'glpi_links' || $scope->field !== 'entities_id') {
            return $repository->countForItem($type, $scope->wrappedCriteria());
        }
        foreach (['glpi_links', 'glpi_links_itemtypes'] as $table) {
            $metadata = $this->metadata($table);
            if ($this->defaultIdentifiers($metadata) === null || !$metadata->isInheritanceTypeNone()) {
                return $repository->countForItem($type, $scope->wrappedCriteria());
            }
        }
        return $repository->nativeCountForItem($type, $scope);
    }
}
