<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\ServiceLevelCalendars;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceLevelCalendarsCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:service_level_calendars');
        $this->setAliases(['db:service_level_calendars']);
        $this->setDescription('Normalize service-level calendar policy and remove redundant SLA/OLA calendar columns');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the audited schema and policy migration during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new ServiceLevelCalendars();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(ServiceLevelCalendars::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        $output->writeln(sprintf('Convert %d ticket-calendar and %d always-open policies; SLA/OLA continue inheriting their parent SLM.', $plan['ticket_calendars'], $plan['always_open']));
        foreach ($plan['check_sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('Calendar policies migrated. Run db:foreign_keys --apply to enforce the calendar association.');
        } else {
            $output->writeln('No changes. Stop writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
