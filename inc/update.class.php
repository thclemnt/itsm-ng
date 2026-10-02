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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 *  Update class
**/
class Update extends CommonGLPI
{
    private $args = [];
    private $DB;
    private $migration;

    /**
     * Constructor
     *
     * @param object $DB   Database instance
     * @param array  $args Command line arguments; default to empty array
     */
    public function __construct($DB, $args = [])
    {
        $this->DB = $DB;
        $this->args = $args;
        $this->declareOldItems();
    }

    /**
     * Initialize session for update
     *
     * @return void
     */
    public function initSession()
    {
        if (is_writable(GLPI_SESSION_DIR)) {
            Session::setPath();
        } else {
            if (isCommandLine()) {
                die("Can't write in " . GLPI_SESSION_DIR . "\n");
            }
        }
        Session::start();

        if (isCommandLine()) {
            // Init debug variable
            $_SESSION = ['glpilanguage' => (isset($this->args['lang']) ? $this->args['lang'] : 'en_GB')];
            $_SESSION["glpi_currenttime"] = date("Y-m-d H:i:s");
        }
    }

    /**
     * Get current values (versions, lang, ...)
     *
     * @return array
     */
    public function getCurrents()
    {
        $currents = [];
        $DB = $this->DB;

        if (!$DB->tableExists('glpi_config') && !$DB->tableExists('glpi_configs')) {
            //very, very old version!
            $currents = [
               'version'   => '0.1',
               'dbversion' => '0.1',
               'language'  => 'en_GB'
            ];
        } elseif (!$DB->tableExists("glpi_configs")) {
            // < 0.78
            // Get current version
            $result = $DB->request([
               'SELECT' => ['version', 'language'],
               'FROM'   => 'glpi_config'
            ])->next();

            $currents['version']    = trim((string) $result['version']);
            $currents['dbversion']  = $currents['version'];
            $currents['language']   = trim((string) $result['language']);
        } elseif ($DB->fieldExists('glpi_configs', 'version')) {
            // < 0.85
            // Get current version and language
            $result = $DB->request([
               'SELECT' => ['version', 'language'],
               'FROM'   => 'glpi_configs'
            ])->next();

            $currents['version']    = trim((string) $result['version']);
            $currents['dbversion']  = $currents['version'];
            $currents['language']   = trim((string) $result['language']);
        } else {
            $currents = Config::getConfigurationValues(
                'core',
                ['version', 'dbversion', 'language', 'itsmversion', 'itsmdbversion']
            );

            if (!isset($currents['dbversion'])) {
                $currents['dbversion'] = $currents['version'];
            }

            // Init ITSM-NG version
            if (!isset($currents['itsmversion'])) {
                $currents['itsmversion'] = "1.0.0";
            }

            if (!isset($currents['itsmdbversion'])) {
                $currents['itsmdbversion'] = $currents['itsmversion'];
            }
        }

        return $currents;
    }


    /**
     * Run updates
     *
     * @param string $current_version Current version
     *
     * @return void
     */
    public function doUpdates($current_version = null)
    {
        // Retain the public facade used by the web updater. Release strings no
        // longer select historical scripts; the canonical ledger owns replay.
        (new \itsmng\Database\Upgrade($this->DB))->apply(
            $this->migration === null ? null : fn (string $message) => $this->migration->displayMessage($message)
        );
    }


    /**
     * Declare old items for compatibility
     *
     * @return void
     */
    public function declareOldItems()
    {
        // Old itemtypes
        define("GENERAL_TYPE", 0);
        define("COMPUTER_TYPE", 1);
        define("NETWORKING_TYPE", 2);
        define("PRINTER_TYPE", 3);
        define("MONITOR_TYPE", 4);
        define("PERIPHERAL_TYPE", 5);
        define("SOFTWARE_TYPE", 6);
        define("CONTACT_TYPE", 7);
        define("ENTERPRISE_TYPE", 8);
        define("INFOCOM_TYPE", 9);
        define("CONTRACT_TYPE", 10);
        define("CARTRIDGEITEM_TYPE", 11);
        define("TYPEDOC_TYPE", 12);
        define("DOCUMENT_TYPE", 13);
        define("KNOWBASE_TYPE", 14);
        define("USER_TYPE", 15);
        define("TRACKING_TYPE", 16);
        define("CONSUMABLEITEM_TYPE", 17);
        define("CONSUMABLE_TYPE", 18);
        define("CARTRIDGE_TYPE", 19);
        define("SOFTWARELICENSE_TYPE", 20);
        define("LINK_TYPE", 21);
        define("STATE_TYPE", 22);
        define("PHONE_TYPE", 23);
        define("DEVICE_TYPE", 24);
        define("REMINDER_TYPE", 25);
        define("STAT_TYPE", 26);
        define("GROUP_TYPE", 27);
        define("ENTITY_TYPE", 28);
        define("RESERVATION_TYPE", 29);
        define("AUTHMAIL_TYPE", 30);
        define("AUTHLDAP_TYPE", 31);
        define("OCSNG_TYPE", 32);
        define("REGISTRY_TYPE", 33);
        define("PROFILE_TYPE", 34);
        define("MAILGATE_TYPE", 35);
        define("RULE_TYPE", 36);
        define("TRANSFER_TYPE", 37);
        define("BOOKMARK_TYPE", 38);
        define("SOFTWAREVERSION_TYPE", 39);
        define("PLUGIN_TYPE", 40);
        define("COMPUTERDISK_TYPE", 41);
        define("NETWORKING_PORT_TYPE", 42);
        define("FOLLOWUP_TYPE", 43);
        define("BUDGET_TYPE", 44);

        // Old devicetypes
        define("MOBOARD_DEVICE", 1);
        define("PROCESSOR_DEVICE", 2);
        define("RAM_DEVICE", 3);
        define("HDD_DEVICE", 4);
        define("NETWORK_DEVICE", 5);
        define("DRIVE_DEVICE", 6);
        define("CONTROL_DEVICE", 7);
        define("GFX_DEVICE", 8);
        define("SND_DEVICE", 9);
        define("PCI_DEVICE", 10);
        define("CASE_DEVICE", 11);
        define("POWER_DEVICE", 12);
    }

    /**
     * Set migration
     *
     * @param Migration $migration Migration instance
     *
     * @return Update
     */
    public function setMigration(Migration $migration)
    {
        $this->migration = $migration;
        return $this;
    }

    /**
     * Check if expected security key file is missing.
     *
     * @return bool
     */
    public function isExpectedSecurityKeyFileMissing(): bool
    {
        return (new \itsmng\Database\Upgrade($this->DB))->isSecurityKeyMissing();
    }

    /**
     * Returns expected security key file path.
     * Supported canonical adoption requires the inherited GLPI 9.5 encryption key.
     *
     * @return string|null
     */
    public function getExpectedSecurityKeyFilePath(): ?string
    {
        return (new \itsmng\Database\Upgrade($this->DB))->expectedSecurityKeyPath();
    }
}
