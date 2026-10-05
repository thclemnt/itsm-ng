<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Alert;
use itsmng\Database\RecordCriteria;
use itsmng\Database\Entity\Reservation;

final class ReservationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function forUser(int $user, string $now, bool $past, ?array $entities): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('r.begin, r.end, IDENTITY(r.users) AS users_id, r.comment, i.id AS reservationitems_id, i.itemtype, i.items_id, IDENTITY(i.entities) AS entities_id')
            ->from(Reservation::class, 'r')->join('r.reservationitems', 'i')
            ->where('IDENTITY(r.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->andWhere('r.end ' . ($past ? '<=' : '>') . ' :now')->setParameter('now', new \DateTime($now), Types::DATETIMETZ_MUTABLE)
            ->orderBy('r.begin', $past ? 'DESC' : 'ASC')->addOrderBy('r.id', 'ASC');
        if ($entities !== null) {
            $query->andWhere('IDENTITY(i.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            foreach (['begin', 'end'] as $field) {
                $row[$field] = $row[$field]?->format('Y-m-d H:i:s');
            }
        }
        return $rows;
    }
    public function groupExists(int $item, int $group): bool
    {
        return (new RecordRepository($this->em))->countMatching('glpi_reservations', [
            'reservationitems_id' => $item, 'group' => $group,
        ]) > 0;
    }

    public function groupIds(int $item, int $group): array
    {
        return (new RecordRepository($this->em))->identifiers('glpi_reservations', 'id', [
            'reservationitems_id' => $item, 'group' => $group,
        ], ['id']);
    }

    /** Half-open intervals allow a reservation to start when the previous one ends. */
    public function conflicts(int $item, string $begin, string $end, ?int $exclude = null): bool
    {
        $criteria = ['reservationitems_id' => $item, 'end' => ['>', $begin], 'begin' => ['<', $end]];
        if ($exclude !== null) {
            $criteria['id'] = ['<>', $exclude];
        }
        return (new RecordRepository($this->em))->countMatching('glpi_reservations', $criteria) > 0;
    }

    public function during(int $item, string $begin, string $end): array
    {
        return $this->rows([
            'reservationitems_id' => $item, 'end' => ['>', $begin], 'begin' => ['<', $end],
        ], ['begin', 'id']);
    }

    public function forItem(int $item, string $now, bool $past): array
    {
        return $this->rows([
            'reservationitems_id' => $item, 'end' => [$past ? '<=' : '>', $now],
        ], [$past ? 'begin DESC' : 'begin', 'id']);
    }

    private function rows(array $criteria, array $order): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'u')->from(Reservation::class, 'r')->leftJoin('r.users', 'u');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Reservation::class));
        $query->where($compiler->where($criteria));
        $compiler->order($order);
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $reservation) {
            $rows[] = $records->toRow($reservation) + [
                '_user_name' => $reservation->users?->name ?? '',
                '_user_realname' => $reservation->users?->realname ?? '',
                '_user_firstname' => $reservation->users?->firstname ?? '',
            ];
        }
        return $rows;
    }

    public function activeItemIds(string $begin, string $end): array
    {
        $rows = $this->em->createQueryBuilder()->select('i.id', 'MIN(r.begin) AS HIDDEN first_begin')
            ->from(Reservation::class, 'r')->join('r.reservationitems', 'i')
            ->where('i.is_active = :active AND r.end > :begin AND r.begin < :end')
            ->setParameter('active', true, Types::BOOLEAN)
            ->setParameter('begin', new \DateTime($begin), Types::DATETIMETZ_MUTABLE)
            ->setParameter('end', new \DateTime($end), Types::DATETIMETZ_MUTABLE)
            ->groupBy('i.id')->orderBy('first_begin')->addOrderBy('i.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'id'));
    }

    /** Select pending alerts without database-specific timestamp arithmetic. */
    public function expiring(int $entity, int $seconds, \DateTimeImmutable $now): array
    {
        $query = $this->em->createQueryBuilder()->select('i.id', 'i.itemtype', 'i.items_id', 'i.comment', 'IDENTITY(i.entities) AS entities_id', 'i.is_recursive', 'i.is_active', 'i.is_deleted', 'r.end AS end', 'r.id AS resaid')
            ->from(Reservation::class, 'r')->join('r.reservationitems', 'i')
            ->leftJoin(Alert::class, 'a', 'WITH', 'a.reservation = r AND a.type = :alert')
            ->where('IDENTITY(i.entities) = :entity AND r.begin < :now AND r.end < :threshold AND a.id IS NULL')
            ->setParameter('entity', $entity, Types::INTEGER)
            ->setParameter('alert', \Alert::END)
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('threshold', $now->setTimestamp($now->getTimestamp() + $seconds), Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('r.id');
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            $row['end'] = $row['end']->format('Y-m-d H:i:s');
        }
        return $rows;
    }
}
