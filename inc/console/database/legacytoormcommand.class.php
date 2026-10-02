<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use Glpi\Console\Command\ForceNoPluginsOptionCommandInterface;
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
        $this->setAliases(['db:legacy_to_orm', 'db:migrate']);
        $this->setDescription('Apply canonical database history, adopting legacy relationships, identifiers and PostgreSQL flags');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the master migration during maintenance with application writers stopped');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $history = new \itsmng\Database\Migration\History();
        $connection = $this->db->getDoctrineConnection();
        $output->writeln('Canonical database history');
        if ($input->getOption('apply')) {
            $history->upgrade($connection, static fn (string $message) => $output->writeln($message));
            $this->db->clearSchemaCache();
            $output->writeln('<info>Canonical database history complete.</info>');
        } else {
            $historyPlan = $history->plan($connection);
            if ($historyPlan['complete']) {
                $output->writeln('Already complete.');
            } else {
                foreach ($historyPlan['pending'] as $version) {
                    $output->writeln('Pending history: ' . $version);
                }
                $output->writeln('Existing baseline and seed data will be validated and preserved; default seed rows are never reinserted during adoption.');
                foreach ($historyPlan as $section => $plan) {
                    if (!is_array($plan)) {
                        continue;
                    }
                    $statements = [];
                    array_walk_recursive($plan, static function ($value) use (&$statements): void {
                        if (is_string($value) && preg_match('/^(?:ALTER|CREATE|DROP|UPDATE|INSERT|DELETE|COMMENT)\b/i', $value)) {
                            $statements[] = $value;
                        }
                    });
                    if ($statements) {
                        $output->writeln('Migration plan: ' . $section);
                        foreach ($statements as $sql) {
                            $output->writeln($sql . ';', OutputInterface::OUTPUT_RAW);
                        }
                    }
                }
                $output->writeln('Foreign keys are audited and installed after reference normalization.');
                $output->writeln('No changes. Stop application writers and run with --apply. PostgreSQL is transactional; MySQL DDL resumes from its persisted journal.');
            }
        }
        return 0;
    }
}
