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

use Glpi\Application\ErrorHandler;

/**
 *  Database class for Mysql
**/
class DBmysql extends DBAdapter
{
    /** Selected endpoint, also used for independent system-catalog reads. */
    private ?string $connectedHost = null;

    /**
     * List of keys that are allowed to use signed integers.
     *
     * Elements contained in this list have to be fixed before being able to globally use foreign key contraints.
     *
     * @var array
     */
    private const ALLOWED_SIGNED_KEYS = [
       // FIXME Entity preference `glpi_entities.calendars_id` inherit/never strategy should be stored in another field.
       'glpi_calendars.id',
       // FIXME Entity preference `glpi_entities.changetemplates_id` inherit/never strategy should be stored in another field.
       'glpi_changetemplates.id',
       // FIXME Entity preference `glpi_entities.contracts_id_default` inherit/never strategy should be stored in another field.
       'glpi_contracts.id',
       // FIXME root entity uses "-1" value for its parent (`glpi_entities.entities_id`), should be null
       // FIXME some entities_id foreign keys are using "-1" as default value, should be null
       // FIXME Entity preference `glpi_entities.entities_id_software` inherit/never strategy should be stored in another field.
       'glpi_entities.id',
       // FIXME Entity preference `glpi_entities.problemtemplates_id` inherit/never strategy should be stored in another field.
       'glpi_problemtemplates.id',
       // FIXME Entity preference `glpi_entities.tickettemplates_id` inherit/never strategy should be stored in another field.
       'glpi_tickettemplates.id',
       // FIXME Entity preference `glpi_entities.transfers_id` inherit/never strategy should be stored in another field.
       'glpi_transfers.id',
    ];

    private string $lastError = '';
    private int $lastErrno = 0;
    private int $affected = 0;

    public function installSchema(): bool
    {
        try {
            \itsmng\Database\Installer::installMysqlSchema($this->getDoctrineConnection());
            return true;
        } finally {
            $this->clearSchemaCache();
        }
    }

    public function getProvider(): string
    {
        return 'mysql';
    }

    /**
     * Connect using current database settings
     * Use dbhost, dbuser, dbpassword and dbdefault
     *
     * @param integer $choice host number (default NULL)
     *
     * @return void
     */
    public function connect($choice = null)
    {
        $this->close();
        $this->connectedHost = (string)(is_array($this->dbhost)
            ? $this->dbhost[$choice ?? array_rand($this->dbhost)] : $this->dbhost);
        $this->lastError = '';
        $this->lastErrno = 0;
        $this->error = 0;
        try {
            $this->doctrine = \Doctrine\DBAL\DriverManager::getConnection($this->connectionParameters());
            $this->doctrine->getServerVersion();
            if (!isset($this->dbenc) || $this->dbenc === 'utf8') {
                $this->doctrine->executeStatement("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'");
            }
            if (GLPI_FORCE_EMPTY_SQL_MODE) {
                $this->doctrine->executeStatement("SET SESSION sql_mode = ''");
            }
            $this->connected = true;
            $this->setTimezone($this->guessTimezone());
            return true;
        } catch (\Doctrine\DBAL\Exception $error) {
            $this->lastError = 'Unable to connect to MySQL. Check host, database, credentials and TLS settings.';
            $this->lastErrno = (int)$error->getCode();
            $this->error = 1;
            $this->close();
            return false;
        }
    }


    /**
     * Escapes special characters in a string for use in an SQL statement,
     * taking into account the current charset of the connection
     *
     * @since 0.84
     *
     * @param string $string String to escape
     *
     * @return string escaped string
     */
    public function escape($string)
    {
        return substr($this->getDoctrineConnection()->quote((string)$string), 1, -1);
    }

    /**
     * Execute a MySQL query
     *
     * @param string $query Query to execute
     *
     * @var array   $CFG_GLPI
     * @var array   $DEBUG_SQL
     * @var integer $SQL_TOTAL_REQUEST
     *
     * @return \itsmng\Database\LegacyResult|boolean Query result handler
     *
     * @throws GlpitestSQLError
     */
    public function query($query)
    {
        return $this->queryParams($query, []);
    }

    /** Compatibility execution boundary; application repositories bind through ORM. */
    public function queryParams(string $query, array $values)
    {
        return $this->executeResult($query, fn () => $this->getDoctrineConnection()->executeQuery($query, $values));
    }

