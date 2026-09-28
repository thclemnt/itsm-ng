<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Reservation;

final class ReservationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function forUser(int $user, string $now, bool $past, ?array $entities): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('r.begin, r.end, r.users_id, r.comment, i.id AS reservationitems_id, i.items_id, i.entities_id')
            ->from(Reservation::class, 'r')->join('r.reservationitems', 'i')
            ->where('r.users_id = :user')->setParameter('user', $user)
            ->andWhere('r.end ' . ($past ? '<=' : '>') . ' :now')->setParameter('now', new \DateTime($now))
            ->orderBy('r.begin', $past ? 'DESC' : 'ASC')->addOrderBy('r.id', 'ASC');
        if ($entities !== null) {
            $query->andWhere('i.entities_id IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            foreach (['begin', 'end'] as $field) {
                $row[$field] = $row[$field]?->format('Y-m-d H:i:s');
            }
        }
        return $rows;
    }
}
