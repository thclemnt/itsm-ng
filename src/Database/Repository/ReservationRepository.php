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
    /** Private display projection; retain the same live connection and two time partitions. */
    public function nativeForUser(int $user, string $now, bool $past, ?array $entities): array
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $reservation = $this->em->getClassMetadata(Reservation::class);
        $item = $this->em->getClassMetadata($reservation->associationMappings['reservationitems']->targetEntity);
        $reference = static function ($metadata, string $property, string $alias) use ($quote, $platform): string {
            $mapping = $metadata->associationMappings[$property];
            if (!$mapping->isToOneOwningSide() || count($mapping->joinColumns) !== 1) {
                throw new \LogicException('Reservation display requires single owning references');
            }
            return $alias . '.' . $quote->getJoinColumnName($mapping->joinColumns[0], $metadata, $platform);
        };
        $column = static fn ($metadata, string $property, string $alias): string => $alias . '.' . $quote->getColumnName($property, $metadata, $platform);
        $types = [];
        $scalar = static function ($metadata, string $property, string $alias, string $result) use ($column, $platform, &$types): string {
            $types[$result] = \Doctrine\DBAL\Types\Type::getType($metadata->getTypeOfField($property));
            return $types[$result]->convertToPHPValueSQL($column($metadata, $property, $alias), $platform)
                . ' AS ' . $platform->quoteIdentifier($result);
        };
        $integer = \Doctrine\DBAL\Types\Type::getType(Types::INTEGER);
        $instant = \Doctrine\DBAL\Types\Type::getType(Types::DATETIMETZ_MUTABLE);
        $query = $connection->createQueryBuilder()->select(
            $scalar($reservation, 'begin', 'r', 'begin'),
            $scalar($reservation, 'end', 'r', 'end'),
            $reference($reservation, 'users', 'r') . ' AS users_id',
            $scalar($reservation, 'comment', 'r', 'comment'),
            $scalar($item, 'id', 'i', 'reservationitems_id'),
            $scalar($item, 'itemtype', 'i', 'itemtype'),
            $scalar($item, 'items_id', 'i', 'items_id'),
            $reference($item, 'entities', 'i') . ' AS entities_id'
        )->from($quote->getTableName($reservation, $platform), 'r')
            ->innerJoin(
                'r',
                $quote->getTableName($item, $platform),
                'i',
                $reference($reservation, 'reservationitems', 'r') . ' = i.' . $quote->getReferencedJoinColumnName(
                    $reservation->associationMappings['reservationitems']->joinColumns[0],
                    $item,
                    $platform
                )
            )
            ->where($reference($reservation, 'users', 'r') . ' = ' . $integer->convertToDatabaseValueSQL(':user', $platform))
            ->andWhere($column($reservation, 'end', 'r') . ($past ? ' <= ' : ' > ') . $instant->convertToDatabaseValueSQL(':now', $platform))
            ->setParameter('user', $user, Types::INTEGER)->setParameter('now', new \DateTime($now), Types::DATETIMETZ_MUTABLE)
            ->orderBy($column($reservation, 'begin', 'r'), $past ? 'DESC' : 'ASC')
            ->addOrderBy($column($reservation, 'id', 'r'), 'ASC');
        if ($entities !== null) {
            $query->andWhere($reference($item, 'entities', 'i') . ' IN (:entities)')
                ->setParameter('entities', $entities ?: [-1], \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }
        // Untyped DQL IDENTITY selections use the string hydration type.
        $types['users_id'] = $types['entities_id'] = \Doctrine\DBAL\Types\Type::getType(Types::STRING);
        $rows = $query->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            foreach ($types as $field => $type) {
                $row[$field] = $type->convertToPHPValue($row[$field], $platform);
            }
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
        $query = $this->em->createQueryBuilder()->select(
            'r.id',
            'r.begin',
            'r.end',
            'r.comment',
            'r.group',
            'IDENTITY(r.reservationitems) AS reservationitems_id',
            'IDENTITY(r.users) AS users_id',
            'u.name AS _user_name',
            'u.realname AS _user_realname',
            'u.firstname AS _user_firstname'
        )->from(Reservation::class, 'r')->leftJoin('r.users', 'u');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Reservation::class));
        $query->where($compiler->where($criteria));
        $compiler->order($order);
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            foreach (['begin', 'end'] as $field) {
                $row[$field] = $row[$field]?->format('Y-m-d H:i:s');
            }
            foreach (['reservationitems_id', 'users_id'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int)$row[$field];
            }
            foreach (['_user_name', '_user_realname', '_user_firstname'] as $field) {
                $row[$field] ??= '';
            }
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