    public function executePrepared(\Doctrine\DBAL\Statement $statement, string $query)
    {
        return $this->executeResult($query, $statement->executeQuery(...));
    }

    private function executeResult(string $query, callable $execute)
    {
        global $CFG_GLPI, $DEBUG_SQL, $GLPI, $SQL_TOTAL_REQUEST;
        $debug = !empty($CFG_GLPI['debug_sql']) && ($_SESSION['glpi_use_mode'] ?? null) === Session::DEBUG_MODE;
        $start = microtime(true);
        $this->lastError = '';
        $this->lastErrno = 0;
        try {
            $result = $execute();
            $this->affected = (int)$result->rowCount();
            if ($result->columnCount() === 0) {
                $result->free();
                return true;
            }
            return new \itsmng\Database\LegacyResult($result);
        } catch (\Doctrine\DBAL\Exception $error) {
            $this->lastError = $error->getMessage();
            $this->lastErrno = (int)$error->getCode();
            $this->affected = -1;
            Toolbox::logSqlError("MySQL query error: {$this->lastError}\nSQL: $query");
            $handler = $GLPI?->getErrorHandler();
            if ($handler instanceof ErrorHandler) {
                $handler->handleSqlError($this->lastErrno, $this->lastError, $query);
            }
            return false;
        } finally {
            $elapsed = microtime(true) - $start;
            if ($this->execution_time === true) {
                $this->execution_time = $elapsed;
            }
            if ($debug) {
                $SQL_TOTAL_REQUEST++;
                $DEBUG_SQL['queries'][$SQL_TOTAL_REQUEST] = $query;
                $DEBUG_SQL['times'][$SQL_TOTAL_REQUEST] = $elapsed;
                $DEBUG_SQL['rows'][$SQL_TOTAL_REQUEST] = $this->affected;
                if ($this->lastError !== '') {
                    $DEBUG_SQL['errors'][$SQL_TOTAL_REQUEST] = $this->lastError;
                }
            }
        }
    }

    public function prepare($query)
    {
        return new \itsmng\Database\LegacyStatement($this, $query);
    }


    /**
     * Number of rows
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return integer number of rows
     */
    public function numrows($result)
    {
        return $result->num_rows;
    }


    /**
     * Fetch array of the next row of a Mysql query
     * Please prefer fetchRow or fetchAssoc
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return string[]|null array results
     */
    public function fetchArray($result)
    {
        return $result->fetch_array();
    }


    /**
     * Fetch row of the next row of a Mysql query
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return mixed|null result row
     */
    public function fetchRow($result)
    {
        return $result->fetch_row();
    }


    /**
     * Fetch assoc of the next row of a Mysql query
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return string[]|null result associative array
     */
    public function fetchAssoc($result)
    {
        return $result->fetch_assoc();
    }


    /**
     * Fetch object of the next row of an SQL query
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return object|null
     */
    public function fetchObject($result)
    {
        return $result->fetch_object();
    }


    /**
     * Move current pointer of a Mysql result to the specific row
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     * @param integer       $num    Row to move current pointer
     *
     * @return boolean
     */
    public function dataSeek($result, $num)
    {
        return $result->data_seek($num);
    }


    /**
     * Give ID of the last inserted item by Mysql
     *
     * @return mixed
     */
    public function insertId()
    {
        try {
            return (int)$this->getDoctrineConnection()->lastInsertId();
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            if ($error->getPrevious() instanceof \Doctrine\DBAL\Driver\Exception\NoIdentityValue) {
                return 0;
            }
            throw $error;
        }
    }


    /**
     * Give number of fields of a Mysql result
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return int number of fields
     */
    public function numFields($result)
    {
        return $result->field_count;
    }


    /**
     * Give name of a field of a Mysql result
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     * @param integer       $nb     ID of the field
     *
     * @return string name of the field
     *
     * @deprecated 9.5.0
     */
    public function fieldName($result, $nb)
    {
        return $result->fieldName($nb);
    }


    /**
     * List tables in database
     *
     * @param string $table Table name condition (glpi_% as default to retrieve only glpi tables)
     * @param array  $where Where clause to append
     *
     * @return DBmysqlIterator
     */
    public function listTables($table = 'glpi\_%', array $where = [])
    {
        $iterator = $this->request([
           'SELECT' => 'table_name as TABLE_NAME',
           'FROM'   => 'information_schema.tables',
           'WHERE'  => [
              'table_schema' => $this->dbdefault,
              'table_type'   => 'BASE TABLE',
              'table_name'   => ['LIKE', $table]
           ] + $where
        ]);
        return $iterator;
    }

