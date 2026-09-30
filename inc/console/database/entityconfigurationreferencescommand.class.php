<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\EntityConfigurationReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EntityConfigurationReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:entity_configuration_references');
        $this->setAliases(['db:entity_configuration_references']);
        $this->setDescription('Split inherited entity settings into selection policies and nullable associations');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Alter audited columns and normalize legacy selection policies during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new EntityConfigurationReferences();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(EntityConfigurationReferences::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['check_sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['counts'] as $reference => $counts) {
            $output->writeln(sprintf('%s: %d inherited, %d empty, %d unchanged', $reference, $counts['inherit'], $counts['empty'], $counts['unchanged']));
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('entity configuration references migrated. Run db:foreign_keys --apply to enforce their constraints.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
