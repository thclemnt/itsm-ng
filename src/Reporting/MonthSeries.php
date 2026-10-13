<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;

/** Validated calendar bounds and empty buckets shared by statistics entry points. */
final class MonthSeries
{
    public static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new InvalidArgumentException('Invalid statistics date');
        }
        return $date;
    }

    public static function fill(array $values, ?DateTimeImmutable $begin, ?DateTimeImmutable $end): array
    {
        if ($begin !== null && $end !== null) {
            $month = $begin->modify('first day of this month')->setTime(0, 0);
            $last = $end->modify('first day of this month')->setTime(0, 0);
            while ($month <= $last) {
                $values[$month->format('Y-m')] ??= 0;
                $month = $month->modify('+1 month');
            }
        }
        ksort($values);
        return $values;
    }
}