    /**
     * Returns tables using "MyIsam" engine.
     *
     * @return DBmysqlIterator
     */
    public function getMyIsamTables(): DBmysqlIterator
    {
        $iterator = $this->listTables('glpi\_%', ['engine' => 'MyIsam']);
        return $iterator;
    }


    /**
     * List fields of a table
     *
     * @param string  $table    Table name condition
     * @param boolean $usecache If use field list cache (default true)
     *
     * @return mixed list of fields
     */
    public function listFields($table, $usecache = true)
    {

        if (!$this->cache_disabled && $usecache && isset($this->field_cache[$table])) {
            return $this->field_cache[$table];
        }
        $result = $this->query("SHOW COLUMNS FROM `$table`");
        if ($result) {
            if ($this->numrows($result) > 0) {
                $this->field_cache[$table] = [];
                while ($data = $this->fetchAssoc($result)) {
                    $this->field_cache[$table][$data["Field"]] = $data;
                }
                return $this->field_cache[$table];
            }
            return [];
        }
        return false;
    }


    /**
     * Get number of affected rows in previous MySQL operation
     *
     * @return int number of affected rows on success, and -1 if the last query failed.
     */
    public function affectedRows()
    {
        return $this->affected;
    }


    /**
     * Free result memory
     *
     * @param \itsmng\Database\LegacyResult $result MySQL result handler
     *
     * @return boolean
     */
    public function freeResult($result)
    {
        return $result->free();
    }

    /**
     * Returns the numerical value of the error message from previous MySQL operation
     *
     * @return int error number from the last MySQL function, or 0 (zero) if no error occurred.
     */
    public function errno()
    {
        return $this->lastErrno;
    }

    /**
     * Returns the text of the error message from previous MySQL operation
     *
     * @return string error text from the last MySQL function, or '' (empty string) if no error occurred.
     */
    public function error()
    {
        return $this->lastError;
    }

    /**
     * Close MySQL connection
     *
     * @return boolean TRUE on success or FALSE on failure.
     */
    public function close()
    {
        $wasConnected = $this->connected;
        $this->doctrine?->close();
        $this->doctrine = null;
        $this->connected = false;
        return $wasConnected;
    }


    /**
     * Get information about DB connection for showSystemInformation
     *
     * @since 0.84
     *
     * @return string[] Array of label / value
     */
    public function getInfo()
    {
        // No translation, used in sysinfo
        $ret = [];
        $req = $this->request("SELECT @@sql_mode as mode, @@version AS vers, @@version_comment AS stype");

        if (($data = $req->next())) {
            if ($data['stype']) {
                $ret['Server Software'] = $data['stype'];
            }
            if ($data['vers']) {
                $ret['Server Version'] = $data['vers'];
            } else {
                $ret['Server Version'] = $this->getDoctrineConnection()->getServerVersion();
            }
            if ($data['mode']) {
                $ret['Server SQL Mode'] = $data['mode'];
            } else {
                $ret['Server SQL Mode'] = '';
            }
        }
        $ret['Parameters'] = $this->dbuser . "@" . $this->connectedHost . "/" . $this->dbdefault;
        $ret['Host info']  = $this->connectedHost;

        return $ret;
    }

    /**
     * Is MySQL strict mode ?
     *
     * @var DB $DB
     *
     * @param string $msg Mode
     *
     * @return boolean
     *
     * @since 0.90
     * @deprecated 9.5.0
     */
    public static function isMySQLStrictMode(&$msg)
    {
        Toolbox::deprecated();
        global $DB;

        $msg = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,NO_AUTO_CREATE_USER';
        $req = $DB->request("SELECT @@sql_mode as mode");
        if (($data = $req->next())) {
            return (preg_match("/STRICT_TRANS/", (string) $data['mode'])
                    && preg_match("/NO_ZERO_/", (string) $data['mode'])
                    && preg_match("/ONLY_FULL_GROUP_BY/", (string) $data['mode']));
        }
        return false;
    }

    /**
     * Get a global DB lock
     *
     * @since 0.84
     *
     * @param string $name lock's name
     *
     * @return boolean
     */
    public function getLock($name)
    {
        $name          = addslashes($this->dbdefault . '.' . $name);
        $query         = "SELECT GET_LOCK('$name', 0)";
        $result        = $this->query($query);
        list($lock_ok) = $this->fetchRow($result);

        return (bool)$lock_ok;
    }

