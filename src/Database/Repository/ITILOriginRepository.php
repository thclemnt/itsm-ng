<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Historical origins do not replay content updates, timestamps or ITIL state transitions. */
final class ITILOriginRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function reassignTicket(int $id, ?int $replacement): void
    {
        $this->reassign(Entity\ITILFollowup::class, 'sourceTicket', $id, $replacement);
        $this->reassign(Entity\ITILFollowup::class, 'promotedTicket', $id, $replacement);
        $this->reassign(Entity\TicketTask::class, 'sourceTicket', $id, $replacement);
    }

    public function reassignFollowup(int $id, ?int $replacement): void
    {
        $this->reassign(Entity\ITILSolution::class, 'followup', $id, $replacement);
    }

    private function reassign(string $entity, string $association, int $id, ?int $replacement): void
    {
        $this->em->createQueryBuilder()->update($entity, 'r')->set('r.' . $association, ':replacement')
            ->where('IDENTITY(r.' . $association . ') = :id')->setParameter('id', $id, Types::INTEGER)
            ->setParameter('replacement', $replacement, Types::INTEGER)->getQuery()->execute();
    }

    public function promotionSource(int $ticket): ?array
    {
        return $this->em->createQueryBuilder()->select('f.id', 'f.itemtype', 'IDENTITY(f.ticket) AS items_id')
            ->from(Entity\ITILFollowup::class, 'f')
            ->where('f.itemtype = :type AND IDENTITY(f.promotedTicket) = :ticket')
            ->setParameter('type', 'Ticket')->setParameter('ticket', $ticket, Types::INTEGER)
            ->orderBy('f.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
