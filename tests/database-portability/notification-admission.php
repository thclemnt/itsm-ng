<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/notification-admission.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
$assertions = 0;
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Registered modes keep the real framework signatures, including legitimate
// native void implementations, rather than adding incompatible parent types.
class PluginAdmissionNotificationLegacy implements NotificationInterface
{
    public static int $calls = 0;
    public function sendNotification($options = []): void
    {
        ++self::$calls;
    }
    public static function check($value, $options = [])
    {
        return true;
    }
    public static function testNotification()
    {
    }
}
class PluginAdmissionNotificationEventLegacy extends NotificationEventMailing
{
}
class PluginAdmissionNotificationIntmode extends NotificationAjax
{
    public static int $result = 0;
    public static int $calls = 0;
    public function sendNotification($options = []): int
    {
        ++self::$calls;
        return self::$result;
    }
}
class PluginAdmissionNotificationEventIntmode extends NotificationEventMailing
{
}
class PluginAdmissionNotificationEventCountedevent extends NotificationEventMailing
{
    public static function raise($event, CommonGLPI $item, array $options, $label, array $data, NotificationTarget $notificationtarget, NotificationTemplate $template, $notify_me, $emitter = null): int
    {
        return 0;
    }
}
class PluginAdmissionNotificationEventVoidevent extends NotificationEventMailing
{
    public static int $calls = 0;
    public static function raise($event, CommonGLPI $item, array $options, $label, array $data, NotificationTarget $notificationtarget, NotificationTemplate $template, $notify_me, $emitter = null): void
    {
        ++self::$calls;
    }
}
class PluginAdmissionNotificationEventRefusingevent extends NotificationEventMailing
{
    public static function raise($event, CommonGLPI $item, array $options, $label, array $data, NotificationTarget $notificationtarget, NotificationTemplate $template, $notify_me, $emitter = null)
    {
        return false;
    }
}
class PluginAdmissionNotificationEventMissingmode extends NotificationEventMailing
{
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Public session login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$writer = $DB;
$configuration = $CFG_GLPI;
$session = $_SESSION;
$server = $_SERVER;
$hooks = $PLUGIN_HOOKS;
$pluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
$plugins = $pluginProperty->getValue();
$cache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$fixtures = new FixtureRecords($DB);
$rootEntityBefore = $connection->fetchAssociative('SELECT * FROM glpi_entities WHERE id = 0');
verify($rootEntityBefore !== false, 'The notification fixture uses the actual root entity');
$cases = 0;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_USER_WARNING);
$connection->beginTransaction();
try {
    $CFG_GLPI['use_notifications'] = false;
    // Use the real Ticket update event; isolate pre-existing installed rules
    // only inside this disposable transaction, restored by the final rollback.
    $connection->executeStatement('UPDATE glpi_notifications SET is_active = false WHERE itemtype IN (?, ?)', ['Ticket', 'Contract']);
    // The owning tree lifecycle derives child depth from the actual root. A
    // hard-coded level can tie its parent and invert notification precedence.
    $childEntity = new Entity();
    $entity = $childEntity->add(['name' => 'Admission child', 'entities_id' => 0]);
    verify(is_int($entity) && $entity > 0, 'Public Entity creation owns the notification hierarchy');
    $childEntityRow = $connection->fetchAssociative('SELECT entities_id, level FROM glpi_entities WHERE id = ?', [$entity]);
    verify((int)$childEntityRow['entities_id'] === 0 && (int)$childEntityRow['level'] === (int)$rootEntityBefore['level'] + 1, 'Native child depth follows the root and precedes ancestor notifications');
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Admission recipient', 'interface' => 'central']);
    $group = $fixtures->create('glpi_groups', ['name' => 'Admission group', 'entities_id' => $entity, 'is_notify' => true]);
    $emptyGroup = $fixtures->create('glpi_groups', ['name' => 'Admission empty group', 'entities_id' => $entity, 'is_notify' => true]);
    $users = [];
    foreach (['en_GB', 'fr_FR'] as $index => $language) {
        $user = $fixtures->create('glpi_users', ['name' => 'Admission user ' . $index, 'language' => $language]);
        $fixtures->create('glpi_useremails', ['users_id' => $user, 'email' => 'admission' . $index . '@example.test', 'is_default' => true]);
        $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $entity]);
        $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $user]);
        $users[] = $user;
    }
    $ticketId = $fixtures->create('glpi_tickets', ['name' => 'Admission ticket', 'content' => 'Admission fixture content', 'entities_id' => $entity]);
    $ticket = new Ticket();
    verify($ticket->getFromDB($ticketId), 'Public notification owner');
    $fixtures->create('glpi_notificationchatconfigs', ['type' => 'all', 'hookurl' => 'https://example.invalid/admission-never-delivered']);
    $CFG_GLPI['use_notifications'] = true;
    $CFG_GLPI['notifications_mailing'] = true;
    $CFG_GLPI['notifications_ajax'] = true;
    $CFG_GLPI['notifications_chat'] = true;
    $CFG_GLPI['admin_email'] = 'admission-sender@example.test';
    $_SESSION['glpinotification_to_myself'] = true;
    $_SERVER['SERVER_NAME'] = 'admission.example.test';
    $pluginProperty->setValue(null, [...$plugins, 'admission']);
    foreach (['legacy', 'intmode', 'voidevent', 'countedevent', 'refusingevent', 'missingevent', 'missingmode'] as $mode) {
        Notification_NotificationTemplate::registerMode($mode, 'Admission ' . $mode, 'admission');
        $CFG_GLPI['notifications_' . $mode] = true;
    }
    $create = static function (string $event, string $mode, ?int $scope = null, ?int $recipients = null, bool $translated = true) use ($fixtures, $entity, $group): array {
        $scope ??= $entity;
        $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Admission ' . $event . ' ' . $mode, 'itemtype' => 'Ticket']);
        if ($translated) {
            foreach (['en_GB', 'fr_FR'] as $language) {
                $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => $language, 'subject' => 'Admission ' . $language, 'content_text' => 'Queued acceptance, not transport delivery']);
            }
        }
        $notification = $fixtures->create('glpi_notifications', ['name' => 'Admission ' . $event, 'itemtype' => 'Ticket', 'event' => 'update', 'entities_id' => $scope, 'is_recursive' => $scope === 0, 'is_active' => true]);
        $fixtures->create('glpi_notifications_notificationtemplates', ['notifications_id' => $notification, 'notificationtemplates_id' => $template, 'mode' => $mode]);
        $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => Notification::GROUP_TYPE, 'items_id' => $recipients ?? $group]);
        return [$notification, $template];
    };
    $count = static function (string $mode) use ($connection, $ticketId): int {
        $table = $mode === 'chat' ? 'glpi_queuedchats' : 'glpi_queuednotifications';
        return (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE itemtype = ? AND items_id = ? AND mode = ?', ['Ticket', $ticketId, $mode]);
    };
    $raise = static fn (string $scenario, string $label = '') => NotificationEvent::raiseEvent($scenario === 'port_no_notification' ? 'delete' : 'update', $ticket, [], $label);
    $scenario = static function (string $name, callable $test) use ($connection, &$cases, &$PLUGIN_HOOKS, &$CFG_GLPI): void {
        $beforeHooks = $PLUGIN_HOOKS;
        $beforeConfig = $CFG_GLPI;
        $connection->beginTransaction();
        try {
            $test();
            ++$cases;
        } finally {
            $connection->rollBack();
            $PLUGIN_HOOKS = $beforeHooks;
            $CFG_GLPI = $beforeConfig;
        }
    };
    $veto = static function (string $class, callable $refuses) use (&$PLUGIN_HOOKS): void {
        $PLUGIN_HOOKS['pre_item_add']['admission'][$class] = static function ($queue) use ($refuses): void {
            if ($refuses($queue->input)) {
                $queue->input = false;
            }
        };
    };
    $scenario('actual three-mode admission', static function () use ($create, $raise, $count): void {
        foreach (['mailing', 'ajax', 'chat'] as $mode) {
            $create('port_admitted', $mode);
        }
        verify($raise('port_admitted') === true, 'All public mode admissions accepted');
        verify($count('mailing') === 2 && $count('ajax') === 2 && $count('chat') === 1, 'Actual mail, browser user-ID and overlapping chat recipients route once per selected mode');
    });
    foreach (['mailing' => QueuedNotification::class, 'ajax' => QueuedNotification::class, 'chat' => QueuedChat::class] as $mode => $model) {
        $scenario('queue veto ' . $mode, static function () use ($create, $raise, $count, $veto, $mode, $model): void {
            $create('port_veto_' . $mode, $mode);
            $veto($model, static fn () => true);
            verify($raise('port_veto_' . $mode) === false && $count($mode) === 0, 'Actual public queue add veto propagates for ' . $mode);
        });
    }
    $scenario('partial modes', static function () use ($create, $raise, $count, $veto): void {
        foreach (['mailing', 'ajax', 'chat'] as $mode) {
            $create('port_partial', $mode);
        }
        $veto(QueuedNotification::class, static fn (array $input) => $input['mode'] === 'mailing' && $input['recipient'] === 'admission0@example.test');
        verify($raise('port_partial') === false, 'One refused recipient makes the whole event refuse admission');
        verify($count('mailing') === 1 && $count('ajax') === 2 && $count('chat') === 1, 'Refusal never short-circuits other recipients or modes');
    });
    $scenario('retry overlapping notification', static function () use ($create, $raise, $count, $veto): void {
        [, $rejectedTemplate] = $create('port_overlap', 'mailing');
        $create('port_overlap', 'mailing', 0);
        $attempts = 0;
        $veto(QueuedNotification::class, static function (array $input) use ($rejectedTemplate, &$attempts): bool {
            ++$attempts;
            return (int)$input['notificationtemplates_id'] === $rejectedTemplate;
        });
        $accepted = $raise('port_overlap');
        $queued = $count('mailing');
        verify($accepted === false && $attempts === 4 && $queued === 2, 'Rejected child attempts stay unprocessed and retry via recursive ancestor; whole-event partial refusal remains observable (accepted=' . (int)$accepted . ', attempts=' . $attempts . ', queued=' . $queued . ')');
    });
    $scenario('accepted overlap suppressed', static function () use ($create, $raise, $count): void {
        $create('port_dedup', 'ajax');
        $create('port_dedup', 'ajax', 0);
        verify($raise('port_dedup') === true && $count('ajax') === 2, 'Accepted recipients retain mode/language overlap suppression across templates');
    });
    $scenario('legacy registered mode and both-field target', static function () use ($create, $raise, $count, &$PLUGIN_HOOKS): void {
        $create('port_legacy', 'legacy');
        $create('port_legacy', 'legacy', 0);
        PluginAdmissionNotificationLegacy::$calls = 0;
        $PLUGIN_HOOKS['item_action_targets']['admission'][NotificationTargetTicket::class] = static function ($target): void {
            foreach ($target->target as &$recipient) {
                $recipient['chat'] = 1;
                $recipient['email'] ??= 'extra@example.test';
            }
        };
        verify($raise('port_legacy') === true && PluginAdmissionNotificationLegacy::$calls === 2, 'Void registered mode is compatible, selected once even with both chat/email fields, and suppresses overlap');
        verify($count('mailing') === 0 && $count('ajax') === 0 && $count('chat') === 0, 'Legacy acceptance is not proof of a core queue or external delivery');
    });
    foreach ([0, 1] as $result) {
        $scenario('documented integer mode ' . $result, static function () use ($create, $raise, $result): void {
            $create('port_integer', 'intmode');
            $create('port_integer', 'intmode', 0);
            PluginAdmissionNotificationIntmode::$result = $result;
            PluginAdmissionNotificationIntmode::$calls = 0;
            verify(Notification::send(['mode' => 'intmode']) === ($result === 0 ? false : 1), 'Send boundary preserves documented integer refusal/acceptance');
            PluginAdmissionNotificationIntmode::$calls = 0;
            verify($raise('port_integer') === (bool)$result && PluginAdmissionNotificationIntmode::$calls === ($result === 0 ? 4 : 2), 'Integer refusal stays retryable and integer acceptance suppresses overlap');
        });
    }
    foreach (['voidevent' => true, 'countedevent' => true, 'refusingevent' => false, 'missingevent' => false, 'missingmode' => false] as $mode => $expected) {
        $scenario('registered event ' . $mode, static function () use ($create, $raise, $mode, $expected, $count): void {
            $create('port_' . $mode, $mode);
            verify($raise('port_' . $mode) === $expected, 'Registered event dispatch exposes explicit false/missing class while preserving native void override: ' . $mode);
            verify($count('mailing') === 0 && $count('ajax') === 0 && $count('chat') === 0, 'Registered event refusal/legacy override never invents a queue');
        });
    }
    $scenario('intentional no-write outcomes', static function () use ($create, $raise, $count, $emptyGroup, $connection, &$CFG_GLPI): void {
        [$disabled] = $create('port_disabled', 'mailing');
        $CFG_GLPI['use_notifications'] = false;
        verify($raise('port_disabled') === true, 'Globally disabled notification remains a successful intentional no-op');
        $CFG_GLPI['use_notifications'] = true;
        $CFG_GLPI['notifications_mailing'] = false;
        verify($raise('port_disabled') === true, 'Disabled selected mode remains a successful intentional no-op');
        $CFG_GLPI['notifications_mailing'] = true;
        $connection->update('glpi_notifications', ['is_active' => false], ['id' => $disabled], ['is_active' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        $create('port_empty', 'mailing', null, $emptyGroup);
        verify($raise('port_empty') === true && $raise('port_no_notification') === true, 'No eligible recipient/notification retains successful no-op semantics');
        $connection->update('glpi_notifications', ['is_active' => true], ['id' => $disabled], ['is_active' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        ob_start();
        try {
            verify($raise('port_disabled', 'Admission debug') === true, 'Debug rendering succeeds without admitting a queue');
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        verify(str_contains($html, 'Admission debug') && $count('mailing') === 0 && $count('ajax') === 0 && $count('chat') === 0, 'Actual debug target/template/language rendering is a no-write operation');
    });
    $scenario('missing translation', static function () use ($create, $raise, $count): void {
        $create('port_unrenderable', 'mailing', null, null, false);
        verify($raise('port_unrenderable') === false && $count('mailing') === 0, 'An eligible recipient without a renderable template is not queue admission');
    });
    $scenario('exception and caller rollback', static function () use ($create, $raise, $connection, $count, &$PLUGIN_HOOKS): void {
        $create('port_exception', 'mailing');
        $attempts = 0;
        $PLUGIN_HOOKS['pre_item_add']['admission'][QueuedNotification::class] = static function () use (&$attempts): void {
            if (++$attempts === 2) {
                throw new RuntimeException('Admission fixture hook exception');
            }
        };
        try {
            $raise('port_exception');
            throw new LogicException('Queue exception was swallowed');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Admission fixture hook exception' && $connection->isTransactionActive() && $count('mailing') === 1, 'Exceptions propagate with earlier admissions inside the caller-owned savepoint');
        }
    });
    verify($count('mailing') === 0 && $count('ajax') === 0 && $count('chat') === 0, 'Caller savepoint rollback restores all queue writes including earlier accepted recipient');
    $scenario('direct public mode results', static function () use ($fixtures, $ticketId, $entity, $veto): void {
        $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Direct admission', 'itemtype' => 'Ticket']);
        $options = ['_itemtype' => 'Ticket', '_items_id' => $ticketId, '_notificationtemplates_id' => $template, '_entities_id' => $entity, '_locations_id' => null, '_groups_id' => null, '_itilcategories_id' => null, 'mode' => 'mailing', 'from' => 'sender@example.test', 'fromname' => 'Admission', 'to' => 'direct@example.test', 'toname' => 'Direct', 'subject' => 'Admission direct', 'content_text' => 'Not delivered'];
        verify(Notification::send($options) === true && Notification::sendChat($options) === true, 'Public send and legacy direct sendChat retain native admission result');
        $veto(QueuedNotification::class, static fn () => true);
        $veto(QueuedChat::class, static fn () => true);
        verify(Notification::send($options) === false && Notification::sendChat($options) === false, 'Direct queue refusal is observable without event dispatch');
    });
    foreach (['mailing', 'ajax', 'chat'] as $mode) {
        $scenario('actual Contract refusal and retry ' . $mode, static function () use ($fixtures, $connection, $entity, $group, $mode, &$PLUGIN_HOOKS): void {
            $contract = $fixtures->create('glpi_contracts', ['name' => 'Admission contract ' . $mode, 'entities_id' => $entity, 'begin_date' => '2024-01-31', 'duration' => 1, 'periodicity' => 1, 'alert' => 1 << Alert::PERIODICITY]);
            $oldAlert = (int)(new Alert())->add(['itemtype' => 'Contract', 'items_id' => $contract, 'type' => Alert::PERIODICITY, 'date' => '2024-02-29 00:00:00']);
            verify($oldAlert > 0, 'Actual previous Contract Alert exists');
            $templates = [];
            foreach (['mailing', 'ajax', 'chat'] as $channel) {
                $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Contract admission ' . $channel, 'itemtype' => 'Contract']);
                $templates[] = $template;
                $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => '', 'subject' => 'Contract admission', 'content_text' => '##FOREACHcontracts####contract.name## ##contract.time####ENDFOREACHcontracts##']);
                // Chat's single common target is deliberately last: prior mail
                // and browser admissions must be observed before its refusal.
                $scope = $channel === 'chat' ? 0 : $entity;
                $notification = $fixtures->create('glpi_notifications', ['name' => 'Contract admission ' . $channel, 'itemtype' => 'Contract', 'event' => 'periodicity', 'entities_id' => $scope, 'is_recursive' => $scope === 0, 'is_active' => true]);
                $fixtures->create('glpi_notifications_notificationtemplates', ['notifications_id' => $notification, 'notificationtemplates_id' => $template, 'mode' => $channel]);
                $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => Notification::GROUP_TYPE, 'items_id' => $group]);
            }
            $contractState = static fn () => $connection->fetchAssociative('SELECT * FROM glpi_contracts WHERE id = ?', [$contract]);
            $beforeContract = $contractState();
            $alerts = static fn () => $connection->fetchAllAssociative('SELECT * FROM glpi_alerts WHERE itemtype = ? AND items_id = ? ORDER BY id', ['Contract', $contract]);
            $audit = static fn () => $connection->fetchAllAssociative('SELECT * FROM glpi_logs WHERE itemtype = ? AND items_id = ? ORDER BY id', ['Contract', $contract]);
            $beforeAlerts = $alerts();
            $beforeAudit = $audit();
            $queued = static function (string $channel) use ($connection, $templates): int {
                $table = $channel === 'chat' ? 'glpi_queuedchats' : 'glpi_queuednotifications';
                return (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE notificationtemplates_id IN (?, ?, ?) AND mode = ?', [...$templates, $channel]);
            };
            $repository = \itsmng\Database\Orm::create($GLOBALS['DB'])->getRepository(\itsmng\Database\Entity\Contract::class);
            $selected = array_values(array_filter($repository->periodicContracts($entity), static fn ($row) => $row['id'] === $contract));
            verify(count($selected) === 1 && $selected[0]['last_period'] instanceof DateTimeInterface, 'Actual Contract repository captures owned previous native timestamp');
            $payload = [$contract => $selected[0] + ['alert_date' => '2024-03-31']];
            $publisher = new \itsmng\Domain\ContractAlertPublisher($GLOBALS['DB']);
            $level = $connection->getTransactionNestingLevel();
            $attempts = $observedQueues = 0;
            $model = $mode === 'chat' ? QueuedChat::class : QueuedNotification::class;
            $PLUGIN_HOOKS['pre_item_add']['admission'][$model] = static function ($queue) use ($mode, $contract, $queued, $audit, $beforeAudit, &$attempts, &$observedQueues): void {
                if ($queue->input['mode'] !== $mode || ++$attempts !== ($mode === 'chat' ? 1 : 2)) {
                    return;
                }
                $observedQueues = $queued('mailing') + $queued('ajax') + $queued('chat');
                verify((new Contract())->update(['id' => $contract, 'comment' => 'Hook mutation before queue refusal']), 'Actual public audit mutation precedes admission refusal');
                verify(count($audit()) > count($beforeAudit), 'Actual public Contract hook produced audit before rollback');
                $queue->input = false;
            };
            verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, $payload, true) === \itsmng\Domain\ContractAlertOutcome::Refused, 'Actual Contract publisher observes ' . $mode . ' admission refusal');
            verify($observedQueues > 0 && $attempts === ($mode === 'chat' ? 1 : 2), 'A later queue veto observes an earlier accepted queue before rollback');
            verify($queued('mailing') === 0 && $queued('ajax') === 0 && $queued('chat') === 0 && $alerts() === $beforeAlerts && $audit() === $beforeAudit && $contractState() === $beforeContract, 'Real publisher refusal restores prior Alert, full Contract row, audit and all mode queues');
            verify($connection->getTransactionNestingLevel() === $level, 'Contract refusal preserves the caller savepoint');
            unset($PLUGIN_HOOKS['pre_item_add']['admission'][$model]);
            $connection->beginTransaction();
            verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, $payload, true) === \itsmng\Domain\ContractAlertOutcome::Published, 'Actual Contract retry publishes after veto removal');
            verify($queued('mailing') === 2 && $queued('ajax') === 2 && $queued('chat') === 1 && count($alerts()) === 1 && (int)$alerts()[0]['id'] !== $oldAlert && $connection->getTransactionNestingLevel() === $level + 1, 'Retry admits each language/user/channel once, replaces old Alert, and leaves caller transaction open');
            verify($publisher->publish('periodicity', Alert::PERIODICITY, $entity, $payload, true) === \itsmng\Domain\ContractAlertOutcome::Skipped && $queued('mailing') === 2 && $queued('ajax') === 2 && $queued('chat') === 1, 'Stale Contract selection never duplicates accepted queues');
            $connection->rollBack();
            verify($queued('mailing') === 0 && $queued('ajax') === 0 && $queued('chat') === 0 && $alerts() === $beforeAlerts && $audit() === $beforeAudit, 'Caller rollback restores old Alert and removes accepted retry queues/audit');
        });
    }
    verify($DB === $writer && $DB->getDoctrineConnection() === $connection && $connection->getTransactionNestingLevel() === 1, 'Admission uses the caller-supplied writer and leaves transaction ownership intact');
} finally {
    $connection->rollBack();
    $CFG_GLPI = $configuration;
    $_SESSION = $session;
    $_SERVER = $server;
    $PLUGIN_HOOKS = $hooks;
    $pluginProperty->setValue(null, $plugins);
    $GLPI_CACHE = $cache;
    restore_error_handler();
}
verify($connection->fetchAssociative('SELECT * FROM glpi_entities WHERE id = 0') === $rootEntityBefore, 'Caller rollback preserves the complete original root entity');
verify(!$connection->isTransactionActive() && !(bool)$connection->fetchOne('SELECT COUNT(*) FROM glpi_entities WHERE id = ?', [$entity]), 'Caller rollback removes its publicly created child and restores transaction ownership');
echo $DB->getProvider() . ': ' . $cases . ' cases, ' . $assertions . " assertions; public notification admission and actual Contract rollback/retry passed; admission is distinct from delivery and attempted filesystem/plugin effects.\n";
