<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Frozen legacy interpretation: weight order, ID tie order, inactive ordinal gaps. */
final readonly class LegacyTicketStatusSnapshot
{
    /** @var array<int, LegacyTicketStatusRow> One-based legacy codes, including inactive rows. */
    public array $positions;
    public string $fingerprint;

    /** @param iterable<LegacyTicketStatusRow> $rows */
    public function __construct(iterable $rows)
    {
        $byId = [];
        foreach ($rows as $row) {
            if (isset($byId[$row->id])) {
                throw new \InvalidArgumentException('Duplicate status identity in the snapshot.');
            }
            $byId[$row->id] = $row;
        }
        ksort($byId, SORT_NUMERIC);
        // Hash every field before presentation sorting. NULL, empty strings,
        // noncanonical flags and duplicate labels must remain distinguishable.
        $this->fingerprint = hash('sha256', json_encode(array_values($byId), JSON_THROW_ON_ERROR));
        $ordered = array_values($byId);
        usort($ordered, static fn (LegacyTicketStatusRow $a, LegacyTicketStatusRow $b): int => ($a->weight <=> $b->weight) ?: ($a->id <=> $b->id));
        $positions = [];
        foreach ($ordered as $index => $row) {
            $positions[$index + 1] = $row;
        }
        $this->positions = $positions;
    }

    public function resolve(int $legacyCode): ?LegacyTicketStatusRow
    {
        return $this->positions[$legacyCode] ?? null;
    }

    /** Suggestions reproduce active English-label lookup without its overwrite/hang bugs. */
    public function roleCandidates(TicketWorkflowRole $role): array
    {
        $candidates = [];
        foreach ($this->positions as $position => $row) {
            if ($row->activeFlag !== 0 && $row->name === $role->legacyLabel()) {
                $candidates[] = ['legacyCode' => $position, 'statusId' => $row->id];
            }
        }
        return $candidates;
    }

    /** @return list<TicketStatusDiagnostic> */
    public function diagnostics(): array
    {
        $diagnostics = [];
        $active = 0;
        foreach ($this->positions as $row) {
            if (!in_array($row->activeFlag, [0, 1], true)) {
                $diagnostics[] = new TicketStatusDiagnostic('noncanonical-active-flag', 'SpecialStatus', $row->id, ['flag' => $row->activeFlag]);
            }
            if ($row->activeFlag !== 0) {
                ++$active;
                if ($row->name === null) {
                    $diagnostics[] = new TicketStatusDiagnostic('active-null-label', 'SpecialStatus', $row->id);
                }
            }
        }
        if ($this->positions === []) {
            $diagnostics[] = new TicketStatusDiagnostic('empty-status-configuration', 'SpecialStatus', null);
        } elseif ($active === 0) {
            $diagnostics[] = new TicketStatusDiagnostic('no-active-status', 'SpecialStatus', null);
        }
        return $diagnostics;
    }
}
