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

/** Shared legacy database API. Engine-specific behavior belongs to its adapter. */
abstract class DBAdapter
{
    //! Database Host - string or Array of string (round robin)
    public $dbhost             = "";
    //! Database User
    public $dbuser             = "";
    //! Database Password
    public $dbpassword         = "";
    //! Default Database
    public $dbdefault          = "";
    //! Database Error
    public $error              = 0;

    // Slave management
    public $slave              = false;

    /**
     * Defines if connection must use SSL.
     *
     * @var boolean
     */
    public $dbssl              = false;

    /** Verify the MySQL TLS certificate and hostname; passed explicitly to the owner. */
    public $dbsslverifyservercert = true;

    /**
     * The path name to the key file (used in case of SSL connection).
     *
     * @var string|null
     */
    public $dbsslkey           = null;

    /**
     * The path name to the certificate file (used in case of SSL connection).
     *
     * @var string|null
     */
    public $dbsslcert          = null;

    /**
     * The path name to the certificate authority file (used in case of SSL connection).
     *
     * @var string|null
     */
    public $dbsslca            = null;

    /**
     * The pathname to a directory that contains trusted SSL CA certificates in PEM format
     * (used in case of SSL connection).
     *
     * @var string|null
     */
    public $dbsslcapath        = null;

    /**
     * A list of allowable ciphers to use for SSL encryption (used in case of SSL connection).
     *
     * @var string|null
     */
    public $dbsslcacipher      = null;


    /** Is it a first connection ?
     * Indicates if the first connection attempt is successful or not
     * if first attempt fail -> display a warning which indicates that glpi is in readonly
    **/
    public $first_connection   = true;
    // Is connected to the DB ?
    public $connected          = false;

    //to calculate execution time
    public $execution_time          = false;

    protected $cache_disabled = false;

    /**
     * Cached list fo tables.
     *
     * @var array
     * @see self::tableExists()
     */
    protected $table_cache = [];

    /**
     * Cached list of fields.
     *
     * @var array
     * @see self::listFields()
     */
    protected $field_cache = [];

    abstract public function getProvider(): string;
    abstract public function connect($choice = null);
    abstract public function query($query);
    abstract public function prepare($query);
    abstract public function escape($string);
    abstract public function numrows($result);
    abstract public function fetchArray($result);
    abstract public function fetchRow($result);
    abstract public function fetchAssoc($result);
    abstract public function fetchObject($result);
    abstract public function dataSeek($result, $num);
    abstract public function insertId();
    abstract public function numFields($result);
    abstract public function fieldName($result, $nb);
    abstract public function affectedRows();
    abstract public function freeResult($result);
    abstract public function errno();
    abstract public function error();
    abstract public function close();
    abstract public function listTables($table = 'glpi\_%', array $where = []);
    abstract public function listFields($table, $usecache = true);
    abstract public function buildDelete($table, $where, array $joins = []);
    abstract public function getVersion();
    abstract public function beginTransaction();
    abstract public function commit();
    abstract public function rollBack();
    abstract public function inTransaction();
    abstract public static function getQuoteNameChar(): string;

    protected ?\Doctrine\DBAL\Connection $doctrine = null;

    /** Doctrine owns the connection used by repositories and transitional callers. */
    public function getDoctrineConnection(): \Doctrine\DBAL\Connection
    {
        return $this->doctrine ?? throw new \RuntimeException('Database connection is not open.');
    }

    /** Domain frame admission is distinct from the legacy logical nesting predicate. */
    public function assertManagedTransaction(): void
    {
        \itsmng\Database\TransactionOwnership::assertManaged($this->getDoctrineConnection());
    }

    /** Capture only after the caller has begun its own managed DBAL layer. */
    public function captureManagedTransactionScope(): \itsmng\Database\ManagedTransactionScope
    {
        $connection = $this->getDoctrineConnection();
        \itsmng\Database\TransactionOwnership::assertManaged($connection);
        return $connection->captureManagedTransactionScope();
    }

    public function expressions(): \itsmng\Database\Expressions
    {
        return new \itsmng\Database\Expressions($this->getDoctrineConnection()->getDatabasePlatform());
    }

    abstract public function installSchema(): bool;

    /** Synchronize generated identifiers after an explicit-ID data import. */
    public function synchronizeSequences(): void
    {
        // MySQL advances AUTO_INCREMENT automatically.
    }

    public function getComparisonOperator(string $operator): string
    {
        return $operator;
    }