    /**
     * Release a global DB lock
     *
     * @since 0.84
     *
     * @param string $name lock's name
     *
     * @return boolean
     */
    public function releaseLock($name)
    {
        $name          = addslashes($this->dbdefault . '.' . $name);
        $query         = "SELECT RELEASE_LOCK('$name')";
        $result        = $this->query($query);
        list($lock_ok) = $this->fetchRow($result);

        return $lock_ok;
    }


    public function constraintExists($table, $constraint)
    {
        if (!$this->tableExists($table)) {
            trigger_error("Table $table does not exists", E_USER_WARNING);
            return false;
        }
        $result = $this->query("SHOW CREATE TABLE `$table`");
        if ($result) {
            if ($this->numrows($result) > 0) {
                $data = $this->fetchArray($result);
                if (preg_match("/CONSTRAINT `$constraint` FOREIGN KEY/", $data[1])) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * Builds a delete statement
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $params Query parameters ([field name => field value)
     * @param array  $where  WHERE clause (@see DBmysqlIterator capabilities)
     * @param array  $joins  JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return string
     */
    public function buildDelete($table, $where, array $joins = [])
    {

        if (!count($where)) {
            throw new \RuntimeException('Cannot run an DELETE query without WHERE clause!');
        }

        $query  = "DELETE " . self::quoteName($table) . " FROM " . self::quoteName($table);

        $it = new DBmysqlIterator($this);
        $query .= $it->analyseJoins($joins);
        $query .= " WHERE " . $it->analyseCrit($where);

        return $query;
    }


    /**
     * Get table schema
     *
     * @param string $table Table name,
     * @param string|null $structure Raw table structure
     *
     * @return array
     */
    public function getTableSchema($table, $structure = null)
    {
        if ($structure === null) {
            $structure = $this->query("SHOW CREATE TABLE `$table`")->fetch_row();
            $structure = $structure[1];
        }

        //get table index
        $index = preg_grep(
            "/^\s\s+?KEY/",
            array_map(
                function ($idx) {
                    return rtrim($idx, ',');
                },
                explode("\n", (string) $structure)
            )
        );
        //get table schema, without index, without AUTO_INCREMENT
        $structure = preg_replace(
            [
              "/\s\s+KEY .*/",
              "/AUTO_INCREMENT=\d+ /"
            ],
            "",
            (string) $structure
        );
        $structure = preg_replace('/,(\s)?$/m', '', $structure);
        $structure = preg_replace('/ COMMENT \'(.+)\'/', '', $structure);

        $structure = str_replace(
            [
              " COLLATE utf8_unicode_ci",
              " CHARACTER SET utf8",
              ', ',
            ],
            [
              '',
              '',
              ',',
            ],
            trim($structure)
        );

        //do not check engine nor collation
        $structure = preg_replace(
            '/\) ENGINE.*$/',
            '',
            $structure
        );

        //Mariadb 10.2 will return current_timestamp()
        //while older retuns CURRENT_TIMESTAMP...
        $structure = preg_replace(
            '/ CURRENT_TIMESTAMP\(\)/i',
            ' CURRENT_TIMESTAMP',
            (string) $structure
        );

        //Mariadb 10.2 allow default values on longblob, text and longtext
        $defaults = [];
        preg_match_all(
            '/^.+ (longblob|text|longtext) .+$/m',
            (string) $structure,
            $defaults
        );
        if (count($defaults[0])) {
            foreach ($defaults[0] as $line) {
                $structure = str_replace(
                    $line,
                    str_replace(' DEFAULT NULL', '', $line),
                    $structure
                );
            }
        }

        $structure = preg_replace("/(DEFAULT) ([-|+]?\d+)(\.\d+)?/", "$1 '$2$3'", (string) $structure);
        //$structure = preg_replace("/(DEFAULT) (')?([-|+]?\d+)(\.\d+)(')?/", "$1 '$3'", $structure);
        $structure = preg_replace('/(BIGINT)\(\d+\)/i', '$1', (string) $structure);
        $structure = preg_replace('/(TINYINT) /i', '$1(4) ', (string) $structure);

        return [
           'schema' => strtolower((string) $structure),
           'index'  => $index
        ];
    }

    /**
     * Get database raw version
     *
     * @return string
     */
    public function getVersion()
    {
        $req = $this->request('SELECT version()')->next();
        $raw = $req['version()'];
        return $raw;
    }

    /**
     * Starts a transaction
     *
     * @return boolean
     */
    public function beginTransaction()
    {
        $this->getDoctrineConnection()->beginTransaction();
        return true;
    }

    /**
     * Commits a transaction
     *
     * @return boolean
     */
    public function commit()
    {
        $this->getDoctrineConnection()->commit();
        return true;
    }

    /**
     * Rollbacks a transaction
     *
     * @return boolean
     */
    public function rollBack()
    {
        $this->getDoctrineConnection()->rollBack();
        return true;
    }

    /**
     * Are we in a transaction?
     *
     * @return boolean
     */
    public function inTransaction()
    {
        return $this->doctrine !== null && $this->doctrine->isTransactionActive();
    }

    /**
     * Check if timezone data is accessible and available in database.
     *
     * @param string $msg  Variable that would contain the reason of data unavailability.
     *
     * @return boolean
     *
     * @since 9.5.0
     */
    public function areTimezonesAvailable(string &$msg = '')
    {
        $cache = Config::getCache('cache_db');

        if ($cache->has('are_timezones_available')) {
            return $cache->get('are_timezones_available');
        }
        $cache->set('are_timezones_available', false, DAY_TIMESTAMP);

        try {
            $names = $this->getTimezoneNames();
        } catch (\Doctrine\DBAL\Exception $error) {
            $msg = __('Access to timezone table (mysql.time_zone_name) is not allowed.');
            return false;
        }
        if (!$names) {
            $msg = __('Timezones seems not loaded, see https://glpi-install.readthedocs.io/en/latest/timezones.html.');
            return false;
        }

        $cache->set('are_timezones_available', true);
        return true;
    }

    /**
     * Defines timezone to use.
     *
     * @param string $timezone
     *
     * @return DBmysql
     */
    public function setTimezone($timezone)
    {
        //setup timezone
        if ($this->areTimezonesAvailable()) {
            date_default_timezone_set($timezone);
            $this->getDoctrineConnection()->executeStatement("SET SESSION time_zone = ?", [$timezone]);
            $_SESSION['glpi_currenttime'] = date("Y-m-d H:i:s");
        }
        return $this;
    }

    /**
     * Returns list of timezones.
     *
     * @return string[]
     *
     * @since 9.5.0
     */
    public function getTimezones()
    {
        $list = []; //default $tz is empty

        $from_php = \DateTimeZone::listIdentifiers();
        $now = new \DateTime();

        try {
            foreach (array_intersect($this->getTimezoneNames(), $from_php) as $name) {
                $now->setTimezone(new \DateTimeZone($name));
                $list[$name] = $name . $now->format(" (T P)");
            }
        } catch (\Exception $e) {
            //do nothing
        }


        return $list;
    }

    private function connectionParameters(): array
    {
        $parts = explode(':', $this->connectedHost ?? (string)$this->dbhost, 2);
        $params = [
            'driver' => 'mysqli', 'charset' => $this->dbenc ?? 'utf8',
            'driverOptions' => [MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true], 'host' => $parts[0], 'dbname' => $this->dbdefault,
            'user' => $this->dbuser, 'password' => rawurldecode((string)$this->dbpassword),
        ];
        if (isset($parts[1])) {
            $params[(int)$parts[1] > 0 ? 'port' : 'unix_socket'] = (int)$parts[1] > 0 ? (int)$parts[1] : $parts[1];
        }
        if ($this->dbssl) {
            $params += [
                'ssl_key' => $this->dbsslkey ?? '', 'ssl_cert' => $this->dbsslcert ?? '',
                'ssl_ca' => $this->dbsslca ?? '', 'ssl_capath' => $this->dbsslcapath ?? '',
                'ssl_cipher' => $this->dbsslcacipher ?? '',
            ];
        }
        return $params;
    }

    /**
     * MariaDB's system tables may use Aria, which cannot participate in savepoints.
     * Keep catalog reads outside the application's transaction, without changing it.
     */
    private function getTimezoneNames(): array
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection($this->connectionParameters());
        try {
            return $connection->fetchFirstColumn('SELECT Name FROM mysql.time_zone_name ORDER BY Name');
        } finally {
            $connection->close();
        }
    }

    /**
     * Returns count of tables that were not migrated to be compatible with timezones usage.
     *
     * @return number
     *
     * @since 9.5.0
     */
    public function notTzMigrated()
    {
        global $DB;

        $result = $DB->request([
            'COUNT'       => 'cpt',
            'FROM'        => 'information_schema.columns',
            'WHERE'       => [
               'information_schema.columns.table_schema' => $DB->dbdefault,
               'information_schema.columns.table_name'   => ['LIKE', 'glpi\_%'],
               'information_schema.columns.data_type'    => ['datetime']
            ]
        ])->next();
        return (int)$result['cpt'];
    }

    /**
     * Returns columns that corresponds to signed primary/foreign keys.
     *
     * @return DBmysqlIterator
     *
     * @since 9.5.7
     */
    public function getSignedKeysColumns()
    {

        $query = [
           'SELECT'     => [
              'information_schema.columns.table_name as TABLE_NAME',
              'information_schema.columns.column_name as COLUMN_NAME',
              'information_schema.columns.data_type as DATA_TYPE',
              'information_schema.columns.column_default as COLUMN_DEFAULT',
              'information_schema.columns.is_nullable as IS_NULLABLE',
              'information_schema.columns.extra as EXTRA',
           ],
           'FROM'       => 'information_schema.columns',
           'INNER JOIN' => [
              'information_schema.tables' => [
                 'FKEY' => [
                    'information_schema.tables'  => 'table_name',
                    'information_schema.columns' => 'table_name',
                    [
                       'AND' => [
                          'information_schema.tables.table_schema' => new QueryExpression(
                              $this->quoteName('information_schema.columns.table_schema')
                          ),
                       ]
                    ],
                 ]
              ]
           ],
           'WHERE'      => [
            'information_schema.tables.table_schema'  => $this->dbdefault,
            'information_schema.tables.table_name'    => ['LIKE', 'glpi\_%'],
            'information_schema.tables.table_type'    => 'BASE TABLE',
            [
               'OR' => [
                  ['information_schema.columns.column_name' => 'id'],
                  ['information_schema.columns.column_name' => ['LIKE', '%\_id']],
                  ['information_schema.columns.column_name' => ['LIKE', '%\_id\_%']],
               ],
            ],
            'information_schema.columns.data_type' => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],
            ['NOT' => ['information_schema.columns.column_type' => ['LIKE', '%unsigned%']]],
           ],
           'ORDER'      => ['TABLE_NAME']
        ];
        foreach (self::ALLOWED_SIGNED_KEYS as $allowed_signed_key) {
            list($excluded_table, $excluded_field) = explode('.', $allowed_signed_key);
            $excluded_fkey = getForeignKeyFieldForTable($excluded_table);
            $query['WHERE'][] = [
               [
                  'NOT' => [
                     'information_schema.tables.table_name'   => $excluded_table,
                     'information_schema.columns.column_name' => $excluded_field
                  ]
               ],
               ['NOT' => ['information_schema.columns.column_name' => $excluded_fkey]],
               ['NOT' => ['information_schema.columns.column_name' => ['LIKE', str_replace('_', '\_', $excluded_fkey . '_%')]]],
            ];
        }

        $iterator = $this->request($query);

        return $iterator;
    }

    /**
     * Returns foreign keys constraints.
     *
     * @return DBmysqlIterator
     *
     * @since 9.5.7
     */
    public function getForeignKeysContraints()
    {

        $query = [
           'SELECT' => [
              'table_schema as TABLE_SCHEMA',
              'table_name as TABLE_NAME',
              'column_name as COLUMN_NAME',
              'constraint_name as CONSTRAINT_NAME',
              'referenced_table_name as REFERENCED_TABLE_NAME',
              'referenced_column_name as REFERENCED_COLUMN_NAME',
              'ordinal_position as ORDINAL_POSITION',
           ],
           'FROM'   => 'information_schema.key_column_usage',
           'WHERE'  => [
              'referenced_table_schema' => $this->dbdefault,
              'referenced_table_name'   => ['LIKE', 'glpi\_%'],
           ],
           'ORDER'  => ['TABLE_NAME']
        ];

        $iterator = $this->request($query);

        return $iterator;
    }


    /**
     * Get character used to quote names for current database engine
     *
     * @return string
     *
     * @since 9.5.0
     */
    public static function getQuoteNameChar(): string
    {
        return '`';
    }


}
