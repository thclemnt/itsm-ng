<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Ticket_Ticket;
use itsmng\Database\Entity\TicketTicket;

/** Directional child counts follow the native ticket relationship endpoints. */
final class TicketRelationshipRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** @param list<int> $excludedStatuses */
    public function countOpenChildren(?int $parent, array $excludedStatuses): int
    {
        $query = $this->em->createQueryBuilder()->select('COUNT(link.id)')->from(TicketTicket::class, 'link')
            ->join('link.tickets_id_1', 'child')->where('link.link = :kind')
            ->setParameter('kind', Ticket_Ticket::SON_OF, Types::INTEGER);
        if ($parent === null) {
            $query->andWhere('link.tickets_id_2 IS NULL');
        } else {
            $query->andWhere('IDENTITY(link.tickets_id_2) = :parent')->setParameter('parent', $parent, Types::BIGINT);
        }
        if ($excludedStatuses !== []) {
            $query->andWhere('child.status NOT IN (:excluded)')
                ->setParameter('excluded', array_values($excludedStatuses), ArrayParameterType::INTEGER);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }
}