    /**
     * Constructor / Connect to the MySQL Database
     *
     * @param integer $choice host number (default NULL)
     *
     * @return void
     */
    public function __construct($choice = null)
    {
        $this->connect($choice);
    }

    /**
     * Guess timezone
     *
     * Will  check for an existing loaded timezone from user,
     * then will check in preferences and finally will fallback to system one.
     *
     * @return string
     *
     * @since 9.5.0
     */
    public function guessTimezone()
    {
        if (isset($_SESSION['glpi_tz'])) {
            $zone = $_SESSION['glpi_tz'];
        } else {
            $conf_tz = ['value' => null];
            if (
                $this->tableExists(Config::getTable())
                && $this->fieldExists(Config::getTable(), 'value')
            ) {
                $conf_tz = $this->request([
                   'SELECT' => 'value',
                   'FROM'   => Config::getTable(),
                   'WHERE'  => [
                      'context'   => 'core',
                      'name'      => 'timezone'
                    ]
                ])->next();
            }
            $zone = !empty($conf_tz['value']) ? $conf_tz['value'] : date_default_timezone_get();
        }

        return $zone;
    }

    /**
     * Execute a MySQL query and die
     * (optionnaly with a message) if it fails
     *
     * @since 0.84
     *
     * @param string $query   Query to execute
     * @param string $message Explanation of query (default '')
     *
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function queryOrDie($query, $message = '')
    {
        $res = $this->query($query);
        if (!$res) {
            //TRANS: %1$s is the description, %2$s is the query, %3$s is the error message
            $message = sprintf(
                __('%1$s - Error during the database query: %2$s - Error is %3$s'),
                $message,
                $query,
                $this->error()
            );
            if (isCommandLine()) {
                throw new \RuntimeException($message);
            } else {
                echo $message . "\n";
                die(1);
            }
        }
        return $res;
    }

    /**
     * Give result from a sql result
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     * @param int           $i      Row offset to give
     * @param string        $field  Field to give
     *
     * @return mixed Value of the Row $i and the Field $field of the Mysql $result
     */
    public function result($result, $i, $field)
    {
        if (
            $result && ($this->dataSeek($result, $i))
            && ($data = $this->fetchArray($result))
            && isset($data[$field])
        ) {
            return $data[$field];
        }
        return null;
    }

    /**
     * Fetch array of the next row of a Mysql query
     * Please prefer fetchRow or fetchAssoc
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return string[]|null array results
     *
     * @deprecated 9.5.0
     */
    public function fetch_array($result)
    {
        Toolbox::deprecated('Use DBmysql::fetchArray()');
        return $this->fetchArray($result);
    }

    /**
     * Fetch row of the next row of a Mysql query
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return mixed|null result row
     *
     * @deprecated 9.5.0
     */
    public function fetch_row($result)
    {
        Toolbox::deprecated('Use DBmysql::fetchRow()');
        return $this->fetchRow($result);
    }

    /**
     * Fetch assoc of the next row of a Mysql query
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return string[]|null result associative array
     *
     * @deprecated 9.5.0
     */
    public function fetch_assoc($result)
    {
        Toolbox::deprecated('Use DBmysql::fetchAssoc()');
        return $this->fetchAssoc($result);
    }

    /**
     * Fetch object of the next row of an SQL query
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return object|null
     */
    public function fetch_object($result)
    {
        Toolbox::deprecated('Use DBmysql::fetchObject()');
        return $this->fetchObject();
    }

    /**
     * Move current pointer of a Mysql result to the specific row
     *
     * @deprecated 9.5.0
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     * @param integer       $num    Row to move current pointer
     *
     * @return boolean
     */
    public function data_seek($result, $num)
    {
        Toolbox::deprecated('Use DBmysql::dataSeek()');
        return $this->dataSeek($result, $num);
    }

    /**
     * Give ID of the last inserted item by Mysql
     *
     * @return mixed
     *
     * @deprecated 9.5.0
     */
    public function insert_id()
    {
        Toolbox::deprecated('Use DBmysql::insertId()');
        return $this->insertId();
    }

    /**
     * Give number of fields of a Mysql result
     *
     * @deprecated 9.5.0
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return int number of fields
     */
    public function num_fields($result)
    {
        Toolbox::deprecated('Use DBmysql::numFields()');
        return $this->numFields($result);
    }

