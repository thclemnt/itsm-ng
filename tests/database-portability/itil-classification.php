<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\V220\NullableReferences;
use itsmng\Database\Migration\V220\ReferenceHistory;

use itsmng\Database\ForeignKeys;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/itil-classification.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
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
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $storage = new \itsmng\Database\MappedStorage($DB);
    foreach (ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Classification original ' . $table . '.' . $column]);
            $replacement = $fixtures->create($target, ['name' => 'Classification replacement ' . $table . '.' . $column]);
            $other = $fixtures->create($target, ['name' => 'Classification unrelated ' . $table . '.' . $column]);
            $extra = [];
            if ($table === 'glpi_itilfollowups' || $table === 'glpi_itilsolutions') {
                $extra = ['itemtype' => 'Ticket', 'items_id' => $fixtures->create('glpi_tickets')];
            }
            $id = $fixtures->create($table, [$column => $parent] + $extra);
            $otherId = $fixtures->create($table, [$column => $other] + $extra);
            $empty = $fixtures->create($table, [$column => null] + $extra);
            $storage->update($table, $empty, [$column => 0]);
            $item = getItemForItemtype(getItemTypeForTable($table));
            verify($item->getFromDB($empty) && $item->fields[$column] === null, 'Empty classification reference: ' . $table . '.' . $column);
            verify(count($item->find(['id' => $empty, $column => 0])) === 1, 'Legacy empty criteria');
            if ($item instanceof CommonITILTask || $item instanceof ITILFollowup) {
                verify($item->getFriendlyName() !== '', 'Unclassified tasks and followups retain their friendly names');
            }
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace classification metadata');
            verify($item->getFromDB($id) && (int)$item->fields[$column] === $replacement, 'Replacement applied: ' . $table . '.' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge classification metadata');
            verify($item->getFromDB($id) && $item->fields[$column] === null, 'Purge clears reference: ' . $table . '.' . $column);
            verify($item->getFromDB($otherId) && (int)$item->fields[$column] === $other, 'Unrelated metadata preserved');
        }
    }
    $repo = new \itsmng\Database\Repository\ITILClassificationRepository(\itsmng\Database\Orm::create($DB));
    $templateId = 100000;
    $templates = [];
    foreach (['TicketTemplate', 'ChangeTemplate', 'ProblemTemplate'] as $type) {
        $templates[$type] = $fixtures->create(getTableForItemType($type), ['id' => $templateId, 'name' => 'Classification template']);
    }
    $incident = $fixtures->create('glpi_itilcategories', ['name' => 'Only incident', 'completename' => 'Only incident', 'tickettemplates_id_incident' => $templateId]);
    $demand = $fixtures->create('glpi_itilcategories', ['name' => 'Only request', 'completename' => 'Only request', 'tickettemplates_id_demand' => $templateId]);
    $change = $fixtures->create('glpi_itilcategories', ['name' => 'Only change', 'completename' => 'Only change', 'changetemplates_id' => $templateId]);
    $problem = $fixtures->create('glpi_itilcategories', ['name' => 'Only problem', 'completename' => 'Only problem', 'problemtemplates_id' => $templateId]);
    $shared = $fixtures->create('glpi_itilcategories', ['name' => 'Shared category', 'completename' => 'Shared category', 'tickettemplates_id_incident' => $templateId, 'changetemplates_id' => $templateId]);
    $foreignEntity = (new Entity())->add(['name' => 'Classification foreign', 'entities_id' => 0]);
    $foreign = $fixtures->create('glpi_itilcategories', ['name' => 'Foreign category', 'completename' => 'Foreign category', 'entities_id' => $foreignEntity, 'tickettemplates_id_incident' => $templateId]);
    $scope = ['entities_id' => 0];
    verify(array_column($repo->categoriesForTemplate('TicketTemplate', $templateId, $scope), 'id') === [$incident, $demand, $shared], 'Ticket template matches only ticket associations in scope');
    verify(array_column($repo->categoriesForTemplate('ChangeTemplate', $templateId, $scope), 'id') === [$change, $shared], 'Change template identity does not collide with ticket or problem IDs');
    verify(array_column($repo->categoriesForTemplate('ProblemTemplate', $templateId, $scope), 'id') === [$problem], 'Problem template identity');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $template = new TicketTemplate();
    verify($template->getFromDB($templateId), 'Load ticket template');
    ob_start();
    ITILCategory::showForITILTemplate($template);
    $html = ob_get_clean();
    verify(str_contains($html, 'Only incident') && str_contains($html, 'Only request') && !str_contains($html, 'Only change') && !str_contains($html, 'Only problem') && !str_contains($html, 'Foreign category'), 'Template tab renders mapped associations with entity scope');
    verify(substr_count($html, '/pics/ok.png') === 3, 'Template tab marks only the selected template family for shared categories');
    $code = "Quoted O'Reilly 日本語";
    $category = $fixtures->create('glpi_itilcategories', ['name' => 'Code lookup', 'code' => $code]);
    verify(ITILCategory::getITILCategoryIDByCode(addslashes($code)) === $category, 'Bound category code preserves quotes and Unicode');
    $fixtures->create('glpi_itilcategories', ['name' => 'Ambiguous code', 'code' => $code]);
    verify(ITILCategory::getITILCategoryIDByCode(addslashes($code)) === -1, 'Duplicate category codes remain ambiguous');
    verify(ITILCategory::getITILCategoryIDByCode("missing' OR 1=1 --") === -1, 'Code lookup binds SQL-shaped input');
    $request = new RequestType();
    foreach (['mail', 'mailfollowup', 'helpdesk', 'followup'] as $source) {
        $field = 'is_' . $source . '_default';
        $first = $request->add(['name' => 'First ' . $source, $field => 1, 'is_active' => 1]);
        verify($first > 0 && RequestType::getDefault($source) === $first, 'Set request source default');
        $second = $request->add(['name' => 'Second ' . $source, $field => 1, 'is_active' => 1]);
        verify($second > 0 && RequestType::getDefault($source) === $second, 'Add replaces previous source default');
        verify($request->getFromDB($first) && (int)$request->fields[$field] === 0, 'Previous source default cleared');
        verify($request->update(['id' => $first, $field => 1]), 'Update request source default');
        verify(RequestType::getDefault($source) === $first, 'Updated default selected');
        verify($request->update(['id' => $first, 'is_active' => 0]), 'Disable request type');
        verify(RequestType::getDefault($source) === 0, 'Inactive defaults excluded');
    }
    verify(RequestType::getDefault("mail; DROP TABLE glpi_users") === 0, 'Invalid source rejected');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->categoriesForTemplate('TicketTemplate', $templateId, $scope);
    ITILCategory::getITILCategoryIDByCode(addslashes($code));
    RequestType::getDefault('helpdesk');
    $repo->clearOtherDefaults($first, ['is_helpdesk_default']);
    verify($SQL_TOTAL_REQUEST === 0, 'Classification reads and default updates use ORM');
    verify((new ForeignKeys())->audit($connection) === [], 'Classification graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NullableReferences(ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION'), 'ITIL classification');
