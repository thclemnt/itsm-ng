<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use itsmng\Database\Entity;

/** Mapped parent and relationship types shared by statistics projections. */
final class ITILStatisticsType
{
    private const TYPES = [
        'Ticket' => [Entity\Ticket::class, 'tickets', Entity\TicketUser::class, Entity\GroupTicket::class, Entity\SupplierTicket::class, Entity\TicketTask::class, Entity\ItemTicket::class],
        'Problem' => [Entity\Problem::class, 'problems', Entity\ProblemUser::class, Entity\GroupProblem::class, Entity\ProblemSupplier::class, Entity\ProblemTask::class, Entity\ItemProblem::class],
        'Change' => [Entity\Change::class, 'changes', Entity\ChangeUser::class, Entity\ChangeGroup::class, Entity\ChangeSupplier::class, Entity\ChangeTask::class, Entity\ChangeItem::class],
    ];

    public static function definition(string $type): array
    {
        return self::TYPES[$type] ?? throw new \InvalidArgumentException('Unmapped statistics item type');
    }
}
