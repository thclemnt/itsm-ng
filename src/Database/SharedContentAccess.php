<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Session;

use function getAncestorsOf;

/** Authenticated viewer context for reminder and RSS sharing. */
final readonly class SharedContentAccess
{
    public function __construct(
        public int $user,
        public bool $readPublic,
        public array $groups,
        public int $profile,
        public array $entities,
        public array $ancestors,
    ) {
    }

    public static function current(bool $readPublic): self
    {
        $entities = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));
        return new self(
            (int)Session::getLoginUserID(),
            $readPublic,
            array_values(array_map('intval', $_SESSION['glpigroups'] ?? [])),
            (int)($_SESSION['glpiactiveprofile']['id'] ?? 0),
            $entities,
            array_values(array_diff(getAncestorsOf('glpi_entities', $entities), $entities)),
        );
    }
}
