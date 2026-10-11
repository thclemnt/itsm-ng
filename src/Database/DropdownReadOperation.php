<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\DropdownTranslationRepository;

/** Current choices retain the mapped repository's query, access and presentation policy. */
final class DropdownReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    /** Only untranslated, explicit scalar labels bypass ORM query compilation. */
    public function label(string $table, int $id, string $type, string $language, array $translations, array $columns): ?array
    {
        $this->beginRead();
        if ($translations === []) {
            $metadata = $this->metadata($table);
            $scalarColumns = true;
            foreach ($columns as $column) {
                $field = $metadata->getFieldName($column);
                $scalarColumns = $scalarColumns && $metadata->hasField($field)
                    && empty($metadata->fieldMappings[$field]->enumType);
            }
            if ($scalarColumns) {
                return (new DropdownTranslationRepository($this->manager))->nativeLabel($table, $id, $columns);
            }
        }
        return (new DropdownTranslationRepository($this->manager))->dropdownRow(
            $table,
            $id,
            $type,
            $language,
            $translations,
            $columns
        );
    }

    public function choices(string $table, array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
    {
        $this->beginRead();
        $metadata = $this->metadata($table);
        return $this->manager->getRepository($metadata->name)->ownedChoices(
            $criteria,
            $order,
            $translations,
            $kind,
            $language,
            $limit,
            $offset,
            $this->defaultIdentifiers($metadata),
            $this,
        );
    }
}
