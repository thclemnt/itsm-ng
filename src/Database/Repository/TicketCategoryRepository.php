<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Ticket;
use itsmng\Database\Entity\ITILCategory;
use itsmng\Database\RecordCriteria;

use function getEntitiesRestrictCriteria;

/** Ticket-form choices retain recursive entity ownership and helpdesk visibility. */
final class TicketCategoryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function choices(int $type, array $entities, bool $helpdesk): array
    {
        if (!$entities) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->from(ITILCategory::class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(ITILCategory::class), legacyValues: false);
        $criteria = getEntitiesRestrictCriteria('glpi_itilcategories', '', $entities, true, true);
        $flag = match ($type) {
            Ticket::INCIDENT_TYPE => 'is_incident',
            Ticket::DEMAND_TYPE => 'is_request',
            default => null,
        };
        if ($flag !== null) {
            $criteria[$flag] = true;
        }
        if ($helpdesk) {
            $criteria['is_helpdeskvisible'] = true;
        }
        $query->select('r.id, r.completename')->where($compiler->where($criteria))->orderBy('r.completename')->addOrderBy('r.id');
        return array_column($query->getQuery()->getScalarResult(), 'completename', 'id');
    }
}
