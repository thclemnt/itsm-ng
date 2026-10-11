<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Session;
use TicketValidation;

/** Immutable authorization snapshot for a ticket read operation. */
final readonly class TicketVisibility
{
    public function __construct(public int $user, public array $entities, public array $groups, public int $rights, public bool $validate = false)
    {
    }

    public static function fromSession(): self
    {
        return new self(
            (int)Session::getLoginUserID(),
            array_map('intval', $_SESSION['glpiactiveentities'] ?? []),
            array_map('intval', $_SESSION['glpigroups'] ?? []),
            (int)($_SESSION['glpiactiveprofile']['ticket'] ?? 0),
            Session::haveRightsOr('ticketvalidation', [TicketValidation::VALIDATEINCIDENT, TicketValidation::VALIDATEREQUEST])
        );
    }

    public function has(int $right): bool
    {
        return ($this->rights & $right) !== 0;
    }
}
