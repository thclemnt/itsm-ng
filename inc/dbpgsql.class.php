<?php

/** PostgreSQL adapter for the shared ITSM-NG database API. GPL-2.0-or-later. */
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception;
use itsmng\Database\LegacyResult;
use itsmng\Database\LegacySql;
use itsmng\Database\Migration\History;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\PostgresConnection;
use itsmng\Database\PostgresParameters;
use itsmng\Database\PostgresStatement;
use itsmng\Database\SequenceSynchronizer;

class DBpgsql extends DBAdapter
{
    private string $lastError = '';
    private string $sqlState = '';
    private int $affected = 0;
    private int|string $lastId = 0;
    public $dbport = 5432;
    public $dbschema = 'public';
    public $dbsslmode = 'prefer';

    /** The adapter, ORM and legacy SQL use one DBAL-owned physical session. */
    public function getDoctrineConnection(): PostgresConnection
    {
        return $this->doctrine ?? throw new RuntimeException('Database connection is not open.');
    }

    /** Protected factory seam keeps construction distinct from physical connect. */
    protected function createDoctrineConnection(array $parameters): PostgresConnection
    {
        return PostgresConnection::create($parameters);
    }

    public function getProvider(): string
    {
        return 'pgsql';
    }

    public function installSchema(): bool
    {
        $connection = $this->getDoctrineConnection();
        (new History())->baseline($connection);
        $this->clearSchemaCache();
        return true;
    }

    public static function getQuoteNameChar(): string
    {
        return '"';
    }

    public function connect($choice = null)
    {
        $this->close();
        $this->error = 1;
        $this->lastError = $this->sqlState = '';
        if (!extension_loaded('pdo_pgsql')) {
            $this->lastError = 'The pdo_pgsql PHP extension is required.';
            return false;
        }
        $host = is_array($this->dbhost) ? $this->dbhost[$choice ?? array_rand($this->dbhost)] : $this->dbhost;
        $port = $this->dbport;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $parts) || preg_match('/^([^:]+):(\d+)$/', $host, $parts)) {
            [, $host, $port] = $parts;
        }
        $timezone = date_default_timezone_get();
        $parameters = [
            'host' => $host, 'port' => (int)$port, 'user' => $this->dbuser,
            'password' => rawurldecode($this->dbpassword), 'dbname' => $this->dbdefault,
            'charset' => 'UTF8', 'connect_timeout' => 5, 'application_name' => 'ITSM-NG',
            'search_path' => '"' . str_replace('"', '""', $this->dbschema) . '"',
            'timezone' => $timezone, 'sslmode' => $this->dbssl ? 'verify-full' : $this->dbsslmode,
        ];
        foreach (['sslcert' => $this->dbsslcert, 'sslkey' => $this->dbsslkey, 'sslrootcert' => $this->dbsslca] as $key => $value) {
            if ($value !== null) {
                $parameters[$key] = $value;
            }
        }
        try {
            $this->doctrine = $this->createDoctrineConnection($parameters);
            $this->doctrine->getServerVersion();
            $this->connected = true;
            $this->error = 0;
            $this->setTimezone($this->guessTimezone());
            return true;
        } catch (Exception $error) {
            $this->lastError = 'Unable to connect to PostgreSQL. Check host, database, credentials and TLS settings.';
            $this->close();
            return false;
        }
    }

    /** Preserve the pre-escaped legacy API; query() decodes these escapes lexically. */
    public function escape($string)
    {
        return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], (string)$string);
    }

    public function query($query)
    {
        return $this->queryParams(LegacySql::postgres($query), []);
    }

    /** Numbered libpq parameters remain a positional compatibility API. */
    public function queryParams(string $sql, array $values)
    {
        foreach ($values as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw new InvalidArgumentException('PostgreSQL text parameters cannot contain NUL bytes.');
            }
        }
        [$sql, $values] = PostgresParameters::bind($sql, $values);
        return $this->executeResult($sql, function () use ($sql, $values) {
            $connection = $this->getDoctrineConnection();
            OwnershipUpdateUnit::assertResolvedWriter($this, $connection);
            return $connection->executeLegacyQuery($sql, $values);
        });
    }

    public function executePrepared(Statement $statement, string $sql, array $values, array $types)
    {
        return $this->executeResult($sql, function () use ($statement, $sql, $values, $types) {
            $connection = $this->getDoctrineConnection();
            OwnershipUpdateUnit::assertResolvedWriter($this, $connection);
            return $connection->executeLegacyStatement($statement, $sql, $values, $types);
        });
    }

    private function executeResult(string $sql, callable $execute)
    {
        global $DEBUG_SQL, $SQL_TOTAL_REQUEST, $CFG_GLPI;
        $start = microtime(true);
        $this->lastError = $this->sqlState = '';
        $this->affected = 0;
        if (!$this->connected) {
            throw new RuntimeException('PostgreSQL connection is not open.');
        }
        try {
            $result = $execute();
            if ($result instanceof LegacyResult) {
                $this->affected = $result->num_rows;
                return $result;
            }
            $this->affected = (int)$result->rowCount();
            $result->free();
            return true;
        } catch (Exception\DriverException $error) {
            $this->sqlState = $error->getSQLState() ?? '';
            $this->lastError = $error->getMessage();
            $this->affected = -1;
            Toolbox::logSqlError("PostgreSQL query error [{$this->sqlState}]: {$this->lastError}\nSQL: $sql");
            if ($error instanceof Exception\ConnectionLost) {
                throw $error;
            }
            return false;
        } finally {
            $elapsed = microtime(true) - $start;
            if ($this->execution_time === true) {
                $this->execution_time = $elapsed;
            }
            if (!empty($CFG_GLPI['debug_sql']) && ($_SESSION['glpi_use_mode'] ?? null) === Session::DEBUG_MODE) {
                $SQL_TOTAL_REQUEST++;
                $DEBUG_SQL['queries'][$SQL_TOTAL_REQUEST] = $sql;
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
        return new PostgresStatement($this, LegacySql::postgres($query));
    }

    public function numrows($result)
    {
        return $result ? $result->num_rows : 0;
    }

    public function fetchArray($result)
    {
        return $result->fetch_array();
    }

    public function fetchRow($result)
    {
        return $result->fetch_row();
    }

    public function fetchAssoc($result)
    {
        return $result->fetch_assoc();
    }

    public function fetchObject($result)
    {
        return $result->fetch_object();
    }

    public function dataSeek($result, $num)
    {
        return $result->data_seek($num);
    }

    public function numFields($result)
    {
        return $result->field_count;
    }

    public function fieldName($result, $nb)
    {
        return $result->fieldName($nb);
    }

    public function freeResult($result)
    {
        return $result->free();
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
        SequenceSynchronizer::synchronize($this->getDoctrineConnection());
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
        } catch (Exception\DriverException $error) {
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
        $wasConnected = $this->connected;
        $this->doctrine?->close();
        $this->doctrine = null;
        $this->connected = false;
        return $wasConnected;
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
        $this->getDoctrineConnection()->setSessionTimezone($timezone);
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
