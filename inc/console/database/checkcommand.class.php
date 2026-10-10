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

namespace Glpi\Console\Database;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Glpi\Console\AbstractCommand;
use itsmng\Database\SchemaCheck;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends AbstractCommand
{
    public const ERROR_SCHEMA_DIFFERENCES = 1;

    protected $requires_db_up_to_date = false;

    protected function configure()
    {
        parent::configure();

        $this->setName('itsmng:database:check');
        $this->setAliases(['db:check']);
        $this->setDescription(__('Check the required core schema using Doctrine DBAL.'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $differences = (new SchemaCheck())->differences($this->db->getDoctrineConnection());
        foreach ($differences as $difference) {
            $output->writeln('<error>' . $difference . '</error>', OutputInterface::VERBOSITY_QUIET);
        }
        $output->writeln('<comment>Mapped core schema and declared native policies are compared; additional native definitions are outside this check.</comment>');
        if ($differences) {
            return self::ERROR_SCHEMA_DIFFERENCES;
        }
        $output->writeln('<info>Required core DBAL schema matches.</info>');
        return 0;
    }
}
