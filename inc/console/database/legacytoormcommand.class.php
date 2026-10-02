<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use Glpi\Console\Command\ForceNoPluginsOptionCommandInterface;
use itsmng\Database\Migration\LegacyToOrm;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class LegacyToOrmCommand extends AbstractCommand implements ForceNoPluginsOptionCommandInterface
{
    protected $requires_db_up_to_date = false;

    public function getNoPluginsOptionValue()
    {
        return true;
    }

    protected function configure()
    {
        parent::configure();
        $this->setName('itsmng:database:legacy_to_orm');
        $this->setAliases(['db:legacy_to_orm']);
        $this->setDescription('Migrate the legacy database to ORM relationships and 64-bit identifiers');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the master migration during maintenance with application writers stopped');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $migration = new LegacyToOrm();
        $connection = $this->db->getDoctrineConnection();
        $output->writeln(LegacyToOrm::VERSION);
        if ($input->getOption('apply')) {
            $migration->apply($connection, static fn (string $message) => $output->writeln($message));
            $this->db->clearSchemaCache();
            $output->writeln('<info>Legacy-to-ORM migration complete.</info>');
        } else {
            $plan = $migration->plan($connection);
            if ($plan['complete']) {
                $output->writeln('Already complete.');
            } else {
                foreach ($plan['identifiers'] as $operation) {
                    $output->writeln($operation['sql'] . ';', OutputInterface::OUTPUT_RAW);
                }
                foreach ($plan['stages'] as $name => $stage) {
                    $output->writeln(is_string($name) ? $name : $stage);
                    $print = static function ($value, $key = '') use ($output): void {
                        if (is_string($value) && preg_match('/^(?:ALTER|CREATE|DROP|UPDATE)\b/i', $value)) {
                            $output->writeln($value . ';', OutputInterface::OUTPUT_RAW);
                        }
                    };
                    if (is_array($stage)) {
                        array_walk_recursive($stage, $print);
                    }
                }
                $output->writeln('Foreign keys are audited and installed after reference normalization.');
                $output->writeln('No changes. Stop application writers and run with --apply. PostgreSQL is transactional; MySQL DDL resumes from its persisted journal.');
            }
        }
        return 0;
    }
}
