<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\ServiceLevelReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceLevelReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:service_levels');
        $this->setAliases(['db:service_levels']);
        $this->setDescription('Migrate optional ticket service-level references to nullable associations');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Alter audited columns and normalize zeros during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new ServiceLevelReferences();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(ServiceLevelReferences::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['counts'] as $reference => $count) {
            $output->writeln(sprintf('%s: %d empty references', $reference, $count));
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('ticket service-level references migrated. Run db:foreign_keys --apply to enforce their constraints.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
