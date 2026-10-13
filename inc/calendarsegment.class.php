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

use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\CalendarRepository;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * CalendarSegment Class
 */
class CalendarSegment extends CommonDBChild
{
    // From CommonDBTM
    public $dohistory       = true;

    // From CommonDBChild
    public static $itemtype = 'Calendar';
    public static $items_id = 'calendars_id';


    /**
     * @since 0.84
    **/
    public function getForbiddenStandardMassiveAction()
    {

        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }


    public static function getTypeName($nb = 0)
    {
        return _n('Time range', 'Time ranges', $nb);
    }


    public function prepareInputForAdd($input)
    {

        // Check override of segment : do not add
        if (
            count(self::getSegmentsBetween(
                $input['calendars_id'],
                $input['day'],
                $input['begin'],
                $input['day'],
                $input['end']
            )) > 0
        ) {
            Session::addMessageAfterRedirect(
                __('Can not add a range riding an existing period'),
                false,
                ERROR
            );
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    /**
     * Duplicate all segments from a calendar to his clone
     *
     * @deprecated 9.5
     *
     * @param $oldid
     * @param $newid
    **/
    public static function cloneCalendar($oldid, $newid)
    {
        Toolbox::deprecated('Use clone');
        global $DB;
        $result = Orm::read($DB, static fn (EntityManager $em): array =>
            (new CalendarRepository($em))->segments((int)$oldid));

        foreach ($result as $data) {
            $c                    = new self();
            unset($data['id']);
            $data['calendars_id'] = $newid;
            $data['_no_history']  = true;

            $c->add($data);
        }
    }


    public function post_addItem()
    {

        // Update calendar cache
        $cal = new Calendar();
        $cal->updateDurationCache($this->fields['calendars_id']);

        parent::post_addItem();
    }


    public function post_deleteFromDB()
    {

        // Update calendar cache
        $cal = new Calendar();
        $cal->updateDurationCache($this->fields['calendars_id']);

        parent::post_deleteFromDB();
    }


    /**
     * Get segments of a calendar between 2 date
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $begin_day       begin day number
     * @param string  $begin_time      begin time to check
     * @param integer $end_day         end day number
     * @param string  $end_time        end time to check
    **/
    public static function getSegmentsBetween($calendars_id, $begin_day, $begin_time, $end_day, $end_time)
    {
        global $DB;
        return Orm::read($DB, static fn (EntityManager $em): array =>
            (new CalendarRepository($em))->between((int)$calendars_id, (int)$begin_day, $begin_time, (int)$end_day, $end_time));
    }


    /**
     * Get active time between begin and end time in a day
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $day             day number
     * @param string  $begin_time      begin time to check
     * @param string  $end_time        end time to check
     *
     * @return integer Time in seconds
    **/
    public static function getActiveTimeBetween($calendars_id, $day, $begin_time, $end_time)
    {
        global $DB;
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return Orm::withReadConnection($connection, static fn (?EntityManager $manager): int =>
            (new CalendarRepository($manager ?? Orm::forConnection($connection)))
                ->activeSeconds((int)$calendars_id, (int)$day, $begin_time, $end_time));
    }


    /**
     * Add a delay of a starting hour in a specific day
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $day             day number
     * @param string  $begin_time      begin time
     * @param integer $delay           timestamp delay to add
     *
     * @return string|false Ending timestamp (HH:mm:dd) of delay or false if not applicable.
    **/
    public static function addDelayInDay($calendars_id, $day, $begin_time, $delay)
    {
        global $DB;
        return Orm::read($DB, static fn (EntityManager $em): string|false =>
            (new CalendarRepository($em))->addDelay((int)$calendars_id, (int)$day, $begin_time, (int)$delay));
    }


    /**
     * Get first working hour of a day
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $day             day number
     *
     * @return string Timestamp (HH:mm:dd) of first working hour
    **/
    public static function getFirstWorkingHour($calendars_id, $day)
    {
        global $DB;
        return Orm::read($DB, static fn (EntityManager $em): ?string =>
            (new CalendarRepository($em))->boundary((int)$calendars_id, (int)$day, false));
    }


    /**
     * Get last working hour of a day
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $day             day number
     *
     * @return string Timestamp (HH:mm:dd) of last working hour
    **/
    public static function getLastWorkingHour($calendars_id, $day)
    {
        global $DB;
        return Orm::read($DB, static fn (EntityManager $em): ?string =>
            (new CalendarRepository($em))->boundary((int)$calendars_id, (int)$day, true));
    }


    /**
     * Is the hour passed is a working hour ?
     *
     * @param integer $calendars_id    id of the calendar
     * @param integer $day             day number
     * @param string  $hour            hour (Format HH:MM::SS)
     *
     * @return boolean
    **/
    public static function isAWorkingHour($calendars_id, $day, $hour)
    {
        global $DB;
        return Orm::read($DB, static fn (EntityManager $em): bool =>
            (new CalendarRepository($em))->contains((int)$calendars_id, (int)$day, $hour));
    }


    /**
     * Show segments of a calendar
     *
     * @param $calendar Calendar object
    **/
    public static function showForCalendar(Calendar $calendar)
    {
        $ID = $calendar->getField('id');
        if (!$calendar->can($ID, READ)) {
            return false;
        }

        $canedit = $calendar->can($ID, UPDATE);
        $rand    = mt_rand();

        global $DB;
        $rows = Orm::read($DB, static fn (EntityManager $em): array =>
            (new CalendarRepository($em))->segments((int)$ID));
        $numrows = count($rows);

        if ($canedit) {
            echo "<div class='firstbloc'>";
            echo "<form name='calendarsegment_form$rand' aria-label='Add a Schedule' id='calendarsegment_form$rand' method='post'
                action='";
            echo Toolbox::getItemTypeFormURL(__CLASS__) . "'>";
            echo "<table class='tab_cadre_fixe' aria-label='Add Schedule Table'>";
            echo "<tr class='tab_bg_1'><th colspan='7'>" . __('Add a schedule') . "</tr>";

            echo "<tr class='tab_bg_2'><td class='center'>" . _n('Day', 'Days', 1) . "</td><td>";
            echo "<input type='hidden' name='calendars_id' value='$ID'>";
            Dropdown::showFromArray('day', Toolbox::getDaysOfWeekArray());
            echo "</td><td class='center'>" . __('Start') . '</td><td>';
            Dropdown::showHours("begin", ['value' => date('H') . ":00"]);
            echo "</td><td class='center'>" . __('End') . '</td><td>';
            Dropdown::showHours("end", ['value' => (date('H') + 1) . ":00"]);
            echo "</td><td class='center'>";
            echo "<input type='submit' name='add' value=\"" . _sx('button', 'Add') . "\" class='submit'>";
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        echo "<div class='spaced'>";
        if ($canedit && $numrows) {
            Html::openMassiveActionsForm('mass' . __CLASS__ . $rand);
            $massiveactionparams = ['num_displayed' => min($_SESSION['glpilist_limit'], $numrows),
                              'container'     => 'mass' . __CLASS__ . $rand];
            Html::showMassiveActions($massiveactionparams);
        }
        echo "<table class='tab_cadre_fixehov' aria-label='Schedule Details Table'>";
        echo "<tr>";
        if ($canedit && $numrows) {
            echo "<th width='10'>";
            echo Html::getCheckAllAsCheckbox('mass' . __CLASS__ . $rand);
            echo "</th>";
        }
        echo "<th>" . _n('Day', 'Days', 1) . "</th>";
        echo "<th>" . __('Start') . "</th>";
        echo "<th>" . __('End') . "</th>";
        echo "</tr>";

        $daysofweek = Toolbox::getDaysOfWeekArray();

        if ($numrows) {
            foreach ($rows as $data) {
                echo "<tr class='tab_bg_1'>";

                if ($canedit) {
                    echo "<td>";
                    Html::showMassiveActionCheckBox(__CLASS__, $data["id"]);
                    echo "</td>";
                }

                echo "<td>";
                echo $daysofweek[$data['day']];
                echo "</td>";
                echo "<td>" . $data["begin"] . "</td>";
                echo "<td>" . $data["end"] . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        if ($canedit && $numrows) {
            $massiveactionparams['ontop'] = false;
            Html::showMassiveActions($massiveactionparams);
            Html::closeForm();
        }
        echo "</div>";
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {

        if (!$withtemplate) {
            $nb = 0;
            switch ($item->getType()) {
                case 'Calendar':
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        $nb = countElementsInTable(
                            $this->getTable(),
                            ['calendars_id' => $item->getID()]
                        );
                    }
                    return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
            }
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        if ($item->getType() == 'Calendar') {
            self::showForCalendar($item);
        }
        return true;
    }
}