    /**
     * Give name of a field of a Mysql result
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     * @param integer       $nb     ID of the field
     *
     * @return string name of the field
     *
     * @deprecated 9.5.0
     */
    public function field_name($result, $nb)
    {
        Toolbox::deprecated('Use DBmysql::fieldName()');
        return $this->fieldName($result, $nb);
    }

    /**
     * List fields of a table
     *
     * @param string  $table    Table name condition
     * @param boolean $usecache If use field list cache (default true)
     *
     * @return mixed list of fields
     *
     * @deprecated 9.5.0
     */
    public function list_fields($table, $usecache = true)
    {
        Toolbox::deprecated('Use DBmysql::listFields()');
        return $this->listFields($table, $usecache);
    }

    /**
     * Get field of a table
     *
     * @param string  $table
     * @param string  $field
     * @param boolean $usecache
     *
     * @return array|null Field characteristics
     */
    public function getField(string $table, string $field, $usecache = true): ?array
    {

        $fields = $this->listFields($table, $usecache);
        return $fields[$field] ?? null;
    }

    /**
     * Get number of affected rows in previous MySQL operation
     *
     * @return int number of affected rows on success, and -1 if the last query failed.
     *
     * @deprecated 9.5.0
     */
    public function affected_rows()
    {
        Toolbox::deprecated('Use DBmysql::affectedRows()');
        return $this->affectedRows();
    }

    /**
     * Free result memory
     *
     * @param \itsmng\Database\LegacyResult $result Buffered query result
     *
     * @return boolean
     *
     * @deprecated 9.5.0
     */
    public function free_result($result)
    {
        Toolbox::deprecated('Use DBmysql::freeResult()');
        return $this->freeResult($result);
    }

    /**
     * is a slave database ?
     *
     * @return boolean
     */
    public function isSlave()
    {
        return $this->slave;
    }

    /**
     * Execute all the request in a file
     *
     * @param string $path with file full path
     *
     * @return boolean true if all query are successfull
     */
    public function runFile($path)
    {
        $script = fopen($path, 'r');
        if (!$script) {
            return false;
        }
        $sql_query = @fread(
            $script,
            @filesize($path)
        ) . "\n";
        fclose($script);
        $sql_query = html_entity_decode($sql_query, ENT_COMPAT, 'UTF-8');

        $sql_query = $this->removeSqlRemarks($sql_query);
        $queries = preg_split('/;\s*$/m', $sql_query);

        foreach ($queries as $query) {
            $query = trim($query);
            if ($query != '') {
                if (!$this->query($query)) {
                    return false;
                }
                if (!isCommandLine()) {
                    // Flush will prevent proxy to timeout as it will receive data.
                    // Flush requires a content to be sent, so we sent spaces as multiple spaces
                    // will be shown as a single one on browser.
                    echo ' ';
                    Html::glpi_flush();
                }
            }
        }

        return true;
    }

    /**
     * Instanciate a Simple DBIterator
     *
     * Examples =
     *  foreach ($DB->request("select * from glpi_states") as $data) { ... }
     *  foreach ($DB->request("glpi_states") as $ID => $data) { ... }
     *  foreach ($DB->request("glpi_states", "ID=1") as $ID => $data) { ... }
     *  foreach ($DB->request("glpi_states", "", "name") as $ID => $data) { ... }
     *  foreach ($DB->request("glpi_computers",array("name"=>"SBEI003W","entities_id"=>1),array("serial","otherserial")) { ... }
     *
     * Examples =
     *   array("id"=>NULL)
     *   array("OR"=>array("id"=>1, "NOT"=>array("state"=>3)));
     *   array("AND"=>array("id"=>1, array("NOT"=>array("state"=>array(3,4,5),"toto"=>2))))
     *
     * FIELDS name or array of field names
     * ORDER name or array of field names
     * LIMIT max of row to retrieve
     * START first row to retrieve
     *
     * @param string|string[] $tableorsql Table name, array of names or SQL query
     * @param string|string[] $crit       String or array of filed/values, ex array("id"=>1), if empty => all rows
     *                                    (default '')
     * @param boolean         $debug      To log the request (default false)
     *
     * @return DBmysqlIterator
     */
    public function request($tableorsql, $crit = "", $debug = false)
    {
        $iterator = new DBmysqlIterator($this);
        $iterator->execute($tableorsql, $crit, $debug);
        return $iterator;
    }

