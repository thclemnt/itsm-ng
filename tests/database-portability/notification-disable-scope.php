<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/notification-disable-scope.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$savedConfig = $CFG_GLPI;
try {
    Notification_NotificationTemplate::registerMode('scopeprobe', 'Scope probe', 'scope_fixture');
    $modes = Notification_NotificationTemplate::getModes();
    $keys = array_map(static fn (string $mode): string => 'notifications_' . $mode, array_keys($modes));
    $CFG_GLPI['use_notifications'] = true;
    foreach ($keys as $offset => $key) {
        $CFG_GLPI[$key] = [true, '1', 1, false][$offset % 4];
    }
    $CFG_GLPI['notifications_ajax_icon_url'] = '/icons/notification.svg';
    $CFG_GLPI['notifications_ajax_check_interval'] = 37;
    $CFG_GLPI['notifications_unregistered_probe'] = 1;
    $before = $CFG_GLPI;
    $disabled = static function () use ($keys, $modes, $before): void {
        global $CFG_GLPI;
        verify($CFG_GLPI['use_notifications'] === 0, 'Global enable flag is temporarily disabled');
        foreach ($keys as $key) {
            verify($CFG_GLPI[$key] === 0, 'Every authoritative registered mode is disabled: ' . $key);
        }
        verify($CFG_GLPI['notifications_modes'] === $modes, 'Mode registration catalog remains an array with all registered modes');
        foreach (['notifications_ajax_icon_url', 'notifications_ajax_check_interval', 'notifications_unregistered_probe'] as $key) {
            verify($CFG_GLPI[$key] === $before[$key], 'Ancillary or unregistered config is preserved: ' . $key);
        }
    };
    NotificationSetting::disableAll();
    $disabled();
    $CFG_GLPI = $before;
    verify(NotificationSetting::withoutNotifications(static function () use ($disabled): string {
        $disabled();
        return 'Accepted';
    }) === 'Accepted', 'Scoped success retains its result');
    verify($CFG_GLPI === $before, 'Scoped success restores flag types and values exactly');
    verify(NotificationSetting::withoutNotifications(static function () use ($disabled): bool {
        $disabled();
        return false;
    }) === false, 'Scoped refusal retains its false result');
    verify($CFG_GLPI === $before, 'Scoped refusal restores exact config');
    try {
        NotificationSetting::withoutNotifications(static function () use ($disabled): never {
            $disabled();
            throw new RuntimeException('Scope test refusal');
        });
        throw new LogicException('Expected scoped exception');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Scope test refusal', 'Scoped exception propagates unchanged');
    }
    verify($CFG_GLPI === $before, 'Scoped exception restores exact config');
    NotificationSetting::withoutNotifications(static function () use ($disabled): void {
        global $CFG_GLPI;
        $disabled();
        $outer = $CFG_GLPI;
        NotificationSetting::withoutNotifications($disabled);
        verify($CFG_GLPI === $outer, 'Nested scope restores outer disabled flags rather than prematurely enabling delivery');
    });
    verify($CFG_GLPI === $before, 'Nested scope restores original enable flags');

    unset($CFG_GLPI['use_notifications']);
    foreach ($keys as $key) {
        unset($CFG_GLPI[$key]);
    }
    $absent = $CFG_GLPI;
    NotificationSetting::withoutNotifications($disabled);
    verify($CFG_GLPI === $absent, 'Originally absent flags remain absent after a temporary scope');
    unset($CFG_GLPI['notifications_modes']);
    NotificationSetting::withoutNotifications(static function (): void {
        global $CFG_GLPI;
        verify(is_array($CFG_GLPI['notifications_modes']), 'Existing getModes behavior initializes its core registry during scope');
    });
    verify(is_array($CFG_GLPI['notifications_modes']) && !array_key_exists('use_notifications', $CFG_GLPI), 'Registry cache enrichment persists, without inventing a persisted enable flag');
} finally {
    $CFG_GLPI = $savedConfig;
}
echo $DB->getProvider() . ": $assertions registered notification flag scope assertions passed.\n";
