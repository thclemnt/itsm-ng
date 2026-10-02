<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ServiceLevelReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/service-levels.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $slm = $fixtures->create('glpi_slms', ['name' => 'Mapped service levels']);
    $at = new DateTimeImmutable('2030-01-01 12:00:00');
    foreach (['sla' => 'Sla', 'ola' => 'Ola'] as $kind => $label) {
        $agreementClass = strtoupper($kind);
        $levelClass = $label . 'Level';
        $queueClass = $label . 'Level_Ticket';
        $agreement = $fixtures->create('glpi_' . $kind . 's', ['slms_id' => $slm, 'type' => SLM::TTR, 'name' => 'Mapped ' . $kind, 'definition_time' => 'hour', 'number_time' => 2]);
        $other = $fixtures->create('glpi_' . $kind . 's', ['slms_id' => $slm, 'type' => SLM::TTO, 'name' => 'Other ' . $kind, 'definition_time' => 'hour', 'number_time' => 1]);
        $levels = [];
        foreach (['first' => [-3600, true], 'second' => [0, true], 'tie' => [0, true], 'disabled' => [-7200, false], 'last' => [3600, true]] as $name => [$delay, $active]) {
            $levels[$name] = $fixtures->create('glpi_' . $kind . 'levels', [$kind . 's_id' => $agreement, 'name' => $kind . ' ' . $name, 'execution_time' => $delay, 'is_active' => $active]);
        }
        $otherLevel = $fixtures->create('glpi_' . $kind . 'levels', [$kind . 's_id' => $other, 'execution_time' => -3600]);
        $repo = new \itsmng\Database\Repository\ServiceLevelRepository(Orm::create($DB), $kind);
        $SQL_TOTAL_REQUEST = 0;
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        verify($repo->firstLevel($agreement) === $levels['first'], 'First active level ignores earlier disabled level');
        verify($repo->nextLevel($agreement, $levels['first']) === $levels['second'], 'Next level ties use stable ID');
        verify($repo->nextLevel($agreement, $levels['second']) === $levels['last'], 'Next level retains strictly later delay');
        verify($repo->nextLevel($agreement, $otherLevel) === 0 && $repo->nextLevel($other, $levels['first']) === 0, 'Cross-agreement current level rejected');
        verify($repo->nextLevel($agreement, 2147483647) === 0 && $repo->nextLevel($agreement, $levels['last']) === 0, 'Unknown and final level have no successor');
        verify($repo->executionTimes($agreement) === [-7200 => -7200, -3600 => -3600, 0 => 0, 3600 => 3600], 'Distinct sorted execution times include disabled levels');
        verify($levelClass::getAlreadyUsedExecutionTime($agreement) === $repo->executionTimes($agreement), 'Model uses mapped timing query');
        $firstMethod = 'getFirst' . $label . 'Level';
        $nextMethod = 'getNext' . $label . 'Level';
        verify($levelClass::$firstMethod($agreement) === $levels['first'] && $levelClass::$nextMethod($agreement, $levels['first']) === $levels['second'], 'Model uses mapped progression');
        verify($SQL_TOTAL_REQUEST === 0, 'Progression does not execute adapter queries');
        $ticket = $fixtures->create('glpi_tickets', ['name' => 'Mapped ticket ' . $kind, 'date' => '2030-01-01 10:00:00', $kind . 's_id_ttr' => $agreement, $kind . 's_id_tto' => $other, $kind . 'levels_id_ttr' => $levels['first']]);
        $due = $fixtures->create('glpi_' . $kind . 'levels_tickets', ['tickets_id' => $ticket, $kind . 'levels_id' => $levels['first'], 'date' => '2030-01-01 11:00:00']);
        $boundary = $fixtures->create('glpi_' . $kind . 'levels_tickets', ['tickets_id' => $ticket, $kind . 'levels_id' => $levels['second'], 'date' => '2030-01-01 12:00:00']);
        $missing = $fixtures->create('glpi_' . $kind . 'levels_tickets', ['tickets_id' => $ticket, $kind . 'levels_id' => $levels['last'], 'date' => null]);
        $own = $fixtures->create('glpi_' . $kind . 'levels_tickets', ['tickets_id' => $ticket, $kind . 'levels_id' => $otherLevel, 'date' => '2030-01-01 10:00:00']);
        verify(array_column($repo->scheduled($ticket, SLM::TTR), 'id') === [$due, $boundary, $missing], 'Pending rows have stable date order with NULL last on both engines');
        $rows = $repo->scheduled($ticket, SLM::TTR, $at);
        verify(array_column($rows, 'id') === [$due] && $rows[0]['type'] === SLM::TTR && $rows[0]['date'] === '2030-01-01 11:00:00', 'Due selection preserves strict time boundary and row contract');
        verify(array_column($repo->scheduled($ticket, SLM::TTO, $at), 'id') === [$own], 'Scheduled selection separates TTO from TTR');
        verify($repo->agreementForTicket($ticket, SLM::TTO) === $other && $repo->agreementForTicket($ticket, SLM::TTR) === $agreement, 'Mapped ticket agreement lookup');
        $model = new $agreementClass();
        verify($model->getDataForTicket($ticket, SLM::TTR) && (int)$model->getID() === $agreement && $model->fields['number_time'] === 2, 'Agreement lookup hydrates complete model');
        verify($model->computeDate('2030-01-01 10:00:00') === '2030-01-01 12:00:00', 'Duration computation without calendar preserved');
        $queue = new $queueClass();
        verify($queue->getFromDBForTicket($ticket, SLM::TTO) && (int)$queue->getID() === $own, 'Queue model lookup');
        $queue->deleteForTicket($ticket, SLM::TTO);
        verify($read('glpi_' . $kind . 'levels_tickets', $own) === null && $read('glpi_' . $kind . 'levels_tickets', $due) !== null, 'Queue deletion respects agreement type');
        $rule = $fixtures->create('glpi_rules', ['name' => 'Mapped agreement rule', 'sub_type' => 'RuleTicket']);
        foreach ([1, 2] as $duplicate) {
            $fixtures->create('glpi_ruleactions', ['rules_id' => $rule, 'field' => $kind . 's_id_ttr', 'value' => (string)$agreement]);
        }
        verify($repo->ruleIds($kind . 's_id_ttr', $agreement) === [$rule], 'Rule view deduplicates mapped parent IDs');
        $action = $fixtures->create('glpi_' . $kind . 'levelactions', [$kind . 'levels_id' => $levels['first'], 'field' => 'priority', 'value' => '3', 'action_type' => 'assign']);
        $criterion = $fixtures->create('glpi_' . $kind . 'levelcriterias', [$kind . 'levels_id' => $levels['first'], 'criteria' => 'priority', 'condition' => 0, 'pattern' => '3']);
        ob_start();
        (new $levelClass())->showForParent($model);
        $html = ob_get_clean();
        verify(str_contains($html, $kind . ' first'), 'Escalation view renders mapped rows');
        verify($model->delete(['id' => $agreement], true), 'Agreement lifecycle purge succeeds with RESTRICT graph');
        verify($read('glpi_' . $kind . 'levels', $levels['first']) === null && $read('glpi_' . $kind . 'levelactions', $action) === null && $read('glpi_' . $kind . 'levelcriterias', $criterion) === null, 'Agreement purge removes levels, actions and criteria');
        verify($repo->scheduled($ticket, SLM::TTR) === [], 'Agreement purge removes scheduled levels');
        verify($read('glpi_tickets', $ticket)[$kind . 's_id_ttr'] === null && $read('glpi_tickets', $ticket)[$kind . 'levels_id_ttr'] === null, 'Agreement purge clears optional ticket references');
        verify($read('glpi_tickets', $ticket)[$kind . 's_id_tto'] === $other, 'Unrelated TTO agreement survives');
        $freshQueue = $fixtures->create('glpi_' . $kind . 'levels_tickets', ['tickets_id' => $ticket, $kind . 'levels_id' => $otherLevel]);
        verify((new Ticket())->delete(['id' => $ticket], true), 'Ticket purge removes scheduled rows');
        verify($read('glpi_' . $kind . 'levels_tickets', $freshQueue) === null, 'No dangling scheduled ticket entry');
    }
    verify((new SLM())->delete(['id' => $slm], true), 'Service-level parent purge removes remaining agreements and levels');
    verify((new ForeignKeys())->audit($connection) === [], 'Service levels graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new ServiceLevelReferences();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'SERVICE_LEVELS') as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_tickets');
    $connection->insert('glpi_tickets', ['id' => $legacy, 'name' => 'legacy-name']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy ticket service-level migration has a plan');
    verify((int)$connection->fetchOne('SELECT slas_id_ttr FROM glpi_tickets WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_tickets', ['slas_id_ttr' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ticket service-level');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_tickets')['slas_id_ttr']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_tickets', ['slas_id_ttr' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT slas_id_ttr FROM glpi_tickets WHERE id = ?', [$legacy]) === null, 'Legacy agreement default becomes NULL');
    verify($migration->apply($connection) === [], 'ticket service-level migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_tickets', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Service levels, nullable references, SLA/OLA selection and purge and migration passed.\n";
