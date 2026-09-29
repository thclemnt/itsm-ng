<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\Migration\OidcReferences;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OidcReferencesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:oidc_references')->setAliases(['db:oidc_references']);
        $this->setDescription('Audit OIDC user states and install uniqueness and native PostgreSQL booleans');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply audited schema changes during maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new OidcReferences();
        $connection = $this->db->getDoctrineConnection();
        $output->writeln(OidcReferences::VERSION);
        foreach ($migration->plan($connection) as $sql) {
            $output->writeln($sql . ';');
        }
        if ($input->getOption('apply')) {
            $migration->apply($connection);
            $output->writeln('OIDC state migrated. Run db:foreign_keys --apply to enforce the user constraint.');
        } else {
            $output->writeln('No changes. Stop application writers and use --apply during maintenance.');
        }
        return 0;
    }
}
