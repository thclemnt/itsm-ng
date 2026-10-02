<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ITILDocumentAccess;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\NotificationRecipientRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/notification-recipients.php /path/to/test-config\n");
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
class CapturedITILRecipients extends NotificationTargetCommonITILObject
{
    public array $captured = [];
    public function addToRecipientsList(array $data)
    {
        $this->captured[] = $data;
    }
}
class LifecycleITILRecipients extends NotificationTargetCommonITILObject
{
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$fixtures = new FixtureRecords($DB);
$DB->beginTransaction();
try {
    $stamp = 'Recipients ' . bin2hex(random_bytes(5));
    $parent = $fixtures->create('glpi_entities', ['name' => $stamp]);
    $child = $fixtures->create('glpi_entities', ['name' => $stamp . ' child', 'entities_id' => $parent]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $stamp . ' foreign']);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactive_entity'] = $child;
    $_SESSION['glpiactiveentities'] = [$child];
    $_SESSION['glpiactiveentities_string'] = (string)$child;
    $private = $fixtures->create('glpi_profiles', ['name' => $stamp . ' private', 'interface' => 'central']);
    $public = $fixtures->create('glpi_profiles', ['name' => $stamp . ' public', 'interface' => 'central']);
    $helpdesk = $fixtures->create('glpi_profiles', ['name' => $stamp . ' helpdesk', 'interface' => 'helpdesk']);
    foreach ([$private, $helpdesk] as $profile) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'followup', 'rights' => ITILFollowup::SEEPRIVATE]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'ticket', 'rights' => Ticket::READALL]);
    }
    $users = [];
    foreach (['manager', 'worker', 'helpdesk', 'foreign', 'nonrecursive', 'inactive', 'deleted', 'ungranted'] as $label) {
        $users[$label] = $fixtures->create('glpi_users', ['name' => $stamp . ' ' . $label, 'language' => 'fr_FR', 'is_active' => $label !== 'inactive', 'is_deleted' => $label === 'deleted']);
        $fixtures->create('glpi_useremails', ['users_id' => $users[$label], 'email' => $label . '@example.test', 'is_default' => true]);
    }
    $grant = static fn ($user, $profile, $entity, $recursive = false) => $fixtures->create('glpi_profiles_users', ['users_id' => $users[$user], 'profiles_id' => $profile, 'entities_id' => $entity, 'is_recursive' => $recursive]);
    $grant('manager', $private, $parent, true);
    $grant('manager', $private, $child);
    $grant('worker', $public, $child);
    $grant('helpdesk', $helpdesk, $child);
    $grant('foreign', $private, $foreign);
    $grant('nonrecursive', $private, $parent);
    $grant('inactive', $private, $child);
    $grant('deleted', $private, $child);
    $group = $fixtures->create('glpi_groups', ['name' => $stamp, 'entities_id' => $child, 'is_notify' => true]);
    $muted = $fixtures->create('glpi_groups', ['name' => $stamp . ' muted', 'entities_id' => $child, 'is_notify' => false]);
    foreach (['manager', 'worker', 'foreign', 'nonrecursive'] as $label) {
        foreach ([$group, $muted] as $id) {
            $fixtures->create('glpi_groups_users', ['groups_id' => $id, 'users_id' => $users[$label], 'is_manager' => $label === 'manager']);
        }
    }
    $recipientRepo = static fn () => new NotificationRecipientRepository(Orm::create($DB));
    $notification = $fixtures->create('glpi_notifications', ['name' => $stamp . ' local notification', 'entities_id' => $child, 'itemtype' => 'Ticket', 'event' => 'new']);
    $outsideNotification = $fixtures->create('glpi_notifications', ['name' => $stamp . ' outside notification', 'entities_id' => $foreign, 'itemtype' => 'Ticket', 'event' => 'new']);
    foreach ([Notification::GROUP_TYPE, Notification::SUPERVISOR_GROUP_TYPE] as $role) {
        foreach ([$notification, $outsideNotification] as $id) {
            $fixtures->create('glpi_notificationtargets', ['notifications_id' => $id, 'type' => $role, 'items_id' => $group]);
        }
    }
    $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => Notification::USER_TYPE, 'items_id' => $group]);
    $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => Notification::GROUP_TYPE, 'items_id' => $muted]);
    $groupObject = new Group();
    verify($groupObject->getFromDB($group), 'Load notification group');
    $notificationScope = getEntitiesRestrictCriteria(Notification::getTable(), '', '', true);
    verify(NotificationTarget::countForGroup($groupObject) === 2, 'Group notification count retains role and entity restrictions');
    verify(array_column($recipientRepo()->notificationsForGroup($group, $notificationScope), 'id') === [$notification, $notification], 'Group notification listing preserves target rows and excludes foreign entities');
    $ids = static function (array $rows): array {
        $ids = array_map('intval', array_column($rows, 'users_id'));
        sort($ids);
        return $ids;
    };
    foreach (['Ticket', 'Problem', 'Change'] as $type) {
        $table = getTableForItemType($type);
        $itemId = $fixtures->create($table, ['name' => $stamp . ' ' . $type, 'entities_id' => $child]);
        $item = new $type();
        $userTable = getTableForItemType($item->userlinkclass);
        $groupTable = getTableForItemType($item->grouplinkclass);
        $supplierTable = getTableForItemType($item->supplierlinkclass);
        $parentColumn = $item->getForeignKeyField();
        foreach (['manager', 'worker', 'helpdesk', 'foreign', 'nonrecursive', 'ungranted'] as $label) {
            $fixtures->create($userTable, [$parentColumn => $itemId, 'users_id' => $users[$label], 'type' => CommonITILActor::ASSIGN,
                'use_notification' => $label !== 'helpdesk', 'alternative_email' => $label === 'manager' ? 'override@example.test' : 'invalid']);
        }
        foreach (['anonymous@example.test', 'invalid'] as $email) {
            $fixtures->create($userTable, [$parentColumn => $itemId, 'users_id' => null, 'type' => CommonITILActor::ASSIGN, 'use_notification' => true, 'alternative_email' => $email]);
        }
        $fixtures->create($groupTable, [$parentColumn => $itemId, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
        $supplier = $fixtures->create('glpi_suppliers', ['name' => $stamp, 'email' => 'supplier@example.test']);
        $fixtures->create($supplierTable, [$parentColumn => $itemId, 'suppliers_id' => $supplier, 'type' => CommonITILActor::ASSIGN]);
        verify($item->getFromDB($itemId), 'Load ' . $type);
        $target = new CapturedITILRecipients($child, '', $item);
        $target->setMode('mailing');
        $profileScope = $target->getProfileJoinCriteria();
        $rows = $recipientRepo()->users(array_values($users), $profileScope);
        verify($ids($rows) === [$users['manager'], $users['worker'], $users['helpdesk'], $users['inactive'], $users['deleted']], 'Recursive/direct grants select unique users and leave account lifecycle to the target');
        verify($recipientRepo()->users([], $profileScope) === [], 'Empty recipient IDs select nobody');
        $bad = $profileScope;
        $bad['INNER JOIN']['glpi_profiles_users']['ON']['glpi_profiles_users'] = 'profiles_id';
        try {
            $recipientRepo()->users([$users['manager']], $bad);
            throw new LogicException('Incorrect join accepted');
        } catch (UnsupportedCriteria) {
        }
        $SQL_TOTAL_REQUEST = 0;
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $target->addLinkedUserByType(CommonITILActor::ASSIGN);
        verify($ids($target->captured) === [-1, $users['manager'], $users['worker']], 'Linked users keep notification opt-out, profile scope and anonymous email handling');
        verify(array_column($target->captured, 'email') === ['override@example.test', 'worker@example.test', 'anonymous@example.test'], 'Valid alternative email and invalid-email fallback');
        $target->captured = [];
        $target->addLinkedGroupSupervisorByType(CommonITILActor::ASSIGN);
        verify($ids($target->captured) === [$users['manager']], 'Group managers retain entity scope');
        $target->captured = [];
        $target->addLinkedGroupWithoutSupervisorByType(CommonITILActor::ASSIGN);
        verify($ids($target->captured) === [$users['worker']], 'Group non-managers retain entity scope');
        $target->captured = [];
        $target->addForGroup(0, $muted);
        verify($target->captured === [], 'Muted groups select nobody');
        $target->addForProfile($private);
        verify($ids($target->captured) === [$users['manager'], $users['manager'], $users['inactive'], $users['deleted']], 'Profile recipients retain distinct grant entities and account lifecycle handling');
        $target->captured = [];
        $item->fields['users_id_recipient'] = $users['worker'];
        $target->addUserByField('users_id_recipient');
        verify($ids($target->captured) === [$users['worker']], 'Field-selected recipients retain profile scope');
        $target->addAdditionnalInfosForTarget();
        verify($target->addAdditionnalUserInfo(['users_id' => $users['manager']]) === ['show_private' => 1]
            && $target->addAdditionnalUserInfo(['users_id' => $users['worker']]) === ['show_private' => 0]
            && $target->addAdditionnalUserInfo(['users_id' => $users['foreign']]) === ['show_private' => 0]
            && $target->addAdditionnalUserInfo(['users_id' => -1]) === ['show_private' => 0], 'Private display checks are per-user and entity');
        verify($SQL_TOTAL_REQUEST === 0, 'Recipient and private-profile reads execute no adapter SQL');
        $target->captured = [];
        $target->addSupplier();
        verify(array_column($target->captured, 'email') === ['supplier@example.test'], 'Mapped supplier projection');
        $taskTable = getTableForItemType($type . 'Task');
        $task = $fixtures->create($taskTable, [$parentColumn => $itemId, 'users_id' => $users['worker'], 'users_id_tech' => $users['manager'], 'groups_id_tech' => $group]);
        $target->captured = [];
        $target->addTaskAuthor(['task_id' => $task]);
        $target->addTaskAssignUser(['task_id' => $task]);
        verify($ids($target->captured) === [$users['manager'], $users['worker']], 'Mapped task author and technician');
        $target->captured = [];
        $target->addTaskAssignGroup(['task_id' => $task]);
        verify($ids($target->captured) === [$users['manager'], $users['worker']], 'Mapped task group includes scoped managers and members');
        $target->captured = [];
        $target->addTaskAssignGroup(['task_groups_id_tech' => $group]);
        verify($ids($target->captured) === [$users['manager'], $users['worker']], 'Deleted task group snapshot retains recipients');
        $target->captured = [];
        $target->addTaskAuthor(['task_users_id' => $users['worker']]);
        $target->addTaskAssignUser(['task_users_id_tech' => $users['manager']]);
        verify($ids($target->captured) === [$users['manager'], $users['worker']], 'Deleted task snapshots retain scoped recipients');
        $followup = $fixtures->create('glpi_itilfollowups', ['itemtype' => $type, 'items_id' => $itemId, 'users_id' => $users['worker']]);
        $target->captured = [];
        $target->addFollowupAuthor(['followup_id' => $followup]);
        verify($ids($target->captured) === [$users['worker']], 'Mapped followup author');
        if ($type !== 'Problem') {
            $validation = $fixtures->create(getTableForItemType($type . 'Validation'), [$parentColumn => $itemId, 'users_id' => $users['worker'], 'users_id_validate' => $users['manager']]);
            $target->captured = [];
            $target->addValidationApprover(['validation_id' => $validation]);
            $target->addValidationRequester(['validation_id' => $validation]);
            verify($ids($target->captured) === [$users['manager'], $users['worker']], 'Mapped validation recipients');
        }
        $privateTarget = new CapturedITILRecipients($child, '', $item, ['sendprivate' => 1]);
        $privateRows = $recipientRepo()->users([$users['manager'], $users['worker'], $users['helpdesk']], $privateTarget->getProfileJoinCriteria());
        verify($ids($privateRows) === [$users['manager']], 'Private recipient eligibility requires central private-followup rights');
        $realTarget = new LifecycleITILRecipients($child, '', $item);
        $realTarget->setMode('ajax')->setEvent(NotificationEventAjax::class);
        foreach (['manager', 'worker', 'inactive', 'deleted', 'foreign'] as $label) {
            $realTarget->addToRecipientsList(['users_id' => $users[$label], 'language' => 'fr_FR']);
        }
        verify(count($realTarget->target) === 2, 'Real target excludes inactive/deleted/foreign recipients');
        $document = $fixtures->create('glpi_documents', ['name' => $stamp . ' document']);
        $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => $type, 'items_id' => $itemId, 'timeline_position' => CommonITILObject::TIMELINE_LEFT]);
        $inline = $fixtures->create('glpi_documents', ['name' => $stamp . ' inline']);
        $fixtures->create('glpi_documents_items', ['documents_id' => $inline, 'itemtype' => $type, 'items_id' => $itemId, 'timeline_position' => CommonITILObject::NO_TIMELINE]);
        $hidden = $fixtures->create('glpi_documents', ['name' => $stamp . ' private attachment']);
        $privateTask = $fixtures->create($taskTable, [$parentColumn => $itemId, 'users_id' => $users['manager'], 'is_private' => true]);
        $fixtures->create('glpi_documents_items', ['documents_id' => $hidden, 'itemtype' => $type . 'Task', 'items_id' => $privateTask]);
        $documents = static fn (ITILDocumentAccess $access) => (new DocumentRepository(Orm::create($DB)))->notificationDocuments($type, $itemId, $access);
        $access = new ITILDocumentAccess($users['worker'], true, false, true, true, false);
        verify(array_column($documents($access), 'id') === [$document], 'Template documents exclude inline and inaccessible private task attachments');
        $access = new ITILDocumentAccess($users['manager'], true, false, true, true, false);
        verify(array_column($documents($access), 'id') === [$document, $hidden], 'Private task author retains access to its attachment');
    }
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
}
echo $DB->getProvider() . ": mapped notification recipients, profile/privacy scope, anonymous email, group managers, child authors and template attachments passed\n";
