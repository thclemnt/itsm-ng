<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\MappedReads;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CertificateRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/expiration.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = new Entity();
    $entityId = (int)$entity->add(['name' => 'Expiration test entity', 'entities_id' => 0, 'send_domains_alert_expired_delay' => 2, 'send_domains_alert_close_expiries_delay' => 3]);
    verify($entityId > 0, 'Expiration configuration entity');
    $today = new DateTimeImmutable('2026-09-28 16:30:00');
    $cases = [
        'expired' => '2026-09-25 23:59:59', 'expired_boundary' => '2026-09-26 00:00:00',
        'yesterday' => '2026-09-27 23:59:59', 'today_start' => '2026-09-28 00:00:00',
        'today_end' => '2026-09-28 23:59:59', 'tomorrow' => '2026-09-29 00:00:00',
        'near_end' => '2026-09-30 23:59:59', 'future_boundary' => '2026-10-01 00:00:00',
        'deleted' => '2026-09-25 23:59:59', 'foreign' => '2026-09-25 23:59:59', 'missing' => null,
    ];
    $domains = [];
    foreach ($cases as $name => $date) {
        $domains[$name] = $fixtures->create('glpi_domains', ['name' => 'Expiration domain ' . $name, 'entities_id' => $name === 'foreign' ? 0 : $entityId, 'is_deleted' => $name === 'deleted', 'date_expiration' => $date]);
    }
    $select = static function (array $criteria) use ($DB, $domains): array {
        return array_column(MappedReads::matching($DB, 'glpi_domains', ['id' => array_values($domains)] + $criteria['WHERE'], ['id ASC']), 'id');
    };
    verify($select(Domain::expiredDomainsCriteria($entityId, $today)) === [$domains['expired']], 'Expired delay uses a strict midnight boundary');
    verify($select(Domain::closeExpiriesDomainsCriteria($entityId, $today)) === [$domains['tomorrow'], $domains['near_end']], 'Upcoming reminders exclude today and the delay boundary');
    verify($entity->update(['id' => $entityId, 'send_domains_alert_expired_delay' => 0, 'send_domains_alert_close_expiries_delay' => 1]), 'Change expiration delays');
    verify($select(Domain::expiredDomainsCriteria($entityId, $today)) === [$domains['expired'], $domains['expired_boundary'], $domains['yesterday']], 'Zero expired delay includes all earlier dates');
    verify($select(Domain::closeExpiriesDomainsCriteria($entityId, $today)) === [], 'One-day upcoming delay has no eligible dates');
    $type = $fixtures->create('glpi_domaintypes', ['name' => 'Selected domain type']);
    $typed = $fixtures->create('glpi_domains', ['name' => 'Typed selector domain', 'entities_id' => $entityId, 'domaintypes_id' => $type]);
    verify(Domain::getUsed([], $type) === [], 'Empty used-ID list is valid');
    verify(Domain::getUsed([$typed, $domains['missing']], $type) === [$typed => $typed], 'Used selector filters mapped domain type');
    verify(Domain::getUsed([$typed, $domains['missing']], 0) === [$domains['missing'] => $domains['missing']], 'Empty mapped type retains legacy zero criteria');
    $html = Domain::dropdownDomains(['entity' => $entityId, 'used' => [$typed], 'display' => false]);
    verify(str_contains($html, 'Expiration domain tomorrow') && !str_contains($html, 'Typed selector domain') && !str_contains($html, 'Expiration domain deleted') && !str_contains($html, 'Expiration domain foreign'), 'Domain selector obeys entity, deletion and exclusion rules');

    $certificates = [];
    foreach (['expired', 'today', 'near', 'boundary', 'missing', 'deleted', 'template', 'foreign', 'alerted', 'other_type', 'other_event'] as $name) {
        $date = match ($name) {
            'expired' => '2026-09-20', 'today' => '2026-09-28', 'boundary' => '2026-10-01', 'missing' => null, default => '2026-09-30',
        };
        $certificates[$name] = $fixtures->create('glpi_certificates', ['name' => 'Expiration certificate ' . $name, 'entities_id' => $name === 'foreign' ? 0 : $entityId, 'is_deleted' => $name === 'deleted', 'is_template' => $name === 'template', 'date_expiration' => $date]);
        if (in_array($name, ['alerted', 'other_type', 'other_event'], true)) {
            $fixtures->create('glpi_alerts', ['itemtype' => $name === 'other_type' ? 'Domain' : 'Certificate', 'items_id' => $certificates[$name], 'type' => $name === 'other_event' ? Alert::NOTICE : Alert::END]);
        }
    }
    $em = Orm::create($DB);
    $repository = new CertificateRepository($em);
    $rows = $repository->expiring($entityId, 3, $today);
    verify(array_column($rows, 'id') === [$certificates['expired'], $certificates['today'], $certificates['near'], $certificates['other_type'], $certificates['other_event']], 'Certificate selection scopes flags, dates and exact prior alert kind');
    verify($rows[0]['date_expiration'] === '2026-09-20', 'Certificate notification dates remain scalar strings');
    verify(array_column($repository->expiring($entityId, 0, $today), 'id') === [$certificates['expired']], 'Certificate zero delay excludes today');
    verify(array_column($repository->expiring($entityId, -1, $today), 'id') === [$certificates['expired']], 'Negative certificate delay remains supported');
    $em->clear();
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository->expiring($entityId, 3, $today);
    Domain::getUsed([$typed], $type);
    $select(Domain::expiredDomainsCriteria($entityId, $today));
    verify($SQL_TOTAL_REQUEST === 0, 'Expiration and used-ID queries bypass legacy execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": domain and certificate expiration boundaries, alert deduplication and scoped selectors passed.\n";
