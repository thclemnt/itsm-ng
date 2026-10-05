<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Explicit reviewed choices bound to the exact status configuration they interpret. */
final readonly class TicketWorkflowRoleDecisions
{
    /** @param array<string, int> $assignments */
    public function __construct(public string $configurationFingerprint, public array $assignments)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $configurationFingerprint)) {
            throw new \InvalidArgumentException('Role decisions require an exact status configuration fingerprint.');
        }
        foreach ($assignments as $role => $id) {
            if (!is_string($role) || TicketWorkflowRole::tryFrom($role) === null || !is_int($id)) {
                throw new \InvalidArgumentException('Role decisions must name a TicketWorkflowRole and an integer status identity.');
            }
        }
    }
}
