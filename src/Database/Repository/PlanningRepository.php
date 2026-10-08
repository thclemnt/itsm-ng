<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Alert as LegacyAlert;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\PlanningRecall;
use itsmng\Database\Entity\Alert;
use itsmng\Database\Entity\PlanningExternalEvent;
use itsmng\Database\Entity\PlanningExternalEventGuest;
use itsmng\Database\RecordCriteria;

/** Mapped calendar projections and transactional group planning subscriptions. */
final class PlanningRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Planning selectors need only labels, with exact entity and optional membership scope. */
    public function groupChoices(int $entity, ?array $groups = null): array
    {
        if ($groups === []) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r.id, r.name')->from(Group::class, 'r');
        $criteria = ['entities_id' => $entity];
        if ($groups !== null) {
            $criteria['id'] = $groups;
        }
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Group::class));
        $query->where($compiler->where($criteria));
        $compiler->order(['name', 'id']);
        return $query->getQuery()->getArrayResult();
    }

    /** Select events once, including events with no category, with typed date and actor predicates. */
    public function externalEvents(array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT r', 'category.color AS cat_color')
            ->from(PlanningExternalEvent::class, 'r')->leftJoin('r.planningeventcategories', 'category')
            ->leftJoin(PlanningExternalEventGuest::class, 'guest', 'WITH', 'guest.event = r');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(PlanningExternalEvent::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(PlanningExternalEventGuest::class), 'guest');
        $query->where($compiler->where($criteria))->orderBy('r.begin')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['cat_color' => $result['cat_color']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    /** Recompute each recipient's date as typed state in one unit of work. */
    public function rescheduleRecalls(string $type, int $item, DateTimeImmutable $begin): void
    {
        $this->em->getConnection()->transactional(function () use ($type, $item, $begin): void {
            $recalls = $this->em->createQueryBuilder()->select('r')->from(PlanningRecall::class, 'r')
                ->where('IDENTITY(r.' . PlanningRecall::referenceAssociation($type) . ') = :item')
                ->setParameter('item', $item, Types::BIGINT)
                ->getQuery()->getResult();
            foreach ($recalls as $recall) {
                $recall->when = DateTime::createFromImmutable($begin)->setTimestamp($begin->getTimestamp() - $recall->before_time);
            }
            $this->em->flush();
            foreach ($recalls as $recall) {
                $this->em->detach($recall);
            }
        });
    }

    /** Select due, undelivered recalls; dispatch and delivery markers remain model responsibilities. */
    public function dueRecalls(DateTimeImmutable $before): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(PlanningRecall::class, 'r')
            ->where('r.when < :before')->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)
            ->andWhere('NOT EXISTS (SELECT a.id FROM ' . Alert::class . ' a WHERE a.planningRecall = r AND a.type = :action)')
            ->setParameter('action', LegacyAlert::ACTION, Types::INTEGER)
            ->orderBy('r.when')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $recall) {
            $rows[] = $records->toRow($recall);
            $this->em->detach($recall);
        }
        return $rows;
    }

    public function updateGroupSubscriptions(int $group, int $currentUser, callable $update): ?array
    {
        $key = 'group_' . $group . '_users';
        return $this->em->getConnection()->transactional(function () use ($key, $currentUser, $update): ?array {
            $query = $this->em->createQueryBuilder()->select('u.id AS id', 'u.plannings AS plannings')->from(User::class, 'u')
                ->where('u.plannings LIKE :key')->setParameter('key', '%' . $key . '%')->orderBy('u.id')->getQuery();
            // Serialize edits to each subscriber's JSON configuration.
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            $session = null;
            foreach ($query->toIterable() as $row) {
                $settings = json_decode($row['plannings'], true);
                if (!is_array($settings['plannings'][$key]['users'] ?? null)) {
                    continue;
                }
                $settings = $update($settings, $key);
                $this->em->createQueryBuilder()->update(User::class, 'u')->set('u.plannings', ':settings')
                    ->setParameter('settings', json_encode($settings, JSON_THROW_ON_ERROR), Types::TEXT)
                    ->where('u.id = :id')->setParameter('id', (int)$row['id'], Types::INTEGER)->getQuery()->execute();
                if ((int)$row['id'] === $currentUser) {
                    $session = $settings;
                }
            }
            return $session;
        });
    }
}
