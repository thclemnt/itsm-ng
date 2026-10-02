<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\ProjectAssets20261003;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/project-assets-schema.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$table = 'glpi_items_projects';
$migration = new ProjectAssets20261003();
verify(Ledger::state($connection, ProjectAssets20261003::VERSION)['complete'], 'Fresh installation replays the appended project migration');
verify((new SchemaCheck())->differences($connection) === [], 'Fresh project schema converges');
$branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
$expected = $CFG_GLPI['contract_types'];
$actual = array_keys($branches);
sort($expected);
sort($actual);
verify($actual === $expected && count($branches) === 35, 'Every configured project-picker kind owns an association');
verify(count($manager->listTableForeignKeys($table)) === 36, 'Container and all thirty-five subjects have real foreign keys');
verify($branches['Project']['column'] === 'subject_projects_id' && Record\ItemProject::referenceAssociation('Project') === 'subjectProject', 'Project subject has a distinct column and property from its container');
$fixtures = new FixtureRecords($DB);
$reject = static function (callable $operation, string $message) use ($connection): void {
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $rejected = in_array($error->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true);
        }
        verify($rejected, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    $owner = $fixtures->create('glpi_projects');
    $sameId = 4294968971;
    foreach ($branches as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId]);
        $fixtures->create($selection['target'], ['id' => $sameId + 1]);
        $em = Orm::create($DB);
        $link = new Record\ItemProject();
        $link->projects = $em->getReference(Record\Project::class, $owner);
        $link->itemtype = $kind;
        $association = Record\ItemProject::referenceAssociation($kind);
        $class = $em->getClassMetadata(Record\ItemProject::class)->getAssociationTargetClass($association);
        $link->{$association} = $em->getReference($class, $sameId);
        $em->persist($link);
        $em->flush();
        verify($link->items_id === $sameId, 'Native owning graph generates a wide legacy identity: ' . $kind);
        $row = $connection->fetchAssociative('SELECT projects_id, ' . $selection['column'] . ', items_id FROM ' . $table . ' WHERE id = ?', [$link->id]);
        verify((int)$row['projects_id'] === $owner && (int)$row[$selection['column']] === $sameId && (int)$row['items_id'] === $sameId, 'Container and subject identities stay independent: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['projects_id' => $owner, 'itemtype' => $kind, $selection['column'] => 999999999]), 'Missing subject is rejected: ' . $kind);
        $reject(static fn () => $connection->insert($table, ['projects_id' => $owner, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Duplicate link is rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Referenced subject cannot bypass application purge: ' . $kind);
        $link->{$association} = $em->getReference($class, $sameId + 1);
        $em->flush();
        verify($link->items_id === $sameId + 1 && $link->projects->id === $owner, 'Native update retargets only the subject: ' . $kind);
        $em->remove($link);
        $em->flush();
    }
    foreach ([[], ['itemtype' => null], ['itemtype' => 'PluginExampleAsset'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert($table, $invalid + ['projects_id' => $owner]), 'Missing, unknown, zero, wrong and multiple subject selections are rejected');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Owning graph operations leave no orphans');
} finally {
    $DB->rollBack();
}

// A completed former installation remains an installation, not an empty-db retry,
// when a newly appended history version is pending.
$oldVersion = Ledger::state($connection, ProjectAssets20261003::VERSION);
$baselineState = Ledger::state($connection, Baseline20261001::VERSION);
try {
    $connection->delete(LegacyToOrm::LEDGER, ['version' => ProjectAssets20261003::VERSION]);
    verify(!History::isInstalling($connection), 'Appended migration does not reopen a completed installation');
    $formerState = $baselineState;
    unset($formerState['installation_complete']);
    Ledger::save($connection, Baseline20261001::VERSION, $formerState);
    verify(!History::isInstalling($connection), 'Former four-version installation remains complete without a newer marker');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(GLPI_ROOT . '/bin/console') . ' db:install --no-interaction --config-dir=' . escapeshellarg(GLPI_CONFIG_DIR) . ($postgres ? ' --force' : '') . ' 2>&1';
    exec($command, $output, $status);
    verify($status !== 0 && (str_contains(implode("\n", $output), 'requires an empty schema') || str_contains(implode("\n", $output), 'already contains')), 'Actual installer refuses the existing schema with only the new migration pending');
} finally {
    Ledger::save($connection, ProjectAssets20261003::VERSION, $oldVersion);
    Ledger::save($connection, Baseline20261001::VERSION, $baselineState);
}

// Reconstruct this empty owned fixture as a populated, already widened legacy
// relation; every interrupted phase must resume without losing its scalar IDs.
verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Refuse to reconstruct a nonempty shared fixture table');
$restore = (new \itsmng\Database\BaselineSchema())->build($platform)->getTable($table);
$owner = $fixtures->create('glpi_projects');
$subjectProject = $fixtures->create('glpi_projects');
$computer = $fixtures->create('glpi_computers', ['id' => 950000571]);
$comment = "Historical project identity O'Reilly 日本語";
try {
    foreach (['columns', 'copy', 'projection', 'constraints'] as $interruptPhase) {
        $manager->dropTable($table);
        $legacy = (new Baseline20261001())->build($platform)->getTable($table);
        foreach (['id', 'projects_id', 'items_id'] as $field) {
            $legacy->getColumn($field)->setType(Type::getType('bigint'));
        }
        $legacy->getColumn('items_id')->setComment($comment);
        $legacy->addForeignKeyConstraint('glpi_projects', ['projects_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'fk_items_projects_projects_id');
        $manager->createTable($legacy);
        $connection->delete(LegacyToOrm::LEDGER, ['version' => ProjectAssets20261003::VERSION]);
        $connection->insert($table, ['id' => 501, 'projects_id' => $owner, 'itemtype' => 'Computer', 'items_id' => $computer]);
        $connection->insert($table, ['id' => 502, 'projects_id' => $owner, 'itemtype' => 'Project', 'items_id' => $subjectProject]);
        if ($interruptPhase === 'columns') {
            $journal = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(GLPI_ROOT . '/bin/console') . ' db:migrate --no-interaction --config-dir=' . escapeshellarg(GLPI_CONFIG_DIR) . ' 2>&1', $output, $status);
            $preview = implode("\n", $output);
            verify($status === 0 && str_contains($preview, 'Migration plan: project_assets') && str_contains($preview, 'subject_projects_id')
                && str_contains($preview, 'UPDATE glpi_items_projects SET') && str_contains($preview, 'No changes.'), 'Actual CLI previews appended owning columns, data conversion and constraints');
            verify($connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version') === $journal
                && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Actual appended migration preview changes neither ledger nor schema');
        }
        foreach ([['itemtype' => 'PluginExampleAsset', 'items_id' => $computer], ['itemtype' => 'Computer', 'items_id' => 999999999], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => null, 'items_id' => 0]] as $invalid) {
            $connection->insert($table, ['id' => 503, 'projects_id' => $owner] + $invalid);
            try {
                $migration->apply($connection);
                throw new LogicException('Invalid legacy project asset accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'project asset kinds') || str_contains($error->getMessage(), 'Invalid or unsupported'), 'Migration reports the invalid project relationship');
                if ($invalid['itemtype'] === 'PluginExampleAsset') {
                    verify(str_contains($error->getMessage(), '503') && str_contains($error->getMessage(), 'current appliances import CLI is unsafe'), 'Plugin diagnostic identifies the row and avoids unsafe current import guidance');
                }
            }
            verify(Ledger::state($connection, ProjectAssets20261003::VERSION) === null && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Invalid preflight changes neither schema nor migration journal');
            $connection->delete($table, ['id' => 503]);
        }
        if ($interruptPhase === 'columns') {
            $connection->executeStatement('ALTER TABLE ' . $table . ' ADD subject_projects_id BIGINT NULL');
            $connection->update($table, ['subject_projects_id' => $owner], ['id' => 502]);
            try {
                $migration->apply($connection);
                throw new LogicException('Container project silently replaced the legacy subject');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'Canonical and legacy typed item references disagree: glpi_items_projects.subject_projects_id'), 'Partial canonical subject disagreement reports the separate Project role');
            }
            verify(Ledger::state($connection, ProjectAssets20261003::VERSION) === null && !$manager->introspectTable($table)->hasColumn('computers_id'), 'Partial Project subject disagreement refuses before further DDL or journal creation');
            $connection->update($table, ['subject_projects_id' => $subjectProject], ['id' => 502]);
        }
        try {
            $migration->apply($connection, static function (string $phase) use ($interruptPhase): void {
                if ($phase === $interruptPhase) {
                    throw new RuntimeException('Injected project phase interruption');
                }
            });
            throw new LogicException('Phase interruption did not execute');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Injected project phase interruption', 'The real migration surfaces interruption: ' . $interruptPhase);
        }
        verify($postgres ? Ledger::state($connection, ProjectAssets20261003::VERSION) === null : !Ledger::state($connection, ProjectAssets20261003::VERSION)['complete'], 'Interrupted PostgreSQL rolls back; MySQL retains the owned incomplete journal');
        $migration->apply($connection);
        $rows = $connection->fetchAllAssociative('SELECT id, projects_id, itemtype, computers_id, subject_projects_id, items_id FROM ' . $table . ' ORDER BY id');
        verify(count($rows) === 2 && (int)$rows[0]['computers_id'] === $computer && (int)$rows[0]['items_id'] === $computer && (int)$rows[1]['subject_projects_id'] === $subjectProject && (int)$rows[1]['items_id'] === $subjectProject && (int)$rows[1]['projects_id'] === $owner, 'Every retry preserves independent populated identities: ' . $interruptPhase);
        $projection = $manager->introspectTable($table)->getColumn('items_id');
        verify($projection->getComment() === $comment && !$projection->getNotnull() && $projection->getDefault() === null, 'Retry preserves comments and the generated nullable projection: ' . $interruptPhase);
        verify(count($manager->listTableForeignKeys($table)) === 36, 'Retry installs every owning FK: ' . $interruptPhase);
        verify($migration->apply($connection) === [] && $migration->plan($connection) === [], 'Completed new version is idempotent');
    }
} finally {
    $manager->dropTable($table);
    $manager->createTable($restore);
    $connection->executeStatement(ProjectAssets20261003::checkSql($table));
    Ledger::save($connection, ProjectAssets20261003::VERSION, $oldVersion);
    $connection->delete('glpi_projects', ['id' => $owner]);
    $connection->delete('glpi_projects', ['id' => $subjectProject]);
    $connection->delete('glpi_computers', ['id' => $computer]);
    $DB->clearSchemaCache();
}
verify((new SchemaCheck())->differences($connection) === [], 'Fixture cleanup restores the entire required schema');
echo $DB->getProvider() . ": thirty-five owning project subjects, separate Project roles, wide native graphs, FK/CHECK/duplicate rejection, frozen populated phased retry, comment preservation, plugin preflight and actual installer refusal passed.\n";
