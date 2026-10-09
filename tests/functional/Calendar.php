<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use Calendar_Holiday;
use CalendarSegment as CalendarSegmentModel;
use DateTimeImmutable;
use Doctrine\Common\EventManager;
use DbTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use itsmng\Database\Entity\Config;
use itsmng\Database\Entity\Calendar as CalendarRecord;
use itsmng\Database\Entity\CalendarHoliday as CalendarHolidayRecord;
use itsmng\Database\Entity\Holiday as HolidayRecord;
use itsmng\Database\Entity\CalendarSegment as CalendarSegmentRecord;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CalendarRepository;
use ReflectionProperty;
use mock\DBmysql as CalendarAdapterProbe;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/calendar.class.php */

class Calendar extends DbTestCase
{
    public function testClosurePermissionsFollowTheCalendarOwnerAndDeclaredHolidayRole(): void
    {
        $savedSession = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $name = 'closure-rights-' . $this->getUniqueString();
            $calendar = $this->createItem('Calendar', ['name' => $name, 'entities_id' => 0, 'is_recursive' => false]);
            $holiday = $this->createItem('Holiday', ['name' => $name, 'begin_date' => '2030-05-01', 'end_date' => '2030-05-02', 'is_perpetual' => false]);
            $second = $this->createItem('Holiday', ['name' => $name . '-second', 'begin_date' => '2030-06-01', 'end_date' => '2030-06-02', 'is_perpetual' => false]);
            $link = $this->createItem('Calendar_Holiday', ['calendars_id' => (int)$calendar->getID(), 'holidays_id' => (int)$holiday->getID()]);
            $id = (int)$link->getID();
            $input = ['calendars_id' => (int)$calendar->getID(), 'holidays_id' => (int)$second->getID()];
            $_SESSION['glpiactiveprofile']['calendar'] = READ;
            $this->boolean($calendar->can($calendar->getID(), READ))->isTrue();
            $this->boolean($link->canCreateItem())->isFalse('DONT_CHECK is not an alternate write grant when the Calendar is only readable');
            $this->boolean((new Calendar_Holiday())->can(-1, CREATE, $input))->isFalse();
            $this->boolean($link->can($id, PURGE))->isFalse();

            $_SESSION['glpiactiveprofile']['calendar'] = UPDATE;
            $this->boolean($holiday->can($holiday->getID(), READ))->isFalse();
            $this->boolean($link->canCreateItem())->isTrue('The declared secondary DONT_CHECK role does not require Holiday READ');
            $this->boolean((new Calendar_Holiday())->can(-1, CREATE, $input))->isTrue();
            $added = $this->createItem('Calendar_Holiday', $input);
            $addedId = (int)$added->getID();
            $this->boolean($added->can($addedId, PURGE))->isTrue();
            $this->boolean($added->delete(['id' => $addedId], true))->isTrue();
            $this->boolean($added->getFromDB($addedId))->isFalse();
            $this->boolean($second->getFromDB($second->getID()))->isTrue('Purging a closure does not purge its reusable Holiday');
            $this->boolean($link->can($id, UPDATE))->isTrue();
            $this->boolean($link->can($id, PURGE))->isTrue();

            $invalid = ['calendars_id' => (int)$calendar->getID(), 'holidays_id' => -1];
            $this->boolean((new Calendar_Holiday())->can(-1, CREATE, $invalid))->isFalse();
            $_SESSION['glpiactiveentities'] = [];
            $_SESSION['glpishowallentities'] = false;
            $this->boolean((new Calendar_Holiday())->can(-1, CREATE, $input))->isFalse('Calendar entity scope still owns closure admission');
            $this->boolean($link->can($id, PURGE))->isFalse();
        } finally {
            $_SESSION = $savedSession;
        }
    }

    public function testComputeEndDate()
    {
        $calendar = new \Calendar();
        //get default calendar
        $this->boolean($calendar->getFromDB(getItemByTypeName('Calendar', 'Default', true)))->isTrue();

        // ## test future dates
        $end_date = $calendar->ComputeEndDate("2018-11-19 10:00:00", 7 * DAY_TIMESTAMP, 0, true);
        $this->string($end_date)->isEqualTo("2018-11-28 10:00:00");
        // end of day
        $end_date = $calendar->ComputeEndDate("2018-11-19 10:00:00", 7 * DAY_TIMESTAMP, 0, true, true);
        $this->string($end_date)->isEqualTo("2018-11-28 20:00:00");

        // ## test past dates
        $end_date = $calendar->ComputeEndDate("2018-11-19 10:00:00", -7 * DAY_TIMESTAMP, 0, true);
        $this->string($end_date)->isEqualTo("2018-11-08 10:00:00");
        // end of day
        $end_date = $calendar->ComputeEndDate("2018-11-19 10:00:00", -7 * DAY_TIMESTAMP, 0, true, true);
        $this->string($end_date)->isEqualTo("2018-11-08 20:00:00");
    }

    protected function activeProvider()
    {
        return [
           [
              'start'  => '2019-01-01 07:00:00',
              'end'    => '2019-01-01 09:00:00',
              'value'  => HOUR_TIMESTAMP
           ], [
              'start'  => '2019-01-01 06:00:00',
              'end'    => '2019-01-01 07:00:00',
              'value'  => 0
           ], [
              'start'  => '2019-01-01 00:00:00',
              'end'    => '2019-01-08 00:00:00',
              'value'  => 12 * HOUR_TIMESTAMP * 5
           ], [
              'start'  => '2019-01-08 00:00:00',
              'end'    => '2019-01-01 00:00:00',
              'value'  => 0
           ], [
              'start'  => '2019-01-01 07:00:00',
              'end'    => '2019-01-01 09:00:00',
              'value'  => HOUR_TIMESTAMP * 2,
              'days'   => true
           ], [
              'start'  => '2019-01-01 00:00:00',
              'end'    => '2019-01-08 00:00:00',
              'value'  => WEEK_TIMESTAMP,
              'days'   => true
           ]
        ];
    }

    /**
     * @dataProvider activeProvider
     */
    public function testGetActiveTimeBetween($start, $end, $value, $days = false)
    {
        $calendar = new \Calendar();
        $this->boolean($calendar->getFromDB(1))->isTrue(); //get default calendar

        $this->variable(
            $calendar->getActiveTimeBetween(
                $start,
                $end,
                $days
            )
        )->isEqualTo($value);
    }

    protected function workingdayProvider()
    {
        return [
           ['2019-01-01 00:00:00', true],
           ['2019-01-02 00:00:00', true],
           ['2019-01-03 00:00:00', true],
           ['2019-01-04 00:00:00', true],
           ['2019-01-05 00:00:00', false],
           ['2019-01-06 00:00:00', false]
        ];
    }

    /**
     * @dataProvider workingdayProvider
     */
    public function testIsAWorkingDay($date, $expected)
    {
        $calendar = new \Calendar();
        $this->boolean($calendar->getFromDB(1))->isTrue(); //get default calendar

        $this->boolean($calendar->isAWorkingDay(strtotime((string) $date)))->isIdenticalTo($expected);
    }

    public function testHasAWorkingDay()
    {
        $calendar = new \Calendar();
        $this->boolean($calendar->getFromDB(1))->isTrue(); //get default calendar
        $this->boolean($calendar->hasAWorkingDay())->isTrue();

        $cid = $calendar->add([
           'name'   => 'Test'
        ]);
        $this->integer($cid)->isGreaterThan(0);
        $this->boolean($calendar->getFromDB($cid));
        $this->boolean($calendar->hasAWorkingDay())->isFalse();
    }

    protected function workinghourProvider()
    {
        return [
           ['2019-01-01 00:00:00', false],
           ['2019-01-02 08:30:00', true],
           ['2019-01-03 18:10:00', true],
           ['2019-01-04 21:00:00', false],
           ['2019-01-05 08:30:00', false],
           ['2019-01-06 00:00:00', false]
        ];
    }

    /**
     * @dataProvider workinghourProvider
     */
    public function testIsAWorkingHour($date, $expected)
    {
        $calendar = new \Calendar();
        $this->boolean($calendar->getFromDB(1))->isTrue(); //get default calendar

        $this->boolean($calendar->isAWorkingHour(strtotime((string) $date)))->isIdenticalTo($expected);
    }

    private function addXmas(\Calendar $calendar)
    {
        $calendar_holiday = new \Calendar_Holiday();
        $this->integer(
            (int)$calendar_holiday->add([
              'calendars_id' => $calendar->fields['id'],
              'holidays_id'  => getItemByTypeName('Holiday', 'X-Mas', true)
         ])
        )->isGreaterThan(0);

        $this->checkXmas($calendar);
    }

    private function checkXmas(\Calendar $calendar)
    {
        $this->boolean(
            $calendar->isHoliday('2018-01-01')
        )->isFalse();

        $this->boolean(
            $calendar->isHoliday('2019-01-01')
        )->isTrue();
    }

    public function testIsHoliday()
    {
        $calendar = new \Calendar();
        // get Default calendar
        $default_id = getItemByTypeName('Calendar', 'Default', true);
        $this->boolean($calendar->getFromDB($default_id))->isTrue();

        $dates = [
           '2019-05-01'   => true,
           '2019-05-02'   => false,
           '2019-07-01'   => false,
           '2019-07-12'   => true
        ];

        //no holiday by default
        foreach (array_keys($dates) as $date) {
            $this->boolean($calendar->isHoliday($date))->isFalse;
        }

        //Clone calendar and add holidays
        $clone_id = $calendar->clone();
        $this->integer($clone_id)->isGreaterThan($default_id);
        $this->boolean($calendar->getFromDB($clone_id))->isTrue();

        $calendar_holiday = new \Calendar_Holiday();
        $holiday = new \Holiday();
        $hid = (int)$holiday->add([
           'name'         => '1st of may',
           'entities_id'  => 0,
           'is_recursive' => 1,
           'begin_date'   => '2019-05-01',
           'end_date'     => '2019-05-01',
           'is_perpetual' => 1
        ]);
        $this->integer($hid)->isGreaterThan(0);
        $this->integer(
            (int)$calendar_holiday->add([
              'holidays_id'  => $hid,
              'calendars_id' => $calendar->fields['id']
         ])
        )->isGreaterThan(0);

        $hid = (int)$holiday->add([
           'name'   => 'Summer vacations',
           'entities_id'  => 0,
           'is_recursive' => 1,
           'begin_date'   => '2019-07-08',
           'end_date'     => '2019-09-01',
           'is_perpetual' => 0
        ]);
        $this->integer($hid)->isGreaterThan(0);
        $this->integer(
            (int)$calendar_holiday->add([
              'holidays_id'  => $hid,
              'calendars_id' => $calendar->fields['id']
         ])
        )->isGreaterThan(0);

        foreach ($dates as $date => $expected) {
            $this->boolean($calendar->isHoliday($date))->isIdenticalTo($expected);
        }

        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeFactories = $factories->getValue();
        for ($repeat = 0; $repeat < 3; ++$repeat) {
            $this->boolean($calendar->isHoliday('2019-07-12'))->isTrue();
        }
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        try {
            $connection->update('glpi_holidays', ['begin_date' => '2020-07-08', 'end_date' => '2020-09-01'], ['id' => $hid]);
            $this->boolean($calendar->isHoliday('2019-07-12'))->isFalse();
        } finally {
            $connection->update('glpi_holidays', ['begin_date' => '2019-07-08', 'end_date' => '2019-09-01'], ['id' => $hid]);
        }
        $this->boolean($calendar->isHoliday('2019-07-12'))->isTrue();

        Orm::withConnection($connection, function (EntityManager $outer) use ($calendar, $factories): void {
            $sentinel = $outer->getReference(Config::class, 1);
            $beforeFactories = $factories->getValue();
            $this->boolean($calendar->isHoliday('2019-07-12'))->isTrue();
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(1);
            $this->boolean($outer->contains($sentinel))->isTrue();
        });

        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $this->boolean($calendar->isHoliday('2019-07-07 23:30:00-02:00'))->isTrue();
            date_default_timezone_set('America/Los_Angeles');
            $this->boolean($calendar->isHoliday('2019-07-07 23:30:00-02:00'))->isFalse();
        } finally {
            date_default_timezone_set($timezone);
        }

        $originalAdapter = $GLOBALS['DB'];
        $events = new EventManager();
        $listener = new class () {
            public int $clears = 0;
            public int $segmentLoads = 0;
            public bool $captureClosures = false;
            public ?EntityManager $holidayManager = null;
            public array $loadedClosures = [];

            public function postLoad(PostLoadEventArgs $event): void
            {
                $record = $event->getObject();
                if ($this->captureClosures && ($record instanceof CalendarHolidayRecord || $record instanceof HolidayRecord)) {
                    $this->holidayManager = $event->getObjectManager();
                    $this->loadedClosures[] = $record;
                    if ($record instanceof HolidayRecord) {
                        $record->comment = 'pending custom holiday callback';
                    }
                }
                if ($event->getObject() instanceof CalendarSegmentRecord) {
                    ++$this->segmentLoads;
                    $event->getObject()->end = '09:00:00';
                }
            }

            public function onClear(): void
            {
                ++$this->clears;
            }
        };
        $events->addEventListener(['onClear', 'postLoad'], $listener);
        $probe = new class ($connection) extends ScalarReadProbe {
            public EventManager $events;

            public function getEventManager(): EventManager
            {
                return $this->events;
            }
        };
        $probe->events = $events;
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new CalendarAdapterProbe();
        $getters = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, $originalAdapter, &$getters): Connection {
            ++$getters;
            $GLOBALS['DB'] = $originalAdapter;
            return $probe;
        };
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $beforeFactories = $factories->getValue();
            for ($repeat = 0; $repeat < 3; ++$repeat) {
                $GLOBALS['DB'] = $adapter;
                $this->boolean($calendar->isHoliday('2019-07-12'))->isTrue();
            }
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(3);
            $this->integer($getters)->isIdenticalTo(3);
            $this->array($probe->queries)->hasSize(3);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $this->object($GLOBALS['DB'])->isIdenticalTo($originalAdapter);
            $beforeFactories = $factories->getValue();
            for ($repeat = 0; $repeat < 3; ++$repeat) {
                $GLOBALS['DB'] = $adapter;
                // The selected custom manager must dispatch its segment postLoad before calculating.
                $this->integer(CalendarSegmentModel::getActiveTimeBetween((int)$calendar->getID(), 1, '00:00:00', '24:00:00'))
                    ->isIdenticalTo(HOUR_TIMESTAMP);
            }
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(3);
            $this->integer($getters)->isIdenticalTo(6);
            $this->array($probe->queries)->hasSize(6);
            $this->integer($listener->segmentLoads)->isIdenticalTo(3);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $this->object($GLOBALS['DB'])->isIdenticalTo($originalAdapter);
            // The annual May closure is inspected even though July 1 is not a holiday.
            $listener->captureClosures = true;
            $GLOBALS['DB'] = $adapter;
            $this->boolean($calendar->isHoliday('2019-07-01'))->isFalse();
            $this->array($listener->loadedClosures)->hasSize(2);
            foreach ($listener->loadedClosures as $record) {
                $this->boolean($listener->holidayManager->contains($record))
                    ->isTrue('A custom postLoad owner retains both closure and Holiday identities');
            }
            $this->integer($listener->clears)->isIdenticalTo(0);
        } finally {
            $GLOBALS['DB'] = $originalAdapter;
            $listener->holidayManager?->clear();
        }

        $originalDate = Type::getType(Types::DATE_IMMUTABLE);
        $converter = new class () extends DateImmutableType {
            public int $sqlCalls = 0;

            public function convertToDatabaseValueSQL(string $expression, AbstractPlatform $platform): string
            {
                ++$this->sqlCalls;
                return $expression;
            }
        };
        try {
            Type::getTypeRegistry()->override(Types::DATE_IMMUTABLE, $converter);
            $beforeFactories = $factories->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $this->boolean($calendar->isHoliday('2019-07-12'))->isTrue();
            }
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(2);
            // :day occurs twice in this fixed DQL query, once for each boundary.
            $this->integer($converter->sqlCalls)->isIdenticalTo(4);
        } finally {
            Type::getTypeRegistry()->override(Types::DATE_IMMUTABLE, $originalDate);
        }

        $manager = Orm::forConnection($connection);
        try {
            $repository = new CalendarRepository($manager);
            $annual = array_values(array_filter(
                $repository->closures((int)$calendar->getID()),
                static fn (CalendarHolidayRecord $link): bool => $link->holidays->is_perpetual
            ));
            $this->array($annual)->hasSize(1);
            $link = $annual[0];
            $holiday = $link->holidays;
            $holiday->comment = 'pending caller holiday edit';
            $link->calendars = $manager->getReference(CalendarRecord::class, (int)$default_id);
            $this->boolean($repository->isHoliday((int)$calendar->getID(), new DateTimeImmutable('2019-07-01')))->isFalse();
            $this->boolean($manager->contains($link))->isTrue();
            $this->boolean($manager->contains($holiday))->isTrue();
            $manager->flush();
            $this->string($connection->fetchOne('SELECT comment FROM glpi_holidays WHERE id = ?', [$holiday->id]))
                ->isIdenticalTo('pending caller holiday edit');
            $this->integer((int)$connection->fetchOne('SELECT calendars_id FROM glpi_calendars_holidays WHERE id = ?', [$link->id]))
                ->isIdenticalTo((int)$default_id);
        } finally {
            $manager->clear();
        }
    }

    public function testClone()
    {
        $calendar = new \Calendar();
        $default_id = getItemByTypeName('Calendar', 'Default', true);
        // get Default calendar
        $this->boolean($calendar->getFromDB($default_id))->isTrue();
        $this->addXmas($calendar);

        $id = $calendar->clone();
        $this->integer($id)->isGreaterThan($default_id);
        $this->boolean($calendar->getFromDB($id))->isTrue();
        //should have been duplicated too.
        $this->checkXmas($calendar);

        //change name, and clone again
        $this->boolean($calendar->update(['id' => $id, 'name' => "Je s\'apelle Groot"]))->isTrue();

        $calendar = new \Calendar();
        $this->boolean($calendar->getFromDB($id))->isTrue();

        $this->boolean($calendar->duplicate())->isTrue();
        $other_id = $calendar->fields['id'];
        $this->integer($other_id)->isGreaterThan($id);
        $this->boolean($calendar->getFromDB($other_id))->isTrue();
        //should have been duplicated too.
        $this->checkXmas($calendar);

        // The cloned segment schedule is read afresh within each scalar ownership scope.
        $expected = (int)$calendar->getDurationsCache()[1];
        $this->integer($expected)->isGreaterThan(HOUR_TIMESTAMP);
        $read = static fn (): int => CalendarSegmentModel::getActiveTimeBetween($other_id, 1, '00:00:00', '24:00:00');
        $this->integer($read())->isIdenticalTo($expected);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeFactories = $factories->getValue();
        for ($repeat = 0; $repeat < 3; ++$repeat) {
            $this->integer($read())->isIdenticalTo($expected);
        }
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0, 'Warmed cloned-segment reads reuse their scalar owner');

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $segments = $connection->fetchAllAssociative('SELECT id, ' . $connection->quoteIdentifier('end')
            . ' FROM glpi_calendarsegments WHERE calendars_id = ? AND day = ?', [$other_id, 1]);
        $this->array($segments)->hasSize(1);
        $segment = $segments[0];
        $update = 'UPDATE glpi_calendarsegments SET ' . $connection->quoteIdentifier('end') . ' = ? WHERE id = ?';
        try {
            $connection->executeStatement($update, [date('H:i:s', strtotime($segment['end']) - HOUR_TIMESTAMP), $segment['id']]);
            $this->integer($read())->isIdenticalTo($expected - HOUR_TIMESTAMP);
        } finally {
            $connection->executeStatement($update, [$segment['end'], $segment['id']]);
        }
        $this->integer($read())->isIdenticalTo($expected);
        Orm::withConnection($connection, function (EntityManager $outer) use ($read, $expected, $factories): void {
            $sentinel = $outer->getReference(Config::class, 1);
            $beforeFactories = $factories->getValue();
            $this->integer($read())->isIdenticalTo($expected);
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(1);
            $this->boolean($outer->contains($sentinel))->isTrue();
        });
    }
}
