<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\OidcRepository;

/** One bootstrap check owns its selected connection and never caches auth state. */
final class OidcRefreshReadOperation
{
    use PrivateReadOwnership;

    public function needsRefresh(int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        $this->metadata('glpi_oidc_users');
        $repository = new OidcRepository($this->manager);
        return $repository->needsRefresh($user);
    }
}
