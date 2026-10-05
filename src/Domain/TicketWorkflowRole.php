<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Domain roles are distinct from mutable labels, positions and database IDs. */
enum TicketWorkflowRole: string
{
    case Incoming = 'incoming';
    case Assigned = 'assigned';
    case Planned = 'planned';
    case Waiting = 'waiting';
    case Solved = 'solved';
    case Closed = 'closed';

    /** Historical label evidence only; never an authoritative role assignment. */
    public function legacyLabel(): string
    {
        return match ($this) {
            self::Incoming => 'New',
            self::Assigned => 'Processing (assigned)',
            self::Planned => 'Processing (planned)',
            self::Waiting => 'Pending',
            self::Solved => 'Solved',
            self::Closed => 'Closed',
        };
    }
}
