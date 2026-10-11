<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Reporting;

use InvalidArgumentException;
use Session;

final class Criteria
{
    /** Same non-recursive entity scope used by the report entry points. */
    public static function entities(): ?array
    {
        return Session::getActiveEntityScope();
    }

    /** Either date must itself fall inside the entire requested interval. */
    public static function financialDates(string $begin, string $end): array
    {
        $dates = [];
        foreach (['buy_date', 'use_date'] as $field) {
            $bounds = [];
            if ($begin !== '') {
                $bounds[] = ['glpi_infocoms.' . $field => ['>=', $begin]];
            }
            if ($end !== '') {
                $bounds[] = ['glpi_infocoms.' . $field => ['<=', $end]];
            }
            if ($bounds) {
                $dates[] = ['AND' => $bounds];
            }
        }
        return $dates ? ['OR' => $dates] : [];
    }

    /** Only configured types may reach a report; malformed selections match nothing. */
    public static function itemtypes(mixed $selection, array $allowed): array
    {
        if ($selection === null || (is_array($selection) && in_array($selection[0] ?? null, [0, '0'], true))) {
            return $allowed;
        }
        if (!is_array($selection)) {
            return [];
        }
        return array_values(array_unique(array_filter($selection, static fn ($type) => is_string($type) && in_array($type, $allowed, true))));
    }

    public static function years(mixed $selection): array
    {
        if (!is_array($selection)) {
            throw new InvalidArgumentException('Invalid report years');
        }
        if (in_array($selection[0] ?? null, [0, '0'], true)) {
            return [];
        }
        foreach ($selection as $year) {
            self::yearBounds($year);
        }
        return array_values(array_unique($selection));
    }

    public static function yearBounds(mixed $year): array
    {
        if (!is_scalar($year) || !preg_match('/^[1-9][0-9]{3}$/D', (string)$year) || (int)$year >= 9999) {
            throw new InvalidArgumentException('Invalid report year');
        }
        return [$year . '-01-01', ((int)$year + 1) . '-01-01'];
    }

    /** Range predicates preserve date indexes and work on both database engines. */
    public static function year(string $column, mixed $year): array
    {
        [$start, $end] = self::yearBounds($year);
        return ['AND' => [[$column => ['>=', $start]], [$column => ['<', $end]]]];
    }
}
