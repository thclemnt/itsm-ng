<?php

/** PostgreSQL adapter for the shared ITSM-NG database API. GPL-2.0-or-later. */
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use itsmng\Database\LegacySql;
use itsmng\Database\PostgresStatement;

class DBpgsql extends DBAdapter
{
    private $dbh = null;
    private string $lastError = '';
    private string $sqlState = '';
    private int $affected = 0;
    private int|string $lastId = 0;
    public $dbport = 5432;
    public $dbschema = 'public';
    public $dbsslmode = 'prefer';

    protected function getNativeConnection(): object
    {
        return $this->dbh;
    }

    /** The same physical connection is used by DBAL and legacy queries. */
    public function getDoctrineConnection(): \itsmng\Database\PostgresConnection
    {
        if ($this->doctrine === null) {
            $this->doctrine = new \itsmng\Database\PostgresConnection(
                ['dbname' => $this->dbdefault],
                new \itsmng\Database\NativeDriver($this->getNativeConnection())
            );
            $this->doctrine->setNestTransactionsWithSavepoints(true);
        }
        return $this->doctrine;
    }

    public function getProvider(): string
    {
        return 'pgsql';
    }

    public function installSchema(): bool
    {
        $connection = $this->getDoctrineConnection();
        (new \itsmng\Database\Migration\History())->baseline($connection);
        $this->clearSchemaCache();
        return true;
    }

    public static function getQuoteNameChar(): string
    {
        return '"';
    }

