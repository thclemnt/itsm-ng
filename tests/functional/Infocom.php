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

/* Test for inc/infocom.class.php */

class Infocom extends \GLPITestCase
{
    public function testUnusableDegressiveInputsReturnNoScheduleBeforeDateParsing(): void
    {
        foreach ([
            [1, 1000, 5, 2, null],
            [1, 1000, 5, 2, ''],
            [1, 1000, 5, 2, '0'],
            [1, 0, 5, 2, '2000-01-01'],
            [1, -1, 5, 2, '2000-01-01'],
            [1, 1000, 0, 2, '2000-01-01'],
            [1, 1000, -1, 2, '2000-01-01'],
            [1, 1000, 5, 1, '2000-01-01'],
            [1, 1000, 5, null, '2000-01-01'],
            [0, 1000, 5, 2, null],
            [3, 1000, 5, 2, null],
        ] as [$type, $value, $duration, $coefficient, $purchase]) {
            foreach (['n', 'all'] as $view) {
                $this->string(\Infocom::Amort($type, $value, $duration, $coefficient, $purchase, null, null, $view))
                    ->isIdenticalTo('-');
            }
        }
    }

    public function testValidDegressiveScheduleIsUnchanged(): void
    {
        $schedule = \Infocom::Amort(1, 1000.0, 5, 2, '2000-01-01', null, '2000-12-31', 'all');
        $this->array($schedule)->isIdenticalTo([
            'annee' => [1 => 2000, 2 => 2001, 3 => 2002, 4 => 2003, 5 => 2004],
            'vcnetdeb' => [1 => 1000.0, 2 => 600.0, 3 => 360.0, 4 => 216.0, 5 => 108.0],
            'annuite' => [1 => 400.0, 2 => 240.0, 3 => 144.0, 4 => 108.0, 5 => 108.0],
            'vcnetfin' => [1 => 600.0, 2 => 360.0, 3 => 216.0, 4 => 108.0, 5 => 0.0],
        ]);
    }

    public function dataLinearAmortise()
    {
        return [
           [
              100000,        //value
              5,             //duration
              '2017-12-31',  //end exercise date
              '2009-12-25',  //buy date
              '2010-03-04',  //use date
              [  //expected
                 2010 => [
                    'start_value' => 100000.0,
                    'value' => 83500.0,
                    'annuity' => 16500.0
                 ],
                 2011 => [
                    'start_value' => 83500.0,
                    'value' => 63500.0,
                    'annuity' => 20000.0
                 ],
                 2012 => [
                    'start_value' => 63500.0,
                    'value' => 43500.0,
                    'annuity' => 20000.0
                 ],
                 2013 => [
                    'start_value' => 43500.0,
                    'value' => 23500.0,
                    'annuity' => 20000.0
                 ],
                 2014 => [
                    'start_value' => 23500.0,
                    'value' => 3500.0,
                    'annuity' => 20000.0
                 ],
                 2015 => [
                    'start_value' => 3500.0,
                    'value' => 0.0,
                    'annuity' => 3500.0
                 ],
                 date('Y') => [
                    'start_value' => 0.0,
                    'value' => 0,
                    'annuity' => 0
                 ]
              ], [  //old format
                 //empty for this one.
              ]
           ],

           [
              10000,         //value
              4,             //duration
              '2017-05-01',  //end exercise date
              '2009-07-22',  //buy date
              '2010-08-02',  //use date
              [  //expected
                 2010 => [
                    'start_value' => 10000.0,
                    'value' => 8125.0,
                    'annuity' => 1875.0
                 ],
                 2011 => [
                    'start_value' => 8125.0,
                    'value' => 5625.0,
                    'annuity' => 2500.0
                 ],
                 2012 => [
                    'start_value' => 5625.0,
                    'value' => 3125.0,
                    'annuity' => 2500.0
                 ],
                 2013 => [
                    'start_value' => 3125.0,
                    'value' => 625.0,
                    'annuity' => 2500.0
                 ],
                 2014 => [
                    'start_value' => 625.0,
                    'value' => 0.0,
                    'annuity' => 625.0
                 ],
                 2015 => [
                    'start_value' => 0.0,
                    'value' => 0,
                    'annuity' => 0
                 ],
                 date('Y') => [
                    'start_value' => 0.0,
                    'value' => 0,
                    'annuity' => 0
                 ]
              ], [  //old format
                 //empty for this one.
              ]
           ],
           [
              10000,                        //value
              4,                            //duration
              '2017-05-01',                 //end exercise date
              (date('Y') - 2) . '-07-22',   //buy date
              (date('Y') - 2) . '-08-02',   //use date
              [  //expected
                 (date('Y') - 2) => [
                    'start_value' => 10000.0,
                    'value' => 8125.0,
                    'annuity' => 1875.0
                 ],
                 (date('Y') - 1) => [
                    'start_value' => 8125.0,
                    'value' => 5625.0,
                    'annuity' => 2500.0
                 ],
                 date('Y') => [
                    'start_value' => 5625.0,
                    'value' => 3125.0,
                    'annuity' => 2500.0
                 ]
              ], [  //old format
                 'annee'     => [
                    (int)(date('Y') - 2),
                    (int)(date('Y') - 1),
                    (int)date('Y')
                 ],
                 'annuite'   => [
                    1875.0,
                    2500.0,
                    2500.0
                 ],
                 'vcnetdeb'  => [
                    10000.0,
                    8125.0,
                    5625.0
                 ],
                 'vcnetfin'  => [
                    8125.0,
                    5625.0,
                    3125.0
                 ]
              ]
           ]

        ];
    }


    /**
     * @dataProvider dataLinearAmortise
     */
    public function testLinearAmortise($value, $duration, $fiscaldate, $buydate, $usedate, $expected, $oldmft)
    {
        $amortise = \Infocom::linearAmortise(
            $value,
            $duration,
            $fiscaldate,
            $buydate,
            $usedate
        );
        $this->array(\Infocom::Amort(2, $value, $duration, null, $buydate, $usedate, $fiscaldate, 'all'))
            ->isIdenticalTo(\Infocom::mapOldAmortiseFormat($amortise, false));
        foreach ($expected as $year => $values) {
            $this->array($amortise[$year])->isIdenticalTo($values);
        }
        if (count($oldmft)) {
            $this->array(\Infocom::mapOldAmortiseFormat($amortise, false))->isIdenticalTo($oldmft);
        }
    }
}
