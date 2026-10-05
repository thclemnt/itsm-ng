<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity\SpecialStatus;
use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketStatusPreflightRepository;
use itsmng\Database\OwnedMutationFrame;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/ticket-status-preflight.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$reader = $DB;
$connection = $reader->getDoctrineConnection();
$verify($connection->getTransactionNestingLevel() === 0, 'Contract begins outside a caller frame');
$em = Orm::create($reader);
$repository = new TicketStatusPreflightRepository($em);
$verify($em->getConnection() === $connection, 'Supplied manager retains its selected connection');

// Independent native read oracle. Domain repository uses entity queries only;
// the contract may inspect raw storage/ledger to prove exact read-only behavior.
$observe = static fn (): array => [
    'statuses' => $connection->fetchAllAssociative('SELECT id, name, weight, is_active, color FROM glpi_specialstatuses ORDER BY id'),
    'tickets' => $connection->fetchAllAssociative('SELECT id, status FROM glpi_tickets ORDER BY id'),
    'ledger' => $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version'),
    'logs' => $connection->fetchOne('SELECT COUNT(*) FROM glpi_logs'),
    'notifications' => $connection->fetchOne('SELECT COUNT(*) FROM glpi_queuednotifications'),
];
$before = $observe();
$pending = new SpecialStatus();
$pending->name = 'Unflushed preflight contract';
$em->persist($pending);
$caller = null;
try {
    $caller = OwnedMutationFrame::begin($connection);
    $level = $connection->getTransactionNestingLevel();
    // A global rebind must not redirect the already-admitted repository.
    $DB = new stdClass();
    $snapshot = $repository->snapshot();
    $result = $repository->inspect();
    $verify($snapshot->fingerprint === $result->snapshot->fingerprint, 'Stable real configuration has exact matching fingerprints');
    $verify($connection->getTransactionNestingLevel() === $level, 'Inspection neither opens nor closes caller-owned frames');
    $verify($pending->id === null && $em->getUnitOfWork()->isScheduledForInsert($pending), 'Scalar ORM reads do not flush pending caller writes');
    $verify(count($snapshot->positions) === count($before['statuses']), 'Every stored status, including inactive rows, is snapshotted');
    $verify(count($result->references) === count($before['tickets']), 'Every ticket owner is inspected without entity/deletion filtering');
    foreach ($before['tickets'] as $ticket) {
        $reference = $result->references[(int)$ticket['id']];
        $verify($reference->legacyCode === (int)$ticket['status'], 'Exact stored before-code survives the owner plan');
        $verify($reference->statusId === $snapshot->resolve((int)$ticket['status'])?->id, 'Each target is the exact inactive-inclusive ordinal interpretation');
    }
    $verify(!$result->ticketOwnerHasNoRefusals(), 'No English-label inference grants workflow role confirmation');
    $verify($observe() === $before, 'No status/ticket/ledger rewrite or lifecycle history/notification insertion');
} finally {
    $DB = $reader;
    $em->detach($pending);
    if ($caller !== null) {
        $caller->rollBack();
    }
}
$verify($connection->getTransactionNestingLevel() === 0, 'Only contract-owned caller frame is closed');
$verify($observe() === $before, 'Whole inspected storage and canonical ledger survive preflight unchanged');
echo 'PASS: ticket-status-preflight (' . $assertions . " assertions)\n";
