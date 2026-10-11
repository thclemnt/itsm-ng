<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class LegacyToOrmCommand extends AbstractUpgradeCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:legacy_to_orm');
        $this->setAliases(['db:legacy_to_orm', 'db:migrate']);
        $this->setDescription('Apply canonical database history, adopting legacy relationships, identifiers and PostgreSQL flags');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the master migration during maintenance with application writers stopped');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->runUpgrade($input, $output, (bool)$input->getOption('apply'));
    }
}
