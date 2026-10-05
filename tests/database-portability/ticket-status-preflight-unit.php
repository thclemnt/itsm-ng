<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Domain\LegacyTicketStatusRow;
use itsmng\Domain\LegacyTicketStatusSnapshot;
use itsmng\Domain\TicketStatusPreflight;
use itsmng\Domain\TicketWorkflowRole;
use itsmng\Domain\TicketWorkflowRoleDecisions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$codes = static fn (TicketStatusPreflight $result): array => array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
$rows = [
    new LegacyTicketStatusRow(91, 'Closed', 80, 1, '#fff'),
    new LegacyTicketStatusRow(70, 'Custom retired', -1, 0, null),
    new LegacyTicketStatusRow(19, 'New', 10, 1, ''),
    new LegacyTicketStatusRow(25, 'Processing (assigned)', 10, 1, null),
    new LegacyTicketStatusRow(31, 'Processing (planned)', 30, 1, null),
    new LegacyTicketStatusRow(44, 'Pending', 40, 1, null),
    new LegacyTicketStatusRow(60, 'Solved', 50, 1, null),
];
$snapshot = new LegacyTicketStatusSnapshot($rows);
$verify(array_map(static fn ($row): int => $row->id, $snapshot->positions) === [1 => 70, 2 => 19, 3 => 25, 4 => 31, 5 => 44, 6 => 60, 7 => 91], 'ID tie order and inactive gaps retain exact legacy positions');
$verify($snapshot->fingerprint === (new LegacyTicketStatusSnapshot(array_reverse($rows)))->fingerprint, 'Input iteration order cannot change authoritative fingerprint');

// Independently replay the frozen historical scan rather than assert a second sort.
$legacyRows = $rows;
usort($legacyRows, static fn ($a, $b): int => $a->id <=> $b->id);
$weights = array_map(static fn ($row): int => $row->weight, $legacyRows);
sort($weights);
$position = 0;
$frozen = [];
while ($weights !== []) {
    foreach ($legacyRows as $key => $row) {
        if ($row->weight === $weights[$position]) {
            $frozen[++$position] = $row->id;
            unset($legacyRows[$key], $weights[$position - 1]);
            break;
        }
    }
}
$verify($frozen === array_map(static fn ($row): int => $row->id, $snapshot->positions), 'Snapshot agrees with frozen weight scan including gaps');
$decisions = new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 19, 'assigned' => 25, 'planned' => 31, 'waiting' => 44, 'solved' => 60, 'closed' => 91]);
$result = new TicketStatusPreflight($snapshot, [['id' => 1001, 'status' => 1], ['id' => 1002, 'status' => 2], ['id' => 1003, 'status' => 7]], $decisions);
$verify($result->ticketOwnerHasNoRefusals(), 'Explicit six distinct decisions resolve the bounded ticket owner');
$verify($result->references[1001]->statusId === 70 && $result->references[1001]->inactive, 'Retired reference retains identity');
$verify($result->references[1002]->statusId === 19 && !$result->references[1002]->inactive, 'Stored ordinal is not row ID or weight');
$verify($result->references[1003]->statusId === 91, 'Last position preserves its actual identity');
$verify(count((new TicketStatusPreflight($snapshot, []))->diagnostics) === 6, 'Unique labels never authoritatively confirm workflow roles');
$bad = new TicketStatusPreflight($snapshot, [['id' => 1, 'status' => 0], ['id' => 2, 'status' => -2], ['id' => 3, 'status' => 8], ['id' => 4, 'status' => 19]], $decisions);
$verify(count($bad->diagnostics) === 4 && array_unique($codes($bad)) === ['unknown-ticket-status'], 'Zero, sentinel, missing position and row ID cannot invent ordinal mappings');
$verify($bad->references[1]->statusId === null && !$bad->ticketOwnerHasNoRefusals(), 'Unknown ticket target is an explicit refusal');
$verify(in_array('role-target-missing', $codes(new TicketStatusPreflight($snapshot, [], new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 999]))), true), 'Missing explicit role target refuses');
$verify(in_array('role-target-conflict', $codes(new TicketStatusPreflight($snapshot, [], new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 19, 'assigned' => 19]))), true), 'One status cannot own two confirmed workflow roles');
$verify(in_array('stale-role-decisions', $codes(new TicketStatusPreflight($snapshot, [], new TicketWorkflowRoleDecisions(str_repeat('0', 64), $decisions->assignments))), true), 'Role decisions require the exact inspected configuration');
$duplicate = new LegacyTicketStatusSnapshot([...$rows, new LegacyTicketStatusRow(92, 'New', 100, 1, null)]);
$ambiguous = new TicketStatusPreflight($duplicate, []);
$verify($ambiguous->diagnostics[0]->evidence['legacyInterpretation'] === 'ambiguous' && count($duplicate->roleCandidates(TicketWorkflowRole::Incoming)) === 2, 'Duplicate semantic label remains an ambiguous suggestion');
$renamed = new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, 'Renamed role', 1, 1, null)]);
$verify((new TicketStatusPreflight($renamed, []))->diagnostics[0]->evidence['legacyInterpretation'] === 'missing-or-renamed', 'Renamed/missing label cannot become a fallback constant');
$invalid = new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, null, 1, 2, null)]);
$verify(array_slice($codes(new TicketStatusPreflight($invalid, [])), 0, 2) === ['noncanonical-active-flag', 'active-null-label'], 'Noncanonical flag and NULL-label legacy noncompletion have distinct diagnostics');
$verify(in_array('empty-status-configuration', $codes(new TicketStatusPreflight(new LegacyTicketStatusSnapshot([]), [])), true), 'Empty configuration refuses');
$verify(in_array('no-active-status', $codes(new TicketStatusPreflight(new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, '', 1, 0, null)]), [])), true), 'All-inactive configuration refuses');
foreach (['label', 'weight', 'flag', 'color', 'identity', 'nullable'] as $change) {
    $replacement = new LegacyTicketStatusRow($change === 'identity' ? 193 : 91, $change === 'label' ? 'Renamed closed' : ($change === 'nullable' ? null : 'Closed'), $change === 'weight' ? 81 : 80, $change === 'flag' ? 0 : 1, $change === 'color' ? '#000' : '#fff');
    $changed = new LegacyTicketStatusSnapshot([$replacement, ...array_slice($rows, 1)]);
    $rejected = false;
    try {
        $result->requireUnchangedConfiguration($changed);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    $verify($rejected, 'Configuration fingerprint detects exact ' . $change . ' change');
}
foreach ([static fn () => new LegacyTicketStatusSnapshot([$rows[0], $rows[0]]), static fn () => new TicketStatusPreflight($snapshot, [['id' => 1, 'status' => 1], ['id' => 1, 'status' => 2]]), static fn () => new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['problem' => 1]), static fn () => new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => '19'])] as $invalidInput) {
    $rejected = false;
    try {
        $invalidInput();
    } catch (InvalidArgumentException $error) {
        $rejected = true;
    }
    $verify($rejected, 'Wrong ownership, lossy coercion and duplicate identities refuse');
}
echo 'PASS: ticket-status-preflight-unit (' . $assertions . " assertions)\n";
