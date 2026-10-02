<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\Entity as Record;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\InfocomRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/warranty-expiration.php /path/to/test-config\n");
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
final class WarrantyCronProbe
{
    public array $messages = [];
    public int $volume = 0;
    public function log(string $message): void
    {
        $this->messages[] = $message;
    }
    public function addVolume(int $volume): void
    {
        $this->volume += $volume;
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = (int)(new Entity())->add(['name' => 'Warranty boundaries', 'entities_id' => 0, 'use_infocoms_alert' => 0]);
    verify($entity > 0, 'Warranty entity');
    $endMask = 1 << Alert::END;
    $today = new DateTimeImmutable('2024-02-28 17:30:00');
    $cases = [
        'expired' => ['2024-01-15', 1, $endMask],
        'today' => ['2024-01-28', 1, $endMask],
        'leap_boundary' => ['2024-01-31', 1, $endMask],
        'future' => ['2024-02-01', 1, $endMask],
        'lifetime' => ['2024-01-01', -1, $endMask],
        'zero_duration' => ['2024-01-01', 0, $endMask],
        'negative_duration' => ['2024-01-01', -2, $endMask],
        'missing' => [null, 1, $endMask],
        'disabled' => ['2024-01-15', 1, 0],
        'notice_only' => ['2024-01-15', 1, 1 << Alert::NOTICE],
        'combined_flags' => ['2024-01-15', 1, $endMask | (1 << Alert::NOTICE)],
        'foreign' => ['2024-01-15', 1, $endMask],
        'alerted' => ['2024-01-15', 1, $endMask],
        'other_event' => ['2024-01-15', 1, $endMask],
        'other_kind' => ['2024-01-15', 1, $endMask],
    ];
    $ids = [];
    foreach ($cases as $name => [$date, $months, $flags]) {
        $asset = $fixtures->create('glpi_computers', ['name' => 'Warranty ' . $name]);
        $ids[$name] = $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $asset,
            'entities_id' => $name === 'foreign' ? 0 : $entity, 'warranty_date' => $date, 'warranty_duration' => $months, 'alert' => $flags]);
    }
    $fixtures->create('glpi_contracts', ['id' => $ids['other_kind']]);
    foreach (['alerted', 'other_event', 'other_kind'] as $name) {
        verify((new Alert())->add(['itemtype' => $name === 'other_kind' ? 'Contract' : 'Infocom',
            'items_id' => $ids[$name], 'type' => $name === 'other_event' ? Alert::NOTICE : Alert::END]) > 0, 'Prior alert ' . $name);
    }
    $em = Orm::create($DB);
    $repository = new InfocomRepository($em);
    $expected = array_map(static fn ($name) => $ids[$name], ['expired', 'today', 'leap_boundary', 'combined_flags', 'other_event', 'other_kind']);
    $rows = $repository->warrantiesExpiring($entity, 1, $today);
    verify(array_column($rows, 'id') === $expected, 'Inclusive cutoff, positive duration, bit flags, exact entity and owning alert subject');
    verify($rows[2]['warrantyexpiration'] === '2024-02-29' && $rows[2]['warranty_date'] === '2024-01-31', 'Calendar query and notification projection agree at leap month end');
    verify(!in_array($ids['leap_boundary'], array_column($repository->warrantiesExpiring($entity, 0, $today), 'id'), true), 'Zero delay excludes tomorrow');
    verify(!in_array($ids['today'], array_column($repository->warrantiesExpiring($entity, -1, $today), 'id'), true), 'Negative delay excludes today');
    verify($repository->warrantiesExpiring(999999999, 1, $today) === [], 'Unknown entity yields no rows');
    foreach ([['2025-01-31', 1, '2025-02-28'], ['2024-02-29', 12, '2025-02-28'], ['2024-12-31', 2, '2025-02-28']] as [$start, $months, $expires]) {
        $record = new Record\Infocom();
        $record->warranty_date = new DateTimeImmutable($start);
        $record->warranty_duration = $months;
        verify($record->warrantyExpiresOn()?->format('Y-m-d') === $expires, 'Entity calendar expiry: ' . $start);
        $asset = $fixtures->create('glpi_computers');
        $id = $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $asset, 'entities_id' => $entity,
            'warranty_date' => $start, 'warranty_duration' => $months, 'alert' => $endMask]);
        $boundary = new DateTimeImmutable($expires);
        verify(in_array($id, array_column($repository->warrantiesExpiring($entity, 0, $boundary), 'id'), true)
            && !in_array($id, array_column($repository->warrantiesExpiring($entity, 0, $boundary->modify('-1 day')), 'id'), true), 'Provider date arithmetic agrees with the entity');
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->warrantiesExpiring($entity, 1, $today);
    verify($SQL_TOTAL_REQUEST === 0, 'Warranty selection bypasses adapter execution');
    verify($em->getConnection() === $connection, 'Repository retains the caller connection and transaction');
    $em->clear();

    $writer = new RecordWriter(Orm::create($DB));
    $writer->update('glpi_entities', 0, ['use_infocoms_alert' => 0]);
    $parent = (int)(new Entity())->add(['name' => 'Warranty notifications', 'entities_id' => 0,
        'use_infocoms_alert' => 1, 'default_infocom_alert' => $endMask, 'send_infocoms_alert_before_delay' => 0]);
    $child = (int)(new Entity())->add(['name' => 'Inherited warranty notifications', 'entities_id' => $parent,
        'use_infocoms_alert' => Entity::CONFIG_PARENT, 'send_infocoms_alert_before_delay' => Entity::CONFIG_PARENT]);
    verify($parent > 0 && $child > 0, 'Notification policy hierarchy');
    $public = [];
    foreach ([$parent, $child] as $owner) {
        $asset = (int)(new Computer())->add(['name' => 'Expiring public warranty ' . $owner, 'entities_id' => $owner]);
        $info = new Infocom();
        $id = (int)$info->add(['itemtype' => 'Computer', 'items_id' => $asset, 'entities_id' => $owner,
            'warranty_date' => '2024-01-31', 'warranty_duration' => 1]);
        verify($asset > 0 && $id > 0 && $info->update(['id' => $id, 'alert' => $endMask]), 'Public asset and financial lifecycle');
        $public[$id] = $asset;
    }
    $CFG_GLPI['use_notifications'] = false;
    verify(Infocom::cronInfocom(new WarrantyCronProbe()) === 0, 'Disabled notifications return without creating alerts');
    foreach ($public as $id => $asset) {
        verify(Alert::alertExists('Infocom', $id, Alert::END) === false, 'Disabled action has no alert side effects');
    }
    $CFG_GLPI['use_notifications'] = true;
    foreach (array_keys(Notification_NotificationTemplate::getModes()) as $mode) {
        $CFG_GLPI['notifications_' . $mode] = false;
    }
    $task = new WarrantyCronProbe();
    verify(Infocom::cronInfocom($task) === 1 && $task->volume === 2 && count($task->messages) === 2, 'Public cron respects inherited policy and per-entity event accounting without external delivery');
    verify(str_contains($task->messages[0], Html::convDate('2024-02-29')), 'Public notification renders the selected calendar expiry');
    verify(Infocom::cronInfocom(new WarrantyCronProbe()) === 0, 'Repeat action deduplicates exact delivered alert subjects');
    $read = new RecordRepository(Orm::create($DB));
    foreach ($public as $id => $asset) {
        $alert = (int)Alert::alertExists('Infocom', $id, Alert::END);
        verify($alert > 0 && (int)$read->find('glpi_alerts', 'id', $alert)['infocoms_id'] === $id, 'Public action creates a real owning subject');
        $connection->beginTransaction();
        try {
            $rejected = false;
            try {
                $connection->insert('glpi_alerts', ['itemtype' => 'Infocom', 'infocoms_id' => $id, 'type' => Alert::END]);
            } catch (DriverException $error) {
                $rejected = in_array($error->getSQLState(), ['23505', '23000'], true);
            }
            verify($rejected, 'Native duplicate warranty alert rejected');
        } finally {
            $connection->rollBack();
        }
        verify((new Computer())->delete(['id' => $asset], true), 'Public asset purge');
        verify($read->find('glpi_infocoms', 'id', $id) === null && $read->find('glpi_alerts', 'id', $alert) === null, 'Asset purge cleans financial ownership and warranty alerts');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Warranty lifecycle leaves no invalid references');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": warranty calendar boundaries, bit flags, entity/alert ownership, inherited cron policy, notification accounting, deduplication and asset purge passed.\n";
