<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Glpi\Console\Database\CheckCommand;
use itsmng\Database\SchemaCheck;
use Symfony\Component\Console\Tester\CommandTester;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/schema-check.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$connection = $DB->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$checker = new SchemaCheck();
$command = new CommandTester(new CheckCommand());
verify($command->execute([]) === 0, 'Installed core schema passes: ' . $command->getDisplay());
verify(str_contains($command->getDisplay(), 'not compared'), 'Command states platform-specific comparison limits');

$probe = new Table('glpi_schema_check_probe');
$probe->addColumn('id', 'integer');
$probe->addColumn('label', 'string', ['length' => 100, 'comment' => 'Historical relationship comment']);
$probe->addColumn('occurred', 'datetimetz', ['notnull' => false] + ($DB->getProvider() === 'mysql' ? ['columnDefinition' => 'TIMESTAMP NULL DEFAULT NULL'] : []));
$probe->setPrimaryKey(['id']);
$probe->addIndex(['label'], 'probe_label');
$expected = new Schema([$probe]);
verify($checker->differences($connection, $expected) === ['Missing table: glpi_schema_check_probe'], 'Missing table is detected');
$manager->createTable($probe);
try {
    verify($checker->differences($connection, $expected) === [], 'DBAL declaration round trips');
    $withoutComment = clone $probe;
    $withoutComment->getColumn('label')->setComment('');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($probe, $withoutComment)) as $sql) {
        $connection->executeStatement($sql);
    }
    verify(in_array('Changed column: glpi_schema_check_probe.label', $checker->differences($connection, $expected), true), 'Lost historical comments are schema drift');
    verify($manager->introspectTable($probe->getName())->getColumn('label')->getComment() === '', 'Check leaves comment drift untouched');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($withoutComment, $probe)) as $sql) {
        $connection->executeStatement($sql);
    }
    if ($DB->getProvider() === 'mysql') {
        $connection->executeStatement('ALTER TABLE glpi_schema_check_probe MODIFY occurred DATETIME DEFAULT NULL');
        verify(in_array('Expected native TIMESTAMP: glpi_schema_check_probe.occurred', $checker->differences($connection, $expected), true), 'Native timestamp regression is detected despite DBAL type aliasing');
    }
    $changed = clone $probe;
    $changed->dropColumn('label');
    $changed->dropIndex('probe_label');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($probe, $changed)) as $sql) {
        $connection->executeStatement($sql);
    }
    $differences = $checker->differences($connection, $expected);
    verify(in_array('Missing column: glpi_schema_check_probe.label', $differences, true), 'Missing column is detected');
    verify(in_array('Missing index: glpi_schema_check_probe.probe_label', $differences, true), 'Missing index is detected');
} finally {
    $manager->dropTable($probe->getName());
}

// Exercise the actual command and its exit status against deliberate core drift.
$name = 'glpi_autoupdatesystems';
$before = $manager->introspectTable($name);
$after = clone $before;
$after->addColumn('schema_check_probe', 'integer', ['notnull' => false]);
try {
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    verify($command->execute([]) === CheckCommand::ERROR_SCHEMA_DIFFERENCES, 'Drift fails the command');
    verify(str_contains($command->getDisplay(), 'Unexpected column: glpi_autoupdatesystems.schema_check_probe'), 'Diagnostic identifies the column');
    verify($manager->introspectTable($name)->hasColumn('schema_check_probe'), 'Check does not repair or mutate the database');
} finally {
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($manager->introspectTable($name), $before)) as $sql) {
        $connection->executeStatement($sql);
    }
}
verify($command->execute([]) === 0, 'Restored core schema passes');
echo $DB->getProvider() . ": read-only schema check, missing definitions, core drift and command exit statuses passed.\n";
