<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/update-writer-refusal.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class RefusingComputerWriter extends Computer
{
    public int $loads = 0;
    public int $writes = 0;
    public int $posts = 0;
    public bool $refuse = true;

    public static function getTable($classname = null)
    {
        return Computer::getTable();
    }

    public static function getType()
    {
        return Computer::class;
    }

    public function post_getFromDB()
    {
        ++$this->loads;
        parent::post_getFromDB();
    }

    public function updateInDB($updates, $oldvalues = [])
    {
        ++$this->writes;
        return $this->refuse ? false : parent::updateInDB($updates, $oldvalues);
    }

    public function post_updateItem($history = 1)
    {
        ++$this->posts;
        // A completed update may enqueue work; a refused writer must never reach it.
        verify((new QueuedNotification())->add([
            'itemtype' => 'Computer', 'items_id' => $this->getID(),
            'name' => 'Writer refusal post hook', 'send_time' => '2030-01-01 00:00:00',
        ]) > 0, 'Completed post-update queue insertion');
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'writer_refusal_fixture']);
$completed = 0;
$PLUGIN_HOOKS['item_update']['writer_refusal_fixture'][RefusingComputerWriter::class] = static function () use (&$completed): void {
    ++$completed;
};
$connection = $DB->getDoctrineConnection();
$level = $connection->getTransactionNestingLevel();
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $id = $fixtures->create('glpi_computers', ['name' => 'Stored writer state']);
    $model = new RefusingComputerWriter();
    $model->notificationqueueonaction = true;
    verify($model->getFromDB($id), 'Load authoritative stored state');
    $stored = $model->fields;
    $manager = Orm::create($DB);
    $read = new RecordRepository($manager);
    $logs = $read->matching('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $id]);
    $queue = $read->matching('glpi_queuednotifications', ['itemtype' => 'Computer', 'items_id' => $id]);
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Previous feedback']];
    $loads = $model->loads;
    verify($model->update(['id' => $id, 'name' => 'Rejected writer value', 'update' => 1]) === false, 'Public update propagates actual writer refusal');
    verify($model->fields === $stored && $model->updates === [] && $model->oldvalues === [], 'Rejected changes restore captured stored fields and clear pending changes');
    verify($model->loads === $loads + 1 && $DB->getDoctrineConnection() === $connection, 'Refusal does not reload the model or replace its supplied connection');
    verify($model->input['name'] === 'Rejected writer value', 'Attempted input remains available for form diagnostics');
    verify($model->writes === 1 && $model->posts === 0 && $completed === 0, 'Refused writer prevents post-update and item_update hooks');
    $manager->clear();
    verify($read->find('glpi_computers', 'id', $id)['name'] === $stored['name'], 'Stored row remains unchanged');
    verify($read->matching('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $id]) === $logs, 'Refusal writes no audit history');
    verify($read->matching('glpi_queuednotifications', ['itemtype' => 'Computer', 'items_id' => $id]) === $queue, 'Refusal enqueues no completed-action notification');
    verify($_SESSION['MESSAGE_AFTER_REDIRECT'] === [INFO => ['Previous feedback']], 'Refusal emits no successful update feedback');
    verify($connection->getTransactionNestingLevel() === $level + 1, 'Caller transaction ownership is unchanged');

    $model->notificationqueueonaction = false;
    verify($model->update(['id' => $id, 'name' => $stored['name']]) === true, 'No-change public update retains success semantics');
    verify($model->writes === 1 && $model->posts === 1 && $completed === 0, 'No-change path does not call refused writer but retains existing post-update behavior');
    $model->refuse = false;
    verify($model->update(['id' => $id, 'name' => 'Accepted writer value']) === true && $model->posts === 2 && $completed === 1, 'Accepted writer retains completed lifecycle');
    $manager->clear();
    verify($read->find('glpi_computers', 'id', $id)['name'] === 'Accepted writer value', 'Accepted writer persists the new value');
    verify(count($read->matching('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $id])) > count($logs), 'Accepted writer retains audit history');
} finally {
    while ($connection->getTransactionNestingLevel() > $level) {
        $connection->rollBack();
    }
    $_SESSION = $savedSession;
    $PLUGIN_HOOKS = $savedHooks;
    $plugins->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": public writer refusal, stored state, history, queue and feedback contract passed.\n";
