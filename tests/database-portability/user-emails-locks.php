<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/user-emails-locks.php /path/to/test-config\n");
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
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$repo = static fn () => new \itsmng\Database\Repository\UserEmailRepository(Orm::create($DB));
$records = static fn () => new RecordRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $user = $fixtures->create('glpi_users', ['name' => 'email-owner']);
    $other = $fixtures->create('glpi_users', ['name' => 'email-other']);
    verify(UserEmail::getDefaultForUser($user) === '' && UserEmail::getAllForUser($user) === [] && !UserEmail::isEmailForUser($user, 'missing@example.test'), 'Empty email lookup contracts');
    verify(!$repo()->selectDefault($user) && !$repo()->selectDefault(0), 'Empty and invalid owners have no default candidate');
    $legacyOwner = $fixtures->create('glpi_users', ['name' => 'email-legacy']);
    $legacyFirst = $fixtures->create('glpi_useremails', ['users_id' => $legacyOwner, 'email' => 'oldest@example.test', 'is_default' => false]);
    $fixtures->create('glpi_useremails', ['users_id' => $legacyOwner, 'email' => 'later@example.test', 'is_default' => false]);
    verify(UserEmail::getDefaultForUser($legacyOwner) === 'oldest@example.test', 'Missing-default fallback uses oldest address');
    verify($repo()->selectDefault($legacyOwner) && $read('glpi_useremails', $legacyFirst)['is_default'] === 1, 'Missing-default selection promotes oldest address');
    $first = (new UserEmail())->add(['users_id' => $user, 'email' => 'first@example.test']);
    $second = (new UserEmail())->add(['users_id' => $user, 'email' => "o'connor@example.test"]);
    $foreign = (new UserEmail())->add(['users_id' => $other, 'email' => 'other@example.test']);
    verify($first > 0 && $second > 0 && $foreign > 0, 'Email lifecycle accepts valid addresses');
    verify($read('glpi_useremails', $first)['is_default'] === 1 && $read('glpi_useremails', $second)['is_default'] === 0, 'First address defaults automatically');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify(UserEmail::getDefaultForUser($user) === 'first@example.test', 'Default address returned');
    verify(UserEmail::getAllForUser($user) === ['first@example.test', "o'connor@example.test"], 'Address listing uses stable ID ordering');
    verify(UserEmail::isEmailForUser($user, "o'connor@example.test") && !UserEmail::isEmailForUser($other, "o'connor@example.test"), 'Quoted email lookup respects owner');
    verify(!$repo()->selectDefault($user, $foreign), 'Another user address cannot become default');
    verify($repo()->selectDefault($user, $second), 'Repository default switch succeeds');
    verify($SQL_TOTAL_REQUEST === 0, 'Email selection and default maintenance bypass adapter SQL');
    verify($read('glpi_useremails', $first)['is_default'] === 0 && $read('glpi_useremails', $second)['is_default'] === 1 && $read('glpi_useremails', $foreign)['is_default'] === 1, 'Default switch is scoped');
    verify((new UserEmail())->update(['id' => $first, 'email' => 'first@example.test', 'is_default' => 1]), 'Model default update succeeds');
    verify(UserEmail::getDefaultForUser($user) === 'first@example.test' && $read('glpi_useremails', $second)['is_default'] === 0, 'Model update uses mapped maintenance');
    verify((new UserEmail())->delete(['id' => $first], true), 'Default address deletion succeeds');
    verify(UserEmail::getDefaultForUser($user) === "o'connor@example.test" && $read('glpi_useremails', $second)['is_default'] === 1, 'Deleting default promotes survivor');
    $third = (new UserEmail())->add(['users_id' => $user, 'email' => 'third@example.test', 'is_default' => 1]);
    verify((new UserEmail())->delete(['id' => $second], true), 'Nondefault deletion succeeds');
    verify($read('glpi_useremails', $third)['is_default'] === 1, 'Deleting nondefault preserves selection');
    verify(!(new UserEmail())->add(['users_id' => $user, 'email' => 'not-an-email']), 'Invalid email remains rejected by lifecycle');
    // Multiple legacy defaults resolve deterministically and are normalized on selection.
    $extra = $fixtures->create('glpi_useremails', ['users_id' => $user, 'email' => 'legacy@example.test', 'is_default' => 1]);
    verify(UserEmail::getDefaultForUser($user) === 'third@example.test', 'Legacy default ties use oldest ID');
    verify($repo()->selectDefault($user) && $read('glpi_useremails', $extra)['is_default'] === 0, 'Survivor selection preserves existing default and clears ties');
    $connection->beginTransaction();
    $repo()->selectDefault($user, $extra);
    $connection->rollBack();
    verify(UserEmail::getDefaultForUser($user) === 'third@example.test', 'Default maintenance shares caller transaction');
    $ticket = $fixtures->create('glpi_tickets', ['name' => 'Locked ticket']);
    $computer = $fixtures->create('glpi_computers', ['name' => 'Locked computer']);
    $early = $fixtures->create('glpi_objectlocks', ['itemtype' => 'Ticket', 'items_id' => $ticket, 'users_id' => $user, 'date_mod' => '2030-01-01 10:00:00']);
    $boundary = $fixtures->create('glpi_objectlocks', ['itemtype' => 'Computer', 'items_id' => $computer, 'users_id' => $other, 'date_mod' => '2030-01-01 11:00:00']);
    $locks = new \itsmng\Database\Repository\ObjectLockRepository(Orm::create($DB));
    $SQL_TOTAL_REQUEST = 0;
    $expired = $locks->expired(new DateTimeImmutable('2030-01-01 11:00:00'));
    verify(in_array($early, array_column($expired, 'id'), true) && !in_array($boundary, array_column($expired, 'id'), true), 'Lock expiry respects strict timestamp boundary');
    verify($SQL_TOTAL_REQUEST === 0, 'Expiry query uses mapped typed timestamp');
    $lock = new ObjectLock();
    verify($lock->getFromDBByCrit(['itemtype' => 'Ticket', 'items_id' => $ticket]) && $lock->fields['users_id'] === $user, 'Lock model retains physical owner contract');
    $oldConfiguration = $CFG_GLPI;
    try {
        $CFG_GLPI['lock_use_lock_item'] = 1;
        $CFG_GLPI['lock_lockprofile_id'] = $_SESSION['glpiactiveprofile']['id'];
        $CFG_GLPI['lock_item_list'] = ['Ticket'];
        $result = ObjectLock::isLocked('Ticket', $ticket);
        verify($result instanceof ObjectLock && $result->fields['users_id'] === $user, 'Lock-status lookup hydrates owner association');
    } finally {
        $CFG_GLPI = $oldConfiguration;
    }
    verify((new User())->delete(['id' => $user], true), 'User purge removes addresses and owned locks');
    verify($read('glpi_objectlocks', $early) === null && $read('glpi_objectlocks', $boundary) !== null, 'Purge clears only deleted user locks');
    verify(UserEmail::getAllForUser($user) === [] && UserEmail::getDefaultForUser($other) === 'other@example.test', 'Purge preserves unrelated emails');
    verify((new ForeignKeys())->audit($connection) === [], 'Owner graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
echo $DB->getProvider() . ": Mapped email lifecycle, default selection, lock expiry and owner purge passed.\n";
