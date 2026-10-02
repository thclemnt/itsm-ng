<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\UnusedProjectTemplateReference;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/project-template-reference.php /path/to/test-config\n");
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
$migration = new UnusedProjectTemplateReference();
$migration->apply($connection);
$DB->clearSchemaCache();
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$id = (new RecordWriter(Orm::create($DB)))->insert('glpi_projects', ['name' => 'Project template migration fixture']);
try {
    $before = $manager->introspectTable('glpi_projects');
    verify(!$before->hasColumn('projecttemplates_id'), 'Canonical schema has no unused field');
    $after = clone $before;
    $after->addColumn('projecttemplates_id', Types::INTEGER, ['notnull' => false, 'default' => 0]);
    $after->addIndex(['projecttemplates_id'], 'projecttemplates_id');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->executeStatement('UPDATE glpi_projects SET projecttemplates_id = 23 WHERE id = ?', [$id]);
    $failed = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'Populated');
    }
    verify($failed && $manager->introspectTable('glpi_projects')->hasColumn('projecttemplates_id'), 'Populated selection refuses before DDL');
    $connection->executeStatement('UPDATE glpi_projects SET projecttemplates_id = NULL WHERE id = ?', [$id]);
    $custom = clone $manager->introspectTable('glpi_projects');
    $custom->addIndex(['projecttemplates_id', 'name'], 'custom_project_template_index');
    $failed = false;
    try {
        UnusedProjectTemplateReference::configureTable($custom);
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'Custom');
    }
    verify($failed, 'Custom composite index is refused');
    $custom = clone $manager->introspectTable('glpi_projects');
    $custom->addForeignKeyConstraint('glpi_projects', ['projecttemplates_id'], ['id'], [], 'custom_project_template_fk');
    $failed = false;
    try {
        UnusedProjectTemplateReference::configureTable($custom);
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'foreign key');
    }
    verify($failed, 'Custom foreign key is refused');
    $before = $manager->introspectTable('glpi_projects');
    $after = clone $before;
    $after->addUniqueIndex(['projecttemplates_id'], 'project_template_probe_unique');
    // All existing values must be NULL to install the probe's unique key.
    $connection->executeStatement('UPDATE glpi_projects SET projecttemplates_id = NULL');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $probe = new Table('glpi_project_template_upgrade_probe');
    $probe->addColumn('selection', Types::INTEGER, ['notnull' => false]);
    $probe->addForeignKeyConstraint('glpi_projects', ['selection'], ['projecttemplates_id'], [], 'project_template_probe_incoming');
    try {
        foreach ($platform->getCreateTableSQL($probe) as $sql) {
            $connection->executeStatement($sql);
        }
        $failed = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $failed = str_contains($error->getMessage(), 'Incoming');
        }
        verify($failed && $manager->introspectTable('glpi_projects')->hasColumn('projecttemplates_id'), 'Incoming custom foreign key refuses before DDL');
    } finally {
        $connection->executeStatement($platform->getDropTableSQL($probe->getName()));
        $before = $manager->introspectTable('glpi_projects');
        $after = clone $before;
        $after->dropIndex('project_template_probe_unique');
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    if ($DB->getProvider() === 'mysql') {
        $connection->beginTransaction();
        try {
            $failed = false;
            try {
                $migration->apply($connection);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'outside');
            }
            verify($failed && $connection->isTransactionActive(), 'MySQL DDL refuses application transaction');
        } finally {
            $connection->rollBack();
        }
    }
    verify(count($migration->apply($connection)['sql']) > 0, 'Unused zero/NULL column and index removed');
    verify($migration->apply($connection)['sql'] === [], 'Idempotent retry');
    $DB->clearSchemaCache();
    $record = (new RecordRepository(Orm::create($DB)))->find('glpi_projects', 'id', $id);
    verify($record['name'] === 'Project template migration fixture' && !array_key_exists('projecttemplates_id', $record), 'Mapped project remains readable');
    echo $DB->getProvider() . ": unused project-template cleanup, populated/custom constraint refusal and retry passed.\n";
} finally {
    $connection->executeStatement('DELETE FROM glpi_projects WHERE id = ?', [$id]);
}
