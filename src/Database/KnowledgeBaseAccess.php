<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** A snapshot of the viewer's context, independent of database query syntax. */
final readonly class KnowledgeBaseAccess
{
    public function __construct(
        public int $user,
        public bool $administrator,
        public bool $readArticles,
        public bool $publicFaq,
        public bool $multiEntity,
        public array $groups,
        public int $profile,
        public array $entities,
        public array $ancestors,
    ) {
    }

    public static function current(): self
    {
        global $CFG_GLPI;
        $entities = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));
        return new self(
            (int)\Session::getLoginUserID(),
            \Session::haveRight('knowbase', \KnowbaseItem::KNOWBASEADMIN),
            \Session::haveRight('knowbase', READ),
            (bool)$CFG_GLPI['use_public_faq'],
            \Session::isMultiEntitiesMode(),
            array_values(array_map('intval', $_SESSION['glpigroups'] ?? [])),
            (int)($_SESSION['glpiactiveprofile']['id'] ?? 0),
            $entities,
            array_values(array_diff(\getAncestorsOf('glpi_entities', $entities), $entities)),
        );
    }
}
