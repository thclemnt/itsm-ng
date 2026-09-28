<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use itsmng\Database\ForeignKeys;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Audit existing installations before adding referential integrity constraints. */
class ForeignKeysCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:foreign_keys');
        $this->setAliases(['db:foreign_keys']);
        $this->setDescription('Audit and install the audited database foreign keys');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply constraints after the orphan audit succeeds');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = new ForeignKeys();
        $connection = $this->db->getDoctrineConnection();
        $problems = $keys->audit($connection);
        foreach ($problems as $relationship => $count) {
            $output->writeln(sprintf('<error>%s: %d orphaned references</error>', $relationship, $count));
        }
        if ($problems) {
            foreach (array_keys($problems) as $reference) {
                [$table, $column] = explode('.', $reference, 2);
                if (isset(\itsmng\Database\OptionalReferences::RELATIONS[$table][$column])) {
                    $output->writeln('Normalize optional references with db:optional_references (device models), db:project_hierarchy (project ancestry), db:infrastructure_references (infrastructure), and db:asset_classification (asset models/types) before enforcing their foreign keys.');
                    break;
                }
            }
            return 1;
        }
        $plan = $keys->plan($connection);
        foreach ($plan as $sql) {
            $output->writeln($sql . ';');
        }
        if ($input->getOption('apply')) {
            if ($this->db->getProvider() === 'pgsql') {
                $connection->transactional(fn () => $keys->apply($connection));
            } else {
                $keys->apply($connection);
            }
            $output->writeln('<info>Foreign keys installed.</info>');
        } else {
            $output->writeln(count($plan) . ' pending constraints. Use --apply to install them. No data was changed.');
        }
        return 0;
    }
}
