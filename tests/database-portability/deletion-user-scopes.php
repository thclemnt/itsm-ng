<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/deletion-user-scopes.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator fixture login');
verify(!$DB->getDoctrineConnection()->isTransactionActive(), 'HTTP fixture graph must be committed and visible to another connection');
$fixtures = new FixtureRecords($DB);
$prefix = 'Scoped deletion ' . bin2hex(random_bytes(5));
$entities = $users = [];
$nestedGroup = null;
$server = null;
$log = tmpfile();
try {
    for ($i = 0; $i < 3; ++$i) {
        $entities[] = $fixtures->create('glpi_entities', ['name' => $prefix . ' entity ' . $i, 'entities_id' => 0]);
    }
    $profile = (int)$_SESSION['glpiactiveprofile']['id'];
    foreach (['detach', 'cancel', 'nested', 'legacy-parent-cancel', 'legacy-cancel', 'legacy-throw', 'structured', 'reused'] as $mode) {
        $users[$mode] = $fixtures->create('glpi_users', ['name' => $prefix . ' ' . $mode]);
        foreach ($mode === 'reused' ? array_slice($entities, 1) : $entities as $entity) {
            $fixtures->create('glpi_profiles_users', ['users_id' => $users[$mode], 'profiles_id' => $profile, 'entities_id' => $entity, 'is_recursive' => false]);
        }
    }
    $nestedGroup = $fixtures->create('glpi_groups', ['name' => $prefix . ' nested']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    verify($socket !== false, 'Reserve private HTTP fixture port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment = getenv();
    $environment['ITSM_DELETION_TOKEN'] = bin2hex(random_bytes(24));
    $environment['ITSM_DELETION_CONFIG'] = GLPI_CONFIG_DIR;
    $server = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', '-S', $address, '-t', sys_get_temp_dir(), __DIR__ . '/fixtures/deletion-user-http.php'],
        [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
        $pipes,
        GLPI_ROOT,
        $environment
    );
    verify(is_resource($server), 'Launch isolated subprocess router without a production route');
    fclose($pipes[0]);
    [$host, $port] = explode(':', $address);
    $ready = false;
    for ($i = 0; $i < 50; ++$i) {
        $probe = @fsockopen($host, (int)$port, $errorCode, $errorMessage, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    verify($ready, 'Private HTTP fixture becomes available');
    foreach (['cancel', 'detach', 'nested', 'legacy-parent-cancel', 'legacy-cancel', 'legacy-throw', 'structured', 'reused'] as $mode) {
        $request = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 30, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n" . 'X-ITSM-Deletion-Token: ' . $environment['ITSM_DELETION_TOKEN'] . "\r\n",
            'content' => json_encode(['id' => $users[$mode], 'mode' => $mode, 'accessible' => array_slice($entities, 0, 2), 'cancel' => $mode === 'cancel'] + ($mode === 'nested' ? ['group' => $nestedGroup] : []) + ($mode === 'reused' ? ['warm_id' => $users['detach']] : []), JSON_THROW_ON_ERROR),
        ]]);
        $body = file_get_contents('http://' . $address . '/', false, $request);
        $response = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if ($mode === 'legacy-throw') {
            verify(($response['message'] ?? '') === 'Expected legacy User override exception', 'Original overridden lifecycle exception propagates');
            $records = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
            verify(
                (new User())->getFromDB($users[$mode]) && array_map('intval', array_column($records->matching('glpi_profiles_users', ['users_id' => $users[$mode]], ['id ASC']), 'entities_id')) === $entities,
                'Throwing legacy override rolls back its parent detachment and retains account'
            );
            continue;
        }
        verify(!isset($response['error']), 'HTTP lifecycle succeeds: ' . ($response['message'] ?? ''));
        verify(!$response['view_all'] && $response['result'] === ($mode === 'nested') && $response['source_exists'], 'Actual scoped backend preserves its public boolean and global account');
        verify(!$response['transaction_active'], 'Scoped lifecycle finishes its owned transaction');
        $cancelled = in_array($mode, ['cancel', 'legacy-parent-cancel', 'legacy-cancel'], true);
        $expected = $cancelled ? $entities : [$entities[2]];
        verify($response['after'] === $expected, $cancelled ? 'Legacy/hook cancellation rolls back attempted grant detachment' : 'Successful structured operation commits both accessible entities and retains inaccessible grants');
        verify(!$response['message_retained'], 'Cancellation does not retain its success message');
        if ($mode === 'reused') {
            verify($response['before'] === array_slice($entities, 1), 'Reused model has a distinct source grant set from its prior account');
        }
        if ($mode === 'nested') {
            verify($response['nested_result'] === false && !(new Group())->getFromDB($nestedGroup), 'Successful nested scoped detachment does not cancel its independent parent purge');
        }
        echo $DB->getProvider() . ': actual HTTP scoped ' . $mode . ' before=' . json_encode($response['before']) . ' after=' . json_encode($response['after']) . "\n";
    }
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'Scoped detachment leaves all enforced references valid');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($nestedGroup !== null && (new Group())->getFromDB($nestedGroup)) {
        (new Group())->delete(['id' => $nestedGroup], true);
    }
    foreach ($users as $id) {
        (new User())->delete(['id' => $id], true);
    }
    foreach ($entities as $id) {
        (new Entity())->delete(['id' => $id], true);
    }
    fclose($log);
}
echo $DB->getProvider() . ": $assertions assertions; actual HTTP scoped detachment and cancelled grant mutation passed.\n";
