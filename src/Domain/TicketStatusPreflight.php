<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Read-only evidence for the Ticket owner. Never a complete executable adoption plan. */
final readonly class TicketStatusPreflight
{
    public array $references;
    public array $diagnostics;
    public array $confirmedRoles;

    /**
     * @param iterable<array{id: int, status: int}> $tickets
     */
    public function __construct(public LegacyTicketStatusSnapshot $snapshot, iterable $tickets, ?TicketWorkflowRoleDecisions $decisions = null)
    {
        $diagnostics = $snapshot->diagnostics();
        $byId = [];
        foreach ($snapshot->positions as $row) {
            $byId[$row->id] = $row;
        }
        $roles = [];
        $claimed = [];
        $confirmedRoles = $decisions?->assignments ?? [];
        if ($decisions !== null && !hash_equals($snapshot->fingerprint, $decisions->configurationFingerprint)) {
            $diagnostics[] = new TicketStatusDiagnostic('stale-role-decisions', 'SpecialStatus', null);
            $confirmedRoles = [];
        }
        foreach (TicketWorkflowRole::cases() as $role) {
            $candidates = $snapshot->roleCandidates($role);
            if (!array_key_exists($role->value, $confirmedRoles)) {
                $diagnostics[] = new TicketStatusDiagnostic('role-decision-required', 'TicketWorkflowRole', $role->value, ['candidates' => $candidates, 'legacyInterpretation' => $candidates === [] ? 'missing-or-renamed' : (count($candidates) > 1 ? 'ambiguous' : 'unique-suggestion')]);
                continue;
            }
            $id = $confirmedRoles[$role->value];
            if (!isset($byId[$id])) {
                $diagnostics[] = new TicketStatusDiagnostic('role-target-missing', 'TicketWorkflowRole', $role->value, ['statusId' => $id]);
            } elseif (isset($claimed[$id])) {
                $diagnostics[] = new TicketStatusDiagnostic('role-target-conflict', 'TicketWorkflowRole', $role->value, ['statusId' => $id, 'otherRole' => $claimed[$id]]);
            } else {
                $roles[$role->value] = $id;
                $claimed[$id] = $role->value;
            }
        }
        $references = [];
        foreach ($tickets as $ticket) {
            $id = $ticket['id'];
            $code = $ticket['status'];
            if (!is_int($id) || $id <= 0 || !is_int($code) || isset($references[$id])) {
                throw new \InvalidArgumentException('Ticket preflight requires unique persisted identities and exact integer status codes.');
            }
            $status = $snapshot->resolve($code);
            $references[$id] = new TicketStatusReference($id, $code, $status?->id, $status !== null && $status->activeFlag === 0);
            if ($status === null) {
                $diagnostics[] = new TicketStatusDiagnostic('unknown-ticket-status', 'Ticket', $id, ['legacyCode' => $code]);
            }
        }
        ksort($references, SORT_NUMERIC);
        $this->references = $references;
        $this->diagnostics = $diagnostics;
        $this->confirmedRoles = $roles;
    }

    /** Only this owner's evidence is valid. Other mutable owners and concurrency remain open. */
    public function ticketOwnerHasNoRefusals(): bool
    {
        return $this->diagnostics === [];
    }

    public function requireUnchangedConfiguration(LegacyTicketStatusSnapshot $current): void
    {
        if (!hash_equals($this->snapshot->fingerprint, $current->fingerprint)) {
            throw new \RuntimeException('SpecialStatus configuration changed while ticket preflight was being read.');
        }
    }
}
