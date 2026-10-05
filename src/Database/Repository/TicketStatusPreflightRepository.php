<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\SpecialStatus;
use itsmng\Database\Entity\Ticket;
use itsmng\Domain\LegacyTicketStatusRow;
use itsmng\Domain\LegacyTicketStatusSnapshot;
use itsmng\Domain\TicketStatusPreflight;
use itsmng\Domain\TicketWorkflowRoleDecisions;

/** Global adoption inspection, not an entity-scoped application ticket collection. */
final class TicketStatusPreflightRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function snapshot(): LegacyTicketStatusSnapshot
    {
        // Scalar entity projections do not flush or reuse stale managed instances.
        // Keep the supplied manager's connection, transaction and read routing.
        $rows = $this->em->createQueryBuilder()
            ->select('s.id AS id', 's.name AS name', 's.weight AS weight', 's.is_active AS activeFlag', 's.color AS color')
            ->from(SpecialStatus::class, 's')->orderBy('s.id')->getQuery()->getArrayResult();
        return new LegacyTicketStatusSnapshot(array_map(static fn (array $row): LegacyTicketStatusRow => new LegacyTicketStatusRow(
            $row['id'], $row['name'], $row['weight'], $row['activeFlag'], $row['color']
        ), $rows));
    }

    /** All tickets, including deleted and other-entity rows, must retain their interpretation. */
    public function inspect(?TicketWorkflowRoleDecisions $decisions = null): TicketStatusPreflight
    {
        $snapshot = $this->snapshot();
        $tickets = $this->em->createQueryBuilder()->select('t.id AS id', 't.status AS status')
            ->from(Ticket::class, 't')->orderBy('t.id')->getQuery()->toIterable([], \Doctrine\ORM\Query::HYDRATE_ARRAY);
        $result = new TicketStatusPreflight($snapshot, $tickets, $decisions);
        $result->requireUnchangedConfiguration($this->snapshot());
        // A matching double read detects observed changes, not a serializable
        // snapshot or lock. It grants no concurrent adoption execution authority.
        return $result;
    }
}