$legacyId = null;
try {
    foreach (ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacyId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_tickets');
    $connection->insert('glpi_tickets', ['id' => $legacyId, 'name' => 'Legacy ITIL classification']);
    $plan = $migration->plan($connection);
    verify($plan['sql'] !== [] && array_sum($plan['counts']) > 0, 'ITIL classification migration plans DDL and normalization');
    verify((int)$connection->fetchOne('SELECT itilcategories_id FROM glpi_tickets WHERE id = ?', [$legacyId]) === 0, 'Plan preserves data');
    $connection->update('glpi_tickets', ['itilcategories_id' => 2147483647], ['id' => $legacyId]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ITIL classification');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_tickets')['itilcategories_id']->getNotnull(), 'Orphan refusal precedes all DDL');
    $connection->update('glpi_tickets', ['itilcategories_id' => 0], ['id' => $legacyId]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT itilcategories_id FROM glpi_tickets WHERE id = ?', [$legacyId]) === null, 'Legacy empty type becomes NULL');
    verify($migration->plan($connection) === ['sql' => [], 'counts' => []] && $migration->apply($connection) === [], 'ITIL classification migration is idempotent');
} finally {
    if ($legacyId !== null) {
        $connection->delete('glpi_tickets', ['id' => $legacyId]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": ITIL classification lifecycle, template scope, request defaults and migration passed.\n";
