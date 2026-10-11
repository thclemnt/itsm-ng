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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends AbstractUpgradeCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:update');
        $this->setAliases(['db:update']);
        $this->setDescription(__('Apply canonical database history and update release metadata'));
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview canonical history without changing the database or release metadata');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Retry canonical history idempotently; historical scripts are never replayed');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->runUpgrade($input, $output, !$input->getOption('dry-run'), true);
    }
}
