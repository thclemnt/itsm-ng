<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\SpecialStatus;
use itsmng\Database\Entity\Ticket as TicketRecord;
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

// Native oracles are contract-only. The production repository uses scalar ORM
// projections. Preserve all existing rows; fixture writes only enter this frame.
$observe = static fn (): array => [
    'statuses' => $connection->fetchAllAssociative('SELECT * FROM glpi_specialstatuses ORDER BY id'),
    'tickets' => $connection->fetchAllAssociative('SELECT * FROM glpi_tickets ORDER BY id'),
    'ledger' => $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version'),
    'logs' => $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id'),
    'notifications' => $connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id'),
];
$nativePositions = static function () use ($connection): array {
    $positions = [];
    foreach ($connection->fetchAllAssociative('SELECT id, name, weight, is_active, color FROM glpi_specialstatuses ORDER BY weight, id') as $index => $row) {
        $positions[$index + 1] = ['id' => (int)$row['id'], 'name' => $row['name'], 'weight' => (int)$row['weight'], 'flag' => (int)$row['is_active'], 'color' => $row['color']];
    }
    return $positions;
};
$original = $observe();
$caller = null;
$pending = null;
$primary = null;
$cleanupFailures = [];
try {
    $caller = OwnedMutationFrame::begin($connection);
    $level = $connection->getTransactionNestingLevel();
    $fixtures = Orm::create($reader);
    $verify($fixtures->find(Entity::class, 0) !== null, 'Canonical real root entity required for ticket ownership');
    $statuses = [];
    foreach ([['Active duplicate', 20, 1, ''], [null, 10, 0, null], ['Active duplicate', 20, 1, '#123456'], [null, 30, 2, null]] as [$name, $weight, $flag, $color]) {
        $status = new SpecialStatus();
        $status->name = $name;
        $status->weight = $weight;
        $status->is_active = $flag;
        $status->color = $color;
        $fixtures->persist($status);
        $statuses[] = $status;
    }
    $fixtures->flush();
    foreach ($statuses as $status) {
        $verify(is_int($status->id) && $status->id > 0, 'Real persisted status fixture identity');
    }
    $expectedPositions = $nativePositions();
    $positionForId = array_flip(array_column($expectedPositions, 'id'));
    // array_column drops the one-based keys; recover ordinal from the native
    // ORDER BY vector, never from the implementation under test.
    $knownCode = $positionForId[$statuses[0]->id] + 1;
    $inactiveCode = $positionForId[$statuses[1]->id] + 1;
    $unknownCode = count($expectedPositions) + 1;
    $fixtureTickets = [];
    foreach (['known' => $knownCode, 'inactive' => $inactiveCode, 'zero' => 0, 'sentinel' => -2, 'unknown' => $unknownCode] as $kind => $code) {
        $ticket = new TicketRecord();
        $ticket->entities = $fixtures->getReference(Entity::class, 0);
        $ticket->name = 'Status preflight ' . $kind;
        $ticket->content = 'Rollback-owned adoption data fixture';
        $ticket->type = 1;
        $ticket->urgency = $ticket->impact = $ticket->priority = 3;
        $ticket->status = $code;
        $fixtures->persist($ticket);
        $fixtureTickets[$kind] = $ticket;
    }
    $fixtures->flush();
    foreach ($fixtureTickets as $ticket) {
        $verify(is_int($ticket->id) && $ticket->id > 0, 'Real FK-valid ticket control is nonempty');
    }
    $before = $observe();
    $verify(count($before['tickets']) === count($original['tickets']) + 5, 'All five actual stored ticket controls are admitted by canonical schema');
    $verify($before['ledger'] === $original['ledger'] && $before['logs'] === $original['logs'] && $before['notifications'] === $original['notifications'], 'Data fixtures do not invent migration receipts or business transition effects');
    $pending = new SpecialStatus();
    $pending->name = 'Unflushed preflight contract';
    $em->persist($pending);
    $DB = new stdClass();
    $snapshot = $repository->snapshot();
    $result = $repository->inspect();
    $actualPositions = [];
    foreach ($snapshot->positions as $position => $row) {
        $actualPositions[$position] = ['id' => $row->id, 'name' => $row->name, 'weight' => $row->weight, 'flag' => $row->activeFlag, 'color' => $row->color];
    }
    $verify($actualPositions === $expectedPositions, 'Every actual snapshot field agrees with independently ordered native storage including NULL/empty/tied/flag values');
    $verify($snapshot->fingerprint === $result->snapshot->fingerprint, 'Stable real configuration has exact matching fingerprints');
    $verify($connection->getTransactionNestingLevel() === $level, 'Inspection neither opens nor closes caller-owned frames');
    $verify($pending->id === null && $em->getUnitOfWork()->isScheduledForInsert($pending), 'Scalar ORM reads do not flush pending caller writes');
    $verify(count($result->references) === count($before['tickets']), 'Every real ticket owner is inspected without entity/deletion filtering');
    $unknownOwners = [];
    foreach ($result->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'unknown-ticket-status') {
            $unknownOwners[$diagnostic->identity] = $diagnostic->evidence['legacyCode'];
        }
    }
    foreach ($before['tickets'] as $ticket) {
        $id = (int)$ticket['id'];
        $code = (int)$ticket['status'];
        $expected = $expectedPositions[$code] ?? null;
        $reference = $result->references[$id];
        $verify($reference->legacyCode === $code, 'Exact actual stored before-code survives the owner plan');
        $verify($reference->statusId === ($expected['id'] ?? null) && $reference->inactive === ($expected !== null && $expected['flag'] === 0), 'Independent native ordinal determines exact target and retirement');
        $verify($expected !== null ? !array_key_exists($id, $unknownOwners) : ($unknownOwners[$id] ?? null) === $code, 'Unknown owning-ticket diagnostic matches native absent position');
    }
    $verify($result->references[$fixtureTickets['known']->id]->statusId === $statuses[0]->id, 'Known actual code resolves to its real status fixture');
    $verify($result->references[$fixtureTickets['inactive']->id]->statusId === $statuses[1]->id && $result->references[$fixtureTickets['inactive']->id]->inactive, 'Inactive ordinal gap preserves exact referenced identity');
    foreach (['zero', 'sentinel', 'unknown'] as $kind) {
        $verify(array_key_exists($fixtureTickets[$kind]->id, $unknownOwners), 'Real stored ' . $kind . ' code refuses without guessed target');
    }
    $configurationProblems = [];
    foreach ($result->diagnostics as $diagnostic) {
        if ($diagnostic->owner === 'SpecialStatus') {
            $configurationProblems[$diagnostic->code][] = $diagnostic->identity;
        }
    }
    $verify(in_array($statuses[3]->id, $configurationProblems['noncanonical-active-flag'] ?? [], true) && in_array($statuses[3]->id, $configurationProblems['active-null-label'] ?? [], true), 'Actual integer flag and nullable active label produce distinct owner refusals');
    $verify(!$result->ticketOwnerHasNoRefusals(), 'No English-label inference grants workflow role confirmation');
    $verify($observe() === $before, 'Preflight leaves whole actual status/ticket/ledger/history/notification storage unchanged');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    $DB = $reader;
    try {
        if ($pending !== null) {
            $em->detach($pending);
        }
    } catch (Throwable $error) {
        $cleanupFailures[] = $error;
    }
    try {
        if ($caller !== null) {
            $caller->rollBack();
        }
    } catch (Throwable $error) {
        $cleanupFailures[] = $error;
    }
}
foreach ($cleanupFailures as $error) {
    fwrite(STDERR, 'Preflight contract cleanup failure: ' . (string)$error . "\n");
}
if ($primary !== null) {
    throw $primary;
}
if ($cleanupFailures !== []) {
    throw $cleanupFailures[0];
}
$verify($connection->getTransactionNestingLevel() === 0, 'Only contract-owned caller frame is closed');
$verify($observe() === $original, 'Whole original owner storage and ledger restored after rollback; allocator advancement is intentionally not reset');
echo 'PASS: ticket-status-preflight (' . $assertions . " assertions)\n";
