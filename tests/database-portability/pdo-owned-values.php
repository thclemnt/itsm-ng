<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\ManagedTransactionConnection;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/pdo-owned-values.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function binaryValue(mixed $value): string
{
    return is_resource($value) ? stream_get_contents($value) : (string)$value;
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable PDO value database required');
$connection = $DB->getDoctrineConnection();
verify($connection instanceof ManagedTransactionConnection && !$connection->isTransactionActive(), 'Idle canonical PDO owner');
$DB->assertManagedTransaction();
$native = $connection->getNativeConnection();
verify($native instanceof PDO && $native->getAttribute(PDO::ATTR_DRIVER_NAME) === ($DB->getProvider() === 'pgsql' ? 'pgsql' : 'mysql'), 'The configured provider is actually owned by its PDO driver');
if ($DB->getProvider() === 'mysql') {
    verify(in_array($native->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true) && $native->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) === false, 'Actual MySQL driver retains native prepare and numeric fetching');
}
$manager = $connection->createSchemaManager();
$name = 'glpi_port_pdo_' . bin2hex(random_bytes(5));
verify(!$manager->tablesExist([$name]), 'Never adopt a preexisting value fixture');
$table = new Table($name);
if ($DB->getProvider() === 'mysql') {
    $table->addOption('charset', 'utf8mb4');
    $table->addOption('collation', 'utf8mb4_unicode_ci');
}
$table->addColumn('id', Types::BIGINT);
$table->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => false]);
$table->addColumn('flag', Types::INTEGER);
$table->addColumn('payload', Types::BLOB, ['notnull' => false]);
$table->addColumn('measure', Types::FLOAT, ['notnull' => false]);
$table->setPrimaryKey(['id']);
$created = false;
$statement = null;
$stream = null;
$protocolStatement = null;
$readPrepares = null;
$primary = null;
$cleanup = [];
try {
    $manager->createTable($table);
    $created = true;
    if ($DB->getProvider() === 'mysql') {
        $encoding = $connection->fetchAssociative(
            "SELECT t.TABLE_COLLATION AS table_collation, c.CHARACTER_SET_NAME AS character_set, c.COLLATION_NAME AS column_collation
            FROM information_schema.TABLES t JOIN information_schema.COLUMNS c
                ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
            WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND c.COLUMN_NAME='label'",
            [$name]
        );
        verify($encoding === ['table_collation' => 'utf8mb4_unicode_ci', 'character_set' => 'utf8mb4', 'column_collation' => 'utf8mb4_unicode_ci'],
            'Actual owned table and label column retain explicit Unicode before the measured bound INSERT');
    }
    $sql = 'INSERT INTO ' . $connection->getDatabasePlatform()->quoteIdentifier($name) . ' (id, label, flag, payload) VALUES (?, ?, ?, ?)';
    if ($DB->getProvider() === 'mysql') {
        // Prepare this read-only observer once, before the baseline. Reusing it
        // does not add prepare commands to the measured public INSERT interval.
        $protocolStatement = $native->prepare("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'");
        verify($protocolStatement instanceof PDOStatement, 'Own an actual same-session prepare-counter observer');
        $readPrepares = static function () use ($protocolStatement): int {
            verify($protocolStatement->execute(), 'Read actual session protocol prepare count');
            $row = $protocolStatement->fetch(PDO::FETCH_NUM);
            verify(
                is_array($row) && count($row) === 2 && $row[0] === 'Com_stmt_prepare'
                && filter_var($row[1], FILTER_VALIDATE_INT) !== false && (int)$row[1] >= 0
                && $protocolStatement->fetch(PDO::FETCH_NUM) === false,
                'Actual server returns exactly one nonnegative prepare counter'
            );
            verify($protocolStatement->closeCursor(), 'Release owned protocol result before public bound operation');
            return (int)$row[1];
        };
        $preparesBefore = $readPrepares();
    }
    $statement = $DB->prepare($sql);
    $id = 4294999001;
    $label = "PDO O'Reilly \\ 日本語";
    $flag = false;
    $payload = "a\0b\xff";
    verify($statement->bind_param('isib', $id, $label, $flag, $payload) && $statement->execute(), 'Actual legacy by-reference statement binds wide IDs, null/boolean and binary bytes through DBAL');
    if ($readPrepares !== null) {
        verify(
            $readPrepares() === $preparesBefore + 1,
            'Actual public bound INSERT emits one native prepare on the same physical session'
        );
    }
    $row = $connection->fetchAssociative('SELECT * FROM ' . $name . ' WHERE id=?', [$id]);
    verify((int)$row['id'] === $id && $row['label'] === $label && $row['flag'] === 0 && binaryValue($row['payload']) === $payload, 'Literal UTF8/backslash and native integer/binary values round-trip without coercion');
    $id++;
    $label = null;
    $flag = 7;
    $payload = "\0rebound\0";
    verify($statement->execute(), 'Re-execution reads actual changed bound variables');
    $row = $connection->fetchAssociative('SELECT * FROM ' . $name . ' WHERE id=?', [$id]);
    verify($row['label'] === null && $row['flag'] === 7 && binaryValue($row['payload']) === $payload, 'Null and rebound binary parameters preserve their exact values');
    $statement->close();
    $statement = null;
    $stream = fopen('php://memory', 'r+');
    verify(is_resource($stream), 'Own an actual LOB stream fixture');
    fwrite($stream, "stream\0payload\xff");
    rewind($stream);
    $connection->insert(
        $name,
        ['id' => ++$id, 'label' => 'DBAL stream', 'flag' => 9, 'payload' => $stream, 'measure' => 1.25],
        ['id' => Types::BIGINT, 'payload' => Types::BLOB, 'measure' => Types::FLOAT]
    );
    $row = $connection->fetchAssociative('SELECT * FROM ' . $name . ' WHERE id=?', [$id]);
    verify(
        binaryValue($row['payload']) === "stream\0payload\xff"
        && \Doctrine\DBAL\Types\Type::getType(Types::FLOAT)->convertToPHPValue($row['measure'], $connection->getDatabasePlatform()) === 1.25,
        'Real DBAL LOB stream and mapped FloatType value survive the physical PDO driver'
    );
    if ($DB->getProvider() === 'mysql') {
        verify($row['measure'] === 1.25, 'Actual MySQL PDO retains its native floating representation');
    }
    $legacyFloat = $DB->query('SELECT measure FROM ' . $name . ' WHERE id=' . $id);
    try {
        verify($DB->fetchAssoc($legacyFloat) === ['measure' => 1.25], 'Actual legacy floating projection preserves the typed application value on both providers');
    } finally {
        $DB->freeResult($legacyFloat);
    }
    $result = $DB->query('SELECT 1 AS duplicate, 2 AS duplicate UNION ALL SELECT 3, 4');
    try {
        verify($DB->fetchRow($result) === [1, 2] && $DB->fetchAssoc($result) === ['duplicate' => 4], 'Actual legacy result preserves native numbers and duplicate names');
        verify($DB->dataSeek($result, 0) && $DB->fetchArray($result) === [0 => 1, 1 => 2, 'duplicate' => 2], 'Seek and combined fetch remain compatible');
    } finally {
        $DB->freeResult($result);
    }
    $result = $DB->query('SELECT id, label FROM ' . $name . ' WHERE id < 0');
    try {
        verify($DB->numrows($result) === 0 && $DB->numFields($result) === 2
            && $DB->fieldName($result, 0) === 'id' && $DB->fieldName($result, 1) === 'label', 'Empty result retains its real column metadata');
    } finally {
        $DB->freeResult($result);
    }
} catch (Throwable $error) {
    $primary = $error;
} finally {
    try {
        $statement?->close();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    if ($protocolStatement !== null) {
        try {
            verify($protocolStatement->closeCursor(), 'Close actual owned prepare-counter result');
            $protocolStatement = null;
            $readPrepares = null;
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    if (is_resource($stream)) {
        fclose($stream);
    }
    if ($created) {
        try {
            $manager->dropTable($name);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
}
if ($primary !== null) {
    fwrite(STDERR, (string)$primary . "\n");
    foreach ($cleanup as $error) {
        fwrite(STDERR, 'Additional owned fixture cleanup failure: ' . (string)$error . "\n");
    }
    exit(1);
}
if ($cleanup) {
    throw new RuntimeException('PDO value fixture cleanup failed.', previous: $cleanup[0]);
}
echo $DB->getProvider() . ": real PDO ownership, native numbers/metadata, legacy bound references, binary bytes and DBAL LOB streams passed.\n";
