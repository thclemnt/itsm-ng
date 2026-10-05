<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\V220\CronLogReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CronLogRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/cron-logs.php /path/to/test-config\n");
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
if (($argv[2] ?? '') === '--claim') {
    $task = new CronTask();
    verify($task->getFromDB((int)$argv[3]), 'Load race task');
    touch($argv[4] . '.' . $argv[5]);
    $deadline = microtime(true) + 20;
    while (!is_file($argv[4]) && microtime(true) < $deadline) {
        usleep(10000);
    }
    verify(is_file($argv[4]), 'Claim barrier timeout');
    exit($task->start() ? 10 : 20);
}
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$newTask = static fn (string $name): int => $fixtures->create('glpi_crontasks', ['itemtype' => 'CronTask', 'name' => $name, 'state' => CronTask::STATE_WAITING]);
$DB->beginTransaction();
try {
    $taskId = $newTask('ORM execution ' . bin2hex(random_bytes(4)));
    $task = new CronTask();
    $second = new CronTask();
    verify($task->getFromDB($taskId) && $second->getFromDB($taskId), 'Load task twice');
    verify($task->start() && !$second->start(), 'Only one start claims a waiting task');
    verify(str_ends_with($read('glpi_crontasks', $taskId)['lastrun'], ':00'), 'Start timestamp is rounded to a minute');
    $task->setVolume(3);
    verify((int)$task->log("Step O'Reilly") > 0, 'Append progress log');
    $task->addVolume(2);
    verify($task->end(1) && !$task->end(1), 'Finish transitions once');
    $repo = new CronLogRepository(Orm::create($DB));
    $history = $repo->history($taskId, 10, 0);
    verify(count($history) === 1 && (int)$history[0]['volume'] === 5, 'History records completed volume');
    $root = (int)$history[0]['crontasklogs_id'];
    $details = $repo->details($taskId, $root);
    verify(count($details) === 3 && $details[0]['crontasklogs_id'] === null && $details[1]['content'] === "Step O'Reilly", 'Start/progress/finish share a mapped run root');
    $failureId = $newTask('ORM failed logging ' . bin2hex(random_bytes(4)));
    $failureTask = new CronTask();
    verify($failureTask->getFromDB($failureId), 'Load failure probe');
    $validTime = $_SESSION['glpi_currenttime'];
    try {
        $_SESSION['glpi_currenttime'] = 'invalid-cron-log-date';
        $failed = false;
        try {
            $failureTask->start();
        } catch (Throwable $error) {
            $failed = true;
        }
        verify($failed && (int)$read('glpi_crontasks', $failureId)['state'] === CronTask::STATE_WAITING, 'Failed start log rolls back task claim');
        $_SESSION['glpi_currenttime'] = $validTime;
        verify($failureTask->start(), 'Start after failed log');
        $_SESSION['glpi_currenttime'] = 'invalid-cron-log-date';
        $failed = false;
        try {
            $failureTask->end(1);
        } catch (Throwable $error) {
            $failed = true;
        }
        verify($failed && (int)$read('glpi_crontasks', $failureId)['state'] === CronTask::STATE_RUNNING, 'Failed finish log rolls back state transition');
    } finally {
        $_SESSION['glpi_currenttime'] = $validTime;
    }
    verify($failureTask->end(1), 'Finish after failed log');
    $otherTask = $newTask('ORM other task ' . bin2hex(random_bytes(4)));
    verify($repo->details($otherTask, $root) === [] && $repo->details($taskId, 0) === [], 'Run details require the owning task and a real log ID');
    $writer = new RecordWriter(Orm::create($DB));
    $log = static fn (int $task, ?int $parent, string $date, int $state, float $elapsed = 0, int $volume = 0): int => $writer->insert('glpi_crontasklogs', [
        'crontasks_id' => $task, 'crontasklogs_id' => $parent, 'date' => $date, 'state' => $state, 'elapsed' => $elapsed, 'volume' => $volume,
    ]);
    $statsTask = $newTask('ORM statistics ' . bin2hex(random_bytes(4)));
    $first = $log($statsTask, null, '2030-01-01 10:00:00', CronTaskLog::STATE_START);
    $one = $log($statsTask, $first, '2030-01-01 10:00:01', CronTaskLog::STATE_STOP, 1.5, 3);
    $two = $log($statsTask, $first, '2030-01-01 10:00:02', CronTaskLog::STATE_STOP, 2.5, 5);
    $error = $log($statsTask, $first, '2030-01-01 10:00:03', CronTaskLog::STATE_ERROR, 999, 999);
    $stats = $repo->statistics($statsTask);
    verify((float)$stats['elapsedtot'] === 4.0 && (float)$stats['elapsedavg'] === 2.0 && (int)$stats['voltot'] === 8 && (float)$stats['volavg'] === 4.0, 'Statistics include successful stops only');
    verify($stats['datemin'] === '2030-01-01 10:00:01', 'Statistics start date');
    verify(array_column($repo->history($statsTask, 1, 1), 'id') === [$two], 'History pagination is stable and includes errors');
    $middle = $log($statsTask, $first, '2030-01-01 10:00:04', CronTaskLog::STATE_RUN);
    $leaf = $log($statsTask, $middle, '2030-01-01 10:00:05', CronTaskLog::STATE_RUN);
    verify((new CronTaskLog())->delete(['id' => $middle], true), 'Remove individual log');
    verify((int)$read('glpi_crontasklogs', $leaf)['crontasklogs_id'] === $first, 'Individual purge preserves descendants under the previous parent');
    $retentionTask = $newTask('ORM retention ' . bin2hex(random_bytes(4)));
    $oldRoot = $log($retentionTask, null, '2030-01-01 00:00:00', CronTaskLog::STATE_START);
    for ($i = 0; $i < 1001; ++$i) {
        $log($retentionTask, $oldRoot, '2030-01-02 00:00:00', CronTaskLog::STATE_RUN);
    }
    $recent = $log($retentionTask, $oldRoot, '2030-01-09 00:00:00', CronTaskLog::STATE_STOP);
    $expiredRoot = $log($retentionTask, null, '2030-01-01 00:00:00', CronTaskLog::STATE_START);
    $log($retentionTask, $expiredRoot, '2030-01-02 00:00:00', CronTaskLog::STATE_STOP);
    $boundary = $log($retentionTask, null, '2030-01-03 00:00:00', CronTaskLog::STATE_RUN);
    $now = new DateTimeImmutable('2030-01-10 00:00:00');
    verify($repo->expire($retentionTask, 7, $now) === 1003, 'Retention deletes expired leaves over multiple batches, then their expired root');
    verify($read('glpi_crontasklogs', $oldRoot) !== null && $read('glpi_crontasklogs', $recent) !== null && $read('glpi_crontasklogs', $boundary) !== null, 'Newer children retain their root; exact cutoff is kept');
    verify($repo->expire($retentionTask, 7, $now) === 0, 'Retention is idempotent');
    verify($read('glpi_crontasklogs', $one) !== null, 'Retention isolates task');
    $activeTask = $newTask('ORM active retention ' . bin2hex(random_bytes(4)));
    $activeRoot = $log($activeTask, null, '2030-01-01 00:00:00', CronTaskLog::STATE_START);
    $writer->update('glpi_crontasks', $activeTask, ['state' => CronTask::STATE_RUNNING]);
    verify($repo->expire($activeTask, 7, $now) === 0 && $read('glpi_crontasklogs', $activeRoot) !== null, 'Active run retains its start record even when old');
    verify($repo->finish($activeTask, CronTask::STATE_WAITING) && $repo->expire($activeTask, 7, $now) === 1, 'Completed run can expire');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->history($taskId, 10, 0);
    $repo->details($taskId, $root);
    $repo->statistics($statsTask);
    $repo->expire($retentionTask, 7, $now);
    verify($SQL_TOTAL_REQUEST === 0, 'Log reads and retention use ORM');
    verify($task->getFromDB($statsTask), 'Load statistics view');
    ob_start();
    $task->showStatistics();
    $task->showHistoryDetail($first);
    $task->showHistory();
    $html = ob_get_clean();
    verify(str_contains($html, 'Statistics') && str_contains($html, 'Activity Log'), 'Statistics and history render');
    verify((new CronTaskLog())->delete(['id' => $first], true), 'Remove run root while retaining messages');
    ob_start();
    $task->showHistory();
    $rootlessHtml = ob_get_clean();
    verify(str_contains($rootlessHtml, 'crontasklogs_id=' . $two . '");'), 'Rootless completion links to its own detail');
    verify(count($repo->details($statsTask, $two)) === 1, 'Rootless completion remains readable');
    verify($task->delete(['id' => $statsTask], true), 'Purge task with log hierarchy');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_crontasklogs', ['crontasks_id' => $statsTask]) === 0, 'Task purge removes all logs through hooks');
    verify((new ForeignKeys())->audit($connection) === [], 'Log graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$migration = new CronLogReferences();
$legacyTask = $newTask('ORM legacy logs ' . bin2hex(random_bytes(4)));
$legacy = null;
try {
    foreach (['crontasklogs_id', 'crontasks_id'] as $column) {
        $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name('glpi_crontasklogs', $column), 'glpi_crontasklogs'));
    }
    $connection->executeStatement('UPDATE glpi_crontasklogs SET crontasklogs_id = 0 WHERE crontasklogs_id IS NULL');
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_crontasklogs');
    $after = clone $before;
    $after->getColumn('crontasklogs_id')->setNotnull(true)->setDefault(0);
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_crontasklogs');
    $connection->insert('glpi_crontasklogs', ['id' => $legacy, 'crontasks_id' => 2147483647, 'crontasklogs_id' => 0, 'state' => 0, 'elapsed' => 0, 'volume' => 0]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Orphaned cron task references');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_crontasklogs')['crontasklogs_id']->getNotnull(), 'Task orphan rejected before parent DDL');
    $connection->update('glpi_crontasklogs', ['crontasks_id' => $legacyTask, 'crontasklogs_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned cron log');
    }
    verify($rejected, 'Parent orphan rejected');
    $connection->update('glpi_crontasklogs', ['crontasklogs_id' => $legacy], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Cyclic cron log parents');
    }
    verify($rejected, 'Cyclic log hierarchy rejected');
    $connection->update('glpi_crontasklogs', ['crontasklogs_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT crontasklogs_id FROM glpi_crontasklogs WHERE id = ?', [$legacy]) === null, 'Legacy start parent becomes NULL');
    verify($migration->apply($connection) === [], 'Log migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_crontasklogs', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    (new CronTask())->delete(['id' => $legacyTask], true);
}

