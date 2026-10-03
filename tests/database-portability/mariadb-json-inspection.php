<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\MariaDBSchemaManager;
use itsmng\Database\MySQLConnection;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/mariadb-json-inspection.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$writer = $DB->getDoctrineConnection();
$platform = $writer->getDatabasePlatform();
if (!$platform instanceof MariaDBPlatform) {
    verify(!$writer->createSchemaManager() instanceof MariaDBSchemaManager, 'Other providers retain their native schema manager');
    echo $DB->getProvider() . ": native JSON inspection uses the provider's unchanged manager\n";
    exit(0);
}
$connection = MySQLConnection::create($writer->getParams());
$manager = $connection->createSchemaManager();
verify($manager instanceof MariaDBSchemaManager, 'Owned connection uses the shared public schema-manager factory');
$initialMode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
$globalMode = (string)$connection->fetchOne('SELECT @@GLOBAL.sql_mode');
$name = 'itsm_port_json_inspection_' . bin2hex(random_bytes(5));
$quote = $platform->quoteSingleIdentifier(...);
$quotedColumn = 'payload`"quoted';
$created = false;
verify(!$manager->tablesExist([$name]), 'Only a create-only owned fixture is accepted');
try {
    $baseModes = array_values(array_filter(explode(',', $initialMode), static fn (string $mode): bool => strtoupper(trim($mode)) !== 'ANSI_QUOTES'));
    $connection->executeStatement('SET SESSION sql_mode = ?', [implode(',', $baseModes)]);
    $connection->executeStatement('CREATE TABLE ' . $quote($name) . ' (id INTEGER NOT NULL PRIMARY KEY, payload JSON NULL, '
        . $quote($quotedColumn) . ' JSON NULL, json_check LONGTEXT NULL CHECK (json_valid(json_check)), plain_text LONGTEXT NULL, literal_fake LONGTEXT NULL, compound_fake LONGTEXT NULL, '
        . $quote('true') . ' LONGTEXT NULL, ' . $quote('false') . ' LONGTEXT NULL, ' . $quote('null') . ' LONGTEXT NULL,'
        . ' CHECK (json_valid(' . $platform->quoteStringLiteral('"literal_fake"') . ')), '
        . ' CHECK (json_valid(compound_fake) OR 1 = 1), '
        . ' CHECK (json_valid("true")), CHECK (json_valid(TRUE)), CHECK (json_valid(FALSE)), CHECK (json_valid(NULL))) ENGINE=InnoDB');
    $created = true;
    $data = ['id' => 1, 'payload' => '{"role":"subject"}', $quotedColumn => '[1,null,"quoted"]', 'json_check' => '{"explicit":"unquoted DDL column"}',
        'plain_text' => 'plain non-JSON text', 'literal_fake' => 'another non-JSON value', 'compound_fake' => 'a permissive compound predicate is not JSON ownership',
        'true' => 'constant TRUE does not reference this column', 'false' => 'constant FALSE does not reference this column', 'null' => 'constant NULL does not reference this column'];
    $quotedData = [];
    foreach ($data as $field => $value) {
        $quotedData[$quote($field)] = $value;
    }
    $connection->insert($quote($name), $quotedData);
    $expected = new Table($name);
    $expected->addColumn('id', 'integer');
    $expected->setPrimaryKey(['id']);
    foreach (['payload', $quotedColumn, 'json_check'] as $field) {
        $expected->addColumn($field, 'json', ['notnull' => false]);
    }
    foreach (['plain_text', 'literal_fake', 'compound_fake', 'true', 'false', 'null'] as $field) {
        $expected->addColumn($field, 'text', ['notnull' => false]);
    }
    $before = $connection->fetchAssociative('SELECT * FROM ' . $quote($name) . ' WHERE id = 1');
    foreach ([false, true] as $ansi) {
        $connection->executeStatement('SET SESSION sql_mode = ?', [implode(',', [...$baseModes, ...($ansi ? ['ANSI_QUOTES'] : [])])]);
        $single = $manager->introspectTable($name);
        $bulk = $manager->introspectSchema()->getTable($name);
        foreach ([$single, $bulk] as $actual) {
            foreach (['payload', $quotedColumn, 'json_check'] as $field) {
                verify(Type::lookupName($actual->getColumn($field)->getType()) === 'json', 'Native JSON alias and escaped quoted identifier round trip under both quote modes');
                verify(!$actual->getColumn($field)->getNotnull() && $actual->getColumn($field)->getDefault() === null, 'JSON NULL/default semantics are preserved');
            }
            foreach (['plain_text', 'literal_fake', 'compound_fake', 'true', 'false', 'null'] as $field) {
                verify(Type::lookupName($actual->getColumn($field)->getType()) === 'text', 'Plain LONGTEXT and literal CHECK lookalikes remain text');
            }
            verify($manager->createComparator()->compareTables($expected, $actual)->isEmpty(), 'DBAL comparison preserves the complete fixture column/index schema');
        }
        verify($connection->fetchAssociative('SELECT * FROM ' . $quote($name) . ' WHERE id = 1') === $before, 'Inspection preserves exact native JSON/text data');
        $rejected = false;
        try {
            $connection->update($name, ['payload' => 'invalid JSON'], ['id' => 1]);
        } catch (\Doctrine\DBAL\Exception) {
            $rejected = true;
        }
        verify($rejected && $connection->fetchAssociative('SELECT * FROM ' . $quote($name) . ' WHERE id = 1') === $before, 'Native JSON rejection remains enforced without data coercion');
    }
} finally {
    if ($created) {
        $manager->dropTable($name);
    }
    $connection->executeStatement('SET SESSION sql_mode = ?', [$initialMode]);
    verify((string)$connection->fetchOne('SELECT @@SESSION.sql_mode') === $initialMode, 'Owned caller session mode restored');
    verify((string)$connection->fetchOne('SELECT @@GLOBAL.sql_mode') === $globalMode, 'No GLOBAL mode changes');
    verify(!$manager->tablesExist([$name]), 'Only the created fixture was removed');
    $connection->close();
}
echo "mysql: $assertions native MariaDB JSON inspection assertions passed\n";
