<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace {
    $directory = $argv[1] ?? '';
    if (!is_file($directory . '/config_db.php')) {
        exit("Usage: php tests/database-portability/notification-template-domain.php /path/to/test-config\n");
    }
    define('GLPI_ROOT', dirname(__DIR__, 2));
    define('GLPI_CONFIG_DIR', realpath($directory));
    require GLPI_ROOT . '/inc/includes.php';
    require __DIR__ . '/FixtureRecords.php';
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, (string)$error . "\n");
        exit(1);
    });
    $assertions = 0;
    function verify(bool $condition, string $message): void
    {
        global $assertions;
        ++$assertions;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

namespace NotificationDomainFixture {
    /** A real namespaced event source exercises NotificationTarget::getInstance. */
    class Item extends \CommonGLPI
    {
        public function __construct(private int $entity)
        {
        }

        public function getEntityID()
        {
            return $this->entity;
        }
    }

    class NotificationTargetItem extends \NotificationTarget
    {
    }
}

namespace {
    use itsmng\Database\Orm;
    use itsmng\Database\OwnedMutationFrame;
    use itsmng\Database\Repository\RecordRepository;
    use itsmng\Domain\NotificationDeliveryService;
    use itsmng\Domain\NotificationTemplateService;

    class PluginPlanNotificationEventPlan extends NotificationEventMailing
    {
        public static array $calls = [];

        public static function raise($event, CommonGLPI $item, array $options, $label, array $data, NotificationTarget $notificationtarget, NotificationTemplate $template, $notify_me, $emitter = null): void
        {
            // Exercise the actual event extension and its per-mode reference.
            // No recipient expansion, queue dispatch or physical transport occurs.
            self::$calls[] = [
                'notification' => $data['id'], 'template' => $template->getID(),
                'mode' => $options['mode'], 'processed_before' => count($options['processed']),
                'entity' => $data['entities_id'], 'allow_response' => $data['allow_response'],
            ];
            $options['processed']['same-recipient'] = true;
            // A callback's flag change must not rewrite the already selected plan.
            $GLOBALS['CFG_GLPI']['notifications_plan'] = false;
        }
    }

    class PluginPlanNotificationEventOtherplan extends PluginPlanNotificationEventPlan
    {
    }

    verify(str_starts_with($DB->dbdefault, 'itsm_port_') && !$DB->isSlave(), 'Disposable primary required');
    $_SESSION['glpiextauth'] = 0;
    verify((new Auth())->login('itsm', 'itsm', true), 'Public login');
    $savedSession = $_SESSION;
    $savedConfiguration = $CFG_GLPI;
    $savedHooks = $PLUGIN_HOOKS;
    $pluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
    $savedPlugins = $pluginProperty->getValue();
    $pluginProperty->setValue(null, [...$savedPlugins, 'plan']);
    $savedCache = $GLPI_CACHE;
    $GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
    $writer = $DB;
    $connection = $writer->getDoctrineConnection();
    $fixtures = new FixtureRecords($writer);
    $records = static fn (): RecordRepository => new RecordRepository(Orm::create($writer));
    $delivery = new NotificationDeliveryService($writer);
    $translations = new NotificationTemplateService($writer);
    $CFG_GLPI['use_notifications'] = false;
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    $frame = OwnedMutationFrame::begin($connection);
    $primary = null;
    $cleanup = [];
    try {
        $child = $fixtures->create('glpi_entities', ['name' => 'Notification plan child', 'entities_id' => 0, 'level' => 2]);
        $sibling = $fixtures->create('glpi_entities', ['name' => 'Notification plan sibling', 'entities_id' => 0, 'level' => 2]);
        $_SESSION['glpiactiveentities'] = [0, $child, $sibling];
        $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpishowallentities'] = false;
        $_SESSION['glpinotification_to_myself'] = true;
        $type = NotificationDomainFixture\Item::class;
        $event = 'port_notification_plan';
        $template = $fixtures->create('glpi_notificationtemplates', ['name' => 'Plan template', 'itemtype' => $type, 'css' => null]);
        $otherTemplate = $fixtures->create('glpi_notificationtemplates', ['name' => 'Other plan template', 'itemtype' => $type]);
        $noDefault = $fixtures->create('glpi_notificationtemplates', ['name' => 'No default plan template', 'itemtype' => $type]);
        $emptyTemplate = $fixtures->create('glpi_notificationtemplates', ['name' => 'Empty plan template', 'itemtype' => $type]);
        $default = $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => '', 'subject' => "Default O'Connor", 'content_text' => null, 'content_html' => null]);
        $english = $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => 'en_GB', 'subject' => 'English', 'content_text' => 'English body', 'content_html' => '']);
        $french = $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $noDefault, 'language' => 'fr_FR', 'subject' => 'French only', 'content_text' => '', 'content_html' => null]);
        $rule = static function (int $entity, bool $recursive, bool $active = true, string $kind = '') use ($fixtures, $type, $event): int {
            return $fixtures->create('glpi_notifications', [
                'name' => 'Plan rule', 'itemtype' => $kind ?: $type, 'event' => $event,
                'entities_id' => $entity, 'is_recursive' => $recursive, 'is_active' => $active, 'allow_response' => false,
            ]);
        };
        $local = $rule($child, false);
        $ancestor = $rule(0, true);
        $nonrecursive = $rule(0, false);
        $foreign = $rule($sibling, true);
        $inactive = $rule($child, false, false);
        $wrongType = $rule($child, false, true, 'Ticket');
        $unbound = $rule($child, false);
        $bind = static fn (int $notification, int $owner, string $mode): int => $fixtures->create('glpi_notifications_notificationtemplates', [
            'notifications_id' => $notification, 'notificationtemplates_id' => $owner, 'mode' => $mode,
        ]);
        $localFirst = $bind($local, $template, 'plan');
        $localSecond = $bind($local, $otherTemplate, 'plan');
        $localOther = $bind($local, $template, 'otherplan');
        $rootBinding = $bind($ancestor, $template, 'plan');
        foreach ([$nonrecursive, $foreign, $inactive, $wrongType] as $excluded) {
            $bind($excluded, $template, 'plan');
        }
        Notification_NotificationTemplate::registerMode('plan', 'Plan probe', 'plan');
        Notification_NotificationTemplate::registerMode('otherplan', 'Other plan probe', 'plan');
        foreach (array_keys(Notification_NotificationTemplate::getModes()) as $mode) {
            $CFG_GLPI['notifications_' . $mode] = false;
        }
        $CFG_GLPI['notifications_plan'] = true;
        $scope = getEntitiesRestrictCriteria(Notification::getTable(), 'entities_id', $child, true);
        $selected = $delivery->plan($event, $type, $scope, ['plan']);
        verify(count($selected) === 3, 'Each selected binding survives notification identity deduplication');
        $rows = $selected->legacyRows();
        verify(array_column($rows, 'id') === [$local, $local, $ancestor], 'Child rules precede recursive ancestors; sibling, nonrecursive, inactive and wrong types are excluded');
        $owners = array_column(array_slice($rows, 0, 2), 'notificationtemplates_id');
        sort($owners);
        $expectedOwners = [$template, $otherTemplate];
        sort($expectedOwners);
        verify($owners === $expectedOwners && $rows[2]['notificationtemplates_id'] === $template, 'Distinct links sharing a rule or template retain their own ownership');
        verify($rows[0]['allow_response'] === 0 && $rows[0]['is_active'] === 1, 'Compatibility row retains legacy integer boolean semantics');
        verify(count($delivery->plan($event, $type, getEntitiesRestrictCriteria(Notification::getTable(), 'entities_id', [], true), ['plan'])) === 0, 'Explicit empty scope cannot leak notifications');
        verify(count($delivery->plan($event, $type, getEntitiesRestrictCriteria(Notification::getTable(), 'entities_id', 0, true), ['plan'])) === 1, 'Explicit root excludes descendant rules');
        $_SESSION['glpishowallentities'] = true;
        verify(count($delivery->plan($event, $type, getEntitiesRestrictCriteria(Notification::getTable(), 'entities_id', '', true), ['plan'])) === 5, 'Existing all-entity selector retains an unrestricted scope');
        $_SESSION['glpishowallentities'] = false;
        $again = $delivery->plan($event, $type, $scope, ['otherplan'])->legacyRows();
        verify(count($again) === 1 && $again[0]['mode'] === 'otherplan', 'A reused service does not retain the previous filtered collection');
        $CFG_GLPI['use_notifications'] = false;
        verify((new Notification())->update(['id' => $ancestor, 'is_active' => false]), 'Actual public rule update');
        verify(count($delivery->plan($event, $type, $scope, ['plan'])) === 2, 'A reused service observes legacy writer changes without stale entity fields');
        verify((new Notification())->update(['id' => $ancestor, 'is_active' => true]), 'Restore ancestor through public lifecycle');
        verify(count($selected) === 3 && $selected->legacyRows() === $rows, 'Previously selected plan is immutable through later writes');
        $all = $delivery->plan($event, $type, $scope, [])->legacyRows();
        $unboundRows = array_values(array_filter($all, static fn (array $row): bool => $row['id'] === $unbound));
        verify(count($unboundRows) === 1 && $unboundRows[0]['mode'] === null && $unboundRows[0]['notificationtemplates_id'] === null, 'No-enabled-mode legacy selector preserves the LEFT JOIN unbound rule');
        $public = Notification::getNotificationsByEventAndType(addslashes($event), addslashes($type), $child);
        verify(count($public) === 3 && $public->numrows() === 3 && $public->next()['id'] === $local, 'Public iterator preserves count/numrows/first-next');
        $keys = [];
        foreach ($public as $key => $row) {
            $keys[] = $key;
        }
        verify($keys === [$local, $local, $ancestor], 'Public iterator retains duplicate rule keys for distinct deliveries');

        // Compare bound native selection, not a guessed cross-provider collation rule.
        $caseBinding = $bind($local, $emptyTemplate, 'PLAN');
        $nativeModes = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM glpi_notifications_notificationtemplates WHERE notifications_id = ? AND mode IN (?)', [$local, 'plan']));
        $mappedModes = [];
        foreach ($delivery->plan($event, $type, $scope, ['plan']) as $step) {
            if ($step->legacyRow()['id'] === $local) {
                $mappedModes[] = $step->bindingId;
            }
        }
        sort($nativeModes);
        sort($mappedModes);
        verify($mappedModes === $nativeModes, 'Mode matching retains actual provider collation');
        verify((new Notification_NotificationTemplate())->delete(['id' => $caseBinding], true), 'Remove case-only binding through public relation lifecycle');
        $model = new NotificationTemplate();
        verify($model->getFromDB($template), 'Public template load');
        foreach (['en_GB', 'en_gb', 'unknown', '', null] as $language) {
            $native = $connection->fetchAssociative('SELECT * FROM glpi_notificationtemplatetranslations WHERE notificationtemplates_id = ? AND language IN (?, ?) ORDER BY language DESC LIMIT 1', [$template, $language, '']);
            $actual = $model->getByLanguage($language);
            verify($actual !== false && $actual['id'] === (int)$native['id'] && $actual['language'] === $native['language'], 'Requested/default locale preserves actual native comparison and ordering');
            verify($actual['content_text'] === $native['content_text'] && $actual['content_html'] === $native['content_html'], 'Unrendered nullable bodies remain exact');
        }
        verify($model->getByLanguage(null)['id'] === $default, 'NULL locale request retains the default');
        verify($model->getFromDB($noDefault) && $model->getByLanguage(null) === false && $model->getByLanguage('unknown') === false, 'Missing default remains a genuine missing translation');
        verify($model->getFromDB($emptyTemplate) && $model->getByLanguage('en_GB') === false, 'Empty template has no invented content');
        $usedLanguages = NotificationTemplateTranslation::getAllUsedLanguages($template);
        ksort($usedLanguages);
        verify($usedLanguages === ['' => '', 'en_GB' => 'en_GB'], 'Public used-locale projection derives from the same translation ownership');
        $beforeLogs = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs');
        $hookCalls = 0;
        $PLUGIN_HOOKS['item_update']['plan'][NotificationTemplateTranslation::class] = static function ($translation) use ($english, &$hookCalls): void {
            if ($translation->getID() === $english) {
                ++$hookCalls;
            }
        };
        $translationModel = new NotificationTemplateTranslation();
        $edit = ['id' => $english, 'subject' => 'Changed English', 'content_text' => 'Changed body', 'content_html' => ''];
        verify($translationModel->can($english, UPDATE, $edit) && $translationModel->update($edit), 'Authorized public translation edit remains the lifecycle owner');
        verify($hookCalls === 1 && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs') > $beforeLogs, 'Translation audit and public update callback are retained');
        verify($translations->contentForLanguage($template, 'en_GB')->subject === 'Changed English', 'Reused translation service observes completed public edits');
        $before = $records()->find('glpi_notificationtemplatetranslations', 'id', $english);
        $PLUGIN_HOOKS['pre_item_update']['plan'][NotificationTemplateTranslation::class] = static function ($translation) use ($english): void {
            if ($translation->getID() === $english) {
                $translation->input = false;
            }
        };
        verify($translationModel->update(['id' => $english, 'subject' => 'Vetoed', 'content_text' => '', 'content_html' => '']) === false
            && $records()->find('glpi_notificationtemplatetranslations', 'id', $english) === $before, 'Public hook cancellation preserves the stored translation');
        unset($PLUGIN_HOOKS['pre_item_update']['plan'][NotificationTemplateTranslation::class]);
        $_SESSION['glpiactiveprofile']['notificationtemplate'] = READ;
        $_SESSION['glpiactiveprofile']['notification'] = 0;
        $hiddenRule = new Notification();
        verify($hiddenRule->getFromDB($local), 'Load actual rule before denied screen');
        ob_start();
        try {
            $hiddenResult = Notification_NotificationTemplate::showForNotification($hiddenRule);
        } finally {
            $hiddenOutput = ob_get_clean();
        }
        verify($hiddenResult === false && $hiddenOutput === '', 'Binding screen still refuses the actual parent READ gate');
        verify(!(new NotificationTemplate())->can($template, UPDATE) && !(new NotificationTemplateTranslation())->can($english, UPDATE), 'Read-only template and child permissions remain unchanged');
        $_SESSION = array_replace($_SESSION, ['glpiactiveprofile' => $savedSession['glpiactiveprofile']]);

        $CFG_GLPI['use_notifications'] = true;
        $CFG_GLPI['notifications_plan'] = true;
        $CFG_GLPI['notifications_otherplan'] = true;
        $beforeQueues = [(int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_queuednotifications'), (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_queuedchats')];
        PluginPlanNotificationEventPlan::$calls = [];
        verify(NotificationEvent::raiseEvent($event, new NotificationDomainFixture\Item($child)), 'Actual namespaced event factory and registered native-void event accept the plan');
        $calls = PluginPlanNotificationEventPlan::$calls;
        verify(count($calls) === 4 && end($calls)['notification'] === $ancestor, 'Callback flag mutation cannot remove already selected child/root deliveries');
        foreach (['plan' => [0, 1, 1], 'otherplan' => [0]] as $mode => $expected) {
            $processed = array_column(array_values(array_filter($calls, static fn (array $call): bool => $call['mode'] === $mode)), 'processed_before');
            verify($processed === $expected, 'Actual processed recipient references are shared within and separated across modes');
        }
        verify([(int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_queuednotifications'), (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_queuedchats')] === $beforeQueues, 'Registered event observation never sends or invents queues');
        $CFG_GLPI['use_notifications'] = false;
        verify($model->getFromDB($template), 'Reload clone source');
        $clone = $model->clone(['name' => 'Plan clone', 'css' => null]);
        verify(is_int($clone) && $clone > 0 && $records()->find('glpi_notificationtemplates', 'id', $clone)['css'] === null, 'Public clone keeps supplied NULL distinct from omission');
        $clonedTranslations = $translations->translations($clone);
        verify(count($clonedTranslations) === 2 && $delivery->bindingsForTemplate($clone) === [], 'Clone copies child translations through public hooks without copying notification bindings');
        $purged = [];
        $PLUGIN_HOOKS['pre_item_purge']['plan'][NotificationTemplateTranslation::class] = static function ($child) use (&$purged): void {
            $purged[] = $child->getID();
        };
        verify((new NotificationTemplate())->delete(['id' => $clone], true), 'Public clone purge');
        verify($translations->translations($clone) === [] && count($purged) === 2, 'Public purge retains every translation child hook and ownership cleanup');
        verify($writer === $DB && Orm::create($writer)->getConnection() === $connection && $delivery->bindingsForNotification($local) !== [] && $connection === $writer->getDoctrineConnection(), 'Services observe owned uncommitted rows on the exact caller-supplied adapter/DBAL connection');
        $frame->assertActive();
    } catch (Throwable $error) {
        $primary = $error;
    } finally {
        try {
            $frame->rollBack();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        $CFG_GLPI = $savedConfiguration;
        $_SESSION = $savedSession;
        $PLUGIN_HOOKS = $savedHooks;
        $GLPI_CACHE = $savedCache;
        try {
            $pluginProperty->setValue(null, $savedPlugins);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    if ($primary !== null) {
        foreach ($cleanup as $error) {
            try {
                fwrite(STDERR, 'Additional cleanup failure: ' . $error->getMessage() . "\n");
            } catch (Throwable) {
            }
        }
        throw $primary;
    }
    if ($cleanup !== []) {
        throw $cleanup[0];
    }
    echo $DB->getProvider() . ': ' . $assertions . " notification plan, locale, public lifecycle and plugin contracts passed.\n";
}
