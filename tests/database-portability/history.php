<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\HistoryRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/history.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$repo = static fn () => new HistoryRepository(Orm::create($DB));
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_logs', 'id', $id);
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$DB->beginTransaction();
try {
    $repo()->deleteMatching([]);
    $computer = new Computer();
    verify($computer->getFromDB($fixtures->create('glpi_computers', ['name' => 'History fixture'])), 'Load history subject');
    $id = (int)$computer->getID();
    $make = static fn (array $values = []): int => $repo()->append($values + [
        'itemtype' => 'Computer', 'items_id' => $id, 'linked_action' => Log::HISTORY_CREATE_ITEM,
        'date_mod' => '2024-02-29 12:34:56', 'user_name' => "O'Reilly \\path 日本語",
        'old_value' => 'NULL', 'new_value' => 'New value',
    ]);
    $first = $make();
    $second = $make();
    $third = $make(['user_name' => 'User 10']);
    $fourth = $make(['user_name' => 'User 2', 'linked_action' => Log::HISTORY_LOG_SIMPLE_MESSAGE]);
    $make(['items_id' => $id + 100000, 'user_name' => 'Other item']);
    $make(['itemtype' => 'Printer', 'user_name' => 'Other type']);
    verify($repo()->count() === 6 && Log::countForItem($computer) === 4, 'Global and scoped counts');
    verify(array_column($repo()->forItem('Computer', $id, offset: 1, limit: 2, sort: 'date_mod', direction: 'ASC'), 'id') === [$second, $third], 'Stable tied-date pagination');
    verify(array_column($repo()->forItem('Computer', $id, limit: 2, sort: 'date_mod', direction: 'DESC'), 'id') === [$fourth, $third], 'Descending tie breaker');
    verify(array_column($repo()->forItem('Computer', $id, limit: 1, sort: 'invalid SQL', direction: 'injected'), 'id') === [$fourth], 'Invalid ordering falls back to descending ID');
    verify($read($first)['old_value'] === 'NULL' && $repo()->count(['old_value' => 'NULL']) === 6, 'Literal NULL history values survive mapping');
    $filter = Log::convertFiltersValuesToSqlCriteria(['users_names' => ["O'Reilly \\path 日本語"], 'date' => '2024-02-29', 'linked_actions' => [Log::HISTORY_CREATE_ITEM]]);
    verify(Log::countForItem($computer, $filter) === 2, 'Bound compound UI filters');
    verify(array_column($repo()->forItem('Computer', $id, $filter), 'id') === [$second, $first], 'Filtered rows match filtered total');
    verify(Log::countForItem($computer, ['items_id' => $id + 100000, 'itemtype' => 'Printer']) === 4, 'Filters cannot override item scope');
    verify(array_values(Log::getDistinctUserNamesValuesInItemLog($computer)) === ["O'Reilly \\path 日本語", 'User 2', 'User 10'], 'Scoped user facets are distinct and naturally sorted');
    verify(count($repo()->facets('Computer', $id, ['linked_action', 'itemtype_link', 'id_search_option'])) === 2, 'Portable grouped facets');
    $rendered = Log::getHistoryData($computer, 1, 2, [], ['sort' => 'date_mod', 'order' => 'ASC']);
    verify(array_column($rendered, 'id') === [$second, $third] && $rendered[0]['change'] === 'Add the item', 'Application history rendering uses mapped page');
    verify(count(Log::getHistoryData($computer, 0, 1, [], ['sort' => ['invalid'], 'order' => ['invalid']])) === 1, 'Malformed sort options retain safe defaults');
    $savedGet = $_UGET;
    try {
        $pageFilters = ['users_names' => ["O'Reilly \\path 日本語"]];
        $_UGET['filters'] = $pageFilters;
        ob_start();
        Log::showForItem($computer);
        $html = ob_get_clean();
        verify(str_contains($html, urlencode(json_encode($pageFilters))), 'History table passes raw filters to its AJAX URL');
    } finally {
        $_UGET = $savedGet;
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    Log::countForItem($computer, $filter);
    Log::getDistinctUserNamesValuesInItemLog($computer);
    Log::getDistinctAffectedFieldValuesInItemLog($computer);
    Log::getDistinctLinkedActionValuesInItemLog($computer);
    $repo()->forItem('Computer', $id);
    verify($SQL_TOTAL_REQUEST === 0, 'History reads bypass adapter SQL');
    $nullSort = $make(['date_mod' => null, 'user_name' => null]);
    foreach (['date_mod', 'user_name'] as $sort) {
        verify($repo()->forItem('Computer', $id, limit: 1, sort: $sort, direction: 'ASC')[0]['id'] === $nullSort, 'NULL sorts first ascending on both providers');
        verify($repo()->forItem('Computer', $id, limit: 1, sort: $sort, direction: 'DESC')[0]['id'] !== $nullSort, 'NULL sorts last descending on both providers');
    }
    $_SESSION['glpiID'] = "History actor's \\name";
    $_SESSION['glpi_currenttime'] = '2024-02-29 12:34:56';
    $literal = "O'Reilly \\path 日本語";
    $history = Log::history($id, 'Computer', [1, addslashes($literal), addslashes(str_repeat('é', 200))]);
    verify($history > 0 && $_SESSION['glpi_maxhistory'] === $history, 'History returns and records generated ID');
    $row = $read($history);
    verify($row['old_value'] === $literal && $row['new_value'] === str_repeat('é', 180) && $row['user_name'] === $_SESSION['glpiID'], 'Legacy escaped changes decode once and retain actor and Unicode truncation');
    verify(Log::history($id, 'Computer', []) === false, 'Empty changes do not create history');
    verify($SQL_TOTAL_REQUEST === 0, 'History append bypasses adapter SQL');
    $repo()->deleteForItem('Computer', $id);
    verify($repo()->count() === 2, 'Item purge retains other item/type history');
    $_SESSION = $savedSession;
    $repo()->deleteMatching([]);

    foreach ([['2024-03-31 12:34:56', 1, '2024-02-29 12:34:56'], ['2023-03-31 12:34:56', 1, '2023-02-28 12:34:56'], ['2024-02-29 12:34:56', 12, '2023-02-28 12:34:56'], ['2024-01-31 12:34:56', 2, '2023-11-30 12:34:56']] as [$now, $months, $expected]) {
        verify(HistoryRepository::cutoff($months, new DateTimeImmutable($now))->format('Y-m-d H:i:s') === $expected, 'Calendar-month retention clamp');
    }
    $cutoff = HistoryRepository::cutoff(1, new DateTimeImmutable('2024-03-31 12:34:56'));
    $old = $make(['date_mod' => '2024-02-29 12:34:55']);
    $edge = $make(['date_mod' => '2024-02-29 12:34:56']);
    $new = $make(['date_mod' => '2024-02-29 12:34:57']);
    $undated = $make(['date_mod' => null]);
    verify($repo()->deleteMatching(['date_mod' => ['<=', $cutoff]]) === 2, 'Cutoff includes exact boundary');
    verify($read($old) === null && $read($edge) === null && $read($new) !== null && $read($undated) !== null, 'Dated retention preserves newer and undated rows');
    $repo()->deleteMatching([]);

    // Exercise retention helpers directly, never the cron body or task dispatcher.
    $cases = [
        ['purgeSoftware', 'item_software_install', ['itemtype' => 'Computer', 'linked_action' => Log::HISTORY_INSTALL_SOFTWARE]],
        ['purgeSoftware', 'item_software_install', ['itemtype' => 'Computer', 'linked_action' => Log::HISTORY_UNINSTALL_SOFTWARE]],
        ['purgeSoftware', 'software_item_install', ['itemtype' => 'SoftwareVersion', 'linked_action' => Log::HISTORY_INSTALL_SOFTWARE]],
        ['purgeSoftware', 'software_version_install', ['itemtype' => 'Software', 'itemtype_link' => 'SoftwareVersion', 'linked_action' => Log::HISTORY_UPDATE_SUBITEM]],
        ['purgeInfocom', 'infocom_creation', ['itemtype' => 'Software', 'itemtype_link' => 'Infocom', 'linked_action' => Log::HISTORY_ADD_SUBITEM]],
        ['purgeInfocom', 'infocom_creation', ['itemtype' => 'Infocom', 'linked_action' => Log::HISTORY_CREATE_ITEM]],
        ['purgeUserInfos', 'profile_user', ['itemtype' => 'User', 'itemtype_link' => 'Profile_User', 'linked_action' => Log::HISTORY_ADD_SUBITEM]],
        ['purgeUserInfos', 'group_user', ['itemtype' => 'User', 'itemtype_link' => 'Group_User', 'linked_action' => Log::HISTORY_DELETE_SUBITEM]],
        ['purgeUserInfos', 'userdeletedfromldap', ['itemtype' => 'User', 'linked_action' => Log::HISTORY_LOG_SIMPLE_MESSAGE]],
        ['purgeUserInfos', 'user_auth_changes', ['itemtype' => 'User', 'linked_action' => Log::HISTORY_ADD_RELATION]],
        ['purgeOthers', 'comments', ['id_search_option' => 16]],
        ['purgeOthers', 'datemod', ['id_search_option' => 19]],
        ['purgePlugins', 'plugins', ['itemtype' => 'PluginHistoryFixture']],
        ['purgeAll', 'all', []],
    ];
    foreach ([
        'purgeDevices' => ['adddevice' => Log::HISTORY_ADD_DEVICE, 'updatedevice' => Log::HISTORY_UPDATE_DEVICE, 'deletedevice' => Log::HISTORY_DELETE_DEVICE, 'connectdevice' => Log::HISTORY_CONNECT_DEVICE, 'disconnectdevice' => Log::HISTORY_DISCONNECT_DEVICE],
        'purgeRelations' => ['addrelation' => Log::HISTORY_ADD_RELATION, 'deleterelation' => Log::HISTORY_DEL_RELATION],
        'purgeItems' => ['createitem' => Log::HISTORY_CREATE_ITEM, 'deleteitem' => Log::HISTORY_DELETE_ITEM, 'updateitem' => Log::HISTORY_UPDATE_SUBITEM, 'restoreitem' => Log::HISTORY_RESTORE_ITEM],
    ] as $method => $actions) {
        foreach ($actions as $setting => $action) {
            $cases[] = [$method, $setting, ['linked_action' => $action]];
        }
    }
    foreach ($cases as [$method, $setting, $scope]) {
        foreach ($CFG_GLPI as $key => $value) {
            if (str_starts_with($key, 'purge_')) {
                $CFG_GLPI[$key] = Config::KEEP_ALL;
            }
        }
        $CFG_GLPI['software_types'] = ['Computer'];
        $connection->beginTransaction();
        try {
            $old = $make($scope + ['date_mod' => '2000-01-01 00:00:00']);
            $future = (new DateTimeImmutable('+1 year'))->format('Y-m-d H:i:s');
            $new = $make($scope + ['date_mod' => $future]);
            verify($read($new)['date_mod'] === $future, 'Future retention fixture stored without timestamp overflow');
            $undated = $make($scope + ['date_mod' => null]);
            $unrelated = $make(['itemtype' => 'UnrelatedHistory', 'linked_action' => 9999, 'id_search_option' => 9999, 'date_mod' => '2000-01-01 00:00:00']);
            $neighbors = [];
            foreach ($scope as $field => $value) {
                $neighbors[] = $make(array_replace($scope, [$field => is_int($value) ? 9999 : 'UnrelatedHistory', 'date_mod' => '2000-01-01 00:00:00']));
            }
            $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
            $SQL_TOTAL_REQUEST = 0;
            PurgeLogs::$method();
            verify(PurgeLogs::getLogsCount() === 4 + count($neighbors), $method . ': KEEP_ALL');
            $CFG_GLPI['purge_' . $setting] = '1';
            PurgeLogs::$method();
            verify($read($old) === null && $read($new) !== null && $read($undated) !== null, $method . ':' . $setting . ': dated retention');
            verify(($read($unrelated) !== null) === ($method !== 'purgeAll'), $method . ': unrelated history scope');
            $CFG_GLPI['purge_' . $setting] = Config::DELETE_ALL;
            PurgeLogs::$method();
            verify($read($new) === null && $read($undated) === null, $method . ': DELETE_ALL includes undated rows');
            foreach ($neighbors as $neighbor) {
                verify($read($neighbor) !== null, $method . ': each individual scope condition protects neighboring history');
            }
            verify($SQL_TOTAL_REQUEST === 0, $method . ': mapped retention and count');
        } finally {
            $connection->rollBack();
        }
    }
    verify(PurgeLogs::getDateModRestriction('1 MONTH); DROP TABLE glpi_logs') === false && PurgeLogs::getDateModRestriction(-2) === false, 'Invalid retention values cannot delete history');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
}
echo $DB->getProvider() . ": history writes, scoped pagination, facets, filters and retention policies passed.\n";
