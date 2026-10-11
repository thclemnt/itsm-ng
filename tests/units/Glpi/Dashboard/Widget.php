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

namespace tests\units\Glpi\Dashboard;

use DbTestCase;
use Glpi\Cache\SimpleCache;
use Glpi\Dashboard\Widget as WidgetModel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/* Test for inc/dashboard/widget.class.php */

class Widget extends DbTestCase
{
    public function testGetAllTypes()
    {
        $types = WidgetModel::getAllTypes();

        $this->array($types)->isNotEmpty();
        foreach ($types as $specs) {
            $this->array($specs)
               ->hasKeys(['label', 'function', 'image']);
        }
    }


    protected function palettes()
    {
        return [
           [
              'bg_color'  => "#FFFFFF",
              'nb_series' => 4,
              'revert'    => true,
              'expected'  => [
                 'names'  => ['a', 'b', 'c', 'd'],
                 'colors' => [
                    '#a6a6a6',
                    '#808080',
                    '#595959',
                    '#333333',
                 ],
              ]
           ], [
              'bg_color'  => "#FFFFFF",
              'nb_series' => 4,
              'revert'    => false,
              'expected'  => [
                 'names'  => ['a', 'b', 'c', 'd'],
                 'colors' => [
                    '#595959',
                    '#808080',
                    '#a6a6a6',
                    '#cccccc',
                 ],
              ]
           ], [
              'bg_color'  => "#FFFFFF",
              'nb_series' => 1,
              'revert'    => true,
              'expected'  => [
                 'names'  => ['a'],
                 'colors' => [
                    '#999999',
                 ],
              ]
           ],
        ];
    }

    /**
     * @dataProvider palettes
     */
    public function testGetGradientPalette(
        string $bg_color,
        int $nb_series,
        bool $revert,
        array $expected
    ) {
        $this->array(WidgetModel::getGradientPalette($bg_color, $nb_series, $revert))
             ->isEqualTo($expected);

        global $GLPI_CACHE;
        $cache = $GLPI_CACHE;
        try {
            $GLPI_CACHE = new SimpleCache(new Psr16Cache(new ArrayAdapter()), '', false);
            $first = WidgetModel::getCssGradientPalette($bg_color, $nb_series, '#palette-first', $revert);
            $second = WidgetModel::getCssGradientPalette($bg_color, $nb_series, '#palette-second', $revert);
            $this->string($first)->contains('#palette-first .ct-series-a');
            $this->string($second)->contains('#palette-second .ct-series-a')->notContains('#palette-first');
            $this->string(WidgetModel::getCssGradientPalette($bg_color, $nb_series, '#palette-second', $revert))
                ->isIdenticalTo($second);
            $this->string(WidgetModel::getCssGradientPalette($bg_color, $nb_series, '#palette-first', $revert))
                ->isIdenticalTo($first);
        } finally {
            $GLPI_CACHE = $cache;
        }
    }
}
