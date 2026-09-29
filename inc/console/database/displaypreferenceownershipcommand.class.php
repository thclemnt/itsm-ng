<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\DisplayPreferenceOwnership;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DisplayPreferenceOwnershipCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:display_preference_ownership');
        $this->setAliases(['db:display_preference_ownership']);
        $this->setDescription('Migrate display preference owners to nullable associations while preserving shared-default uniqueness');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Alter audited columns and normalize zeros during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new DisplayPreferenceOwnership();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(DisplayPreferenceOwnership::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['counts'] as $reference => $count) {
            $output->writeln(sprintf('%s: %d empty references', $reference, $count));
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('display preference ownership migrated. Run db:foreign_keys --apply to enforce their constraints.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