// Two independent PHP processes race for a disposable task; no task body runs.
$raceTask = $newTask('ORM concurrent claim ' . bin2hex(random_bytes(4)));
$barrier = tempnam(sys_get_temp_dir(), 'orm-cron-claim-');
unlink($barrier);
$children = [];
try {
    foreach ([1, 2] as $worker) {
        $command = [PHP_BINARY];
        if (ini_get('auto_prepend_file')) {
            array_push($command, '-d', 'auto_prepend_file=' . ini_get('auto_prepend_file'));
        }
        array_push($command, __FILE__, $directory, '--claim', (string)$raceTask, $barrier, (string)$worker);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $barrier . '.' . $worker . '.log', 'w'], 2 => ['file', $barrier . '.' . $worker . '.log', 'a']], $pipes);
        verify(is_resource($process), 'Start claim worker');
        fclose($pipes[0]);
        $children[] = $process;
    }
    $deadline = microtime(true) + 20;
    while ((!is_file($barrier . '.1') || !is_file($barrier . '.2')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    verify(is_file($barrier . '.1') && is_file($barrier . '.2'), 'Both workers reached claim barrier');
    touch($barrier);
    $codes = array_map(proc_close(...), $children);
    $children = [];
    sort($codes);
    verify($codes === [10, 20], 'Exactly one concurrent task claim succeeds');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_crontasklogs', ['crontasks_id' => $raceTask, 'state' => CronTaskLog::STATE_START]) === 1, 'Only the successful claim creates a start log');
} finally {
    touch($barrier);
    foreach ($children as $process) {
        proc_close($process);
    }
    (new CronTask())->delete(['id' => $raceTask], true);
    foreach (glob($barrier . '*') as $file) {
        unlink($file);
    }
}
echo $DB->getProvider() . ": cron log graph, concurrent claims, statistics, history, retention and migration passed.\n";
