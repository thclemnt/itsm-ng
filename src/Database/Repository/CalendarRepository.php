<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\CalendarHoliday;
use itsmng\Database\Entity\CalendarSegment;
use itsmng\Database\Entity\Holiday;
use itsmng\Domain\CalendarSchedule;

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
        $start = CalendarSchedule::seconds($begin);
        $stop = CalendarSchedule::seconds($end);
        return array_filter($this->segments($calendar, $firstDay, $lastDay), static fn ($row) =>
            ($row['day'] < $lastDay || ($row['begin'] !== null && CalendarSchedule::seconds($row['begin']) < $stop))
            && ($row['day'] > $firstDay || ($row['end'] !== null && CalendarSchedule::seconds($row['end']) >= $start)));
    }

    public function activeSeconds(int $calendar, int $day, string $begin, string $end): int
    {
        return (new CalendarSchedule($this->segments($calendar, $day, $day)))->activeSeconds($day, $begin, $end);
    }

    public function addDelay(int $calendar, int $day, string $begin, int $delay): string|false
    {
        return (new CalendarSchedule($this->segments($calendar, $day, $day)))->addDelay($day, $begin, $delay);
    }

    public function boundary(int $calendar, int $day, bool $last): ?string
    {
        $column = $last ? 'end' : 'begin';
        $values = array_filter(array_column($this->segments($calendar, $day, $day), $column), static fn ($v) => $v !== null);
        return $values ? ($last ? max($values) : min($values)) : null;
    }

    public function contains(int $calendar, int $day, string $time): bool
    {
        return (new CalendarSchedule($this->segments($calendar, $day, $day)))->contains($day, $time);
    }

    /** Read both native value sets once; never retain managed entity state between calculations. */
    public function schedule(int $calendar): CalendarSchedule
    {
        $rows = $this->em->createQueryBuilder()
            ->select('s.day AS dayNumber', 's.begin AS startTime', 's.end AS endTime')
            ->from(CalendarSegment::class, 's')->where('IDENTITY(s.calendars) = :calendar')
            ->setParameter('calendar', $calendar, Types::INTEGER)
            ->orderBy('s.day')->addOrderBy('s.begin')->addOrderBy('s.end')->addOrderBy('s.id')
            ->getQuery()->getArrayResult();
        $segments = array_map(static fn ($row) => [
            'day' => $row['dayNumber'], 'begin' => $row['startTime'], 'end' => $row['endTime'],
        ], $rows);
        $rows = $this->closureQuery($calendar)
            ->select('holiday.begin_date AS startDate', 'holiday.end_date AS endDate', 'holiday.is_perpetual AS perpetual')
            ->getQuery()->getArrayResult();
        $holidays = [];
        foreach ($rows as $row) {
            $holiday = new Holiday();
            $holiday->begin_date = $row['startDate'];
            $holiday->end_date = $row['endDate'];
            $holiday->is_perpetual = $row['perpetual'];
            $holidays[] = $holiday;
        }
        return new CalendarSchedule($segments, $holidays);
    }

    /** @return list<CalendarHoliday> Every owning membership retains its individual link identity. */
    public function closures(int $calendar): array
    {
        return $this->closureQuery($calendar)->orderBy('holiday.name')->addOrderBy('link.id')
            ->getQuery()->getResult();
    }

    private function closureQuery(int $calendar): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('link', 'holiday')->from(CalendarHoliday::class, 'link')
            ->join('link.holidays', 'holiday')->where('IDENTITY(link.calendars) = :calendar')
            ->setParameter('calendar', $calendar, Types::INTEGER);
    }

    public function isHoliday(int $calendar, DateTimeImmutable $day): bool
    {
        $links = $this->closureQuery($calendar)
            ->andWhere('holiday.is_perpetual = :yes OR (holiday.begin_date <= :day AND holiday.end_date >= :day)')
            ->setParameter('yes', true, Types::BOOLEAN)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)->getQuery()->toIterable();
        foreach ($links as $link) {
            $holiday = $link->holidays;
            $this->em->detach($link);
            $this->em->detach($holiday);
            if ($holiday->containsDay($day)) {
                return true;
            }
        }
        return false;
    }

}
