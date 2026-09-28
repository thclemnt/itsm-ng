<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\NormalizeOptionalReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OptionalReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:optional_references');
        $this->setAliases(['db:optional_references']);
        $this->setDescription('Migrate audited optional model references from zero to NULL');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Normalize empty selections after validating all references');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new NormalizeOptionalReferences();
        $connection = $this->db->getDoctrineConnection();
        $counts = $input->getOption('apply') ? $migration->apply($connection) : $migration->plan($connection);
        $output->writeln(NormalizeOptionalReferences::VERSION);
        foreach ($counts as $reference => $count) {
            $output->writeln(sprintf('%s: %d empty selections', $reference, $count));
        }
        $output->writeln($input->getOption('apply') ? 'Optional references normalized. Run db:foreign_keys --apply to enforce them.' : 'No data changed. Use --apply during maintenance to replace zero with NULL.');
        return 0;
    }
}
