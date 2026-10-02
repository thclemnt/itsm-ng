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

namespace Glpi\Console\Migration;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Glpi\Console\AbstractCommand;
use itsmng\Appliance\AppliancePluginImport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppliancesPluginToCoreCommand extends AbstractCommand
{
    public const ERROR_PLUGIN_VERSION_OR_DATA_INVALID = 1;
    public const ERROR_PLUGIN_IMPORT_FAILED = 2;

    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:migration:appliances_plugin_to_core');
        $this->setDescription(__('Import Appliances plugin data into core, preserving unrelated core records'));
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, __('Validate the complete import without writing data'));
        $this->addOption('skip-errors', 's', InputOption::VALUE_NONE, __('Deprecated: appliance import requires an atomic valid graph'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('skip-errors')) {
            $output->writeln('<error>Appliance import cannot skip errors in an owned graph; correct the diagnostics and retry without --skip-errors.</error>');
            return self::ERROR_PLUGIN_VERSION_OR_DATA_INVALID;
        }
        $importer = new AppliancePluginImport($this->db);
        try {
            $plan = $importer->plan();
        } catch (\Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return self::ERROR_PLUGIN_VERSION_OR_DATA_INVALID;
        }
        $output->writeln('Validated appliance import: ' . json_encode($plan->counts, JSON_THROW_ON_ERROR));
        if ($input->getOption('dry-run')) {
            $output->writeln($plan->alreadyImported ? 'This source has already been imported.' : 'Dry run completed; no data written.');
            return 0;
        }
        if (!$plan->alreadyImported && !$input->getOption('no-interaction')) {
            $run = $this->getHelper('question')->ask($input, $output, new ConfirmationQuestion(
                '<question>Import this validated appliance graph while retaining existing core data? [yes/No]</question>',
                false
            ));
            if (!$run) {
                $output->writeln('Import aborted.');
                return 0;
            }
        }
        try {
            $result = $importer->import(static function (string $event, string $kind, int $id) use ($output): void {
                $output->writeln($event . ': ' . $kind . ' ' . $id, OutputInterface::VERBOSITY_VERBOSE);
            });
        } catch (\Throwable $error) {
            $output->writeln('<error>Appliance import rolled back: ' . $error->getMessage() . '</error>');
            return self::ERROR_PLUGIN_IMPORT_FAILED;
        }
        $output->writeln($result->alreadyImported ? 'This source has already been imported; application changes retained.' : 'Appliance import completed.');
        return 0;
    }
}
