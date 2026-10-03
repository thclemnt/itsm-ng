<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace Glpi\Console\Database;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use GLPIKey;
use Toolbox;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class InstallCommand extends AbstractConfigureCommand
{
    /**
     * Error code returned when failing to create database.
     *
     * @var integer
     */
    public const ERROR_DB_CREATION_FAILED = 5;

    /**
     * Error code returned when trying to install and having a DB already containing glpi_* tables.
     *
     * @var integer
     */
    public const ERROR_DB_ALREADY_CONTAINS_TABLES = 6;

    /**
     * Error code returned when failing to create database schema.
     *
     * @var integer
     */
    public const ERROR_SCHEMA_CREATION_FAILED = 7;

    /**
     * Error code returned when failing to create encryption key file.
     *
     * @var integer
     */
    public const ERROR_CANNOT_CREATE_ENCRYPTION_KEY_FILE = 8;

    protected function configure()
    {

        parent::configure();

        $this->setName('itsmng:database:install');
        $this->setAliases(['db:install']);
        $this->setDescription('Install database schema');

        $this->addOption(
            'default-language',
            'L',
            InputOption::VALUE_OPTIONAL,
            __('Default language of ITSM-NG'),
            'en_GB'
        );

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            __('Force execution of installation, overriding existing database')
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {

        parent::initialize($input, $output);

        $this->outputWarningOnMissingOptionnalRequirements();
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {

        if (
            $this->isDbAlreadyConfigured()
            && $this->isInputContainingConfigValues($input, $output)
            && !$input->getOption('reconfigure')
        ) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $question_helper */
            $question_helper = $this->getHelper('question');
            $reconfigure = $question_helper->ask(
                $input,
                $output,
                new ConfirmationQuestion(
                    __('Command input contains configuration options that may override existing configuration.')
                      . PHP_EOL
                      . __('Do you want to reconfigure database ?') . ' [Yes/no]',
                    true
                )
            );
            $input->setOption('reconfigure', $reconfigure);
        }

        if (!$this->isDbAlreadyConfigured() || $input->getOption('reconfigure')) {
            parent::interact($input, $output);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        global $DB;

        $default_language = $input->getOption('default-language');
        $force            = $input->getOption('force');
        $database         = null;

        if (
            $this->isDbAlreadyConfigured()
            && $this->isInputContainingConfigValues($input, $output)
            && !$input->getOption('reconfigure')
        ) {
            // Prevent overriding of existing DB when input contains configuration values and
            // --reconfigure option is not used.
            $output->writeln(
                '<error>' . __('Database configuration already exists. Use --reconfigure option to override existing configuration.') . '</error>'
            );
            return self::ERROR_DB_CONFIG_ALREADY_SET;
        }

        if (!$this->isDbAlreadyConfigured() || $input->getOption('reconfigure')) {
            $result = $this->configureDatabase($input, $output);

            if (self::ABORTED_BY_USER === $result) {
                return 0; // Considered as success
            } elseif (self::SUCCESS !== $result) {
                return $result; // Fail with error code
            }

            $db_host     = $input->getOption('db-host');
            $db_port     = $input->getOption('db-port');
            $db_hostport = $db_host . (!empty($db_port) ? ':' . $db_port : '');
            $db_name     = $input->getOption('db-name');
            $db_user     = $input->getOption('db-user');
            $db_pass     = $input->getOption('db-password');
        } else {
            // The console owns the configured write connection, including its
            // provider-specific transport and schema. Do not reconstruct it.
            if (!$DB instanceof \DBAdapter || !$DB->connected || $DB->isSlave()) {
                $output->writeln('<error>Installation requires a connected configured write adapter.</error>');
                return self::ERROR_DB_CONNECTION_FAILED;
            }

            // Ask to confirm installation based on existing configuration.
            // $DB->dbhost can be array when using round robin feature
            $db_hostport = is_array($DB->dbhost) ? $DB->dbhost[0] : $DB->dbhost;

            $db_name = $DB->dbdefault;
            $db_user = $DB->dbuser;

            $run = $this->askForDbConfigConfirmation(
                $input,
                $output,
                $db_hostport,
                $db_name,
                $db_user
            );
            if (!$run) {
                $output->writeln(
                    '<comment>' . __('Installation aborted.') . '</comment>',
                    OutputInterface::VERBOSITY_VERBOSE
                );
                return 0;
            }
            $database = $DB;
        }

        $provider = $database === null ? $input->getOption('db-type') : $database->getProvider();
        if ($provider === 'pgsql') {
            $database ??= \DBConnection::createConnection('pgsql', $db_hostport, $db_user, $db_pass, $db_name);
            if (!$database->connected) {
                $output->writeln('<error>' . $database->error() . '</error>');
                return self::ERROR_DB_CONNECTION_FAILED;
            }
            if (count($database->listTables()) > 0 && !\itsmng\Database\Migration\History::isInstalling($database->getDoctrineConnection())) {
                $output->writeln('<error>PostgreSQL installation requires an empty schema. Use a new database.</error>');
                return self::ERROR_DB_ALREADY_CONTAINS_TABLES;
            }
            $glpikey = new GLPIKey();
            if (!$glpikey->keyExists() && !$glpikey->generate(false)) {
                return self::ERROR_CANNOT_CREATE_ENCRYPTION_KEY_FILE;
            }
            \itsmng\Database\Installer::installPostgres($database, $default_language);
            $output->writeln('<info>' . __('Installation done.') . '</info>');
            return 0;
        }

        $db_instance = $database;
        if ($db_instance === null) {
            $server = \itsmng\Database\InstallationConnection::mysqlServer($db_hostport, $db_user, $db_pass);
            try {
                $server->getServerVersion();
            } catch (\Doctrine\DBAL\Exception $error) {
                $output->writeln('<error>' . $error->getMessage() . '</error>', OutputInterface::VERBOSITY_QUIET);
                $server->close();
                return self::ERROR_DB_CONNECTION_FAILED;
            }

            $output->writeln(
                '<comment>' . __('Creating the database...') . '</comment>',
                OutputInterface::VERBOSITY_VERBOSE
            );
            try {
                \itsmng\Database\InstallationConnection::ensureMysqlDatabase($server, $db_name);
            } catch (\Doctrine\DBAL\Exception $error) {
                $output->writeln('<error>' . $error->getMessage() . '</error>', OutputInterface::VERBOSITY_QUIET);
                return self::ERROR_DB_CREATION_FAILED;
            } finally {
                $server->close();
            }

            // A provider change cannot reuse the previously loaded DB subclass.
            $db_instance = \DBConnection::createConnection('mysql', $db_hostport, $db_user, $db_pass, $db_name);
        }
        if (!$db_instance->connected) {
            $output->writeln('<error>' . $db_instance->error() . '</error>', OutputInterface::VERBOSITY_QUIET);
            return self::ERROR_DB_CONNECTION_FAILED;
        }
        if (\itsmng\Database\InstallationConnection::hasApplicationTables($db_instance->getDoctrineConnection()) && !$force && !\itsmng\Database\Migration\History::isInstalling($db_instance->getDoctrineConnection())) {
            $output->writeln(
                '<error>' . __('Database already contains "glpi_*" tables. Use --force option to override existing database.') . '</error>'
            );
            return self::ERROR_DB_ALREADY_CONTAINS_TABLES;
        }

        // Schema creation supplies all values; there are no stored passwords to migrate.
        $glpikey = new GLPIKey();
        if (!$glpikey->keyExists() && !$glpikey->generate(false)) {
            $message = __('Security key cannot be generated!');
            $output->writeln('<error>' . $message . '</error>', OutputInterface::VERBOSITY_QUIET);
            return self::ERROR_CANNOT_CREATE_ENCRYPTION_KEY_FILE;
        }

        $output->writeln(
            '<comment>' . __('Loading default schema...') . '</comment>',
            OutputInterface::VERBOSITY_VERBOSE
        );
        // TODO Get rid of output buffering
        ob_start();
        Toolbox::createSchema($default_language, $db_instance, $force);
        $message = ob_get_clean();
        if (!empty($message)) {
            $output->writeln('<error>' . $message . '</error>', OutputInterface::VERBOSITY_QUIET);
            return self::ERROR_SCHEMA_CREATION_FAILED;
        }

        $output->writeln('<info>' . __('Installation done.') . '</info>');

        return 0; // Success
    }

    /**
     * Check if DB config should be set by current command run.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return boolean
     */
    private function shouldSetDBConfig(InputInterface $input, OutputInterface $output)
    {

        return $input->getOption('reconfigure') || !file_exists(GLPI_CONFIG_DIR . '/config_db.php');
    }

    /**
     * Check if input contains DB config options.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return boolean
     */
    private function isInputContainingConfigValues(InputInterface $input, OutputInterface $output)
    {

        $config_options = [
           'db-type',
           'db-host',
           'db-port',
           'db-name',
           'db-user',
           'db-password',
        ];
        foreach ($config_options as $option) {
            $default_value = $this->getDefinition()->getOption($option)->getDefault();
            $input_value   = $input->getOption($option);

            if ($default_value !== $input_value) {
                return true;
            }
        }

        return false;
    }
}
