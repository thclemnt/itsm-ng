<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\ContentMetadataReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ContentMetadataReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:content_metadata');
        $this->setAliases(['db:content_metadata']);
        $this->setDescription('Migrate optional Content metadata references to nullable associations');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Alter audited columns and normalize zeros during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new ContentMetadataReferences();
        $connection = $this->db->getDoctrineConnection();
        $plan = $migration->plan($connection);
        $output->writeln(ContentMetadataReferences::VERSION);
        foreach ($plan['sql'] as $sql) {
            $output->writeln($sql . ';');
        }
        foreach ($plan['counts'] as $reference => $count) {
            $output->writeln(sprintf('%s: %d empty references', $reference, $count));
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('Content metadata references migrated. Run db:foreign_keys --apply to enforce their constraints.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance. MySQL DDL commits separately; retry is idempotent.');
        }
        return 0;
    }
}
