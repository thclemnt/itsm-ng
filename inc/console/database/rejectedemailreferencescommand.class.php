<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\RejectedEmailReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RejectedEmailReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:rejected_email_references');
        $this->setAliases(['db:rejected_email_references']);
        $this->setDescription('Migrate optional rejected email references to nullable associations');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Alter audited columns and normalize zeros during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new RejectedEmailReferences();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(RejectedEmailReferences::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['counts'] as $reference => $count) {
            $output->writeln(sprintf('%s: %d empty references', $reference, $count));
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('rejected email references migrated. Run db:foreign_keys --apply to enforce their constraints.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
