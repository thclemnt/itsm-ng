<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** One permission calculation, with its exact legacy criterion and typed membership when available. */
final readonly class EntityRestriction
{
    public function __construct(
        public array $criteria,
        public string $table,
        public string $field,
        public bool $hasEntityMembership,
        public ?array $entities,
        public array $ancestors = [],
        public bool $entityList = true,
    ) {
    }

    public static function fromSelection(array $criteria, string $table, string $field, mixed $value, array $ancestors, bool $globalScope): self
    {
        $entities = is_array($value) ? array_values($value) : [$value];
        $ancestors = array_values($ancestors);
        $known = !$globalScope && $field === 'entities_id';
        foreach (array_merge($entities, $ancestors) as $id) {
            $known = $known && (is_int($id) || (is_string($id) && ctype_digit($id)));
        }
        return new self(
            $criteria,
            $table,
            $field,
            $known,
            $known ? array_map('intval', $entities) : null,
            $known ? array_map('intval', $ancestors) : [],
            is_array($value),
        );
    }

    /** Retain the public helper's duplicate-key protection byte for byte. */
    public function wrappedCriteria(): array
    {
        return $this->criteria ? [crc32(serialize($this->criteria)) => $this->criteria] : [];
    }
}
