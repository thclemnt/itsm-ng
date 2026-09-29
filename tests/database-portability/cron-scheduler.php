<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CronTaskRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/cron-scheduler.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
define('GLPI_CRON_DIR', sys_get_temp_dir() . '/orm-scheduler-' . bin2hex(random_bytes(8)));
mkdir(GLPI_CRON_DIR, 0770);
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
$originalTimezone = date_default_timezone_get();
$DB->setTimezone('Europe/Paris');
verify(date_default_timezone_get() === 'Europe/Paris', 'Timezone-aware test session');
$DB->beginTransaction();
try {
    $em = Orm::create($DB);
    $em->createQueryBuilder()->update(\itsmng\Database\Entity\CronTask::class, 't')
        ->set('t.state', ':disabled')->setParameter('disabled', CronTask::STATE_DISABLE)->getQuery()->execute();
    $fixtures = new FixtureRecords($DB);
    $repo = new CronTaskRepository(Orm::create($DB));
    $writer = new RecordWriter(Orm::create($DB));
    $new = static fn (string $name, array $values = []): int => $fixtures->create('glpi_crontasks', $values + [
        'name' => $name, 'itemtype' => 'CronTask', 'state' => CronTask::STATE_WAITING,
        'mode' => CronTask::MODE_INTERNAL, 'allowmode' => 3, 'hourmin' => 0, 'hourmax' => 24, 'frequency' => 60,
    ]);
    $now = new DateTimeImmutable('2030-01-10 12:00:00');
    $next = static fn (string $name, int $mode = 0, array $plugins = [], array $locks = [], ?DateTimeImmutable $time = null): ?array
        => $repo->next($mode, $name, $plugins, $locks, $time ?? $now);
    $task = $new("Scheduler O'Reilly");
    verify($next("Scheduler O'Reilly")['id'] === $task, 'Bound task name and never-run task');
    verify($next('missing') === null, 'Missing name returns no task');
    verify($next("Scheduler O'Reilly", CronTask::MODE_EXTERNAL) === null, 'Configured mode is required');
    verify($next("Scheduler O'Reilly", locks: ["Scheduler O'Reilly"]) === null, 'Task lock excludes normal run');
    $writer->update('glpi_crontasks', $task, ['lastrun' => '2030-01-10 11:59:01']);
    verify($next("Scheduler O'Reilly") === null, 'Frequency not yet elapsed');
    $writer->update('glpi_crontasks', $task, ['lastrun' => '2030-01-10 11:59:00']);
    verify($next("Scheduler O'Reilly")['id'] === $task, 'Exact frequency boundary is due');
    foreach ([[8, 12, false], [12, 18, true], [12, 12, false], [20, 13, true], [20, 12, false], [12, 8, true]] as [$min, $max, $expected]) {
        $writer->update('glpi_crontasks', $task, ['hourmin' => $min, 'hourmax' => $max]);
        verify(($next("Scheduler O'Reilly") !== null) === $expected, "Allowed-hour boundary $min/$max");
    }
    $writer->update('glpi_crontasks', $task, ['hourmin' => 20, 'hourmax' => 6]);
    verify($next("Scheduler O'Reilly", time: new DateTimeImmutable('2030-01-11 02:00:00'))['id'] === $task, 'Overnight window after midnight');
    $writer->update('glpi_crontasks', $task, ['state' => CronTask::STATE_DISABLE, 'allowmode' => CronTask::MODE_EXTERNAL, 'lastrun' => '2030-01-11 12:00:00']);
    verify($next("Scheduler O'Reilly") === null, 'Disabled task excluded normally');
    verify($next("Scheduler O'Reilly", -CronTask::MODE_INTERNAL) === null, 'Force respects allowed-mode bitmask');
    verify($next("Scheduler O'Reilly", -CronTask::MODE_EXTERNAL, locks: ["Scheduler O'Reilly"])['id'] === $task, 'Force ignores lock, hours, frequency and configured state');
    $writer->update('glpi_crontasks', $task, ['state' => CronTask::STATE_RUNNING]);
    verify($next("Scheduler O'Reilly", -CronTask::MODE_EXTERNAL) === null, 'Force cannot select a running task');

    $spring = $new('spring clock', ['frequency' => 3600, 'lastrun' => '2030-03-31 01:30:00']);
    verify($next('spring clock', time: new DateTimeImmutable('2030-03-31 03:00:00')) === null, 'Spring clock change does not shorten elapsed frequency');
    verify($next('spring clock', time: new DateTimeImmutable('2030-03-31 03:30:00'))['id'] === $spring, 'Spring frequency expires after one real hour');
    $fall = $new('fall clock', ['frequency' => 7200, 'lastrun' => '2030-10-27 01:30:00']);
    verify($next('fall clock', time: new DateTimeImmutable('2030-10-27 03:00:00'))['id'] === $fall, 'Fall clock change does not extend elapsed frequency');
    $writer->update('glpi_crontasks', $spring, ['state' => CronTask::STATE_DISABLE]);
    $writer->update('glpi_crontasks', $fall, ['state' => CronTask::STATE_DISABLE]);

    $plugin = $new('plugin', ['itemtype' => 'PluginSchedulerTask']);
    $namespace = $new('namespace', ['itemtype' => 'GlpiPlugin\\Scheduler\\Task']);
    $otherNamespace = $new('other namespace', ['itemtype' => 'GlpiPlugin\\SchedulerNg\\Task']);
    verify($next('plugin') === null && $next('namespace') === null, 'Inactive plugin tasks excluded');
    verify($next('plugin', plugins: ['scheduler'])['id'] === $plugin && $next('namespace', plugins: ['scheduler'])['id'] === $namespace, 'Both active plugin naming conventions match case-insensitively');
    verify($next('other namespace', plugins: ['scheduler']) === null, 'Namespace boundary excludes similarly named plugin');
    $literal = $new('literal wildcard', ['itemtype' => 'GlpiPlugin\\Sched_%\\Task']);
    verify($next('namespace', plugins: ['Sched_%']) === null && $next('literal wildcard', plugins: ['Sched_%'])['id'] === $literal, 'Plugin wildcards are literal');
    verify(array_column($repo->forPlugin('scheduler'), 'id') === [$plugin, $namespace], 'Unregistration candidates share literal plugin prefixes');
    verify($repo->forPlugin('') === [], 'Empty plugin cannot select all tasks');

    $later = $new('later', ['lastrun' => '2030-01-10 11:58:00']);
    $earlier = $new('earlier', ['lastrun' => '2030-01-10 11:57:00']);
    $never = $new('never');
    $neverSecond = $new('never second');
    verify($next('', plugins: ['scheduler'])['id'] === $never, 'Core precedes plugins and never-run tasks precede dated tasks');
    $writer->update('glpi_crontasks', $never, ['state' => CronTask::STATE_DISABLE]);
    verify($next('', plugins: ['scheduler'])['id'] === $neverSecond, 'ID breaks equal due-time ties');
    $writer->update('glpi_crontasks', $neverSecond, ['state' => CronTask::STATE_DISABLE]);
    verify($next('', plugins: ['scheduler'])['id'] === $earlier, 'Oldest due timestamp precedes newer due task');
    $writer->update('glpi_crontasks', $earlier, ['state' => CronTask::STATE_DISABLE]);
    $writer->update('glpi_crontasks', $later, ['state' => CronTask::STATE_DISABLE]);
    verify($next('', plugins: ['scheduler'])['id'] === $plugin, 'Plugin selected when no eligible core task remains');

    $short = $new('short watcher', ['state' => CronTask::STATE_RUNNING, 'frequency' => 60, 'lastrun' => '2030-01-10 11:58:00']);
    $long = $new('long watcher', ['state' => CronTask::STATE_RUNNING, 'frequency' => 86400, 'lastrun' => '2030-01-10 10:00:00']);
    $null = $new('null watcher', ['state' => CronTask::STATE_RUNNING]);
    $ids = array_column($repo->overdue($now), 'id');
    verify(!in_array($short, $ids, true) && !in_array($long, $ids, true), 'Watcher excludes exact two-frequency/two-hour cutoff');
    $ids = array_column($repo->overdue($now->modify('+1 second')), 'id');
    verify(in_array($short, $ids, true) && in_array($long, $ids, true) && !in_array($null, $ids, true), 'Watcher thresholds are OR, and NULL dates remain excluded');

    $errors = $new('error decisions');
    $log = static fn (int $state): int => $writer->insert('glpi_crontasklogs', [
        'crontasks_id' => $errors, 'crontasklogs_id' => null, 'state' => $state, 'date' => '2030-01-10 11:00:00',
    ]);
    for ($i = 0; $i < 4; ++$i) {
        $log(CronTaskLog::STATE_ERROR);
    }
    verify(!$repo->needsErrorNotification($errors, now: $now), 'Four errors stay below threshold');
    $log(CronTaskLog::STATE_ERROR);
    verify($repo->needsErrorNotification($errors, now: $now), 'Five errors trigger decision');
    for ($i = 0; $i < 12; ++$i) {
        $log(CronTaskLog::STATE_RUN);
    }
    verify($repo->needsErrorNotification($errors, now: $now), 'Progress messages do not displace completed runs');
    for ($i = 0; $i < 6; ++$i) {
        $log(CronTaskLog::STATE_STOP);
    }
    verify(!$repo->needsErrorNotification($errors, now: $now), 'Only ten latest completed runs count');
    $log(CronTaskLog::STATE_ERROR);
    $log(CronTaskLog::STATE_ERROR);
    $log(CronTaskLog::STATE_ERROR);
    $log(CronTaskLog::STATE_ERROR);
    $log(CronTaskLog::STATE_ERROR);
    $alert = $writer->insert('glpi_alerts', ['itemtype' => 'CronTask', 'items_id' => $errors, 'date' => '2030-01-09 12:00:01']);
    verify(!$repo->needsErrorNotification($errors, now: $now), 'Recent alert suppresses repeat');
    $writer->update('glpi_alerts', $alert, ['date' => '2030-01-09 12:00:00']);
    verify($repo->needsErrorNotification($errors, now: $now), 'Exact one-day alert boundary is eligible');
    $writer->update('glpi_alerts', $alert, ['items_id' => $short, 'date' => '2030-01-10 12:00:00']);
    verify($repo->needsErrorNotification($errors, now: $now), 'Other task alert does not suppress');
    $writer->update('glpi_alerts', $alert, ['items_id' => $errors, 'itemtype' => 'Ticket']);
    verify($repo->needsErrorNotification($errors, now: $now), 'Other itemtype alert does not suppress');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->next(0, '', []);
    $repo->usedItemtypes();
    $repo->forPlugin('scheduler');
    $repo->overdue();
    $repo->needsErrorNotification($errors);
    verify($SQL_TOTAL_REQUEST === 0, 'Scheduler and error decisions use ORM');
    verify(in_array('GlpiPlugin\\Scheduler\\Task', CronTask::getUsedItemtypes(), true), 'Public distinct itemtypes use mapped records');
    $public = $new('public scheduler');
    $model = new CronTask();
    verify($model->getNeedToRun(0, 'public scheduler') && $model->getID() === $public, 'Public selection populates model fields');
    touch(GLPI_CRON_DIR . '/public scheduler.lock');
    verify(!$model->getNeedToRun(0, 'public scheduler'), 'Public task lock');
    verify($model->getNeedToRun(-CronTask::MODE_INTERNAL, 'public scheduler'), 'Public forced mode ignores task lock');
    touch(GLPI_CRON_DIR . '/all.lock');
    verify(!$model->getNeedToRun(0, 'public scheduler'), 'Public global lock');
    verify($model->getNeedToRun(-CronTask::MODE_INTERNAL, 'public scheduler'), 'Public forced mode ignores global lock');
    $writer->insert('glpi_crontasklogs', ['crontasks_id' => $namespace, 'crontasklogs_id' => null]);
    verify(CronTask::unregister('scheduler'), 'Public plugin unregistration');
    $read = new RecordRepository(Orm::create($DB));
    verify($read->find('glpi_crontasks', 'id', $namespace) === null && $read->find('glpi_crontasks', 'id', $plugin) === null, 'Both plugin task forms purged');
    verify($read->find('glpi_crontasks', 'id', $otherNamespace) !== null, 'Similar plugin retained');
    verify($read->countMatching('glpi_crontasklogs', ['crontasks_id' => $namespace]) === 0, 'Unregistration preserves lifecycle log cleanup');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'Scheduler operations preserve references');
} finally {
    $DB->rollBack();
    $DB->setTimezone($originalTimezone);
    foreach (glob(GLPI_CRON_DIR . '/*.lock') as $lock) {
        unlink($lock);
    }
    rmdir(GLPI_CRON_DIR);
}
echo $DB->getProvider() . ": ORM scheduler windows, frequency, modes, plugins, locks, watcher and alert decisions passed.\n";
