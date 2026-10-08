<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Domain;

use DateTime;
use DateTimeImmutable;
use LogicException;
use atoum\atoum\test;
use itsmng\Database\Entity\Holiday;
use itsmng\Domain\CalendarSchedule as Schedule;

class CalendarSchedule extends test
{
    public function testSegmentBoundsKeepNullableAndEndOfDaySemantics(): void
    {
        $segments = [
            ['day' => 1, 'begin' => null, 'end' => '08:00:00'],
            ['day' => 1, 'begin' => '08:00:00', 'end' => null],
            ['day' => 1, 'begin' => '09:00:00', 'end' => '09:00:00'],
            ['day' => 1, 'begin' => '11:00:00', 'end' => '10:00:00'],
        ];
        $empty = new Schedule($segments);
        $this->boolean($empty->hasAWorkingDay())->isFalse();
        $this->exception(static fn () => $empty->nextWorkingOccurrence(strtotime('2026-10-05 08:00:00')))
            ->isInstanceOf(LogicException::class);
        $segments[] = ['day' => 1, 'begin' => '12:00:00', 'end' => '24:00:00'];
        $schedule = new Schedule($segments);
        $this->integer($schedule->activeSeconds(1, '00:00:00', '24:00:00'))->isIdenticalTo(43200);
        $this->integer($schedule->activeSeconds(2, '00:00:00', '24:00:00'))->isIdenticalTo(0);
        $this->boolean($schedule->contains(1, '09:00:00'))->isTrue();
        $this->boolean($schedule->contains(1, '10:30:00'))->isFalse();
        $this->boolean($schedule->contains(1, '24:00:00'))->isTrue();
        $this->string($schedule->addDelay(1, '12:00:00', 43200))->isIdenticalTo('24:00:00');
        $this->variable($schedule->addDelay(1, '12:00:00', 43201))->isIdenticalTo(false);
    }

    public function testClosureSnapshotRetainsHoursAndIgnoresLaterCallerMutation(): void
    {
        $segments = [
            ['day' => 1, 'begin' => '09:00:00', 'end' => '19:00:00'],
            ['day' => 2, 'begin' => '10:00:00', 'end' => '18:00:00'],
        ];
        $holiday = new Holiday();
        $holiday->begin_date = new DateTime('2026-10-05');
        $holiday->end_date = new DateTime('2026-10-05');
        $schedule = new Schedule($segments, [$holiday]);
        $holiday->end_date->modify('+1 day');
        $this->integer($schedule->nextWorkingOccurrence(strtotime('2026-10-05 15:00:00')))
            ->isIdenticalTo(strtotime('2026-10-06 15:00:00'));
        $this->integer($schedule->nextWorkingOccurrence(strtotime('2026-10-05 08:00:00')))
            ->isIdenticalTo(strtotime('2026-10-06 10:00:00'));
        $fresh = new Schedule($segments, [$holiday]);
        $this->integer($fresh->nextWorkingOccurrence(strtotime('2026-10-05 08:00:00')))
            ->isIdenticalTo(strtotime('2026-10-12 09:00:00'));
    }

    public function testPerpetualClosureCanCrossTheYearBoundary(): void
    {
        $segments = [];
        for ($day = 0; $day <= 6; $day++) {
            $segments[] = ['day' => $day, 'begin' => '09:00:00', 'end' => '19:00:00'];
        }
        $holiday = new Holiday();
        $holiday->begin_date = new DateTimeImmutable('2020-12-31');
        $holiday->end_date = new DateTimeImmutable('2021-01-02');
        $holiday->is_perpetual = true;
        $schedule = new Schedule($segments, [$holiday]);
        $this->integer($schedule->nextWorkingOccurrence(strtotime('2026-01-01 11:00:00')))
            ->isIdenticalTo(strtotime('2026-01-03 11:00:00'));
    }
}
