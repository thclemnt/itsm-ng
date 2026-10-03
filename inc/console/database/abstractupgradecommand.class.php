<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace Glpi\Console\Database;

use Glpi\Console\AbstractCommand;
use Glpi\Console\Command\ForceNoPluginsOptionCommandInterface;
use itsmng\Database\Upgrade;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/** CLI policy and presentation for the shared canonical upgrade coordinator. */
abstract class AbstractUpgradeCommand extends AbstractCommand implements ForceNoPluginsOptionCommandInterface
{
    public const ERROR_NO_UNSTABLE_UPDATE = 1;
    public const ERROR_MISSING_SECURITY_KEY_FILE = 2;

    protected $requires_db_up_to_date = false;

    public function getNoPluginsOptionValue()
    {
        return true;
    }

    protected function configure()
    {
        parent::configure();
        $this->addOption('allow-unstable', 'u', InputOption::VALUE_NONE, __('Allow update to an unstable version'));
    }

    protected function runUpgrade(InputInterface $input, OutputInterface $output, bool $apply, bool $confirm = false): int
    {
        $upgrade = new Upgrade($this->db);
        $output->writeln('Canonical database history');
        if (!$apply) {
            $plan = $upgrade->plan();
            foreach ($plan['pending'] as $version) {
                $output->writeln('Pending history: ' . $version);
            }
            $output->writeln('Existing data is preserved; installation seeds and historical MySQL scripts are never replayed during adoption.');
            if (isset($plan['canonical_preflight'])) {
                $output->writeln($plan['canonical_preflight'], OutputInterface::OUTPUT_RAW);
            }
            foreach ($plan as $section => $details) {
                if (!is_array($details)) {
                    continue;
                }
                if (($details['kind'] ?? null) === 'data_prerequisite') {
                    $receipt = $details['receipt'];
                    $output->writeln('Elective data prerequisite: ' . $details['version']);
                    $output->writeln($details['description'], OutputInterface::OUTPUT_RAW);
                    $output->writeln('Frozen source format: ' . $receipt['format'] . '; fingerprint: ' . $receipt['fingerprint']);
                    $output->writeln('Validated source counts: ' . json_encode($receipt['counts'], JSON_THROW_ON_ERROR));
                    $output->writeln('Planned inserts: ' . count($details['records']) . '; identity remaps: ' . count($details['updates'])
                        . '; deferred document rows: ' . count($receipt['deferred_documents']) . '.');
                    $output->writeln('Keep the historical source plugin inactive and all source/application writers stopped through validation, ledger bootstrap and canonical replay.');
                    // Source values are data, including text beginning with SQL
                    // keywords. Never collect them as executable migration SQL.
                    continue;
                }
                $statements = [];
                array_walk_recursive($details, static function ($value) use (&$statements): void {
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
            if ($plan['security_key_missing']) {
                $output->writeln('<error>Restore the original encryption key before applying: ' . $plan['security_key'] . '</error>');
            }
            $output->writeln($plan['complete'] ? 'Canonical history is complete; applying also publishes the current release metadata.' : 'Stop application writers before applying. PostgreSQL is transactional; MySQL DDL resumes from its persisted journal.');
            $output->writeln('No changes. Use db:migrate --apply or db:update to apply.');
            return 0;
        }
        if (defined('ITSM_PREVER') && !$input->getOption('allow-unstable')) {
            $output->writeln('<error>Use --allow-unstable to apply history from this development release.</error>', OutputInterface::VERBOSITY_QUIET);
            return self::ERROR_NO_UNSTABLE_UPDATE;
        }
        if ($upgrade->isSecurityKeyMissing()) {
            $output->writeln('<error>The original encryption key is missing or unreadable: ' . $upgrade->expectedSecurityKeyPath() . '. Restore it before upgrading; a new key cannot decrypt existing data.</error>', OutputInterface::VERBOSITY_QUIET);
            return self::ERROR_MISSING_SECURITY_KEY_FILE;
        }
        if ($confirm && !$input->getOption('no-interaction')) {
            if (!$this->getHelper('question')->ask($input, $output, new ConfirmationQuestion(__('Do you want to continue ?') . ' [Yes/no]', true))) {
                $output->writeln(__('Update aborted.'));
                return 0;
            }
        }
        $upgrade->apply(static fn (string $message) => $output->writeln($message));
        $output->writeln('<info>Canonical database history complete; release metadata updated.</info>');
        return 0;
    }
}
