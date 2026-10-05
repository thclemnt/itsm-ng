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

namespace tests\units\Glpi\System\Requirement;

class DbEngine extends \GLPITestCase
{
    protected function versionProvider()
    {
        return [
           [
              'provider'  => 'mysql',
              'version'   => '5.6.46-log',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 5.6.46.']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '10.4.8-MariaDB-1:10.4.8+maria~bionic',
              'validated' => true,
              'messages'  => ['Database version seems correct (10.4.8) - Perfect!']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '5.5.38-0ubuntu0.14.04.1',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 5.5.38.']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '8.0.15',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 8.0.15.']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '8.0.16',
              'validated' => true,
              'messages'  => ['Database version seems correct (8.0.16) - Perfect!']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '8.4.0',
              'validated' => true,
              'messages'  => ['Database version seems correct (8.4.0) - Perfect!']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '10.2.21-MariaDB',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 10.2.21.']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '10.2.22-MariaDB',
              'validated' => true,
              'messages'  => ['Database version seems correct (10.2.22) - Perfect!']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '5.5.5-10.2.21-MariaDB',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 10.2.21.']
           ],
           [
              'provider'  => 'mysql',
              'version'   => '5.5.5-10.2.22-MariaDB',
              'validated' => true,
              'messages'  => ['Database version seems correct (10.2.22) - Perfect!']
           ],
           [
              'provider'  => 'mysql',
              'version'   => 'unknown',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: unknown.']
           ],
           [
              'provider'  => 'pgsql',
              'version'   => '14.0',
              'validated' => true,
              'messages'  => ['Database version seems correct (14.0) - Perfect!']
           ],
           [
              'provider'  => 'pgsql',
              'version'   => '16.4 (Debian 16.4-1.pgdg120+1)',
              'validated' => true,
              'messages'  => ['Database version seems correct (16.4) - Perfect!']
           ],
           [
              'provider'  => 'pgsql',
              'version'   => '13.16',
              'validated' => false,
              'messages'  => ['Your database engine version seems too old: 13.16.']
           ],
        ];
    }

    /**
     * @dataProvider versionProvider
     */
    public function testCheck(string $provider, string $version, bool $validated, array $messages)
    {

        $this->mockGenerator->orphanize('__construct');
        $db = new \mock\DB();
        $this->calling($db)->getProvider = $provider;
        $this->calling($db)->getVersion = $version;

        $this->newTestedInstance($db);
        $this->boolean($this->testedInstance->isValidated())->isIdenticalTo($validated);
        $this->array($this->testedInstance->getValidationMessages())
           ->isIdenticalTo($messages);
    }
}
