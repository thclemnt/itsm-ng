<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\ProjectRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/project-notifications.php /path/to/test-config\n");
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
trait CaptureProjectRecipients
{
    public array $captured = [];
    public function addToRecipientsList(array $data)
    {
        $this->captured[] = $data;
    }
}
class CapturedProjectNotifications extends NotificationTargetProject
{
    use CaptureProjectRecipients;
}
class CapturedProjectTaskNotifications extends NotificationTargetProjectTask
{
    use CaptureProjectRecipients;
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$fixtures = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $owner = 900000041;
    $member = 900000042;
    $project = $fixtures->create('glpi_projects', ['id' => $owner, 'name' => 'Notification project']);
    $task = $fixtures->create('glpi_projecttasks', ['id' => $owner, 'projects_id' => $project, 'name' => 'Notification task']);
    $otherProject = $fixtures->create('glpi_projects', ['name' => 'Other project']);
    $otherTask = $fixtures->create('glpi_projecttasks', ['projects_id' => $otherProject]);
    $subtask = $fixtures->create('glpi_projecttasks', ['projecttasks_id' => $task, 'name' => 'Notification subtask']);
    $cost = $fixtures->create('glpi_projectcosts', ['projects_id' => $project, 'name' => 'Project expense', 'cost' => '12.5000']);
    $fixtures->create('glpi_projectcosts', ['projects_id' => $otherProject, 'name' => 'Outside expense', 'cost' => '999.0000']);
    $computer = $fixtures->create('glpi_computers', ['name' => 'Project asset', 'serial' => 'PROJ-SERIAL']);
    $fixtures->create('glpi_items_projects', ['projects_id' => $project, 'itemtype' => 'Computer', 'items_id' => $computer]);
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Project ticket']);
    $fixtures->create('glpi_itils_projects', ['projects_id' => $project, 'itemtype' => 'Ticket', 'items_id' => $ticket]);
    $fixtures->create('glpi_projecttasks_tickets', ['projecttasks_id' => $task, 'tickets_id' => $ticket]);
    $user = $fixtures->create('glpi_users', ['id' => $member, 'name' => 'Team user', 'language' => 'fr_FR']);
    $worker = $fixtures->create('glpi_users', ['name' => 'Team worker', 'language' => 'en_GB']);
    $outside = $fixtures->create('glpi_users', ['name' => 'Outside user']);
    foreach ([$user, $worker] as $id) {
        $fixtures->create('glpi_profiles_users', ['users_id' => $id, 'profiles_id' => $_SESSION['glpiactiveprofile']['id'], 'entities_id' => 0, 'is_recursive' => true]);
    }
    $group = $fixtures->create('glpi_groups', ['id' => $member, 'name' => 'Team group', 'is_notify' => true]);
    $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $user, 'is_manager' => true]);
    $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $worker, 'is_manager' => false]);
    $fixtures->create('glpi_contacts', ['id' => $member, 'name' => 'Team contact', 'email' => 'contact@example.test']);
    $fixtures->create('glpi_suppliers', ['id' => $member, 'name' => 'Team supplier', 'email' => 'supplier@example.test']);
    foreach (['glpi_projectteams' => ['projects_id', $project, $otherProject], 'glpi_projecttaskteams' => ['projecttasks_id', $task, $otherTask]] as $table => [$column, $id, $other]) {
        foreach (['User', 'Group', 'Contact', 'Supplier'] as $kind) {
            $fixtures->create($table, [$column => $id, 'itemtype' => $kind, 'items_id' => $member]);
        }
        $fixtures->create($table, [$column => $other, 'itemtype' => 'User', 'items_id' => $outside]);
    }
    $category = $fixtures->create('glpi_documentcategories', ['name' => 'Project documents']);
    $document = $fixtures->create('glpi_documents', ['name' => 'Project attachment', 'filename' => 'project.pdf', 'link' => 'https://example.test/project', 'documentcategories_id' => $category]);
    $taskDocument = $fixtures->create('glpi_documents', ['name' => 'Task attachment', 'filename' => 'task.pdf', 'is_deleted' => true]);
    foreach ([1, 2] as $position) {
        $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Project', 'items_id' => $project, 'timeline_position' => $position]);
    }
    $fixtures->create('glpi_documents_items', ['documents_id' => $taskDocument, 'itemtype' => 'ProjectTask', 'items_id' => $task]);
    $fixtures->create('glpi_documents_items', ['documents_id' => $taskDocument, 'itemtype' => 'Project', 'items_id' => $otherProject]);
    $repository = new ProjectRepository(Orm::create($DB));
    foreach (['User', 'Group', 'Contact', 'Supplier'] as $kind) {
        verify($repository->projectTeamMemberIds($project, $kind) === [$member], 'Project recipient identity: ' . $kind);
        verify($repository->taskTeamMemberIds($task, $kind) === [$member], 'Task recipient identity: ' . $kind);
    }
    try {
        $repository->projectTeamMemberIds($project, "User' OR 1=1 --");
        throw new RuntimeException('Unsupported recipient kind was accepted');
    } catch (InvalidArgumentException) {
    }
    foreach ([Project::class => CapturedProjectNotifications::class, ProjectTask::class => CapturedProjectTaskNotifications::class] as $kind => $targetClass) {
        $object = new $kind();
        verify($object->getFromDB($owner), 'Load notification owner');
        $target = new $targetClass(0, 'new', $object);
        $target->addTeamUsers();
        verify(array_map('intval', array_column($target->captured, 'users_id')) === [$user] && trim($target->captured[0]['language']) === 'fr_FR', 'User team notification preserves language and scope');
        foreach ([0 => [$user, $worker], 1 => [$user], 2 => [$worker]] as $role => $expected) {
            $target->captured = [];
            $target->addTeamGroups($role);
            $actual = array_map('intval', array_column($target->captured, 'users_id'));
            sort($actual);
            sort($expected);
            verify($actual === $expected, 'Group role recipients: ' . $role);
        }
        foreach (['addTeamContacts' => 'contact@example.test', 'addTeamSuppliers' => 'supplier@example.test'] as $method => $email) {
            $target->captured = [];
            $target->$method();
            verify(array_column($target->captured, 'email') === [$email] && $target->captured[0]['usertype'] === NotificationTarget::ANONYMOUS_USER, 'External team notification recipient');
        }
        $target->addDataForTemplate('new', ['additionnaloption' => ['usertype' => NotificationTarget::ANONYMOUS_USER]]);
        $expected = $kind === Project::class ? [$document, $document] : [$taskDocument];
        verify(array_map('intval', array_column($target->data['documents'], '##document.id##')) === $expected, 'Template retains document bindings and isolates owner type');
        verify($target->data['##' . strtolower($kind) . '.numberofdocuments##'] === count($expected), 'Template document count');
        verify($target->data['documents'][0]['##document.filename##'] === ($kind === Project::class ? 'project.pdf' : 'task.pdf'), 'Document metadata projection');
        verify($target->data['##' . strtolower($kind) . '.numberofteammembers##'] === 4, 'Template contains each scoped team kind');
        verify(array_map('intval', array_column($target->data['tickets'], '##ticket.id##')) === [$ticket], 'Template uses mapped ticket link');
        if ($kind === Project::class) {
            verify(array_column($target->data['items'], '##item.name##') === ['Project asset'], 'ITIL loop cannot leak its last kind into asset criteria');
            verify(array_column($target->data['costs'], '##cost.name##') === ['Project expense'], 'Template costs exclude other projects');
            verify((float)$target->data['##project.totalcost##'] === 12.5, 'Template totals use mapped cost rows');
            verify(array_column($target->data['tasks'], '##task.name##') === ['Notification task'], 'Template tasks exclude other projects and ownerless subtasks');
        } else {
            verify(array_column($target->data['tasks'], '##task.name##') === ['Notification subtask'], 'Template subtasks follow their task owner');
        }
    }
    $documents = new DocumentRepository(Orm::create($DB));
    verify($documents->documentsForItem('Project', 0) === [] && $documents->documentsForItem("Project' OR 1=1 --", $owner) === [], 'Document predicates are bound and empty owners stay empty');
    $before = $SQL_TOTAL_REQUEST;
    $repository->projectTeamMemberIds($project, 'User');
    $repository->taskTeamMemberIds($task, 'Group');
    $documents->documentsForItem('Project', $project);
    verify($SQL_TOTAL_REQUEST === $before, 'Warmed projections bypass the legacy query adapter');
} finally {
    $DB->rollback();
    $GLPI_CACHE = $savedCache;
}
echo $DB->getProvider() . ": project notification teams, group roles, external recipients, document tags, overlapping identities and ORM query boundary passed.\n";
