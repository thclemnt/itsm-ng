<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\BaselineSchema;
use itsmng\Database\NativeTimestampSchema;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/native-timestamps-coverage.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable installed schema required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$mysql = $platform instanceof AbstractMySQLPlatform;
verify($mysql || $platform instanceof PostgreSQLPlatform, 'Supported native provider required');
$manager = $connection->createSchemaManager();
$catalog = $manager->listTableNames();
sort($catalog, SORT_STRING);
$ledger = $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version');
$timezone = $connection->fetchOne($mysql ? 'SELECT @@SESSION.time_zone' : 'SHOW TIME ZONE');
$em = Orm::create($DB);
$metadata = $em->getMetadataFactory()->getAllMetadata();
$coreTables = [];
foreach ($metadata as $entity) {
    $coreTables[$entity->getTableName()] = true;
}
$declarations = NativeTimestampSchema::declarations($metadata);
$expected = (new BaselineSchema())->build($platform);
$native = $manager->introspectSchema();
verify((new SchemaCheck())->differences($connection, $expected) === [], 'Complete native schema retains the existing strict read-only comparison');
verify(NativeTimestampSchema::differences($connection, $expected, $metadata) === [], 'Every declared native automatic clock retains its exact touch behavior');

$rows = $connection->fetchAllAssociative($mysql
    ? 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, DATETIME_PRECISION AS temporal_precision, '
        . 'IS_NULLABLE AS is_nullable, COLUMN_COMMENT AS column_comment, EXTRA AS extra '
        . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'"
    : 'SELECT c.table_name, c.column_name, c.data_type, c.datetime_precision AS temporal_precision, c.is_nullable, '
        . 'col_description(a.attrelid, a.attnum) AS column_comment '
        . 'FROM information_schema.columns c JOIN pg_namespace n ON n.nspname = c.table_schema '
        . 'JOIN pg_class r ON r.relnamespace = n.oid AND r.relname = c.table_name '
        . 'JOIN pg_attribute a ON a.attrelid = r.oid AND a.attname = c.column_name AND NOT a.attisdropped '
        . "WHERE c.table_schema = current_schema() AND c.data_type = 'timestamp with time zone'");
$facts = [];
foreach ($rows as $row) {
    if (!isset($coreTables[$row['table_name']])) {
        continue; // Plugin/other owned schemas do not become a manually declared core policy.
    }
    $key = $row['table_name'] . '.' . $row['column_name'];
    verify(!isset($facts[$key]), 'Native catalogs have one authoritative fact per temporal column');
    $facts[$key] = $row;
}
$ownedKeys = [];
foreach ($declarations as $table => $columns) {
    foreach ($columns as $column => $policy) {
        $key = $table . '.' . $column;
        $ownedKeys[] = $key;
        verify(isset($facts[$key]), 'Property-owned native timestamp exists: ' . $key);
        $actual = $facts[$key];
        $wanted = $expected->getTable($table)->getColumn($column);
        $inspected = $native->getTable($table)->getColumn($column);
        $ddl = $platform->getColumnDeclarationSQL($column, $wanted->toArray(true));
        verify(preg_match('/\bTIMESTAMP(?:\(([0-9]+)\))?(?:\s|$)/i', $ddl, $precision) === 1,
            'Canonical property DDL exposes its native fractional precision: ' . $key);
        $expectedPrecision = isset($precision[1]) && $precision[1] !== '' ? (int)$precision[1] : ($mysql ? 0 : 6);
        verify($actual['data_type'] === ($mysql ? 'timestamp' : 'timestamp with time zone')
            && (int)$actual['temporal_precision'] === $expectedPrecision
            && ($actual['is_nullable'] === 'NO') === $wanted->getNotnull()
            && (string)$actual['column_comment'] === $wanted->getComment(),
            'Actual type, fractional precision, nullability and comment retain property intent: ' . $key);
        verify($platform->getDefaultValueDeclarationSQL($wanted->toArray(true)) === $platform->getDefaultValueDeclarationSQL($inspected->toArray(true)),
            'The actual introspected native default retains property semantics: ' . $key);
        if ($mysql) {
            $autoTouch = preg_match('/(?:^|\s)on update CURRENT_TIMESTAMP(?:\(\))?(?:\s|$)/iD', $actual['extra']) === 1;
            verify($autoTouch === ($policy->touchTrigger !== null), 'Native ON UPDATE is neither added nor removed: ' . $key);
        }
    }
}
sort($ownedKeys, SORT_STRING);
if ($mysql) {
    // Unlike PostgreSQL's datetimetz, MySQL DATETIME wall-time must not enter this native set.
    $nativeKeys = array_keys($facts);
    sort($nativeKeys, SORT_STRING);
    verify($nativeKeys === $ownedKeys, 'All installed MySQL TIMESTAMP columns have property ownership, with no unexpected native type drift');
}
$afterCatalog = $manager->listTableNames();
sort($afterCatalog, SORT_STRING);
verify($afterCatalog === $catalog && $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version') === $ledger,
    'Native timestamp inspection neither repairs DDL nor changes the canonical ledger');
verify($connection->fetchOne($mysql ? 'SELECT @@SESSION.time_zone' : 'SHOW TIME ZONE') === $timezone,
    'Native timestamp inspection retains the selected connection timezone');
printf("PASS: %d native inspection assertions cover %d declared temporal columns\n", $assertions, count($ownedKeys));
