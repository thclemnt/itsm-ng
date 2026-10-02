<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RelationshipLifecycleRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/relationship-lifecycle.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $repo = fn (): RelationshipLifecycleRepository => new RelationshipLifecycleRepository(Orm::create($DB));
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $index = static fn (string $table): ?string => ($model = getItemForItemtype(getItemTypeForTable($table))) ? $model->getIndexName() : null;
    $resolve = static fn (string $type): ?string => ($model = getItemForItemtype($type)) && $model->isEntityAssign() ? EntityRegistry::tables()[$model->getTable()] : null;
    $prefix = 'Relationship lifecycle ' . bin2hex(random_bytes(5));
    $entity = $fixtures->create('glpi_entities', ['entities_id' => 0, 'name' => $prefix]);
    $_SESSION['glpiactiveentities'][] = $entity;

    $project = $fixtures->create('glpi_projects', ['name' => $prefix]);
    $replacement = $fixtures->create('glpi_projects', ['name' => $prefix . ' replacement']);
    if ($read('glpi_projecttasks', $project) === null) {
        $fixtures->create('glpi_projecttasks', ['id' => $project, 'projects_id' => $replacement]);
    }
    $projectKanban = $fixtures->create('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $project, 'users_id' => Session::getLoginUserID()]);
    $taskKanban = $fixtures->create('glpi_items_kanbans', ['itemtype' => 'ProjectTask', 'items_id' => $project, 'users_id' => Session::getLoginUserID()]);
    $selections = iterator_to_array($repo()->replacements('glpi_projects', $project, $project, 'Project', $index), false);
    $kanbanSelection = array_values(array_filter($selections, static fn (array $selection): bool => $selection['table'] === 'glpi_items_kanbans'));
    verify(count($kanbanSelection) === 1 && $kanbanSelection[0]['column'] === 'items_id' && array_map('intval', $kanbanSelection[0]['ids']) === [$projectKanban] && !$kanbanSelection[0]['physical'], 'Polymorphic replacement selects one ID/type pair, never the discriminator column');
    $model = new Project();
    verify($model->getFromDB($project), 'Load project');
    $model->input = ['_replace_by' => $replacement];
    $model->cleanRelationData();
    verify((int)$read('glpi_items_kanbans', $projectKanban)['items_id'] === $replacement && $read('glpi_items_kanbans', $projectKanban)['itemtype'] === 'Project', 'Public generic replacement preserves the discriminator');
    verify((int)$read('glpi_items_kanbans', $taskKanban)['items_id'] === $project && $read('glpi_items_kanbans', $taskKanban)['itemtype'] === 'ProjectTask', 'Same numeric ID of another type is untouched');

    $mail = $fixtures->create('glpi_authmails', ['name' => $prefix]);
    if ($read('glpi_authldaps', $mail) === null) {
        $fixtures->create('glpi_authldaps', ['id' => $mail, 'name' => $prefix]);
    }
    $mailReplacement = $fixtures->create('glpi_authmails', ['name' => $prefix . ' replacement']);
    $mailUser = $fixtures->create('glpi_users', ['name' => $prefix . ' mail', 'authtype' => Auth::MAIL, 'auths_id' => $mail]);
    $ldapUser = $fixtures->create('glpi_users', ['name' => $prefix . ' ldap', 'authtype' => Auth::LDAP, 'auths_id' => $mail]);
    $opaqueUser = $fixtures->create('glpi_users', ['name' => $prefix . ' opaque', 'authtype' => Auth::DB_GLPI, 'auths_id' => $mail]);
    $selections = iterator_to_array($repo()->replacements('glpi_authmails', $mail, $mail, 'AuthMail', $index), false);
    verify(count($selections) === 1 && $selections[0]['column'] === 'auths_id' && $selections[0]['physical']
        && array_map('intval', $selections[0]['ids']) === [$mailUser], 'Canonical source selection excludes another source branch and opaque legacy payload');
    $mailModel = new AuthMail();
    verify($mailModel->getFromDB($mail), 'Load authentication source');
    $mailModel->input = ['_replace_by' => $mailReplacement];
    $mailModel->cleanRelationData();
    verify((int)$read('glpi_users', $mailUser)['authmails_id'] === $mailReplacement
        && (int)$read('glpi_users', $ldapUser)['authldaps_id'] === $mail && (int)$read('glpi_users', $opaqueUser)['auth_source_code'] === $mail, 'Public source replacement updates only its owning association');

    $calendar = $fixtures->create('glpi_calendars', ['name' => $prefix, 'is_recursive' => true]);
    $segment = $fixtures->create('glpi_calendarsegments', ['calendars_id' => $calendar]);
    verify(iterator_to_array($repo()->replacements('glpi_calendars', $calendar, $calendar, 'Calendar', $index), false) === [], 'Model-managed segments are excluded from generic replacement');
    $slm = $fixtures->create('glpi_slms', ['calendars_id' => $calendar, 'entities_id' => $entity]);
    verify($repo()->hasOutsideEntities('glpi_calendars', $calendar, $calendar, 'Calendar', [0], $resolve), 'A typed child association in a foreign entity blocks recursion removal');
    verify(!$repo()->hasOutsideEntities('glpi_calendars', $calendar, $calendar, 'Calendar', [0, $entity], $resolve), 'Expanded scope admits the child');
    $calendarModel = new Calendar();
    verify($calendarModel->getFromDB($calendar) && !$calendarModel->canUnrecurs(), 'Public recursion follows actual child ownership');
    $selections = iterator_to_array($repo()->replacements('glpi_calendars', $calendar, $calendar, 'Calendar', $index), false);
    verify(count($selections) === 1 && $selections[0]['column'] === 'calendars_id' && $selections[0]['physical'] && array_map('intval', $selections[0]['ids']) === [$slm], 'Typed replacements use physical IDs and skip model-managed columns');

    $location = $fixtures->create('glpi_locations', ['name' => $prefix, 'is_recursive' => true]);
    $fixtures->create('glpi_locations', ['name' => $prefix . ' child', 'locations_id' => $location, 'entities_id' => $entity]);
    $locationModel = new Location();
    verify($locationModel->getFromDB($location) && !$locationModel->canUnrecurs(), 'Mapped self-parent children preserve tree recursion restriction');

    $appliance = $fixtures->create('glpi_appliances', ['name' => $prefix, 'is_recursive' => true]);
    $local = $fixtures->create('glpi_computers');
    $fixtures->create('glpi_monitors', ['id' => $local, 'entities_id' => $entity]);
    $fixtures->create('glpi_appliances_items', ['appliances_id' => $appliance, 'items_id' => $local, 'itemtype' => 'Computer']);
    $applianceModel = new Appliance();
    verify($applianceModel->getFromDB($appliance) && $applianceModel->canUnrecurs(), 'Virtual asset join pairs type with ID instead of a colliding foreign monitor');
    $foreign = $fixtures->create('glpi_computers', ['entities_id' => $entity, 'is_deleted' => true]);
    $fixtures->create('glpi_appliances_items', ['appliances_id' => $appliance, 'items_id' => $foreign, 'itemtype' => 'Computer']);
    verify(!$applianceModel->canUnrecurs(), 'Every actual linked asset is checked, including deleted foreign assets');
    verify(!$repo()->hasOutsideEntities('glpi_appliances', $appliance, $appliance, 'Appliance', [0, $entity], $resolve), 'Virtual asset scope uses the complete allowed entity set');

    $document = $fixtures->create('glpi_documents', ['entities_id' => $entity]);
    $monitorDocument = $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'items_id' => $local, 'itemtype' => 'Monitor', 'entities_id' => 0]);
    verify(!$repo()->hasOutsideEntities('glpi_computers', $local, $local, 'Computer', [0], $resolve), 'Foreign document for a colliding type does not block this computer');
    $computerDocument = $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'items_id' => $local, 'itemtype' => 'Computer', 'entities_id' => 0]);
    verify($repo()->hasOutsideEntities('glpi_computers', $local, $local, 'Computer', [0], $resolve), 'Reverse document scope uses the document owner despite a stale cached link entity');
    verify($repo()->hasDeclaredOutsideEntities('glpi_appliances', ['glpi_appliances_items' => 'appliances_id'], $appliance, 'Appliance', [0], $resolve), 'Declared plugin link goes through the mapped virtual asset join');
    try {
        $repo()->hasDeclaredOutsideEntities('glpi_appliances', ['unregistered_plugin_items' => 'appliances_id'], $appliance, 'Appliance', [0], $resolve);
        throw new LogicException('Unregistered plugin relation ignored');
    } catch (InvalidArgumentException) {
    }

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $DB->clearSchemaCache();
    $savedActiveEntity = $_SESSION['glpiactive_entity'];
    $_SESSION['glpiactive_entity'] = $entity;
    try {
        $emptyLicense = new SoftwareLicense();
        verify($emptyLicense->getEmpty() && $emptyLicense->fields['entities_id'] === $entity
            && $emptyLicense->fields['softwares_id'] === '' && $emptyLicense->fields['id'] === '', 'Empty mapped object retains public empty values and active entity without column queries');
    } finally {
        $_SESSION['glpiactive_entity'] = $savedActiveEntity;
    }
    $ids = static fn (array $items): array => array_map(static fn (CommonDBTM $item): int => $item->getID(), $items);
    verify($ids(CalendarSegment::getItemsAssociatedTo('Calendar', $calendar)) === [$segment]
        && CalendarSegment::getItemsAssociatedTo('Computer', $calendar) === [], 'Mapped fixed-parent discovery loads actual objects and rejects a different parent kind');
    verify($ids(Document_Item::getItemsAssociatedTo('Computer', $local)) === [$computerDocument]
        && $ids(Document_Item::getItemsAssociatedTo('Monitor', $local)) === [$monitorDocument], 'Mapped relation discovery pairs ID with type');
    $documentPeers = $ids(Document_Item::getItemsAssociatedTo('Document', $document));
    sort($documentPeers);
    $expectedPeers = [$monitorDocument, $computerDocument];
    sort($expectedPeers);
    verify($documentPeers === $expectedPeers, 'Mapped relation discovery also follows its owning document end');
    $applianceModel->canUnrecurs();
    $calendarModel->canUnrecurs();
    iterator_to_array($repo()->replacements('glpi_projects', $replacement, $replacement, 'Project', $index), false);
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped empty objects, association discovery, relationship selections and public recursion checks bypass adapter SQL');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned foreign keys');
    verify($read('glpi_calendarsegments', $segment) !== null, 'Recursion checks never mutate model-managed children');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": owning relationship cleanup, polymorphic replacement pairs, recursion scopes and virtual asset joins passed.\n";