    public function connect($choice = null)
    {
        $this->connected = false;
        $this->error = 1;
        $this->lastError = '';
        if (!extension_loaded('pgsql')) {
            $this->lastError = 'The pgsql PHP extension is required.';
            return false;
        }
        if ($this->dbh) {
            $this->close();
        }
        $host = is_array($this->dbhost) ? $this->dbhost[$choice ?? array_rand($this->dbhost)] : $this->dbhost;
        $port = $this->dbport;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $parts) || preg_match('/^([^:]+):(\d+)$/', $host, $parts)) {
            [, $host, $port] = $parts;
        }
        $params = [
            'host' => $host, 'port' => $port, 'user' => $this->dbuser,
            'password' => rawurldecode($this->dbpassword), 'dbname' => $this->dbdefault,
            'connect_timeout' => 5, 'application_name' => 'ITSM-NG',
            'sslmode' => $this->dbssl ? 'verify-full' : $this->dbsslmode,
        ];
        foreach (['sslcert' => $this->dbsslcert, 'sslkey' => $this->dbsslkey, 'sslrootcert' => $this->dbsslca] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }
        $dsn = [];
        foreach ($params as $key => $value) {
            $dsn[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value) . "'";
        }
        $this->dbh = @pg_connect(implode(' ', $dsn), PGSQL_CONNECT_FORCE_NEW);
        if (!$this->dbh) {
            // Do not expose the DSN/password in diagnostics.
            $this->lastError = 'Unable to connect to PostgreSQL. Check host, database, credentials and TLS settings.';
            return false;
        }
        pg_set_client_encoding($this->dbh, 'UTF8');
        $this->connected = true;
        $this->error = 0;
        $this->queryParams("SELECT set_config('search_path', $1, false)", ['"' . str_replace('"', '""', $this->dbschema) . '"']);
        $this->queryParams("SELECT set_config('standard_conforming_strings', 'on', false)", []);
        $this->setTimezone($this->guessTimezone());
        return true;
    }

    /** Preserve the pre-escaped legacy API; query() decodes these escapes lexically. */
    public function escape($string)
    {
        return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], (string)$string);
    }

    public function query($query)
    {
        $result = $this->queryParams(LegacySql::postgres($query), []);
        // The public adapter contract returns true for commands and a result
        // only for row sets, including INSERT/UPDATE ... RETURNING.
        if ($result !== false && pg_result_status($result) === PGSQL_COMMAND_OK) {
            $this->freeResult($result);
            return true;
        }
        return $result;
    }

    /** Execute native PostgreSQL SQL with separate values, without legacy escaping. */
    public function queryParams(string $sql, array $values)
    {
        global $DEBUG_SQL, $SQL_TOTAL_REQUEST, $CFG_GLPI;
        foreach ($values as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw new InvalidArgumentException('PostgreSQL text parameters cannot contain NUL bytes.');
            }
        }
        $start = microtime(true);
        $this->lastError = $this->sqlState = '';
        $this->affected = 0;
        if (!$this->connected) {
            throw new RuntimeException('PostgreSQL connection is not open.');
        }
        if (!pg_send_query_params($this->dbh, $sql, $values)) {
            throw new RuntimeException('Unable to send the PostgreSQL query.');
        }
        $result = pg_get_result($this->dbh);
        if ($result === false) {
            throw new RuntimeException('PostgreSQL returned no query result.');
        }
        $this->sqlState = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) ?: '';
        if (in_array(pg_result_status($result), [PGSQL_FATAL_ERROR, PGSQL_BAD_RESPONSE], true)) {
            $this->lastError = pg_result_error($result);
            $this->affected = -1;
            pg_free_result($result);
            $result = false;
        } else {
            $this->affected = pg_affected_rows($result);
        }
        // Drain the asynchronous command before another query can be sent.
        while ($extra = pg_get_result($this->dbh)) {
            pg_free_result($extra);
        }
        if ($this->execution_time === true) {
            $this->execution_time = microtime(true) - $start;
        }
        if (!empty($CFG_GLPI['debug_sql']) && ($_SESSION['glpi_use_mode'] ?? null) === Session::DEBUG_MODE) {
            $SQL_TOTAL_REQUEST++;
            $DEBUG_SQL['queries'][$SQL_TOTAL_REQUEST] = $sql;
            $DEBUG_SQL['times'][$SQL_TOTAL_REQUEST] = microtime(true) - $start;
            $DEBUG_SQL['rows'][$SQL_TOTAL_REQUEST] = $this->affected;
            if (!$result) {
                $DEBUG_SQL['errors'][$SQL_TOTAL_REQUEST] = $this->lastError;
            }
        }
        if (!$result) {
            Toolbox::logSqlError("PostgreSQL query error [{$this->sqlState}]: {$this->lastError}\nSQL: $sql");
        }
        return $result;
    }

    public function prepare($query)
    {
        return new PostgresStatement($this, LegacySql::postgres($query, true));
    }

    public function numrows($result)
    {
        return $result ? pg_num_rows($result) : 0;
    }

    public function fetchArray($result)
    {
        $row = $this->fetchRow($result);
        if ($row === null) {
            return null;
        }
        foreach ($row as $i => $value) {
            $row[pg_field_name($result, $i)] = $value;
        }
        return $row;
    }

    public function fetchRow($result)
    {
        $row = pg_fetch_row($result);
        if ($row === false) {
            return null;
        }
        foreach ($row as $i => $value) {
            if ($value === null) {
                continue;
            }
            $type = pg_field_type($result, $i);
            if (in_array($type, ['int2', 'int4', 'int8'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $row[$i] = (int)$value;
            } elseif (in_array($type, ['float4', 'float8'], true)) {
                $row[$i] = (float)$value;
            } elseif ($type === 'bool') {
                // Keep the legacy model contract while storing real SQL booleans.
                $row[$i] = $value === 't' ? 1 : 0;
            } elseif ($type === 'timestamptz') {
                $row[$i] = (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
            }
        }
        return $row;
    }

    public function fetchAssoc($result)
    {
        $row = $this->fetchRow($result);
        if ($row === null) {
            return null;
        }
        $assoc = [];
        foreach ($row as $i => $value) {
            $assoc[pg_field_name($result, $i)] = $value;
        }
        return $assoc;
    }

    public function fetchObject($result)
    {
        $row = $this->fetchAssoc($result);
        return $row === null ? null : (object)$row;
    }

    public function dataSeek($result, $num)
    {
        return pg_result_seek($result, $num);
    }

    public function numFields($result)
    {
        return pg_num_fields($result);
    }

    public function fieldName($result, $nb)
    {
        return pg_field_name($result, $nb);
    }

    public function freeResult($result)
    {
        return pg_free_result($result);
    }

    public function affectedRows()
    {
        return $this->affected;
    }

    public function errno()
    {
        return $this->sqlState === '' ? 0 : $this->sqlState;
    }

    public function error()
    {
        return $this->lastError;
    }

    public function insertId()
    {
        return $this->lastId;
    }

    public function insert($table, $params)
    {
        $this->lastId = 0;
        $sql = $this->buildInsert($table, $params);
        $hasId = isset($this->listFields($table)['id']);
        $result = $this->query($sql . ($hasId ? ' RETURNING "id"' : ''));
        if ($result && $hasId) {
            $this->lastId = $this->fetchRow($result)[0];
            $this->freeResult($result);
        }
        return $result !== false;
    }

    public function insertOrDie($table, $params, $message = '')
    {
        $result = $this->insert($table, $params);
        if (!$result) {
            throw new RuntimeException($message . ': ' . $this->error());
        }
        return $result;
    }

    public function buildInsert($table, $params)
    {
        return $params === [] ? 'INSERT INTO ' . static::quoteName($table) . ' DEFAULT VALUES' : parent::buildInsert($table, $params);
    }

    public function buildDelete($table, $where, array $joins = [])
    {
        if (!$where) {
            throw new RuntimeException('Cannot run a DELETE query without WHERE clause!');
        }
        $it = new DBmysqlIterator($this);
        $name = static::quoteName($table);
        if (!$joins) {
            return "DELETE FROM $name WHERE " . $it->analyseCrit($where);
        }
        return "DELETE FROM $name WHERE ctid IN (SELECT $name.ctid FROM $name"
            . $it->analyseJoins($joins) . ' WHERE ' . $it->analyseCrit($where) . ')';
    }

    public function buildUpdate($table, $params, $clauses, array $joins = [])
    {
        $where = $clauses['WHERE'] ?? $clauses;
        if (!$where) {
            throw new RuntimeException('Cannot run an UPDATE query without WHERE clause!');
        }
        if (!$joins && !isset($clauses['ORDER']) && !isset($clauses['LIMIT']) && !isset($clauses['START'])) {
            return parent::buildUpdate($table, $params, $clauses);
        }
        $it = new DBmysqlIterator($this);
        $name = static::quoteName($table);
        $set = [];
        foreach ($params as $field => $value) {
            $set[] = static::quoteName($field) . ' = ' . static::quoteValue($value);
        }
        $select = "SELECT $name.ctid FROM $name" . $it->analyseJoins($joins) . ' WHERE ' . $it->analyseCrit($where);
        if (isset($clauses['ORDER'])) {
            $select .= $it->handleOrderClause($clauses['ORDER']);
        }
        $select .= $it->handleLimits($clauses['LIMIT'] ?? 0, $clauses['START'] ?? 0);
        return "UPDATE $name SET " . implode(', ', $set) . " WHERE ctid IN ($select)";
    }

    public function listTables($table = 'glpi\_%', array $where = [])
    {
        return $this->request([
            'SELECT' => 'table_name AS TABLE_NAME', 'FROM' => 'information_schema.tables',
            'WHERE' => ['table_schema' => $this->escape($this->dbschema), 'table_type' => 'BASE TABLE', 'table_name' => ['LIKE', $this->escape($table)]] + $where,
            'ORDER' => 'table_name',
        ]);
    }

    public function listFields($table, $usecache = true)
    {
        if (!$this->cache_disabled && $usecache && isset($this->field_cache[$table])) {
            return $this->field_cache[$table];
        }
        $result = $this->queryParams(<<<'SQL'
SELECT a.attname AS "Field", format_type(a.atttypid, a.atttypmod) AS "Type",
       CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS "Null",
       CASE WHEN EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.oid AND i.indisprimary AND a.attnum = ANY(i.indkey)) THEN 'PRI' ELSE '' END AS "Key",
       pg_get_expr(d.adbin, d.adrelid) AS "Default",
       CASE WHEN a.attidentity <> '' OR pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS "Extra"
FROM pg_attribute a
JOIN pg_class c ON c.oid = a.attrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
WHERE n.nspname = $1 AND c.relname = $2 AND a.attnum > 0 AND NOT a.attisdropped
ORDER BY a.attnum
SQL, [$this->dbschema, $table]);
        if (!$result) {
            return false;
        }
        $fields = [];
        while ($row = $this->fetchAssoc($result)) {
            $fields[$row['Field']] = $row;
        }
        $this->freeResult($result);
        return $this->field_cache[$table] = $fields;
    }

    public function constraintExists($table, $constraint)
    {
        $result = $this->queryParams('SELECT 1 FROM information_schema.table_constraints WHERE table_schema = $1 AND table_name = $2 AND constraint_name = $3', [$this->dbschema, $table, $constraint]);
        return $this->numrows($result) > 0;
    }

    public function synchronizeSequences(): void
    {
        \itsmng\Database\SequenceSynchronizer::synchronize($this->getDoctrineConnection());
    }

    public function getVersion()
    {
        return $this->getDoctrineConnection()->getServerVersion();
    }

    public function getInfo()
    {
        return ['Server Software' => 'PostgreSQL', 'Server Version' => $this->getVersion(), 'Parameters' => $this->dbuser . '@' . (is_array($this->dbhost) ? implode(',', $this->dbhost) : $this->dbhost) . '/' . $this->dbdefault];
    }

    public function beginTransaction()
    {
        $this->getDoctrineConnection()->beginTransaction();
        return true;
    }

    public function commit()
    {
        $connection = $this->getDoctrineConnection();
        try {
            // The public legacy API can see raw BEGIN outside DBAL's nesting.
            // Preserve its aborted-transaction refusal before delegation even
            // when DBAL itself would report NoActiveTransaction.
            $connection->assertCommittable();
            $connection->commit();
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            if ($error->getSQLState() === '25P02') {
                return false;
            }
            throw $error;
        }
        return true;
    }

    public function rollBack()
    {
        $this->getDoctrineConnection()->rollBack();
        return true;
    }

    public function inTransaction()
    {
        return $this->getDoctrineConnection()->isTransactionActive();
    }

    public function close()
    {
        if (!$this->dbh) {
            return false;
        }
        $wrapped = $this->doctrine !== null
            && $this->doctrine->getDriver()->hasTransferredConnection();
        if ($this->doctrine !== null) {
            $this->doctrine->close();
            $this->doctrine = null;
        }
        // A facade that never transferred this handle has no owning driver
        // destructor. Conversely, DBAL may already have destroyed its driver
        // on connection loss; do not close that same handle a second time.
        $result = $wrapped ? true : pg_close($this->dbh);
        $this->dbh = null;
        $this->connected = false;
        return $result;
    }

    public function getLock($name)
    {
        $result = $this->queryParams('SELECT pg_try_advisory_lock(hashtextextended($1, 0))', [$this->dbdefault . '.' . $name]);
        return $result && $this->fetchRow($result)[0] === 1;
    }

    public function releaseLock($name)
    {
        $result = $this->queryParams('SELECT pg_advisory_unlock(hashtextextended($1, 0))', [$this->dbdefault . '.' . $name]);
        return $result && $this->fetchRow($result)[0] === 1;
    }

    public function areTimezonesAvailable(string &$msg = '')
    {
        return true;
    }

    public function notTzMigrated()
    {
        return 0;
    }

    public function setTimezone($timezone)
    {
        new DateTimeZone($timezone);
        if (!$this->queryParams("SELECT set_config('TimeZone', $1, false)", [$timezone])) {
            throw new RuntimeException($this->error());
        }
        date_default_timezone_set($timezone);
        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        return $this;
    }

    public function getTimezones()
    {
        $list = [];
        $now = new DateTimeImmutable();
        foreach ($this->request(['SELECT' => 'name', 'FROM' => 'pg_timezone_names']) as $row) {
            if (in_array($row['name'], DateTimeZone::listIdentifiers(), true)) {
                $list[$row['name']] = $row['name'] . $now->setTimezone(new DateTimeZone($row['name']))->format(' (T P)');
            }
        }
        return $list;
    }

    public function getComparisonOperator(string $operator): string
    {
        return match ($operator) {
            'REGEXP' => '~', 'NOT REGEX', 'NOT REGEXP' => '!~', 'LIKE' => 'ILIKE', 'NOT LIKE' => 'NOT ILIKE', default => $operator
        };
    }
}
