<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Component-issued query options are scalar-bound; array subset matching is insufficient. */
final class DropdownChoiceContext
{
    public static function token(string $kind, array $options): string
    {
        return \Session::getNewIDORToken($kind, ['_dropdown_choice_context' => self::encode($options)]);
    }

    public static function encode(array $options): string
    {
        $scope = $options['entity_restrict'] ?? -1;
        if (is_string($scope) && str_starts_with($scope, '[')) {
            $decoded = json_decode($scope, true);
            if (is_array($decoded)) {
                $scope = $decoded;
            }
        }
        return json_encode([
            'entity_restrict' => is_array($scope) ? array_values(array_map('intval', $scope)) : (is_numeric($scope) ? (int)$scope : $scope),
            'condition' => is_string($options['condition'] ?? null) ? $options['condition'] : '',
            'displaywith' => is_array($options['displaywith'] ?? null) ? array_values($options['displaywith']) : [],
            'permit_select_parent' => filter_var($options['permit_select_parent'] ?? false, FILTER_VALIDATE_BOOL),
            'right' => self::right($options['right'] ?? 'all'),
            'inactive_deleted' => (int)($options['inactive_deleted'] ?? 0),
            'with_no_right' => (int)($options['with_no_right'] ?? 0),
        ], JSON_THROW_ON_ERROR);
    }

    /** HTTP form encoding turns integer grants into strings, including array entries. */
    private static function right(mixed $right): mixed
    {
        if (is_array($right)) {
            return array_map(self::right(...), $right);
        }
        return is_numeric($right) ? (int)$right : $right;
    }
}
