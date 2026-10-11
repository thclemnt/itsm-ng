<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DateTimeImmutable;
use LogicException;
use itsmng\Database\Entity\Holiday;

/** Calendar values owned by one scheduling calculation, without a connection or shared cache. */
final class CalendarSchedule
{
    private array $holidays = [];

    /** @param list<Holiday> $holidays Unmanaged closure values. */
    public function __construct(private readonly array $segments, array $holidays = [])
    {
        foreach ($holidays as $holiday) {
            $copy = clone $holiday;
            $copy->begin_date = $holiday->begin_date === null ? null : clone $holiday->begin_date;
            $copy->end_date = $holiday->end_date === null ? null : clone $holiday->end_date;
            $this->holidays[] = $copy;
        }
    }

    public function activeSeconds(int $day, string $begin, string $end): int
    {
        $sum = 0;
        foreach ($this->segments as $row) {
            if ((int)$row['day'] === $day && $row['begin'] !== null && $row['end'] !== null) {
                $sum += max(0, min(self::seconds($end), self::seconds($row['end'])) - max(self::seconds($begin), self::seconds($row['begin'])));
            }
        }
        return $sum;
    }

    public function addDelay(int $day, string $begin, int $delay): string|false
    {
        $start = self::seconds($begin);
        foreach ($this->segments as $row) {
            if ((int)$row['day'] !== $day || $row['begin'] === null || $row['end'] === null || self::seconds($row['end']) <= $start) {
                continue;
            }
            $segmentStart = max($start, self::seconds($row['begin']));
            $available = self::seconds($row['end']) - $segmentStart;
            if ($delay <= $available) {
                $time = $segmentStart + $delay;
                return sprintf('%02d:%02d:%02d', intdiv($time, 3600), intdiv($time % 3600, 60), $time % 60);
            }
            $delay -= $available;
        }
        return false;
    }

    public function contains(int $day, string $time): bool
    {
        $time = self::seconds($time);
        foreach ($this->segments as $row) {
            if ((int)$row['day'] === $day && $row['begin'] !== null && $row['end'] !== null
                && self::seconds($row['begin']) <= $time && self::seconds($row['end']) >= $time) {
                return true;
            }
        }
        return false;
    }

    public function hasAWorkingDay(): bool
    {
        for ($day = 0; $day <= 6; $day++) {
            if ($this->activeSeconds($day, '00:00:00', '24:00:00') > 0) {
                return true;
            }
        }
        return false;
    }

    public function nextWorkingOccurrence(int $time, ?int $end = null): int|false
    {
        if (!$this->hasAWorkingDay()) {
            throw new LogicException('A recurrence calendar requires positive working duration.');
        }
        if (!$this->hasAnnualOpening()) {
            return false;
        }
        while (true) {
            $day = (int)date('w', $time);
            $date = new DateTimeImmutable(date('Y-m-d', $time));
            $closed = false;
            foreach ($this->holidays as $holiday) {
                if ($holiday->containsDay($date)) {
                    $closed = true;
                    break;
                }
            }
            if (!$closed && $this->activeSeconds($day, '00:00:00', '24:00:00') > 0) {
                $occurrence = $this->contains($day, date('H:i:s', $time)) ? $time
                    : strtotime($date->format('Y-m-d') . ' ' . $this->addDelay($day, '00:00:00', 0));
                return $end === null || $occurrence <= $end ? $occurrence : false;
            }
            if ($end !== null && $time > $end) {
                return false;
            }
            $time = strtotime('+ 1 day', $time);
        }
    }

    /** Annual closures depend only on month/day, including February 29. */
    private function hasAnnualOpening(): bool
    {
        $perpetual = array_filter($this->holidays, static fn (Holiday $holiday): bool => $holiday->is_perpetual);
        if ($perpetual === []) {
            return true;
        }
        // In a full Gregorian cycle every annual date reaches every weekday.
        // Thus any uncovered annual date eventually meets a working weekday;
        // finite closures can delay it, but cannot remove it forever.
        for ($date = new DateTimeImmutable('2000-01-01'); $date->format('Y') === '2000'; $date = $date->modify('+1 day')) {
            foreach ($perpetual as $holiday) {
                if ($holiday->containsDay($date)) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    public static function seconds(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 3600 + (int)($parts[1] ?? 0) * 60 + (int)($parts[2] ?? 0);
    }
}
