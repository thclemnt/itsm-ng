<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\CalendarSegment;

final class CalendarRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** @return array<int, array> Calendar segments in their stable weekly order. */
    public function segments(int $calendar, ?int $firstDay = null, ?int $lastDay = null): array
    {
        $query = $this->em->createQueryBuilder()->select('s')->from(CalendarSegment::class, 's')
            ->where('s.calendars = :calendar')->setParameter('calendar', $calendar)
            ->orderBy('s.day')->addOrderBy('s.begin')->addOrderBy('s.end')->addOrderBy('s.id');
        if ($firstDay !== null) {
            $query->andWhere('s.day >= :first')->setParameter('first', $firstDay);
        }
        if ($lastDay !== null) {
            $query->andWhere('s.day <= :last')->setParameter('last', $lastDay);
        }
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->getResult() as $segment) {
            $rows[$segment->id] = $records->toRow($segment);
        }
        return $rows;
    }

    public function between(int $calendar, int $firstDay, string $begin, int $lastDay, string $end): array
    {
        $start = self::seconds($begin);
        $stop = self::seconds($end);
        return array_filter($this->segments($calendar, $firstDay, $lastDay), static fn ($row) =>
            ($row['day'] < $lastDay || ($row['begin'] !== null && self::seconds($row['begin']) < $stop))
            && ($row['day'] > $firstDay || ($row['end'] !== null && self::seconds($row['end']) >= $start)));
    }

    public function activeSeconds(int $calendar, int $day, string $begin, string $end): int
    {
        $sum = 0;
        foreach ($this->segments($calendar, $day, $day) as $row) {
            if ($row['begin'] !== null && $row['end'] !== null) {
                $sum += max(0, min(self::seconds($end), self::seconds($row['end'])) - max(self::seconds($begin), self::seconds($row['begin'])));
            }
        }
        return $sum;
    }

    public function addDelay(int $calendar, int $day, string $begin, int $delay): string|false
    {
        $start = self::seconds($begin);
        foreach ($this->segments($calendar, $day, $day) as $row) {
            if ($row['begin'] === null || $row['end'] === null || self::seconds($row['end']) <= $start) {
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

    public function boundary(int $calendar, int $day, bool $last): ?string
    {
        $column = $last ? 'end' : 'begin';
        $values = array_filter(array_column($this->segments($calendar, $day, $day), $column), static fn ($v) => $v !== null);
        return $values ? ($last ? max($values) : min($values)) : null;
    }

    public function contains(int $calendar, int $day, string $time): bool
    {
        $time = self::seconds($time);
        foreach ($this->segments($calendar, $day, $day) as $row) {
            if ($row['begin'] !== null && $row['end'] !== null && self::seconds($row['begin']) <= $time && self::seconds($row['end']) >= $time) {
                return true;
            }
        }
        return false;
    }

    private static function seconds(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 3600 + (int)($parts[1] ?? 0) * 60 + (int)($parts[2] ?? 0);
    }
}