    /**
     * Check if a table exists
     *
     * @since 9.2
     * @since 9.5 Added $usecache parameter.
     *
     * @param string  $tablename Table name
     * @param boolean $usecache  If use table list cache
     *
     * @return boolean
     **/
    public function tableExists($tablename, $usecache = true)
    {

        if (!$this->cache_disabled && $usecache && in_array($tablename, $this->table_cache)) {
            return true;
        }

        // Retrieve all tables if cache is empty but enabled, in order to fill cache
        // with all known tables
        $retrieve_all = !$this->cache_disabled && empty($this->table_cache);

        $result = $this->listTables($retrieve_all ? 'glpi\_%' : $tablename);
        $found_tables = [];
        while ($data = $result->next()) {
            $found_tables[] = $data['TABLE_NAME'];
        }

        if (!$this->cache_disabled) {
            $this->table_cache = array_unique(array_merge($this->table_cache, $found_tables));
        }

        if (in_array($tablename, $found_tables)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a field exists
     *
     * @since 9.2
     *
     * @param string  $table    Table name for the field we're looking for
     * @param string  $field    Field name
     * @param Boolean $usecache Use cache; @see DBmysql::listFields(), defaults to true
     *
     * @return boolean
     **/
    public function fieldExists($table, $field, $usecache = true)
    {
        if (!$this->tableExists($table, $usecache)) {
            trigger_error("Table $table does not exists", E_USER_WARNING);
            return false;
        }

        if ($fields = $this->listFields($table, $usecache)) {
            if (isset($fields[$field])) {
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Disable table cache globally; usefull for migrations
     *
     * @return void
     */
    public function disableTableCaching()
    {
        $this->cache_disabled = true;
    }

    /**
     * Quote field name
     *
     * @since 9.3
     *
     * @param string $name of field to quote (or table.field)
     *
     * @return string
     */
    public static function quoteName($name)
    {
        // handle verbatim names
        if ($name instanceof QueryExpression) {
            return $name->getValue();
        }

        // handle aliases
        $name_matches = [];
        if (preg_match('/^(?<name>.+[\s|`])AS(?<alias>[\s|`].+)$/i', $name, $name_matches) === 1) {
            $name = rtrim($name_matches['name']);
            $alias = ltrim($name_matches['alias']);
            return static::quoteName($name) . ' AS ' . static::quoteName($alias);
        }

        // handle names with multiple chunks (e.g. db.table.field or table.field)
        if (strpos($name, '.')) {
            $names = explode('.', $name);
            return implode('.', array_map(static::quoteName(...), $names));
        }

        // do not quote wildcard (*)
        if ($name === '*') {
            return $name;
        }

        $quote = static::getQuoteNameChar();
        if (strlen($name) >= 2 && $name[0] === $quote && substr($name, -1) === $quote) {
            return $name;
        }
        if ($quote !== '`' && preg_match('/^`([^`]|``)+`$/', $name)) {
            $name = str_replace('``', '`', substr($name, 1, -1));
        }
        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }

    /**
     * Quote value for insert/update
     *
     * @param mixed $value Value
     *
     * @return mixed
     */
    public static function quoteValue($value)
    {
        if ($value instanceof QueryParam || $value instanceof QueryExpression) {
            //no quote for query parameters nor expressions
            $value = $value->getValue();
        } elseif ($value === null || $value === 'NULL' || $value === 'null') {
            $value = 'NULL';
        } elseif (is_bool($value)) {
            // transform boolean as int (prevent `false` to be transformed to empty string)
            $value = "'" . (int)$value . "'";
        } else {
            //phone numbers may start with '+' and will be considered as numeric
            $value = "'$value'";
        }
        return $value;
    }

    /**
     * Builds an insert statement
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $params Query parameters ([field name => field value)
     *
     * @return string
     */
    public function buildInsert($table, $params)
    {
        $query = "INSERT INTO " . static::quoteName($table) . " (";

        $fields = [];
        foreach ($params as $key => &$value) {
            $fields[] = $this->quoteName($key);
            $value = $this->quoteValue($value);
        }

        $query .= implode(', ', $fields);
        $query .= ") VALUES (";
        $query .= implode(", ", $params);
        $query .= ")";

        return $query;
    }

    /**
     * Insert a row in the database
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $params Query parameters ([field name => field value)
     *
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function insert($table, $params)
    {
        $result = $this->query(
            $this->buildInsert($table, $params)
        );
        return $result;
    }

    /**
     * Insert a row in the database and die
     * (optionnaly with a message) if it fails
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $params  Query parameters ([field name => field value)
     * @param string $message Explanation of query (default '')
     *
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function insertOrDie($table, $params, $message = '')
    {
        $insert = $this->buildInsert($table, $params);
        $res = $this->query($insert);
        if (!$res) {
            //TRANS: %1$s is the description, %2$s is the query, %3$s is the error message
            $message = sprintf(
                __('%1$s - Error during the database query: %2$s - Error is %3$s'),
                $message,
                $insert,
                $this->error()
            );
            if (isCommandLine()) {
                throw new \RuntimeException($message);
            } else {
                echo $message . "\n";
                die(1);
            }
        }
        return $res;
    }

    /**
     * Builds an update statement
     *
     * @since 9.3
     *
     * @param string $table   Table name
     * @param array  $params  Query parameters ([field name => field value)
     * @param array  $clauses Clauses to use. If not 'WHERE' key specified, will b the WHERE clause (@see DBmysqlIterator capabilities)
     * @param array  $joins  JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return string
     */
    public function buildUpdate($table, $params, $clauses, array $joins = [])
    {
        //when no explicit "WHERE", we only have a WHEre clause.
        if (!isset($clauses['WHERE'])) {
            $clauses  = ['WHERE' => $clauses];
        } else {
            $known_clauses = ['WHERE', 'ORDER', 'LIMIT', 'START'];
            foreach (array_keys($clauses) as $key) {
                if (!in_array($key, $known_clauses)) {
                    throw new \RuntimeException(
                        str_replace(
                            '%clause',
                            $key,
                            'Trying to use an unknonw clause (%clause) building update query!'
                        )
                    );
                }
            }
        }

        if (!count($clauses['WHERE'])) {
            throw new \RuntimeException('Cannot run an UPDATE query without WHERE clause!');
        }

        $query  = "UPDATE " . static::quoteName($table);

        //JOINS
        $it = new DBmysqlIterator($this);
        $query .= $it->analyseJoins($joins);

        $query .= " SET ";
        foreach ($params as $field => $value) {
            $query .= static::quoteName($field) . " = " . $this->quoteValue($value) . ", ";
        }
        $query = rtrim($query, ', ');

        $query .= " WHERE " . $it->analyseCrit($clauses['WHERE']);

        // ORDER BY
        if (isset($clauses['ORDER']) && !empty($clauses['ORDER'])) {
            $query .= $it->handleOrderClause($clauses['ORDER']);
        }

        if (isset($clauses['LIMIT']) && !empty($clauses['LIMIT'])) {
            $offset = (isset($clauses['START']) && !empty($clauses['START'])) ? $clauses['START'] : null;
            $query .= $it->handleLimits($clauses['LIMIT'], $offset);
        }

        return $query;
    }

    /**
     * Update a row in the database
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $params Query parameters ([:field name => field value)
     * @param array  $where  WHERE clause
     * @param array  $joins  JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function update($table, $params, $where, array $joins = [])
    {
        $query = $this->buildUpdate($table, $params, $where, $joins);
        $result = $this->query($query);
        return $result;
    }

    /**
     * Update a row in the database or die
     * (optionnaly with a message) if it fails
     *
     * @since 9.3
     *
     * @param string $table   Table name
     * @param array  $params  Query parameters ([:field name => field value)
     * @param array  $where   WHERE clause
     * @param string $message Explanation of query (default '')
     * @param array  $joins   JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function updateOrDie($table, $params, $where, $message = '', array $joins = [])
    {
        $update = $this->buildUpdate($table, $params, $where, $joins);
        $res = $this->query($update);
        if (!$res) {
            //TRANS: %1$s is the description, %2$s is the query, %3$s is the error message
            $message = sprintf(
                __('%1$s - Error during the database query: %2$s - Error is %3$s'),
                $message,
                $update,
                $this->error()
            );
            if (isCommandLine()) {
                throw new \RuntimeException($message);
            } else {
                echo $message . "\n";
                die(1);
            }
        }
        return $res;
    }

    /**
     * Update a row in the database or insert a new one
     *
     * @since 9.4
     *
     * @param string  $table   Table name
     * @param array   $params  Query parameters ([:field name => field value)
     * @param array   $where   WHERE clause
     * @param boolean $onlyone Do the update only one one element, defaults to true
     *
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function updateOrInsert($table, $params, $where, $onlyone = true)
    {
        $req = $this->request($table, $where);
        $data = array_merge($where, $params);
        if ($req->count() == 0) {
            return $this->insertOrDie($table, $data, 'Unable to create new element or update existing one');
        } elseif ($req->count() == 1 || !$onlyone) {
            return $this->updateOrDie($table, $data, $where, 'Unable to create new element or update existing one');
        } else {
            Toolbox::logWarning('Update would change too many rows!');
            return false;
        }
    }

    /**
     * Delete rows in the database
     *
     * @since 9.3
     *
     * @param string $table  Table name
     * @param array  $where  WHERE clause
     * @param array  $joins  JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function delete($table, $where, array $joins = [])
    {
        $query = $this->buildDelete($table, $where, $joins);
        $result = $this->query($query);
        return $result;
    }

    /**
     * Delete a row in the database and die
     * (optionnaly with a message) if it fails
     *
     * @since 9.3
     *
     * @param string $table   Table name
     * @param array  $where   WHERE clause
     * @param string $message Explanation of query (default '')
     * @param array  $joins   JOINS criteria array
     *
     * @since 9.4.0 $joins parameter added
     * @return \itsmng\Database\LegacyResult|bool Query result handler
     */
    public function deleteOrDie($table, $where, $message = '', array $joins = [])
    {
        $update = $this->buildDelete($table, $where, $joins);
        $res = $this->query($update);
        if (!$res) {
            //TRANS: %1$s is the description, %2$s is the query, %3$s is the error message
            $message = sprintf(
                __('%1$s - Error during the database query: %2$s - Error is %3$s'),
                $message,
                $update,
                $this->error()
            );
            if (isCommandLine()) {
                throw new \RuntimeException($message);
            } else {
                echo $message . "\n";
                die(1);
            }
        }
        return $res;
    }

    /**
     * Clear cached schema information.
     *
     * @return void
     */
    public function clearSchemaCache()
    {
        $this->table_cache = [];
        $this->field_cache = [];
    }

    /**
     * Quote a value for a specified type
     * Should be used for PDO, but this will prevent heavy
     * replacements in the source code in the future.
     *
     * @param mixed   $value Value to quote
     * @param integer $type  Value type, defaults to PDO::PARAM_STR
     *
     * @return mixed
     *
     * @since 9.5.0
     */
    public function quote($value, int $type = 2/*\PDO::PARAM_STR*/)
    {
        return "'" . $this->escape($value) . "'";
        //return $this->dbh->quote($value, $type);
    }

    /**
     * Is value quoted as database field/expression?
     *
     * @param string|\QueryExpression $value Value to check
     *
     * @return boolean
     *
     * @since 9.5.0
     */
    public static function isNameQuoted($value): bool
    {
        $quote = static::getQuoteNameChar();
        return is_string($value) && trim($value, $quote) != $value;
    }

    /**
     * Remove SQL comments
     * © 2011 PHPBB Group
     *
     * @param string $output SQL statements
     *
     * @return string
     */
    public function removeSqlComments($output)
    {
        $lines = explode("\n", $output);
        $output = "";

        // try to keep mem. use down
        $linecount = count($lines);

        $in_comment = false;
        for ($i = 0; $i < $linecount; $i++) {
            if (preg_match("/^\/\*/", $lines[$i])) {
                $in_comment = true;
            }

            if (!$in_comment) {
                $output .= $lines[$i] . "\n";
            }

            if (preg_match("/\*\/$/", preg_quote($lines[$i]))) {
                $in_comment = false;
            }
        }

        unset($lines);
        return trim($output);
    }

    /**
     * Remove remarks and comments from SQL
     * @see DBmysql::removeSqlComments()
     * © 2011 PHPBB Group
     *
     * @param $string $sql SQL statements
     *
     * @return string
     */
    public function removeSqlRemarks($sql)
    {
        $lines = explode("\n", (string) $sql);

        // try to keep mem. use down
        $sql = "";

        $linecount = count($lines);
        $output = "";

        for ($i = 0; $i < $linecount; $i++) {
            if (($i != ($linecount - 1)) || (strlen($lines[$i]) > 0)) {
                if (isset($lines[$i][0])) {
                    if ($lines[$i][0] != "#" && substr($lines[$i], 0, 2) != "--") {
                        $output .= $lines[$i] . "\n";
                    } else {
                        $output .= "\n";
                    }
                }
                // Trading a bit of speed for lower mem. use here.
                $lines[$i] = "";
            }
        }
        return trim($this->removeSqlComments($output));
    }
}
