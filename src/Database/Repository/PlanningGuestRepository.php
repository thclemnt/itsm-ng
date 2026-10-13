<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity\PlanningExternalEvent;
use itsmng\Database\Entity\PlanningExternalEventGuest;
use itsmng\Database\Entity\User;

final class PlanningGuestRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function selections(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
                throw new InvalidArgumentException('Planning guests require positive user IDs');
            }
            $ids[(int)$value] = (int)$value;
        }
        return array_values($ids);
    }

    public function userIds(int $event): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()
            ->select('IDENTITY(g.user) AS id')->from(PlanningExternalEventGuest::class, 'g')
            ->where('g.event = :event')->setParameter('event', $event, Types::INTEGER)
            ->orderBy('g.position')->addOrderBy('g.id')->getQuery()->getScalarResult(), 'id'));
    }

    public function replaceGuests(int $event, array $values): void
    {
        $ids = self::selections($values);
        $this->em->getConnection()->transactional(function () use ($event, $ids): void {
            $record = $this->em->find(PlanningExternalEvent::class, $event);
            if ($record === null) {
                throw new InvalidArgumentException('Unknown planning event');
            }
            $this->em->lock($record, LockMode::PESSIMISTIC_WRITE);
            // Validate existence without hydrating each complete user. Bound the
            // parameter list while retaining the first invalid selection's error.
            foreach (array_chunk($ids, 1000) as $batch) {
                $existing = array_fill_keys($this->em->createQueryBuilder()->select('u.id')
                    ->from(User::class, 'u')->where('u.id IN (:guests)')
                    ->setParameter('guests', $batch, ArrayParameterType::INTEGER)
                    ->getQuery()->getSingleColumnResult(), true);
                foreach ($batch as $id) {
                    if (!isset($existing[$id])) {
                        throw new InvalidArgumentException('Unknown planning guest: ' . $id);
                    }
                }
            }
            $this->removeForEvent($event);
            foreach ($ids as $position => $id) {
                $guest = new PlanningExternalEventGuest();
                $guest->event = $record;
                $guest->user = $this->em->getReference(User::class, $id);
                $guest->position = $position;
                $this->em->persist($guest);
            }
            $this->em->flush();
        });
    }

    public function removeForEvent(int $event): void
    {
        $this->em->createQueryBuilder()->delete(PlanningExternalEventGuest::class, 'g')
            ->where('g.event = :id')->setParameter('id', $event, Types::INTEGER)->getQuery()->execute();
    }

    public function reassignUser(int $source, ?int $destination): void
    {
        if ($source === $destination) {
            return;
        }
        $this->em->getConnection()->transactional(function () use ($source, $destination): void {
            if ($destination !== null && $this->em->find(User::class, $destination) === null) {
                throw new InvalidArgumentException('Unknown replacement guest');
            }
            $events = $this->em->createQueryBuilder()->select('IDENTITY(g.event) AS id')->from(PlanningExternalEventGuest::class, 'g')
                ->where('g.user = :id')->setParameter('id', $source, Types::INTEGER)->orderBy('g.event')->getQuery()->getScalarResult();
            foreach ($events as $event) {
                $id = (int)$event['id'];
                $record = $this->em->find(PlanningExternalEvent::class, $id);
                if ($record === null) {
                    continue;
                }
                $this->em->lock($record, LockMode::PESSIMISTIC_WRITE);
                $ids = [];
                foreach ($this->userIds($id) as $user) {
                    if ($user !== $source) {
                        $ids[] = $user;
                    } elseif ($destination !== null) {
                        $ids[] = $destination;
                    }
                }
                $this->replaceGuests($id, $ids);
            }
        });
    }
}
