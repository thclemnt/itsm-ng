<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Real result, bound binary and physical ownership contracts on disposable databases. */
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\LegacySql;
use itsmng\Database\PostgresParameters;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php postgresql-driver.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function refused(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        verify(true, $message);
        return;
    }
    throw new RuntimeException($message . ': invalid input was accepted');
}
function closedStatement(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (LogicException $error) {
        verify(str_contains($error->getMessage(), 'statement'), $message);
        return;
    }
    throw new RuntimeException($message . ': stale statement was accepted');
}

$connection = $DB->getDoctrineConnection();
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
verify(!$connection->isTransactionActive(), 'Contract starts outside caller transactions');
$postgres = $DB->getProvider() === 'pgsql';
$originalTimezone = date_default_timezone_get();
$hadCurrentTime = array_key_exists('glpi_currenttime', $_SESSION);
$originalCurrentTime = $_SESSION['glpi_currenttime'] ?? null;
$sessionTimezone = $postgres ? $connection->fetchOne("SELECT current_setting('TimeZone')") : null;
$byteaOutput = $postgres ? $connection->fetchOne("SELECT current_setting('bytea_output')") : null;
$tableName = 'glpi_driver_bytes_' . bin2hex(random_bytes(5));
$table = new Table($tableName);
$table->addColumn('id', 'integer', ['autoincrement' => true]);
$table->addColumn('marker', 'string', ['length' => 60]);
$table->addColumn('payload', 'binary', ['length' => 1024, 'notnull' => false]);
$table->setPrimaryKey(['id']);
$created = false;
$secondary = $statement = $update = $retained = null;
$applicationLock = false;
$lock = null;
$streams = [];
$cleanupErrors = [];
$primary = null;
try {
    verify(!$DB->tableExists($tableName, false), 'New table name has no previous owner');
    $connection->createSchemaManager()->createTable($table);
    $created = true;
    $DB->clearSchemaCache();
    $statement = $DB->prepare($DB->buildInsert($tableName, ['marker' => new QueryParam(), 'payload' => new QueryParam()]));
    $marker = '';
    $payload = null;
    $statement->bind_param('sb', $marker, $payload);
    $expected = [];
    foreach (['empty' => '', 'nul' => "before\0after", 'non_utf8' => "\xff\x80\xfe", 'escaped' => "\\x0061\\000 ? $1 '", 'null' => null] as $marker => $payload) {
        verify($statement->execute(), 'Legacy typed binary insert succeeds for ' . $marker);
        verify($statement->affected_rows === 1 && $statement->error === '', 'Binary insert retains command count and error for ' . $marker);
        $expected[$marker] = $payload;
    }
    foreach (['stream' => "\0stream\xff\0", 'empty_stream' => ''] as $marker => $bytes) {
        $payload = fopen('php://temp', 'w+b');
        $streams[] = $payload;
        verify(fwrite($payload, $bytes) === strlen($bytes) && rewind($payload), 'Caller prepares owned binary stream');
        verify($statement->execute(), 'Legacy binary stream insert reads actual stream bytes');
        verify(is_resource($payload), 'Statement does not close the caller input stream');
        $expected[$marker] = $bytes;
    }
    $statement->close();
    foreach (['dbal_nul' => "\0DBAL\xff", 'dbal_null' => null] as $marker => $payload) {
        verify($connection->insert($tableName, ['marker' => $marker, 'payload' => $payload], ['marker' => ParameterType::STRING, 'payload' => $payload === null ? ParameterType::NULL : ParameterType::BINARY]) === 1, 'DBAL typed binary insert succeeds');
        $expected[$marker] = $payload;
    }
    ksort($expected);
    $read = static function () use ($DB, $tableName, $expected): void {
        $result = $DB->query('SELECT marker, payload FROM ' . $DB->quoteName($tableName) . ' ORDER BY marker');
        verify($result !== false && $DB->numrows($result) === count($expected), 'Buffered binary result has every owned row');
        verify($DB->numFields($result) === 2 && $DB->fieldName($result, 1) === 'payload', 'Binary result retains native column metadata');
        foreach ($expected as $marker => $bytes) {
            verify($DB->fetchAssoc($result) === ['marker' => $marker, 'payload' => $bytes], 'Binary value round-trips byte-for-byte: ' . $marker);
        }
        verify($DB->fetchRow($result) === null, 'Binary rowset end remains null');
        verify($DB->dataSeek($result, 0), 'Binary result can rewind without rereading consumed streams');
        $marker = array_key_first($expected);
        verify($DB->fetchRow($result) === [$marker, $expected[$marker]], 'Rewound binary bytes are stable');
        verify(!$DB->dataSeek($result, -1) && !$DB->dataSeek($result, count($expected)), 'Invalid binary seek offsets are refused');
        $DB->freeResult($result);
    };
    $read();
    verify($DB->request(['SELECT' => [new QueryExpression('1 AS expression_value')], 'FROM' => $tableName, 'WHERE' => ['marker' => 'empty'], 'LIMIT' => 1])->next() === ['expression_value' => 1], 'Existing QueryExpression projection still executes through the shared owner');
    if ($postgres) {
        foreach (['hex', 'escape'] as $mode) {
            $connection->executeQuery("SELECT set_config('bytea_output', ?, false)", [$mode])->free();
            $read();
            $native = $connection->fetchOne('SELECT payload FROM ' . $DB->quoteName($tableName) . " WHERE marker = 'nul'");
            $bytes = is_resource($native) ? stream_get_contents($native) : $native;
            verify($bytes === "before\0after", 'Raw PDO/DBAL binary value is byte-exact independently of bytea_output');
        }
    }

    $update = $DB->prepare('UPDATE ' . $DB->quoteName($tableName) . ' SET payload = ? WHERE marker = ?');
    $bytes = "updated\0\xfe";
    $marker = 'nul';
    $update->bind_param('bs', $bytes, $marker);
    verify($update->execute() && $update->affected_rows === 1, 'Bound binary UPDATE mutates precisely its owned row');
    $result = $DB->query('SELECT payload FROM ' . $DB->quoteName($tableName) . " WHERE marker = 'nul'");
    verify($DB->fetchRow($result) === [$bytes], 'Updated binary bytes survive legacy fetch');
    $DB->freeResult($result);
    $bytes = null;
    verify($update->execute(), 'Bound nullable binary UPDATE succeeds by reference');
    verify($connection->fetchOne('SELECT payload FROM ' . $DB->quoteName($tableName) . " WHERE marker = 'nul'") === null, 'Updated binary NULL remains SQL NULL');
    $update->close();

    if ($postgres) {
        $DB->setTimezone('Europe/Paris');
        $result = $DB->queryParams(<<<'SQL'
SELECT TRUE AS yes, FALSE AS no, NULL::boolean AS optional,
       4294967297::bigint AS wide, 1.25::float8 AS fraction,
       '2024-01-02 03:04:05.123456+01'::timestamptz AS instant,
       '2024-01-02 03:04:05.123456+01'::text AS text_instant,
       't'::text AS text_boolean, '4294967297'::text AS text_integer,
       '2024-01-02 03:04:05.123456'::timestamp AS local_time
SQL, []);
        verify($DB->fetchAssoc($result) === ['yes' => 1, 'no' => 0, 'optional' => null, 'wide' => 4294967297, 'fraction' => 1.25,
            'instant' => '2024-01-02 03:04:05', 'text_instant' => '2024-01-02 03:04:05.123456+01', 'text_boolean' => 't',
            'text_integer' => '4294967297', 'local_time' => '2024-01-02 03:04:05.123456'], 'Only real result types trigger legacy normalization');
        $DB->freeResult($result);
        $raw = $connection->fetchAssociative("SELECT TRUE AS yes, FALSE AS no, '2024-01-02 03:04:05.123456+01'::timestamptz AS instant");
        verify($raw['yes'] === true && $raw['no'] === false, 'Ordinary DBAL booleans retain native PDO conventions');
        verify(str_contains($raw['instant'], '.123456') && (new DateTimeImmutable($raw['instant']))->format('P') === '+01:00', 'Ordinary DBAL timestamp retains fraction and timezone');

        $result = $DB->queryParams(<<<'SQL'
SELECT $2::text AS second, $1::text AS first, $2::text AS repeated,
       '$3 ? :not_bound'::text AS quoted,
       $body$ ? $4 :ignored ' \ backtick ` $body$::text AS body
       /* outer /* inner */ ? $5 :ignored */ -- $6 ?
SQL, ['first ? \\ value', "second ' value"]);
        verify($DB->fetchAssoc($result) === ['second' => "second ' value", 'first' => 'first ? \\ value', 'repeated' => "second ' value",
            'quoted' => '$3 ? :not_bound', 'body' => " ? $4 :ignored ' \\ backtick ` "], 'Numbered references preserve repeat/order and literal/comment bytes');
        $DB->freeResult($result);
        $result = $DB->queryParams(<<<'SQL'
SELECT 'ends-with-\'::text AS literal, $1::text AS bound
SQL, ['later bound value']);
        verify($DB->fetchAssoc($result) === ['literal' => 'ends-with-\\', 'bound' => 'later bound value'], 'Standard PostgreSQL string ending in backslash does not swallow a later numbered bind');
        $DB->freeResult($result);
        $raw = $connection->fetchAssociative(<<<'SQL'
SELECT 'ends-with-\'::text AS literal, ?::text AS bound
SQL, ['later DBAL bound value']);
        verify($raw === ['literal' => 'ends-with-\\', 'bound' => 'later DBAL bound value'], 'Ordinary DBAL preserves PostgreSQL standard-string and positional-binding semantics');
        $values = array_map(static fn (int $number): string => 'value-' . $number, range(1, 12));
        $columns = array_map(static fn (int $number): string => '$' . $number . '::text AS p' . $number, range(1, 12));
        $result = $DB->queryParams('SELECT ' . implode(', ', $columns), $values);
        verify($DB->fetchRow($result) === $values, 'Multidigit numbered placeholders bind their own ordinals');
        $DB->freeResult($result);
        $result = $DB->queryParams("SELECT $1::jsonb ? 'present' AS present", ['{"present":false}']);
        verify($DB->fetchAssoc($result) === ['present' => 1], 'Native numbered API preserves question-mark SQL operator');
        $DB->freeResult($result);
        refused(fn () => $DB->queryParams('SELECT $0::text', ['value']), 'Zero parameter ordinal is refused');
        refused(fn () => $DB->queryParams('SELECT $2::text', ['value']), 'Missing parameter value is refused');
        refused(fn () => $DB->queryParams('SELECT $1::text', ['value', 'unused']), 'Unreferenced parameter input is refused');
        refused(fn () => $DB->queryParams('SELECT $1::text', ["NUL\0text"]), 'Text NUL is refused before server truncation');
        verify(LegacySql::postgres('SELECT $$ ? `not_identifier` \' untouched $$', true) === 'SELECT $$ ? `not_identifier` \' untouched $$', 'Legacy quote conversion preserves whole dollar body');
        refused(fn () => PostgresParameters::bind('SELECT $1, /* unfinished', ['value']), 'Incomplete nested comment is diagnosed');

        $secondary = (new ReflectionClass($DB))->newInstanceWithoutConstructor();
        verify($secondary->connect() === true, 'Exclusive configured physical owner opens');
        $owner = $secondary->getDoctrineConnection();
        $retained = $secondary->prepare('SELECT ?::text AS marker');
        $marker = 'retained buffered value';
        $retained->bind_param('s', $marker);
        verify($retained->execute(), 'Retained legacy statement executes on its actual owner');
        $buffered = $retained->get_result();
        $lock = 'driver-owner-' . bin2hex(random_bytes(8));
        verify($secondary->getLock($lock) && !$DB->getLock($lock), 'Separate owner holds a real contended session lock');
        $owner->close();
        $applicationLock = $DB->getLock($lock);
        verify(!$owner->isConnected() && $applicationLock, 'Direct DBAL close invalidates retained statements and releases the physical session lock');
        verify($DB->releaseLock($lock), 'Application releases only its acquired test lock');
        $applicationLock = false;
        closedStatement($retained->execute(...), 'Legacy retained statement cannot execute after direct owner close');
        verify($DB->dataSeek($buffered, 0) && $DB->fetchAssoc($buffered) === ['marker' => $marker], 'Already buffered legacy result survives physical owner close');
        $retained->close();
        verify($secondary->connect() === true, 'Explicit adapter reconnect obtains a fresh initialized physical owner');
        verify($secondary->getDoctrineConnection() !== $owner && $secondary->getDoctrineConnection()->isConnected(), 'Reconnect replaces the closed owner without stale prepared state');
        verify($secondary->close() === true, 'Exclusive owner closes after successful reconnect');
        $secondary = null;
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    foreach ([$statement, $update, $retained, $secondary] as $resource) {
        if ($resource !== null) {
            try {
                $resource->close();
            } catch (Throwable $error) {
                $cleanupErrors[] = $error;
            }
        }
    }
    if ($applicationLock) {
        try {
            verify($DB->releaseLock($lock), 'Cleanup releases only the application-acquired test lock');
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    foreach ($streams as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    if ($created) {
        try {
            $connection->createSchemaManager()->dropTable($tableName);
            $DB->clearSchemaCache();
            verify(!$DB->tableExists($tableName, false), 'Only owned binary fixture table is removed');
        } catch (Throwable $error) {
            $cleanupErrors[] = $error;
        }
    }
    if ($postgres) {
        foreach (['bytea_output' => $byteaOutput, 'TimeZone' => $sessionTimezone] as $setting => $value) {
            try {
                $connection->executeQuery('SELECT set_config(?, ?, false)', [$setting, $value])->free();
                if ($setting === 'TimeZone') {
                    $connection->setSessionTimezone($value);
                }
            } catch (Throwable $error) {
                $cleanupErrors[] = $error;
            }
        }
    }
    date_default_timezone_set($originalTimezone);
    if ($hadCurrentTime) {
        $_SESSION['glpi_currenttime'] = $originalCurrentTime;
    } else {
        unset($_SESSION['glpi_currenttime']);
    }
}
if ($primary !== null) {
    if ($cleanupErrors !== []) {
        fwrite(STDERR, count($cleanupErrors) . " additional driver cleanup failures\n");
    }
    throw $primary;
}
if ($cleanupErrors !== []) {
    throw new RuntimeException('Owned driver fixture cleanup failed.', previous: $cleanupErrors[0]);
}
echo "$assertions driver result/parameter/binary/ownership assertions passed.\n";
