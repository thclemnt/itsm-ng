<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DropdownLifecycleRepository;
use itsmng\Database\Repository\KanbanRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/dropdown-lifecycle.php /path/to/test-config\n");
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
$relations = getDbRelations();
verify($relations === EntityRegistry::lifecycleRelations(), 'Core relation compatibility view is derived from owning metadata');
verify($relations['glpi_authldaps']['_glpi_users'] === 'auths_id' && $relations['glpi_authmails']['glpi_users'] === 'auths_id', 'Logical discriminator columns retain their branch-specific lifecycle policies');
verify(!isset($relations['_virtual_device']['glpi_documents_items'])
    && $relations['glpi_computers']['_glpi_documents_items'] === ['items_id', 'itemtype'], 'Document subject lifecycle links come from owning association metadata');
verify($relations['_virtual_device']['glpi_infocoms'] === ['items_id', 'itemtype'], 'Remaining virtual asset recursion links come from their ID property');
verify($relations['glpi_domains']['_glpi_domains_items'] === 'domains_id', 'Typed parent and polymorphic item sides remain separate');
verify($relations['glpi_profiles']['_glpi_dashboards'] === 'profileId', 'Quoted join column remains a canonical identifier');
verify($relations['glpi_projects']['_glpi_items_kanbans'] === ['items_id', 'itemtype']
    && !isset($relations['glpi_projects']['glpi_items_kanbans']), 'Kanban board lifecycle is model-managed in the metadata-derived compatibility view');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $repo = fn (): DropdownLifecycleRepository => new DropdownLifecycleRepository(Orm::create($DB));
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $prefix = 'Lifecycle ' . bin2hex(random_bytes(5));
    $child = $fixtures->create('glpi_entities', ['entities_id' => 0, 'name' => $prefix]);
    $sibling = $fixtures->create('glpi_entities', ['entities_id' => 0, 'name' => $prefix . ' sibling']);
    $location = $fixtures->create('glpi_locations', ['entities_id' => $child]);
    $calendar = new Calendar();
    verify($calendar->prepareInputForAdd(['locations_id' => $location]) === ['locations_id' => $location, 'entities_id' => $child], 'New location-based dropdown inherits owning entity');
    verify($calendar->prepareInputForUpdate(['locations_id' => $location, 'entities_id' => 0]) === ['locations_id' => $location, 'entities_id' => 0], 'Location update preserves chosen real root');
    verify($calendar->prepareInputForAdd(['locations_id' => 2147483647]) === ['locations_id' => 2147483647], 'Missing location does not fabricate ownership');
    $rootCalendar = $fixtures->create('glpi_calendars', ['name' => $prefix . ' recursive', 'entities_id' => 0, 'is_recursive' => true]);
    $fixtures->create('glpi_calendars', ['name' => $prefix . ' recursive', 'entities_id' => $sibling]);
    $lookup = ['name' => $prefix . ' recursive', 'entities_id' => $child];
    verify($calendar->findID($lookup) === $rootCalendar, 'Recursive ancestor is visible while sibling ownership is excluded');
    $lookup['entities_id'] = [];
    verify($calendar->findID($lookup) === -1, 'Empty entity scope matches no ownership');
    $localCalendar = $fixtures->create('glpi_calendars', ['name' => $prefix . ' local', 'entities_id' => $child]);
    $lookup = ['name' => $prefix . ' local', 'entities_id' => 0];
    verify($calendar->findID($lookup) === -1, 'Nonrecursive child is not visible from root');
    $lookup['entities_id'] = $child;
    verify($calendar->findID($lookup) === $localCalendar, 'Direct owner scope selects its local dropdown');

    $os = new OperatingSystem();
    foreach (["O'Reilly \\ 日本語 " . $prefix, 'NULL', 'null'] as $name) {
        $id = $fixtures->create('glpi_operatingsystems', ['name' => $name]);
        $lookup = ['name' => addslashes($name)];
        // The installed data may already contain an identical name.
        $expected = $repo()->findId('glpi_operatingsystems', $name, []);
        verify($os->findID($lookup) === $expected && $expected > 0 && $os->import($lookup) === $expected, 'Typed names, including literal NULL, reuse the existing dropdown');
    }
    $unused = $fixtures->create('glpi_calendars', ['name' => $prefix . ' unused']);
    $segment = $fixtures->create('glpi_calendarsegments', ['calendars_id' => $unused]);
    verify($calendar->getFromDB($unused) && !$calendar->isUsed(), 'Model-managed calendar children do not block their parent purge');
    $slm = $fixtures->create('glpi_slms', ['calendars_id' => $unused]);
    verify($calendar->isUsed(), 'Owning service-level association counts as dropdown usage');
    verify($calendar->delete(['id' => $unused, '_replace_by' => $rootCalendar], true), 'Public purge replaces automatic references and deletes model-managed children');
    verify($read('glpi_calendarsegments', $segment) === null && (int)$read('glpi_slms', $slm)['calendars_id'] === $rootCalendar, 'Derived lifecycle policy preserves both cleanup paths');

    $project = $fixtures->create('glpi_projects');
    if ($read('glpi_projecttasks', $project) === null) {
        $fixtures->create('glpi_projecttasks', ['id' => $project]);
    }
    $taskState = ['board' => 'task with overlapping identity'];
    $projectState = ['board' => 'project'];
    $neighborState = ['board' => 'neighboring project'];
    $boards = fn (): KanbanRepository => new KanbanRepository(Orm::create($DB));
    $fixtures->create('glpi_items_kanbans', ['itemtype' => 'ProjectTask', 'items_id' => $project, 'state' => json_encode($taskState, JSON_THROW_ON_ERROR)]);
    verify(!$repo()->isUsed('glpi_projects', $project, 'Project'), 'Colliding polymorphic IDs in another item type are excluded');
    verify($boards()->load('ProjectTask', $project, 0) === $taskState && $boards()->load('Project', $project, 0) === [], 'Kanban loading binds the item discriminator to its ID');
    $fixtures->create('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $project, 'state' => json_encode($projectState, JSON_THROW_ON_ERROR)]);
    $neighborProject = $fixtures->create('glpi_projects');
    $fixtures->create('glpi_items_kanbans', ['itemtype' => 'Project', 'items_id' => $neighborProject, 'state' => json_encode($neighborState, JSON_THROW_ON_ERROR)]);
    verify(!$repo()->isUsed('glpi_projects', $project, 'Project'), 'A matching model-managed Kanban board does not block generic usage checks');
    verify($boards()->load('Project', $project, 0) === $projectState
        && $boards()->load('ProjectTask', $project, 0) === $taskState
        && $boards()->load('Project', $neighborProject, 0) === $neighborState, 'Kanban loading preserves both discriminated and neighboring board identities');

    // These legacy subject fields currently declare unmanaged polymorphic
    // references. A future typed software ownership migration must update this
    // policy fixture together with its authoritative entity declarations.
    $software = $fixtures->create('glpi_softwares');
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software]);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software]);
    foreach ([['glpi_items_softwareversions', 'softwareversions_id', $version], ['glpi_items_softwarelicenses', 'softwarelicenses_id', $license]] as [$table, $ownerColumn, $owner]) {
        verify($relations['glpi_computers'][$table] === ['items_id', 'itemtype']
            && !isset($relations['glpi_computers']['_' . $table]), $table . ': current subject policy participates in generic usage');
        $computer = $fixtures->create('glpi_computers');
        if ($read('glpi_phones', $computer) === null) {
            $fixtures->create('glpi_phones', ['id' => $computer]);
        }
        $neighborComputer = $fixtures->create('glpi_computers');
        $fixtures->create($table, [$ownerColumn => $owner, 'itemtype' => 'Phone', 'items_id' => $computer]);
        verify(!$repo()->isUsed('glpi_computers', $computer, 'Computer'), $table . ': a real colliding Phone subject does not count as Computer usage');
        $fixtures->create($table, [$ownerColumn => $owner, 'itemtype' => 'Computer', 'items_id' => $computer]);
        verify($repo()->isUsed('glpi_computers', $computer, 'Computer'), $table . ': generic usage binds ID and discriminator together');
        verify(!$repo()->isUsed('glpi_computers', $neighborComputer, 'Computer'), $table . ': a matching discriminator with another ID does not count as usage');
    }

    $mail = $fixtures->create('glpi_authmails');
    $ldap = $fixtures->create('glpi_authldaps');
    $fixtures->create('glpi_users', ['name' => $prefix . ' opaque', 'authtype' => Auth::DB_GLPI, 'auths_id' => $mail]);
    verify(!$repo()->isUsed('glpi_authmails', $mail, 'AuthMail'), 'Opaque source code is not a mail-server relationship');
    $fixtures->create('glpi_users', ['name' => $prefix . ' ldap', 'authtype' => Auth::LDAP, 'auths_id' => $ldap]);
    verify(!$repo()->isUsed('glpi_authldaps', $ldap, 'AuthLDAP'), 'LDAP user cleanup remains application-managed');
    $fixtures->create('glpi_users', ['name' => $prefix . ' mail', 'authtype' => Auth::MAIL, 'auths_id' => $mail]);
    verify($repo()->isUsed('glpi_authmails', $mail, 'AuthMail'), 'Mail usage follows the typed association rather than a generated legacy selection');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo()->locationEntity($location);
    $repo()->findId('glpi_calendars', $prefix . ' local', ['entities_id' => $child]);
    $repo()->isUsed('glpi_calendars', $rootCalendar, 'Calendar');
    $repo()->isUsed('glpi_projects', $project, 'Project');
    $repo()->isUsed('glpi_computers', $computer, 'Computer');
    $boards()->load('Project', $project, 0);
    verify($SQL_TOTAL_REQUEST === 0, 'Dropdown lifecycle reads bypass legacy adapter execution');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'Lifecycle operations leave no orphaned FK relationships');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": owning lifecycle metadata, scoped literal imports, usage discriminators and public dropdown purge passed.\n";
