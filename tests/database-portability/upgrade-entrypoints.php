<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Upgrade;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/upgrade-entrypoints.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
$temporary = sys_get_temp_dir() . '/itsm-upgrade-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
mkdir($temporary . '/plugins/upgrade_fixture', 0700, true);
file_put_contents($temporary . '/plugins/upgrade_fixture/setup.php', '<?php function plugin_version_upgrade_fixture() { return ["name" => "Upgrade fixture", "version" => "1.0.0", "requirements" => ["glpi" => ["min" => "9.5.0"]]]; }');
define('PLUGINS_DIRECTORIES', [GLPI_ROOT . '/plugins', $temporary . '/plugins']);
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
function command(array $arguments): array
{
    $pipes = [];
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, GLPI_ROOT);
    verify(is_resource($process), 'Subprocess starts');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable fixture database required');
verify(History::pendingVersions($DB->getDoctrineConnection()) === [], 'Install canonical history before this entrypoint contract');
$connection = $DB->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$platform = $connection->getDatabasePlatform();
$upgrade = new Upgrade($DB);
$version = History::VERSIONS[array_key_last(History::VERSIONS)];
$originalLedger = $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version');
$originalRelease = $upgrade->release();
$originalLocks = $connection->fetchAllAssociative("SELECT name, value FROM glpi_configs WHERE context = 'core' AND name IN ('lock_use_lock_item', 'lock_lockprofile_id')");
$originalOidc = $connection->fetchAllAssociative('SELECT * FROM glpi_oidc_config ORDER BY id');
$originalRights = $connection->fetchAllAssociative('SELECT id, name, rights FROM glpi_profilerights ORDER BY id');
$maxLog = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM glpi_logs');
mkdir($temporary . '/cookies', 0700);
mkdir($temporary . '/files', 0700);
$key = $upgrade->expectedSecurityKeyPath();
verify($key !== null && !$upgrade->isSecurityKeyMissing(), 'Existing installation encryption key required');
$keyHash = hash_file('sha256', $key);
// Keep only the encryption-key fixture on its original filesystem. PHP rename()
// across devices copies/unlinks the file and loses its original inode.
$keyNode = static function (string $path): array|false {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if ($stat === false) {
        return false;
    }
    return array_intersect_key($stat, array_flip(['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size']));
};
$originalKeyNode = $keyNode($key);
verify($originalKeyNode !== false && ($originalKeyNode['mode'] & 0170000) === 0100000 && $originalKeyNode['nlink'] === 1, 'Original key is an ordinary single-link file');
$keyIsOriginal = static fn (string $path): bool => $keyNode($path) === $originalKeyNode && hash_file('sha256', $path) === $keyHash;
$keyHolding = $keyBackup = $keyHoldingNode = null;
$keyCleanupErrors = [];
$primaryError = null;
$holdingIsOwned = static function () use (&$keyHolding, &$keyHoldingNode): bool {
    if ($keyHolding === null || $keyHoldingNode === null) {
        return false;
    }
    clearstatcache(true, $keyHolding);
    $stat = lstat($keyHolding);
    return $stat !== false && array_intersect_key($stat, array_flip(['dev', 'ino', 'uid', 'gid', 'mode'])) === $keyHoldingNode;
};
$restoreKey = static function () use (&$keyHolding, &$keyBackup, $holdingIsOwned, $keyIsOriginal, $key): void {
    if ($keyHolding === null) {
        return;
    }
    verify($holdingIsOwned(), 'Key holding directory retains its exclusively owned identity');
    clearstatcache(true, $keyBackup);
    if (file_exists($keyBackup) || is_link($keyBackup)) {
        verify($keyIsOriginal($keyBackup), 'Only the original key inode and bytes may be restored');
        clearstatcache(true, $key);
        verify(!file_exists($key) && !is_link($key), 'Restoration never overwrites an unexpected key-path occupant');
        verify(rename($keyBackup, $key), 'Same-device original key restoration succeeds');
    }
    verify($keyIsOriginal($key), 'Key restoration preserves the original inode, ownership, mode and bytes');
};
$server = null;
$user = $profile = $membership = $right = $plugin = $audit = null;
$beforeColumns = null;
$cli = static fn (array $args): array => command([PHP_BINARY, GLPI_ROOT . '/bin/console', '--config-dir=' . GLPI_CONFIG_DIR, '--no-interaction', ...$args]);
$pending = static function () use ($connection, $version): void {
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
};
$snapshot = static fn (): array => [
    $connection->fetchAllAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' ORDER BY version'),
    $upgrade->release(),
    $connection->fetchAllAssociative('SELECT id, password FROM glpi_users ORDER BY id'),
    $connection->fetchAllAssociative('SELECT id, rights FROM glpi_profilerights ORDER BY id'),
    $connection->fetchAllAssociative('SELECT id, state FROM glpi_plugins ORDER BY id'),
    $connection->fetchAllAssociative('SELECT * FROM glpi_oidc_config ORDER BY id'),
    $connection->fetchAllAssociative('SELECT id, old_value, new_value FROM glpi_logs ORDER BY id'),
];
try {
    $fixtures = new FixtureRecords($DB);
    $name = 'upgrade_viewer_' . bin2hex(random_bytes(4));
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Upgrade read-only viewer', 'interface' => 'central']);
    $user = $fixtures->create('glpi_users', ['name' => $name, 'password' => Auth::getPasswordHash('UpgradeFixturePassword'), 'authtype' => Auth::DB_GLPI, 'profiles_id' => $profile, 'is_active' => true]);
    $membership = $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => 0]);
    $right = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'config', 'rights' => READ]);
    $bootstrap = $temporary . '/bootstrap.php';
    file_put_contents($bootstrap, '<?php define("GLPI_CONFIG_DIR", ' . var_export(GLPI_CONFIG_DIR, true) . '); define("GLPI_VAR_DIR", ' . var_export($temporary . '/files', true) . ');');
    // Let the checked-in configuration bootstrap create every required data directory.
    foreach (get_defined_constants() as $constant => $path) {
        if (preg_match('/^GLPI_\w+_DIR$/D', $constant) && is_string($path) && str_starts_with($path, GLPI_VAR_DIR . '/')) {
            $target = $temporary . '/files' . substr($path, strlen(GLPI_VAR_DIR));
            is_dir($target) || mkdir($target, 0700, true);
        }
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    verify(is_resource($socket), 'Reserve HTTP fixture port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $bootstrap, '-S', $address, '-t', GLPI_ROOT], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['redirect', 1]], $pipes, GLPI_ROOT);
    verify(is_resource($server), 'HTTP server starts');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $probe = @stream_socket_client('tcp://' . $address, $errorNumber, $errorText, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            break;
        }
        usleep(100000);
    }
    $http = static function (string $phase) use ($address, $temporary, $name): void {
        [$status, $output] = command(['python3', GLPI_ROOT . '/tests/database-portability/upgrade-entrypoints-web.py', 'http://' . $address, $phase, $temporary . '/cookies', $name]);
        verify($status === 0, 'Actual HTTP ' . $phase . ': ' . $output);
    };
    $http('login');

    $plugin = $fixtures->create('glpi_plugins', ['name' => 'Upgrade fixture', 'directory' => 'upgrade_fixture', 'state' => Plugin::ACTIVATED]);
    $audit = $fixtures->create('glpi_logs', ['itemtype' => 'User', 'items_id' => $user, 'old_value' => "Existing audit O'Reilly 日本語", 'new_value' => 'Preserve history']);
    $connection->update('glpi_oidc_config', ['is_activate' => true, 'is_forced' => true], ['id' => 0], ['is_activate' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'is_forced' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    foreach ($originalRights as $row) {
        if (in_array($row['name'], ['followup', 'task'], true)) {
            $connection->update('glpi_profilerights', ['rights' => 0], ['id' => $row['id']]);
        }
    }
    foreach (['version', 'itsmversion'] as $field) {
        $connection->update('glpi_configs', ['value' => '2.1.6'], ['context' => 'core', 'name' => $field]);
    }
    $connection->update('glpi_configs', ['value' => '1'], ['context' => 'core', 'name' => 'lock_use_lock_item']);
    $connection->update('glpi_configs', ['value' => (string)$profile], ['context' => 'core', 'name' => 'lock_lockprofile_id']);
    $pending();
    $before = $snapshot();
    $readDatabase = new class () extends DB {
        public function isSlave()
        {
            return true;
        }
    };
    $readUpgrade = new Upgrade($readDatabase);
    verify($readUpgrade->plan()['pending'] === [$version], 'Read-route preview retains its supplied connection and stays read-only');
    try {
        $readUpgrade->apply();
        throw new LogicException('Read-route upgrade was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'configured write connection'), 'Read-route apply has an actionable refusal');
    }
    verify($snapshot() === $before, 'Read-route preview/refusal preserves database state');
    foreach ([['db:migrate'], ['db:legacy_to_orm'], ['itsmng:database:legacy_to_orm'], ['db:update', '--dry-run']] as $args) {
        [$status, $output] = $cli($args);
        verify($status === 0 && str_contains($output, 'Pending history: ' . $version) && str_contains($output, 'No changes.'), 'Actual CLI canonical preview: ' . implode(' ', $args) . ': ' . $output);
        verify($snapshot() === $before, 'Preview preserves ledger, release, credentials, rights, plugins, OIDC and audit');
    }
    [$status, $output] = $cli(['task:unlock', '--all']);
    verify($status === 129 && str_contains($output, 'Canonical history is pending'), 'Ordinary CLI writes refuse pending history despite current database release strings');
    [$status, $output] = $cli(['db:check']);
    verify($status === 0, 'Read-only schema diagnostics remain available during pending history: ' . $output);
    $http('preview');
    verify($snapshot() === $before, 'Authenticated HTTP preview and anonymous/read-only denials make no changes');

    $holdingCandidate = GLPI_CONFIG_DIR . '/.upgrade-key-' . bin2hex(random_bytes(8));
    verify(mkdir($holdingCandidate, 0700), 'Create an exclusive private key holding directory');
    $keyHolding = $holdingCandidate;
    $keyBackup = $keyHolding . '/original.key';
    $holdingStat = lstat($keyHolding);
    verify($holdingStat !== false, 'Inspect the newly owned key holding directory');
    $keyHoldingNode = array_intersect_key($holdingStat, array_flip(['dev', 'ino', 'uid', 'gid', 'mode']));
    verify(($holdingStat['mode'] & 0177777) === 0040700 && $holdingStat['dev'] === $originalKeyNode['dev'] && $holdingStat['uid'] === $originalKeyNode['uid'], 'Private holding directory shares the original key filesystem and owner');
    verify($holdingIsOwned() && $keyIsOriginal($key) && !file_exists($keyBackup) && !is_link($keyBackup), 'Only the original key moves into its empty owned destination');
    verify(rename($key, $keyBackup), 'Same-device original key withdrawal succeeds');
    clearstatcache(true, $key);
    verify(!file_exists($key) && !is_link($key) && $keyIsOriginal($keyBackup), 'Lost-key fixture retains the original inode and bytes privately');
    $keyPhaseError = null;
    try {
        foreach ([['db:update'], ['db:update', '--force'], ['db:migrate', '--apply']] as $args) {
            [$status, $output] = $cli($args);
            verify($status === 2 && str_contains($output, 'original encryption key'), 'CLI apply refuses lost key without replacing it: ' . $output);
        }
        $alias = $connection->fetchAssociative("SELECT * FROM glpi_configs WHERE context = 'core' AND name = 'itsmversion'");
        $connection->delete('glpi_configs', ['context' => 'core', 'name' => 'itsmversion']);
        try {
            [$status, $output] = $cli(['db:migrate', '--apply']);
            verify($status === 2 && str_contains($output, 'original encryption key'), 'Missing ITSM alias cannot bypass inherited-key policy');
        } finally {
            $connection->insert('glpi_configs', $alias);
        }
        mkdir($key);
        try {
            verify($upgrade->isSecurityKeyMissing(), 'A directory cannot substitute for the existing encryption key file');
        } finally {
            rmdir($key);
        }
        $http('missing-key');
        verify(!file_exists($key) && $snapshot() === $before, 'Lost key changes neither encrypted data nor history/release state');
    } catch (Throwable $error) {
        $keyPhaseError = $error;
        throw $error;
    } finally {
        try {
            $restoreKey();
        } catch (Throwable $error) {
            if ($keyPhaseError === null) {
                throw $error;
            }
            $keyCleanupErrors[] = $error;
        }
    }

    $beforeColumns = $manager->introspectTable('glpi_profiles');
    $without = clone $beforeColumns;
    $without->dropColumn('helpdesk_hardware');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($beforeColumns, $without)) as $sql) {
        $connection->executeStatement($sql);
    }
    [$status, $output] = $cli(['db:update']);
    verify($status !== 0 && str_contains($output, 'Missing column: glpi_profiles.helpdesk_hardware') && str_contains($output, 'matching historical application'), 'Unsupported older shape has actionable prerequisites before adoption DDL: ' . $output);
    $http('unsupported');
    verify($snapshot() === $before, 'Unsupported schema refusal changes neither ledger nor release/customer data');
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($manager->introspectTable('glpi_profiles'), $beforeColumns)) as $sql) {
        $connection->executeStatement($sql);
    }
    $beforeColumns = null;

    $connection->executeStatement('ALTER TABLE glpi_configs RENAME COLUMN context TO legacy_context_fixture');
    try {
        [$status, $output] = $cli(['db:update']);
        verify($status !== 0 && str_contains($output, 'Missing column: glpi_configs.context') && str_contains($output, 'matching historical application'), 'Historical config bootstrap reaches supported-shape diagnostics before current ORM hydration: ' . $output);
        $http('unsupported');
    } finally {
        $connection->executeStatement('ALTER TABLE glpi_configs RENAME COLUMN legacy_context_fixture TO context');
    }
    verify($snapshot() === $before, 'Historical config bootstrap diagnostics preserve customer data and receipts');

    $baselineRow = $connection->fetchAssociative('SELECT version, state FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [\itsmng\Database\Migration\Baseline20261001::VERSION]);
    Ledger::save($connection, $baselineRow['version'], ['origin' => 'installed', 'complete' => false]);
    try {
        [$status, $output] = $cli(['db:migrate']);
        verify($status !== 0 && str_contains($output, 'db:install'), 'Interrupted installation routes to its existing baseline/seed resume before schema prerequisites');
    } finally {
        $connection->update(LegacyToOrm::LEDGER, ['state' => $baselineRow['state']], ['version' => $baselineRow['version']]);
    }
    $connection->update('glpi_configs', ['value' => '9.9.9'], ['context' => 'core', 'name' => 'itsmversion']);
    try {
        [$status, $output] = $cli(['db:update', '--dry-run']);
        verify($status !== 0 && str_contains($output, 'cannot downgrade'), 'Future application metadata refuses a downgrade before migration/publication');
    } finally {
        $connection->update('glpi_configs', ['value' => '2.1.6'], ['context' => 'core', 'name' => 'itsmversion']);
    }

    $connection->insert(LegacyToOrm::LEDGER, ['version' => $version, 'state' => '{broken']);
    $http('broken-ledger');
    [$status, $output] = $cli(['task:unlock', '--all']);
    verify($status === 129 && str_contains($output, 'ledger could not be validated'), 'Broken receipts deny ordinary CLI requests with actionable diagnostics');
    $pending();

    foreach ([['db:update'], ['db:migrate', '--apply'], ['db:legacy_to_orm', '--apply'], ['itsmng:database:update', '--force']] as $args) {
        $pending();
        foreach (['version', 'itsmversion'] as $field) {
            $connection->update('glpi_configs', ['value' => '2.1.6'], ['context' => 'core', 'name' => $field]);
        }
        $preserved = array_slice($snapshot(), 2, 4);
        [$status, $output] = $cli($args);
        verify($status === 0 && str_contains($output, 'Canonical database history complete'), 'Actual CLI canonical apply: ' . implode(' ', $args) . ': ' . $output);
        verify(History::pendingVersions($connection) === [], 'CLI applies every pending canonical migration');
        verify(array_slice($snapshot(), 2, 4) === $preserved, 'CLI preserves passwords, customized rights, active plugins and OIDC configuration');
        verify(count($upgrade->release()) === 4 && array_diff_assoc($upgrade->release(), ['version' => ITSM_VERSION, 'itsmversion' => ITSM_VERSION, 'dbversion' => ITSM_SCHEMA_VERSION, 'itsmdbversion' => ITSM_SCHEMA_VERSION]) === [], 'Release metadata publishes only after canonical convergence');
    }
    foreach (['version', 'itsmversion'] as $field) {
        $connection->update('glpi_configs', ['value' => '2.1.6'], ['context' => 'core', 'name' => $field]);
    }
    $beforeVeto = $snapshot();
    $originalHook = $PLUGIN_HOOKS['pre_item_update'] ?? null;
    $PLUGIN_HOOKS['pre_item_update']['upgrade_fixture']['Config'] = static function (Config $config): void {
        if ($config->fields['name'] === 'itsmversion') {
            $config->input = false;
        }
    };
    try {
        $upgrade->apply();
        throw new LogicException('Configuration lifecycle veto was ignored');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'publication was rejected for itsmversion'), 'Public upgrade facade detects a genuine Config lifecycle veto');
        verify($snapshot() === $beforeVeto, 'Publication rolls back both earlier config updates and their audit history after a later hook veto');
    } finally {
        if ($originalHook === null) {
            unset($PLUGIN_HOOKS['pre_item_update']);
        } else {
            $PLUGIN_HOOKS['pre_item_update'] = $originalHook;
        }
    }
    if ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        $connection->executeStatement('ALTER TABLE glpi_configs ENGINE=MyISAM');
        try {
            $upgrade->apply();
            throw new LogicException('Nontransactional release publication was accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'glpi_configs to use InnoDB'), 'Release publication rejects nontransactional config storage before history writes');
            verify($snapshot() === $beforeVeto, 'Engine refusal leaves config, audit, history and customer data unchanged');
        } finally {
            $connection->executeStatement('ALTER TABLE glpi_configs ENGINE=InnoDB');
        }
    }
    $upgrade->apply();
    $beforeRetry = $snapshot();
    [$status, $output] = $cli(['db:update', '--force']);
    verify($status === 0 && $snapshot() === $beforeRetry, 'Force retries history idempotently without replaying old scripts or creating duplicate audit records');
    $pending();
    $http('apply');
    verify(History::pendingVersions($connection) === [], 'Actual authenticated web POST completes canonical history');
    $pending();
    (new Update($DB))->doUpdates();
    verify(History::pendingVersions($connection) === [], 'Existing public Update facade follows canonical history on both providers');
    verify($keyIsOriginal($key), 'Every entrypoint preserves the original key inode, ownership and mode');
    verify(hash_file('sha256', $key) === $keyHash, 'Every entrypoint preserves the original key bytes');
    verify($connection->fetchOne('SELECT old_value FROM glpi_logs WHERE id = ?', [$audit]) === "Existing audit O'Reilly 日本語", 'Existing audit history survives every supported upgrade entrypoint');
    verify((int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_logs WHERE itemtype = 'Config' AND id > ?", [$maxLog]) >= 2, 'Release publication retains Config audit hooks');
} catch (Throwable $error) {
    $primaryError = $error;
    throw $error;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    try {
        $restoreKey();
        if ($keyHolding !== null) {
            verify($holdingIsOwned() && scandir($keyHolding) === ['.', '..'], 'Retire only the empty original owned key holding directory');
            verify(rmdir($keyHolding), 'Owned key holding directory retirement succeeds');
        }
    } catch (Throwable $error) {
        $keyCleanupErrors[] = $error;
    }
    if ($beforeColumns !== null) {
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($manager->introspectTable('glpi_profiles'), $beforeColumns)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    foreach ($originalLedger as $row) {
        if ($row['version'] === $version) {
            $connection->insert(LegacyToOrm::LEDGER, $row);
        }
    }
    foreach ($originalRelease as $field => $value) {
        $connection->update('glpi_configs', ['value' => $value], ['context' => 'core', 'name' => $field]);
    }
    foreach ($originalLocks as $row) {
        $connection->update('glpi_configs', ['value' => $row['value']], ['context' => 'core', 'name' => $row['name']]);
    }
    foreach ($originalOidc as $row) {
        $connection->update('glpi_oidc_config', $row, ['id' => $row['id']]);
    }
    foreach ($originalRights as $row) {
        $connection->update('glpi_profilerights', ['rights' => $row['rights']], ['id' => $row['id']]);
    }
    foreach ([['glpi_profiles_users', $membership], ['glpi_profilerights', $right], ['glpi_users', $user], ['glpi_profiles', $profile], ['glpi_plugins', $plugin], ['glpi_logs', $audit]] as [$table, $id]) {
        if ($id !== null) {
            $connection->delete($table, ['id' => $id]);
        }
    }
    $connection->executeStatement("DELETE FROM glpi_logs WHERE itemtype = 'Config' AND id > ?", [$maxLog]);
    $DB->clearSchemaCache();
    $GLPI_CACHE->clear();
    if ($keyCleanupErrors !== []) {
        if ($primaryError === null) {
            throw $keyCleanupErrors[0];
        }
        try {
            fwrite(STDERR, 'Additional owned key cleanup failure; original contract failure retained.' . "\n");
        } catch (Throwable) {
            // Reporting must not replace the original contract failure.
        }
    }
}
echo $DB->getProvider() . ": canonical CLI/web upgrades, authorization, readiness, preview, retry, prerequisites and customer-data preservation passed.\n";
