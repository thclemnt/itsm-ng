<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Portable SQL expressions. Arguments are SQL expressions, never raw user values. */
final class Expressions
{
    public function __construct(private AbstractPlatform $platform)
    {
    }

    public function dateAdd(string $date, string|int $amount, string $unit): string
    {
        $suffix = match (strtoupper($unit)) {
            'SECOND' => 'Seconds', 'MINUTE' => 'Minutes', 'HOUR' => 'Hour',
            'DAY' => 'Days', 'WEEK' => 'Weeks', 'MONTH' => 'Month',
            'QUARTER' => 'Quarters', 'YEAR' => 'Years',
            default => throw new \InvalidArgumentException('Unsupported date interval unit'),
        };
        if ($this->platform instanceof PostgreSQLPlatform) {
            $date = 'CAST(' . $date . ' AS timestamp with time zone)';
        }
        return $this->platform->{'getDateAdd' . $suffix . 'Expression'}($date, (string)$amount);
    }

    public function yearMonth(string $date): string
    {
        return $this->platform instanceof PostgreSQLPlatform
            ? "TO_CHAR($date, 'YYYY-MM')" : "DATE_FORMAT($date, '%Y-%m')";
    }

    /** Calendar text matches legacy event keys, including repeated local hours. */
    public function temporalText(string $value, string $kind): string
    {
        [$postgres, $mysql] = match ($kind) {
            'date' => ['YYYY-MM-DD', '%Y-%m-%d'],
            'datetime' => ['YYYY-MM-DD HH24:MI:SS', '%Y-%m-%d %H:%i:%s'],
            default => throw new \InvalidArgumentException('Unknown temporal text representation.'),
        };
        return $this->platform instanceof PostgreSQLPlatform
            ? 'TO_CHAR(' . $value . ", '" . $postgres . "')"
            : 'DATE_FORMAT(' . $value . ", '" . $mysql . "')";
    }

    public function epoch(string $date = 'CURRENT_TIMESTAMP'): string
    {
        return $this->platform instanceof PostgreSQLPlatform ? "EXTRACT(EPOCH FROM $date)" : "UNIX_TIMESTAMP($date)";
    }

    public function secondsBetween(string $end, string $start): string
    {
        return $this->platform instanceof PostgreSQLPlatform
            ? "EXTRACT(EPOCH FROM ($end - $start))"
            : "TIMESTAMPDIFF(SECOND, $start, $end)";
    }
}
