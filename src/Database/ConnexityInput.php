<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use CommonDBChild;
use CommonDBConnexity;
use CommonDBRelation;
use InvalidArgumentException;
use itsmng\Database\Mapping\LegacyInput;
use LogicException;

/** The public model's endpoint roles use the entity's authoritative owning declarations. */
final class ConnexityInput
{
    /** Discrimination that belongs to a recipient or another field is not an endpoint. */
    public static function endpoints(CommonDBConnexity $model): array
    {
        $roles = $model instanceof CommonDBRelation
            ? [[$model::$itemtype_1, $model::$items_id_1], [$model::$itemtype_2, $model::$items_id_2]]
            : ($model instanceof CommonDBChild ? [[$model::$itemtype, $model::$items_id]] : []);
        $references = EntityRegistry::discriminatedReferences($model::getTable());
        $endpoints = [];
        foreach ($roles as [$kind, $identity]) {
            if (($references[$identity]['discriminator'] ?? null) === $kind) {
                $endpoints[$identity] = $references[$identity];
            }
        }
        return $endpoints;
    }

    /** Keep the derived legacy identity available to authorization, caches and history. */
    public static function normalize(CommonDBConnexity $model, array $input): array
    {
        $endpoints = self::endpoints($model);
        if (!$endpoints) {
            return $input;
        }
        $class = EntityRegistry::tables()[$model::getTable()];
        $record = new $class();
        if (!$record instanceof LegacyInput) {
            throw new LogicException('A discriminated public endpoint requires its entity input policy.');
        }
        foreach ($endpoints as $identity => $endpoint) {
            $discriminator = $endpoint['discriminator'];
            $columns = array_column($endpoint['selections'], 'column');
            $fallback = $endpoint['fallback_column'] ?? null;
            $keys = [$discriminator, $identity, ...$columns];
            if ($fallback !== null) {
                $keys[] = $fallback;
            }
            if (!array_intersect(array_keys($input), $keys)) {
                continue;
            }
            $reference = array_intersect_key($input, array_fill_keys($keys, true));
            if (!array_key_exists($discriminator, $reference)) {
                $reference[$discriminator] = $model->fields[$discriminator] ?? null;
            }
            $kind = $reference[$discriminator];
            if ($kind !== null && !is_string($kind) && !is_int($kind)) {
                throw new InvalidArgumentException('Invalid endpoint discriminator.');
            }
            $selection = is_string($kind) || is_int($kind) ? ($endpoint['selections'][$kind] ?? null) : null;
            $column = $selection['column'] ?? $fallback;
            if (!array_key_exists($identity, $reference) && ($column === null || !array_key_exists($column, $reference))) {
                $reference[$identity] = $column === null && array_key_exists('empty_value', $endpoint)
                    ? $endpoint['empty_value'] : ($model->fields[$identity] ?? $endpoint['empty_value'] ?? null);
            }
            $normalized = $record->normalizeInput($reference);
            // The normalizer removes generated fields before ORM persistence;
            // the public lifecycle still needs the resolved endpoint identity.
            $normalized[$identity] = $selection !== null
                ? ($normalized[$column] ?? $selection['empty_value'] ?? null)
                : ($fallback === null ? ($endpoint['empty_value'] ?? null) : ($normalized[$fallback] ?? null));
            $input = array_replace($input, $normalized);
        }
        return $input;
    }

    public static function fields(CommonDBConnexity $model): array
    {
        return $model instanceof CommonDBRelation
            ? [$model::$itemtype_1, $model::$items_id_1, $model::$itemtype_2, $model::$items_id_2]
            : [$model::$itemtype, $model::$items_id];
    }

    public static function endpointFields(CommonDBConnexity $model): array
    {
        $columns = self::fields($model);
        foreach (self::endpoints($model) as $endpoint) {
            array_push($columns, ...array_column($endpoint['selections'], 'column'));
            if (($endpoint['fallback_column'] ?? null) !== null) {
                $columns[] = $endpoint['fallback_column'];
            }
        }
        return array_values(array_unique($columns));
    }
}
