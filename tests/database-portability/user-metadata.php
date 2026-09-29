<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\UserMetadataReferences;
use itsmng\Database\OptionalReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\UserRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/user-metadata.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    foreach (OptionalReferences::USER_METADATA['glpi_users'] as $column => $target) {
        $suffix = bin2hex(random_bytes(4));
        $parent = $target === 'glpi_profiles' ? (new Profile())->add(['name' => 'Metadata parent ' . $suffix]) : $fixtures->create($target, ['name' => 'Metadata parent ' . $suffix]);
        $replacement = $target === 'glpi_profiles' ? (new Profile())->add(['name' => 'Metadata replacement ' . $suffix]) : $fixtures->create($target, ['name' => 'Metadata replacement ' . $suffix]);
        $other = $target === 'glpi_profiles' ? (new Profile())->add(['name' => 'Metadata other ' . $suffix]) : $fixtures->create($target, ['name' => 'Metadata other ' . $suffix]);
        $child = $fixtures->create('glpi_users', [$column => $parent, 'name' => 'Metadata child ' . $suffix]);
        $unrelated = $fixtures->create('glpi_users', [$column => $other, 'name' => 'Metadata unrelated ' . $suffix]);
        if ($column === 'profiles_id') {
            $fixtures->create('glpi_profiles_users', ['users_id' => $child, 'profiles_id' => $replacement, 'entities_id' => 0]);
        }
        $model = getItemForItemtype(getItemTypeForTable($target));
        verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace ' . $column);
        verify((int)$read('glpi_users', $child)[$column] === $replacement, 'Replacement updates ' . $column);
        verify($model->delete(['id' => $replacement], true), 'Purge ' . $column);
        verify($read('glpi_users', $child)[$column] === null, 'Purge clears ' . $column);
        verify((int)$read('glpi_users', $unrelated)[$column] === $other, 'Unrelated reference unchanged ' . $column);
    }
    $profile = (new Profile())->add(['name' => 'Preference retired']);
    $replacement = (new Profile())->add(['name' => 'Preference replacement']);
    $noGrant = $fixtures->create('glpi_users', ['name' => 'No replacement grant', 'profiles_id' => $profile]);
    verify((new Profile())->delete(['id' => $profile, '_replace_by' => $replacement], true), 'Delete profile with unassigned replacement');
    verify($read('glpi_users', $noGrant)['profiles_id'] === null, 'Unassigned replacement clears preference');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_profiles_users', ['users_id' => $noGrant]) === 0, 'Replacement never grants a profile');
    $self = $fixtures->create('glpi_users', ['name' => 'Self supervisor']);
    $writer = new \itsmng\Database\Repository\RecordWriter(Orm::create($DB));
    $writer->update('glpi_users', $self, ['users_id_supervisor' => $self]);
    verify((new User())->delete(['id' => $self], true), 'Self supervisor does not block purge');

    $repo = new UserRepository(Orm::create($DB));
    $target = $fixtures->create('glpi_users', ['name' => "Preference O'Reilly\\account", 'is_active' => true]);
    $other = $fixtures->create('glpi_users', ['name' => 'Preference second account', 'is_active' => false]);
    $category = $fixtures->create('glpi_usercategories', ['name' => 'Unique identity category']);
    $writer->update('glpi_users', $target, ['usercategories_id' => $category]);
    verify($repo->uniqueId('name', "Preference O'Reilly\\account") === $target, 'Raw bound username');
    verify(User::getIdByName("Preference O'Reilly\\account") === $target, 'Public username lookup preserves quotes and backslashes');
    verify(User::getIdByField('name', addslashes("Preference O'Reilly\\account"), false) === $target, 'Legacy preescaped username lookup');
    verify(User::getIdByField('usercategories_id', $category) === $target, 'Identity lookup supports association columns');
    verify(User::getIdByField('comment', 'missing') === false, 'Missing identity returns false');
    $writer->update('glpi_users', $target, ['comment' => 'Duplicate identity']);
    $writer->update('glpi_users', $other, ['comment' => 'Duplicate identity']);
    verify(User::getIdByField('comment', 'Duplicate identity') === false, 'Ambiguous identity does not choose an account');
    $one = $fixtures->create('glpi_useremails', ['users_id' => $target, 'email' => "o'reilly@example.invalid", 'is_default' => true]);
    $two = $fixtures->create('glpi_useremails', ['users_id' => $target, 'email' => 'second@example.invalid']);
    $fixtures->create('glpi_useremails', ['users_id' => $other, 'email' => "o'reilly@example.invalid"]);
    verify(array_column($repo->emails($target), 'id') === [$one, $two], 'Email list is scoped and ordered');
    verify($repo->preferredByEmail("O'REILLY@EXAMPLE.INVALID") === $target, 'Email lookup prefers active account and ignores case');
    verify(User::getOrImportByEmail(addslashes("o'reilly@example.invalid")) === $target, 'Existing-email public lookup uses mapped join');
    $writer->update('glpi_users', $other, ['is_active' => true]);
    $writer->update('glpi_users', $target, ['is_deleted' => true]);
    verify($repo->preferredByEmail("o'reilly@example.invalid") === $other, 'Among active accounts, prefer nondeleted');
    $writer->update('glpi_users', $target, ['is_deleted' => false]);
    verify($repo->preferredByEmail("o'reilly@example.invalid") === $target, 'Equal email priorities use stable account ID');
    verify($repo->preferredByEmail('absent@example.invalid') === null, 'Missing email returns null without LDAP import');

    $profileA = (new Profile())->add(['name' => 'Target only profile']);
    $profileB = (new Profile())->add(['name' => 'Target second profile']);
    $entity = (new Entity())->add(['name' => 'User preference entity', 'entities_id' => 0]);
    foreach ([[$profileA, 0], [$profileA, $entity], [$profileB, 0]] as [$profileId, $entityId]) {
        $fixtures->create('glpi_profiles_users', ['users_id' => $target, 'profiles_id' => $profileId, 'entities_id' => $entityId]);
    }
    verify($repo->profiles($target) === [$profileA => 'Target only profile', $profileB => 'Target second profile'], 'Profile labels deduplicate entity grants');
    $model = new User();
    verify($model->update(['id' => $target, 'profiles_id' => $profileA]), 'Set assigned default profile');
    $model->update(['id' => $target, 'profiles_id' => $replacement]);
    verify((int)$read('glpi_users', $target)['profiles_id'] === $profileA, 'Unassigned preference rejected');
    $model->update(['id' => $target, 'profiles_id' => -1]);
    verify((int)$read('glpi_users', $target)['profiles_id'] === $profileA, 'Invalid negative profile remains rejected');
    verify($model->update(['id' => $target, 'profiles_id' => 0]), 'Clear default profile');
    verify($read('glpi_users', $target)['profiles_id'] === null, 'Cleared profile persists as NULL');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->profiles($target);
    $repo->emails($target);
    $repo->preferredByEmail("o'reilly@example.invalid");
    $repo->uniqueId('name', "Preference O'Reilly\\account");
    $repo->defaultProfileReplacements($profileA, $profileB);
    verify($SQL_TOTAL_REQUEST === 0, 'User metadata queries use ORM');
    ob_start();
    $model->showMyForm('/front/preference.php', $target);
    $html = ob_get_clean();
    verify(str_contains($html, 'Target only profile') && str_contains($html, 'Target second profile'), 'Preference form uses target account profiles');
    verify(str_contains($html, 'second@example.invalid'), 'Preference form renders target emails');
    $permissions = new \itsmng\Database\Repository\ProfileRepository(Orm::create($DB));
    $low = $fixtures->create('glpi_profiles', ['name' => 'Lower rights', 'interface' => 'central']);
    $high = $fixtures->create('glpi_profiles', ['name' => 'Higher rights', 'interface' => 'central']);
    $missing = $fixtures->create('glpi_profiles', ['name' => 'Missing right', 'interface' => 'central']);
    $helpdesk = $fixtures->create('glpi_profiles', ['name' => 'Helpdesk rights', 'interface' => 'helpdesk']);
    foreach ([[$low, 1], [$high, 3]] as [$profileId, $bits]) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profileId, 'name' => 'ticket', 'rights' => $bits]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profileId, 'name' => 'computer', 'rights' => 0]);
    }
    verify($permissions->canManage([$low], ['ticket' => 1, 'computer' => 0], 'central', false), 'Equal rights allow profile management');
    verify(!$permissions->canManage([$high], ['ticket' => 1, 'computer' => 0], 'central', false), 'Additional target rights deny management');
    verify(!$permissions->canManage([$missing], ['ticket' => 1, 'computer' => 0], 'central', false), 'Missing registered rights deny management');
    verify($permissions->canManage([$low, $high], ['ticket' => 3, 'computer' => 0], 'central', false), 'Superset permits all requested profiles');
    verify($permissions->canManage([$helpdesk], ['ticket' => 0], 'central', false), 'Central interface retains access to helpdesk profiles');
    verify(!$permissions->canManage([$low], ['ticket' => 3, 'computer' => 0], 'helpdesk', false), 'Helpdesk cannot manage central profile');
    verify($permissions->canManage([$high], [], 'helpdesk', true), 'Profile creation right permits existing profiles');
    verify(!$permissions->canManage([2147483647], [], 'central', true), 'Unknown profile does not pass permission check');
    verify(!$permissions->canManage([], ['ticket' => 1], 'central', false) && $permissions->canManage([], [], 'central', true), 'Empty list checks all profiles');
    $activeProfile = $_SESSION['glpiactiveprofile'];
    unset($_SESSION['glpiactiveprofile']);
    verify(!Profile::currentUserHaveMoreRightThan([$low]), 'No active profile denies management');
    $_SESSION['glpiactiveprofile'] = $activeProfile;
    verify((new ForeignKeys())->audit($connection) === [], 'User metadata graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new UserMetadataReferences();
$legacy = null;
try {
    foreach (OptionalReferences::USER_METADATA as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_users');
    $connection->insert('glpi_users', ['id' => $legacy, 'name' => 'Legacy user metadata']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy user metadata migration has a plan');
    verify((int)$connection->fetchOne('SELECT usercategories_id FROM glpi_users WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_users', ['usercategories_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned user metadata');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_users')['usercategories_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_users', ['usercategories_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT usercategories_id FROM glpi_users WHERE id = ?', [$legacy]) === null, 'Legacy user category becomes NULL');
    verify($migration->apply($connection) === [], 'User metadata migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_users', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": user metadata lifecycle, profile preferences, email and identity queries, and migration passed.\n";
