<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/deletion-atomicity.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
/** Verifies the real public read hook is called once after locking the source. */
class DeletionLoadProbe extends Group
{
    public int $loads = 0;

    public static function getTable($classname = null)
    {
        return Group::getTable();
    }

    public static function getType()
    {
        return Group::getType();
    }

    public function post_getFromDB()
    {
        ++$this->loads;
        parent::post_getFromDB();
    }
}

/** Test plugin transport records actual delivery without network side effects. */
class PluginOrm_delete_fixtureNotificationEventDeletionprobe
{
    public static array $deliveries = [];

    public static function canCron(): bool
    {
        return true;
    }

    public static function send(array $rows): void
    {
        global $DB;
        foreach ($rows as $row) {
            self::$deliveries[] = [
                'queue' => (int)$row['id'],
                'nesting' => $DB->getDoctrineConnection()->getTransactionNestingLevel(),
                'source_exists' => (new Group())->getFromDB($row['items_id']),
            ];
            (new QueuedNotification())->update(['id' => $row['id'], 'is_deleted' => 1]);
        }
    }
}

$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator fixture login');
$connection = $DB->getDoctrineConnection();
$savedConfig = $CFG_GLPI;
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'orm_delete_fixture']);
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$prefix = 'Deletion ' . bin2hex(random_bytes(5));
$records = fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$graphs = [];
$graph = function (int $entity = 0) use ($fixtures, $prefix, &$graphs): array {
    $group = new Group();
    $id = (int)$group->add(['name' => $prefix . ' source ' . count($graphs), 'entities_id' => $entity]);
    verify($id > 0 && $group->update(['id' => $id, 'comment' => $prefix]), 'Source creation includes audit history');
    $user = $fixtures->create('glpi_users', ['name' => $prefix . ' member ' . count($graphs)]);
    $membership = $fixtures->create('glpi_groups_users', ['groups_id' => $id, 'users_id' => $user]);
    $model = $fixtures->create('glpi_consumableitems', ['entities_id' => $entity, 'name' => $prefix]);
    $stock = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'items_id' => $id, 'itemtype' => 'Group', 'date_out' => '2026-10-02']);
    $computer = $fixtures->create('glpi_computers', ['entities_id' => $entity, 'groups_id' => $id]);
    return $graphs[] = compact('id', 'user', 'membership', 'model', 'stock', 'computer');
};
$snapshot = function (array $graph) use ($records, $read): array {
    return [
        'group' => $read('glpi_groups', $graph['id']),
        'membership' => $read('glpi_groups_users', $graph['membership']),
        'history' => $records()->matching('glpi_logs', ['items_id' => $graph['id'], 'itemtype' => 'Group'], ['id ASC']),
        'stock' => $read('glpi_consumables', $graph['stock']),
        'computer' => $read('glpi_computers', $graph['computer']),
    ];
};
$preHooks = 0;
$PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function () use (&$preHooks): void {
    ++$preHooks;
};
try {
    // Exercise real autocommit, where the historical partial-purge defect occurred.
    $autocommit = $graph();
    $target = (int)(new Group())->add(['name' => $prefix . ' stale']);
    verify((new Group())->delete(['id' => $target], true), 'Actually purge the replacement target');
    $before = $snapshot($autocommit);
    verify(count($before['history']) >= 2 && $before['membership'] !== null, 'Probe has histories and membership to preserve');
    $hooksBefore = $preHooks;
    verify(!$connection->isTransactionActive(), 'Public refusal starts outside a transaction');
    $source = new Group();
    $originalState = get_object_vars($source);
    verify(!$source->delete(['id' => $autocommit['id'], '_replace_by' => $target], true), 'Stale replacement refuses without an FK exception');
    verify($snapshot($autocommit) === $before && $preHooks === $hooksBefore, 'Invalid target leaves stock, assignments, histories and hooks untouched');
    verify(get_object_vars($source) === $originalState && !$connection->isTransactionActive(), 'Refusal restores model state and closes owned transaction');
    $DB->slave = true;
    try {
        verify(!$source->delete(['id' => $autocommit['id']], true) && $snapshot($autocommit) === $before, 'Read-connection public deletion remains refused');
    } finally {
        $DB->slave = false;
    }

    $DB->beginTransaction();
    try {
        $caller = $fixtures->create('glpi_groups', ['name' => $prefix . ' caller']);
        foreach ([$target, $autocommit['id'], -1, 'invalid', [], true] as $invalid) {
            verify(!$source->delete(['id' => $autocommit['id'], '_replace_by' => $invalid], true), 'Invalid replacement refuses inside caller transaction');
            verify($connection->getTransactionNestingLevel() === 1 && $read('glpi_groups', $caller) !== null && $snapshot($autocommit) === $before, 'Savepoint refusal preserves caller writes and source graph');
        }
        $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null;
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function ($item) use ($prefix): void {
            (new Group())->update(['id' => $item->fields['id'], 'comment' => $prefix . ' cancelled write']);
            Session::addMessageAfterRedirect('Should be rolled back');
            Session::addMessageAfterRedirect('Expected refusal diagnostic', false, ERROR);
            $item->input = false;
        };
        verify(!$source->delete(['id' => $autocommit['id']], true), 'Pre-purge hook cancellation returns false');
        verify($snapshot($autocommit) === $before && ($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? null) === ($messages[INFO] ?? null), 'Cancelled hook writes, history and success messages roll back');
        verify(in_array('Expected refusal diagnostic', $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [], true), 'Failure diagnostic remains available after cancelled operation');
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function ($item) use ($prefix): void {
            (new Group())->update(['id' => $item->fields['id'], 'comment' => $prefix . ' thrown write']);
            throw new RuntimeException('Expected lifecycle exception');
        };
        try {
            $source->delete(['id' => $autocommit['id']], true);
            throw new LogicException('Throwing hook succeeded');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Expected lifecycle exception', 'Original hook exception propagates');
        }
        verify($snapshot($autocommit) === $before && $connection->getTransactionNestingLevel() === 1, 'Exception rolls back only the owned savepoint');
        unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
        $PLUGIN_HOOKS['item_purge']['orm_delete_fixture'][Group::class] = static function (): void {
            throw new RuntimeException('Expected after-cleanup exception');
        };
        try {
            $source->delete(['id' => $autocommit['id']], true);
            throw new LogicException('Post-purge exception succeeded');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Expected after-cleanup exception', 'Post-purge exception remains observable');
        }
        verify($snapshot($autocommit) === $before, 'Exception after real cleanup restores membership, history, stock, relations and source');
        unset($PLUGIN_HOOKS['item_purge']['orm_delete_fixture']);
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function ($item) use ($target): void {
            $item->input['_replace_by'] = $target;
        };
        verify(!$source->delete(['id' => $autocommit['id']], true) && $snapshot($autocommit) === $before, 'Rewritten invalid hook target is revalidated before cleanup');
        unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
        $nested = $fixtures->create('glpi_groups', ['name' => $prefix . ' nested']);
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function ($item) use ($nested): void {
            if ((int)$item->getID() === $nested) {
                $item->input = false;
            } else {
                (new Group())->delete(['id' => $nested], true); // Deliberately ignored by a plugin.
            }
        };
        verify(!$source->delete(['id' => $autocommit['id']], true), 'Ignored nested cancellation still cancels the outer unit');
        verify($snapshot($autocommit) === $before && $read('glpi_groups', $nested) !== null, 'Both nested and outer graphs remain');
        unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
        $PLUGIN_HOOKS['pre_item_update']['orm_delete_fixture'][Computer::class] = static function ($item): void {
            $item->input = false;
        };
        verify(!$source->delete(['id' => $autocommit['id']], true), 'Refused actual child replacement cancels parent purge');
        verify($snapshot($autocommit) === $before, 'Refused cleanup update retains every source relationship');
        unset($PLUGIN_HOOKS['pre_item_update']['orm_delete_fixture']);

        $replacement = $fixtures->create('glpi_groups', ['name' => $prefix . ' replacement']);
        verify($source->delete(['id' => $autocommit['id'], '_replace_by' => $replacement], true), 'Valid group replacement purges publicly');
        verify($read('glpi_groups', $autocommit['id']) === null && $read('glpi_groups_users', $autocommit['membership']) === null, 'Valid purge deletes the source and membership');
        verify((int)$read('glpi_consumables', $autocommit['stock'])['groups_id'] === $replacement
            && (int)$read('glpi_computers', $autocommit['computer'])['groups_id'] === $replacement, 'Valid purge retargets typed consumable and asset owners');
        $DB->rollBack();
        verify($snapshot($autocommit) === $before && $read('glpi_groups', $caller) === null, 'Caller rollback restores the successfully released purge savepoint');
    } finally {
        if ($connection->isTransactionActive()) {
            $DB->rollBack();
        }
        $PLUGIN_HOOKS = $savedHooks;
    }

    // Actual persisted queue and plugin transport prove the physical-commit boundary.
    Notification_NotificationTemplate::registerMode('deletionprobe', 'Deletion probe', 'orm_delete_fixture');
    $CFG_GLPI['notifications_deletionprobe'] = true;
    $notificationGroup = $graph();
    $queued = [];
    $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function ($item) use (&$queued): void {
        $id = (int)(new QueuedNotification())->add([
            'itemtype' => 'Group', 'items_id' => $item->getID(), 'mode' => 'deletionprobe',
            'send_time' => '2026-01-01 00:00:00', 'name' => 'Deletion probe',
        ]);
        verify($id > 0, 'Public notification creation occurs inside the lifecycle');
        $queued[] = $id;
        QueuedNotification::forceSendFor('Group', $item->getID());
        QueuedNotification::forceSendFor('Group', $item->getID());
        verify(PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries === [], 'Repeated force-send does not deliver inside a database unit');
    };
    $notifying = new Group();
    $notifying->notificationqueueonaction = true;
    verify($notifying->delete(['id' => $notificationGroup['id']], true), 'Guard-owned notifying purge succeeds');
    verify(count(PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries) === 1
        && PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries[0]['nesting'] === 0
        && !PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries[0]['source_exists'], 'Deduplicated transport sees committed source removal');
    PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries = [];
    $callerNotificationGroup = $graph();
    $DB->beginTransaction();
    try {
        verify($notifying->delete(['id' => $callerNotificationGroup['id']], true), 'Caller-owned notifying purge releases its savepoint');
        verify(PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries === []
            && !(bool)$read('glpi_queuednotifications', end($queued))['is_deleted'], 'Caller-owned unit leaves notification pending');
        $DB->rollBack();
        verify($read('glpi_groups', $callerNotificationGroup['id']) !== null
            && $read('glpi_queuednotifications', end($queued)) === null, 'Caller rollback restores source and removes uncommitted queue without delivery');
    } finally {
        if ($connection->isTransactionActive()) {
            $DB->rollBack();
        }
    }
    $DB->beginTransaction();
    verify($notifying->delete(['id' => $callerNotificationGroup['id']], true), 'Caller commit scenario purges');
    $DB->commit();
    verify(PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries === []
        && $read('glpi_queuednotifications', end($queued)) !== null, 'Outer caller commit retains queue for cron rather than flushing at savepoint release');
    QueuedNotification::forceSendFor('Group', $callerNotificationGroup['id']);
    verify(count(PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries) === 1
        && PluginOrm_delete_fixtureNotificationEventDeletionprobe::$deliveries[0]['nesting'] === 0, 'Normal delivery can process the committed retained queue');
    unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
    foreach ($queued as $id) {
        if ($read('glpi_queuednotifications', $id) !== null) {
            (new QueuedNotification())->delete(['id' => $id], true);
        }
    }

    $DB->beginTransaction();
    try {
        foreach ([[], ['_replace_by' => null], ['_replace_by' => 0]] as $empty) {
            $sourceGraph = $graph();
            verify((new Group())->delete(['id' => $sourceGraph['id']] + $empty, true), 'Absent, NULL and zero replacement preserve empty-selection policy');
            $stock = $read('glpi_consumables', $sourceGraph['stock']);
            verify($stock['groups_id'] === null && $stock['itemtype'] === null && $stock['date_out'] === null, 'Unreplaced consumables return to stock');
        }
        $loadGraph = $graph();
        $loadProbe = new DeletionLoadProbe();
        verify($loadProbe->delete(['id' => $loadGraph['id']], true) && $loadProbe->loads === 1, 'Source lock/reload preserves exactly one public post_getFromDB hook');
        $ticket = $fixtures->create('glpi_tickets', ['id' => 810000000000 + random_int(0, 1000000)]);
        $otherTicket = $fixtures->create('glpi_tickets', ['id' => $ticket + 1000001]);
        $satisfaction = $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $ticket]);
        $otherSatisfaction = $fixtures->create('glpi_ticketsatisfactions', ['tickets_id' => $otherTicket]);
        verify(!(new TicketSatisfaction())->delete(['tickets_id' => $ticket, '_replace_by' => $satisfaction], true)
            && $read('glpi_ticketsatisfactions', $satisfaction) !== null, 'Replacement resolves the public association index rather than a physical-ID collision');
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][TicketSatisfaction::class] = static function ($item) use ($otherTicket, $otherSatisfaction): void {
            $item->fields['id'] = $otherSatisfaction;
            $item->fields['tickets_id'] = $otherTicket;
            $item->input['tickets_id'] = $otherTicket;
        };
        verify(!(new TicketSatisfaction())->delete(['tickets_id' => $ticket], true)
            && $read('glpi_ticketsatisfactions', $satisfaction) !== null
            && $read('glpi_ticketsatisfactions', $otherSatisfaction) !== null, 'Hook cannot adopt an unlocked source through physical or public association identity');
        unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
        verify((new TicketSatisfaction())->delete(['tickets_id' => $ticket, '_replace_by' => $otherTicket], true)
            && $read('glpi_ticketsatisfactions', $satisfaction) === null
            && $read('glpi_ticketsatisfactions', $otherSatisfaction) !== null, 'Non-id public index locks and deletes the intended canonical record');
        $childEntity = $fixtures->create('glpi_entities', ['name' => $prefix . ' child', 'entities_id' => 0]);
        $siblingEntity = $fixtures->create('glpi_entities', ['name' => $prefix . ' sibling', 'entities_id' => 0]);
        $owned = $graph($childEntity);
        $sibling = $fixtures->create('glpi_groups', ['name' => $prefix . ' sibling', 'entities_id' => $siblingEntity, 'is_recursive' => true]);
        $ancestor = $fixtures->create('glpi_groups', ['name' => $prefix . ' ancestor', 'entities_id' => 0, 'is_recursive' => true]);
        $nonrecursive = $fixtures->create('glpi_groups', ['name' => $prefix . ' nonrecursive', 'entities_id' => 0]);
        $ownedBefore = $snapshot($owned);
        foreach ([$sibling, $nonrecursive] as $wrongScope) {
            verify(!(new Group())->delete(['id' => $owned['id'], '_replace_by' => $wrongScope], true) && $snapshot($owned) === $ownedBefore, 'Scope refuses sibling and nonrecursive ancestor replacements');
        }
        verify((new Group())->delete(['id' => $owned['id'], '_replace_by' => $ancestor], true), 'Recursive ancestor offered by actual selector remains a valid replacement');
        verify((int)$read('glpi_consumables', $owned['stock'])['groups_id'] === $ancestor, 'Ancestor replacement retains actual typed stock subject');

        $localRule = $fixtures->create('glpi_fieldunicities', ['name' => $prefix . ' local rule', 'entities_id' => $childEntity]);
        $globalRule = $fixtures->create('glpi_fieldunicities', ['name' => $prefix . ' global rule', 'entities_id' => null]);
        verify((new FieldUnicity())->delete(['id' => $localRule, '_replace_by' => $globalRule], true)
            && $read('glpi_fieldunicities', $globalRule) !== null, 'Property-declared global scope remains an eligible replacement for an owned dropdown');
        $parent = (int)(new Group())->add(['name' => $prefix . ' tree parent']);
        $child = (int)(new Group())->add(['name' => $prefix . ' tree child', 'groups_id' => $parent]);
        $grandchild = (int)(new Group())->add(['name' => $prefix . ' grandchild', 'groups_id' => $child]);
        verify(!(new Group())->delete(['id' => $parent, '_replace_by' => $grandchild], true), 'Actual descendant is refused without relying on a cached tree');
        verify((int)$read('glpi_groups', $child)['groups_id'] === $parent, 'Refused tree replacement never reparents children');
        verify(Toolbox::useCache(), 'Rollback hierarchy probe uses the actual shared cache');
        getSonsOf('glpi_groups', $parent);
        getAncestorsOf('glpi_groups', $grandchild);
        $PLUGIN_HOOKS['item_purge']['orm_delete_fixture'][Group::class] = static function () use ($child, $grandchild, $parent): void {
            $ancestors = getAncestorsOf('glpi_groups', $grandchild);
            $children = getSonsOf('glpi_groups', $parent);
            verify(!in_array($child, $ancestors) && !in_array($child, $children), 'Hook reads actual transaction-local hierarchy');
            throw new RuntimeException('Expected tree cache rollback');
        };
        try {
            (new Group())->delete(['id' => $child, '_replace_by' => $parent], true);
            throw new LogicException('Tree rollback probe succeeded');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Expected tree cache rollback', 'Failed tree lifecycle propagates original exception');
        }
        unset($PLUGIN_HOOKS['item_purge']['orm_delete_fixture']);
        verify(in_array($child, getAncestorsOf('glpi_groups', $grandchild))
            && in_array($child, getSonsOf('glpi_groups', $parent)), 'Rollback never publishes uncommitted hierarchy into shared external cache');
        verify((new Group())->delete(['id' => $child, '_replace_by' => $parent], true), 'Tree deletion supports valid ancestor reparenting');
        verify((int)$read('glpi_groups', $grandchild)['groups_id'] === $parent && (int)$read('glpi_groups', $grandchild)['level'] === 2, 'Public tree hooks preserve hierarchy and recompute descendant fields');
        $rootBefore = $read('glpi_entities', 0);
        $rootSession = $_SESSION;
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Entity::class] = static function ($item): void {
            $item->input['id'] = 1;
        };
        verify(!(new Entity())->delete(['id' => 0], true) && $read('glpi_entities', 0) === $rootBefore
            && $_SESSION === $rootSession, 'Pre-purge input rewrite cannot defeat protected locked root identity or change session state');
        unset($PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture']);
        verify(!(new Entity())->delete(['id' => 0], true) && $read('glpi_entities', 0) !== null, 'Real root entity remains protected');

        $globalUser = $fixtures->create('glpi_users', ['name' => $prefix . ' global source', 'entities_id' => $childEntity]);
        $globalReplacement = $fixtures->create('glpi_users', ['name' => $prefix . ' global replacement', 'entities_id' => $siblingEntity]);
        $userComputer = $fixtures->create('glpi_computers', ['users_id' => $globalUser]);
        verify((new User())->delete(['id' => $globalUser, '_replace_by' => $globalReplacement], true), 'Global user replacement ignores unrelated preferred-entity fields');
        verify((int)$read('glpi_computers', $userComputer)['users_id'] === $globalReplacement, 'User replacement preserves actual owning association');
        $soft = $fixtures->create('glpi_computers', ['name' => $prefix . ' soft delete']);
        $softBefore = $read('glpi_computers', $soft);
        $logsBefore = $records()->matching('glpi_logs', ['items_id' => $soft, 'itemtype' => 'Computer'], ['id ASC']);
        $PLUGIN_HOOKS['item_delete']['orm_delete_fixture'][Computer::class] = static function (): void {
            throw new RuntimeException('Expected soft-delete hook exception');
        };
        try {
            (new Computer())->delete(['id' => $soft]);
            throw new LogicException('Soft-delete exception succeeded');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Expected soft-delete hook exception', 'Actual soft-delete hook exception propagates');
        }
        unset($PLUGIN_HOOKS['item_delete']['orm_delete_fixture']);
        verify($read('glpi_computers', $soft) === $softBefore
            && $records()->matching('glpi_logs', ['items_id' => $soft, 'itemtype' => 'Computer'], ['id ASC']) === $logsBefore, 'Cancelled soft delete restores flag/date and audit history');
        verify((new Computer())->delete(['id' => $soft]) && (bool)$read('glpi_computers', $soft)['is_deleted'], 'Successful public soft-delete behavior and history are retained');
        verify((new ForeignKeys())->audit($connection) === [], 'Every final owning reference remains valid');
    } finally {
        $DB->rollBack();
        // These graphs were created inside the transaction and no longer exist.
        $graphs = [$autocommit, $notificationGroup, $callerNotificationGroup];
    }
} finally {
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
    $CFG_GLPI = $savedConfig;
    $_SESSION = $savedSession;
    foreach ($graphs as $graph) {
        foreach ([['Group', 'id'], ['Computer', 'computer'], ['Consumable', 'stock'], ['ConsumableItem', 'model'], ['User', 'user']] as [$type, $key]) {
            $model = new $type();
            if ($model->getFromDB($graph[$key])) {
                $model->delete(['id' => $graph[$key]], true);
            }
        }
    }
}
echo $DB->getProvider() . ": $assertions assertions; atomic public purge, replacement scopes, nested/cancelled hooks and preserved tree/root/global-user behavior passed.\n";
