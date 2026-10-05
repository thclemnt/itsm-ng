<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/relation-endpoint-rights.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$depth = $connection->getTransactionNestingLevel();
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$connection->beginTransaction();
try {
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $software = $fixtures->create('glpi_softwares', ['name' => 'Relation global policy software']);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'number' => -1]);
    $monitor = $fixtures->create('glpi_monitors', ['name' => 'Relation global policy monitor']);
    $proposal = ['itemtype' => 'Monitor', 'items_id' => $monitor, 'softwarelicenses_id' => $license];
    $relation = static function (int $ownerRight, int $licenseRight) use ($proposal): array {
        $_SESSION['glpiactiveprofile']['monitor'] = $ownerRight;
        $_SESSION['glpiactiveprofile']['license'] = $licenseRight;
        // Software's form preguard is distinct from SoftwareLicense's actual rightname.
        $_SESSION['glpiactiveprofile']['software'] = READ;
        $model = new Item_SoftwareLicense();
        $input = $proposal;
        return [$model, $input, $model->can(-1, CREATE, $input)];
    };
    verify(SoftwareLicense::$rightname === 'license', 'The fixed licence declares its actual global right');
    $before = $records()->countMatching('glpi_items_softwarelicenses', []);
    [, , $allowed] = $relation(READ, $savedSession['glpiactiveprofile']['license']);
    verify($allowed, 'Original inherited administrator licence grant permits a visible read-only owner');
    [$readonly, , $allowed] = $relation(READ, READ);
    verify(!$allowed && !Monitor::canUpdate() && !SoftwareLicense::canUpdate(), 'Both genuinely read-only endpoints refuse public CREATE');
    verify(!$readonly->canCreateItem(), 'Loaded endpoint CREATE cannot bypass the fixed global policy');
    [, , $allowed] = $relation(READ, 0);
    verify(!$allowed, 'A fixed endpoint lacking visibility refuses public CREATE');
    [$ownerWritable, $ownerInput, $allowed] = $relation(READ | UPDATE, READ);
    verify($allowed, 'Writable owner and visible licence permit public CREATE');
    verify(!$ownerWritable->canRelationItem('canUpdateItem', 'canUpdate', true, true), 'Force-both requires the fixed licence global write policy');
    [$licenseWritable, $licenseInput, $allowed] = $relation(READ, READ | UPDATE);
    verify($allowed, 'Visible owner and writable licence permit public CREATE');
    verify(!$licenseWritable->canRelationItem('canUpdateItem', 'canUpdate', true, true), 'Force-both also requires the dynamic owner write policy');
    [$bothWritable, , $allowed] = $relation(READ | UPDATE, READ | UPDATE);
    verify($allowed && $bothWritable->canRelationItem('canUpdateItem', 'canUpdate', true, true), 'Force-both accepts two writable endpoints');
    [$view, , $allowed] = $relation(READ | UPDATE, 0);
    verify(!$allowed && !$view->canViewItem(), 'Loaded relation view requires the fixed endpoint global visibility too');
    [, , $allowed] = $relation(READ, READ);
    $_SESSION['glpiactiveprofile']['software'] = READ | UPDATE;
    $softwareOnly = $proposal;
    verify(!(new Item_SoftwareLicense())->can(-1, CREATE, $softwareOnly), 'Software UPDATE cannot substitute for licence UPDATE');
    verify($records()->countMatching('glpi_items_softwarelicenses', []) === $before, 'All authorization-only controls leave assignments unchanged');
    [$ownerWritable, $ownerInput, $allowed] = $relation(READ | UPDATE, READ);
    verify($allowed, 'Accepted owner-write command is authorized before public add');
    $id = $ownerWritable->add($ownerInput);
    verify($id > 0, 'Public lifecycle accepts an authorized owner-write assignment');
    $row = $records()->find('glpi_items_softwarelicenses', 'id', $id);
    verify($row['monitors_id'] === $monitor && $row['items_id'] === $monitor && $row['softwarelicenses_id'] === $license, 'Accepted assignment retains actual owning columns and generated projection');
    $_SESSION['glpiactiveprofile']['monitor'] = READ;
    $_SESSION['glpiactiveprofile']['license'] = READ;
    verify((new Item_SoftwareLicense())->can($id, READ), 'Both readable endpoints permit a stored relation read');
    foreach ([UPDATE, DELETE, PURGE] as $right) {
        verify(!(new Item_SoftwareLicense())->can($id, $right), 'Read-only endpoints refuse stored mutation ' . $right);
    }
    $_SESSION['glpiactiveprofile']['monitor'] = READ | UPDATE;
    $_SESSION['glpiactiveprofile']['license'] = READ | UPDATE;
    $missing = $proposal;
    $missing['softwarelicenses_id'] = 0;
    verify(!(new Item_SoftwareLicense())->can(-1, CREATE, $missing), 'A required fixed attachment cannot be omitted despite writable grants');

    // HAVE_VIEW roles remain view roles even in a forced endpoint check.
    $_SESSION = $savedSession;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $computer = $fixtures->create('glpi_computers', ['name' => 'Read-only project subject']);
    $project = $fixtures->create('glpi_projects', ['name' => 'Relation declared view role']);
    $_SESSION['glpiactiveprofile']['project'] = Project::READALL | UPDATE;
    $_SESSION['glpiactiveprofile']['computer'] = READ;
    $projectInput = ['projects_id' => $project, 'itemtype' => 'Computer', 'items_id' => $computer];
    $projectRelation = new Item_Project();
    verify($projectRelation->can(-1, CREATE, $projectInput), 'A declared view-only asset endpoint needs no global UPDATE');
    verify($projectRelation->canRelationItem('canUpdateItem', 'canUpdate', true, true), 'Force-both honors the actual declared view-only endpoint role');
    $_SESSION['glpiactiveprofile']['computer'] = 0;
    verify(!$projectRelation->canCreateItem() && !$projectRelation->canViewItem(), 'The declared view-only endpoint still requires real visibility');

    foreach ([0, $computer] as $identity) {
        $wrongKind = $projectInput;
        $wrongKind['itemtype'] = 'Ticket';
        $wrongKind['items_id'] = $identity;
        verify(!(new Item_Project())->can(-1, CREATE, $wrongKind), 'An unsupported mapped endpoint kind cannot become an empty or loaded attachment');
    }

    // Ticket OWN is a specialized write policy, not the global UPDATE bit.
    $_SESSION = $savedSession;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $actor = Session::getLoginUserID();
    $recipient = $fixtures->create('glpi_users', ['name' => 'Other relation ticket recipient']);
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Owned relation ticket', 'users_id_recipient' => $recipient, 'status' => Ticket::ASSIGNED]);
    $foreign = $fixtures->create('glpi_entities', ['name' => 'Invisible relation ticket entity', 'entities_id' => 0]);
    $hiddenTicket = $fixtures->create('glpi_tickets', ['entities_id' => $foreign, 'name' => 'Hidden owned relation ticket', 'users_id_recipient' => $recipient, 'status' => Ticket::ASSIGNED]);
    foreach ([$ticket, $hiddenTicket] as $parent) {
        verify((new Ticket_User())->add(['tickets_id' => $parent, 'users_id' => $actor, 'type' => CommonITILActor::ASSIGN, '_disablenotif' => true]) > 0, 'Prepare actual assigned actor before reducing rights');
    }
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::OWN | Ticket::READASSIGN;
    $_SESSION['glpiactiveprofile']['user'] = 0;
    $ownedTicket = new Ticket();
    verify(!Session::haveRight('ticket', UPDATE) && Ticket::canUpdate() && $ownedTicket->can($ticket, UPDATE), 'An assigned actor can write under the real specialized OWN policy');
    verify(!User::canView() && !User::canUpdate() && Ticket_User::$checkItem_2_Rights === CommonDBConnexity::DONT_CHECK_ITEM_RIGHTS, 'The real recipient role deliberately excludes user global grants');
    $actorInput = ['tickets_id' => $ticket, 'users_id' => $actor, 'type' => CommonITILActor::OBSERVER, '_disablenotif' => true];
    $actorsBefore = $records()->countMatching('glpi_tickets_users', []);
    $actorRelation = new Ticket_User();
    verify($actorRelation->can(-1, CREATE, $actorInput), 'Specialized Ticket OWN and recipient DONT_CHECK permit actual relation CREATE');
    $observer = $actorRelation->add($actorInput);
    verify($observer > 0 && (new Ticket_User())->can($observer, READ), 'Public actor lifecycle and relation read retain DONT_CHECK semantics');
    $actorRow = $records()->find('glpi_tickets_users', 'id', $observer);
    verify($actorRow['tickets_id'] === $ticket && $actorRow['users_id'] === $actor, 'Accepted actor binds its real parent and recipient');
    $hiddenInput = ['tickets_id' => $hiddenTicket, 'users_id' => $actor, 'type' => CommonITILActor::OBSERVER];
    verify(!(new Ticket_User())->can(-1, CREATE, $hiddenInput), 'Specialized global rights cannot bypass the parent entity scope');
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READASSIGN;
    $readonlyActor = ['tickets_id' => $ticket, 'users_id' => $actor, 'type' => CommonITILActor::REQUESTER];
    verify(!(new Ticket_User())->can(-1, CREATE, $readonlyActor), 'A recipient DONT_CHECK role cannot manufacture writable parent rights');
    verify($records()->countMatching('glpi_tickets_users', []) === $actorsBefore + 1, 'Refused scoped and read-only actor controls write no extra rows');
    $_SESSION['glpiactiveprofile']['ticket'] = Ticket::OWN | Ticket::READASSIGN;
    $anonymousInput = ['tickets_id' => $ticket, 'users_id' => null, 'type' => CommonITILActor::OBSERVER, 'alternative_email' => 'relation-anonymous@example.invalid', '_disablenotif' => true];
    $anonymousRelation = new Ticket_User();
    verify($anonymousRelation->can(-1, CREATE, $anonymousInput), 'The actual anonymous-email attachment policy remains accepted');
    $anonymous = $anonymousRelation->add($anonymousInput);
    verify($anonymous > 0 && $records()->find('glpi_tickets_users', 'id', $anonymous)['users_id'] === null, 'Anonymous public actor retains its nullable recipient');
    $invalidAnonymous = ['tickets_id' => $ticket, 'users_id' => null, 'type' => CommonITILActor::REQUESTER];
    verify(!(new Ticket_User())->can(-1, CREATE, $invalidAnonymous), 'A missing mandatory recipient without alternate email remains refused');

    $zeroAnonymous = $anonymousInput;
    $zeroAnonymous['users_id'] = 0;
    $zeroAnonymous['alternative_email'] = 'relation-zero-anonymous@example.invalid';
    $zeroRelation = new Ticket_User();
    verify($zeroRelation->can(-1, CREATE, $zeroAnonymous), 'Legacy zero anonymous-email attachment remains accepted');
    $zeroId = $zeroRelation->add($zeroAnonymous);
    verify($zeroId > 0 && $records()->find('glpi_tickets_users', 'id', $zeroId)['users_id'] === null, 'Legacy zero public actor converges on its nullable recipient');
    $missingRecipient = $anonymousInput;
    $missingRecipient['users_id'] = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_users') + 100;
    verify(!(new Ticket_User())->can(-1, CREATE, $missingRecipient), 'Alternative email cannot excuse a supplied nonzero missing recipient');

    // Optional creation-form attachment does not authorize an invalid database insert.
    $_SESSION = $savedSession;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $notification = $fixtures->create('glpi_notifications', ['itemtype' => 'Ticket', 'event' => 'new', 'name' => 'Relation optional template form']);
    $optional = ['notifications_id' => $notification, 'notificationtemplates_id' => 0, 'mode' => Notification_NotificationTemplate::MODE_MAIL];
    verify(!(Notification_NotificationTemplate::$mustBeAttached_2) && (new Notification_NotificationTemplate())->can(-1, CREATE, $optional), 'The actual optional template attachment still permits its creation form');
    $missingParent = $optional;
    $missingParent['notifications_id'] = 0;
    verify(!(new Notification_NotificationTemplate())->can(-1, CREATE, $missingParent), 'The same form retains its required notification attachment');
    $invalidTemplate = $optional;
    $invalidTemplate['notificationtemplates_id'] = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_notificationtemplates') + 100;
    verify(!(new Notification_NotificationTemplate())->can(-1, CREATE, $invalidTemplate), 'An optional creation form cannot excuse a supplied nonzero missing template');
    $rootRelation = new Entity_RSSFeed();
    $rootRelation->fields['entities_id'] = 0;
    $rootItem = null;
    verify($rootRelation->canConnexityItem('canUpdateItem', 'canUpdate', CommonDBConnexity::DONT_CHECK_ITEM_RIGHTS, 'Entity', 'entities_id', $rootItem)
        && $rootItem instanceof Entity && (int)$rootItem->getID() === 0, 'An actual root entity identity zero resolves before empty-selection handling');
    verify($connection->getTransactionNestingLevel() === $depth + 1, 'Public controls preserve the owned transaction');
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
}
verify($connection->getTransactionNestingLevel() === $depth, 'Fixture rollback restores original transaction depth');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after tests');
echo $DB->getProvider() . ": fixed endpoint globals, one-write/view roles, force-both, Ticket OWN, recipient DONT_CHECK, scope and optional attachments passed.\n";
