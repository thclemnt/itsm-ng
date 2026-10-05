<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Domain;

use itsmng\Domain\LegacyTicketStatusRow;
use itsmng\Domain\LegacyTicketStatusSnapshot;
use itsmng\Domain\TicketStatusPreflight as Preflight;
use itsmng\Domain\TicketWorkflowRole;
use itsmng\Domain\TicketWorkflowRoleDecisions;

class TicketStatusPreflight extends \atoum\atoum\test
{
    private function rows(): array
    {
        return [
            new LegacyTicketStatusRow(91, 'Closed', 80, 1, '#fff'),
            new LegacyTicketStatusRow(70, 'Custom retired', -1, 0, null),
            new LegacyTicketStatusRow(19, 'New', 10, 1, ''),
            new LegacyTicketStatusRow(25, 'Processing (assigned)', 10, 1, null),
            new LegacyTicketStatusRow(31, 'Processing (planned)', 30, 1, null),
            new LegacyTicketStatusRow(44, 'Pending', 40, 1, null),
            new LegacyTicketStatusRow(60, 'Solved', 50, 1, null),
        ];
    }

    private function decisions(LegacyTicketStatusSnapshot $snapshot): TicketWorkflowRoleDecisions
    {
        return new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 19, 'assigned' => 25, 'planned' => 31, 'waiting' => 44, 'solved' => 60, 'closed' => 91]);
    }

    private function codes(Preflight $result): array
    {
        return array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
    }

    public function testLegacyWeightScanAndInactiveGaps(): void
    {
        $rows = $this->rows();
        $snapshot = new LegacyTicketStatusSnapshot($rows);
        $this->array(array_map(static fn ($row): int => $row->id, $snapshot->positions))->isIdenticalTo([1 => 70, 2 => 19, 3 => 25, 4 => 31, 5 => 44, 6 => 60, 7 => 91]);
        $this->string($snapshot->fingerprint)->isIdenticalTo((new LegacyTicketStatusSnapshot(array_reverse($rows)))->fingerprint);

        // Independent historical scan: do not use the snapshot's comparator.
        usort($rows, static fn ($a, $b): int => $a->id <=> $b->id);
        $weights = array_map(static fn ($row): int => $row->weight, $rows);
        sort($weights);
        $position = 0;
        $frozen = [];
        while ($weights !== []) {
            foreach ($rows as $key => $row) {
                if ($row->weight === $weights[$position]) {
                    $frozen[++$position] = $row->id;
                    unset($rows[$key], $weights[$position - 1]);
                    break;
                }
            }
        }
        $this->array(array_map(static fn ($row): int => $row->id, $snapshot->positions))->isIdenticalTo($frozen);
    }

    public function testExplicitWorkflowDecisionsAndStoredOrdinals(): void
    {
        $snapshot = new LegacyTicketStatusSnapshot($this->rows());
        $result = new Preflight($snapshot, [['id' => 1001, 'status' => 1], ['id' => 1002, 'status' => 2], ['id' => 1003, 'status' => 7]], $this->decisions($snapshot));
        $this->boolean($result->ticketOwnerHasNoRefusals())->isTrue();
        $this->integer($result->references[1001]->statusId)->isIdenticalTo(70);
        $this->boolean($result->references[1001]->inactive)->isTrue();
        $this->integer($result->references[1002]->statusId)->isIdenticalTo(19);
        $this->boolean($result->references[1002]->inactive)->isFalse();
        $this->integer($result->references[1003]->statusId)->isIdenticalTo(91);
        $this->array((new Preflight($snapshot, []))->diagnostics)->hasSize(6);
    }

    public function testUnknownCodesRefuseGuessedTargets(): void
    {
        $snapshot = new LegacyTicketStatusSnapshot($this->rows());
        $bad = new Preflight($snapshot, [['id' => 1, 'status' => 0], ['id' => 2, 'status' => -2], ['id' => 3, 'status' => 8], ['id' => 4, 'status' => 19]], $this->decisions($snapshot));
        $this->array($bad->diagnostics)->hasSize(4);
        $this->array(array_unique($this->codes($bad)))->isIdenticalTo(['unknown-ticket-status']);
        $this->variable($bad->references[1]->statusId)->isNull();
        $this->boolean($bad->ticketOwnerHasNoRefusals())->isFalse();
    }

    public function testMissingConflictingAndStaleRoleDecisions(): void
    {
        $snapshot = new LegacyTicketStatusSnapshot($this->rows());
        $this->array($this->codes(new Preflight($snapshot, [], new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 999]))))->contains('role-target-missing');
        $this->array($this->codes(new Preflight($snapshot, [], new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => 19, 'assigned' => 19]))))->contains('role-target-conflict');
        $this->array($this->codes(new Preflight($snapshot, [], new TicketWorkflowRoleDecisions(str_repeat('0', 64), $this->decisions($snapshot)->assignments))))->contains('stale-role-decisions');
    }

    public function testLabelsAreSuggestionsAndInvalidConfigurationRefuses(): void
    {
        $duplicate = new LegacyTicketStatusSnapshot([...$this->rows(), new LegacyTicketStatusRow(92, 'New', 100, 1, null)]);
        $this->string((new Preflight($duplicate, []))->diagnostics[0]->evidence['legacyInterpretation'])->isIdenticalTo('ambiguous');
        $this->array($duplicate->roleCandidates(TicketWorkflowRole::Incoming))->hasSize(2);
        $renamed = new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, 'Renamed role', 1, 1, null)]);
        $this->string((new Preflight($renamed, []))->diagnostics[0]->evidence['legacyInterpretation'])->isIdenticalTo('missing-or-renamed');
        $invalid = new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, null, 1, 2, null)]);
        $this->array(array_slice($this->codes(new Preflight($invalid, [])), 0, 2))->isIdenticalTo(['noncanonical-active-flag', 'active-null-label']);
        $this->array($this->codes(new Preflight(new LegacyTicketStatusSnapshot([]), [])))->contains('empty-status-configuration');
        $this->array($this->codes(new Preflight(new LegacyTicketStatusSnapshot([new LegacyTicketStatusRow(1, '', 1, 0, null)]), [])))->contains('no-active-status');
    }

    public function fingerprintChanges(): array
    {
        return array_map(static fn ($field): array => [$field], ['label', 'weight', 'flag', 'color', 'identity', 'nullable']);
    }

    /**
     * @dataProvider fingerprintChanges
     */
    public function testFingerprintRejectsEachConfigurationChange(string $change): void
    {
        $rows = $this->rows();
        $snapshot = new LegacyTicketStatusSnapshot($rows);
        $result = new Preflight($snapshot, [], $this->decisions($snapshot));
        $replacement = new LegacyTicketStatusRow($change === 'identity' ? 193 : 91, $change === 'label' ? 'Renamed closed' : ($change === 'nullable' ? null : 'Closed'), $change === 'weight' ? 81 : 80, $change === 'flag' ? 0 : 1, $change === 'color' ? '#000' : '#fff');
        $changed = new LegacyTicketStatusSnapshot([$replacement, ...array_slice($rows, 1)]);
        $this->exception(static fn () => $result->requireUnchangedConfiguration($changed))->isInstanceOf(\RuntimeException::class);
    }

    public function testDuplicateIdentitiesWrongOwnersAndLossyRoleInputRefuse(): void
    {
        $rows = $this->rows();
        $snapshot = new LegacyTicketStatusSnapshot($rows);
        foreach ([static fn () => new LegacyTicketStatusSnapshot([$rows[0], $rows[0]]), static fn () => new Preflight($snapshot, [['id' => 1, 'status' => 1], ['id' => 1, 'status' => 2]]), static fn () => new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['problem' => 1]), static fn () => new TicketWorkflowRoleDecisions($snapshot->fingerprint, ['incoming' => '19'])] as $invalidInput) {
            $this->exception($invalidInput)->isInstanceOf(\InvalidArgumentException::class);
        }
    }
}
