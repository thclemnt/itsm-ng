<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\PlanningExternalEvent;
use itsmng\Database\RecordCriteria;

/** Mapped calendar projections and transactional group planning subscriptions. */
final class PlanningRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Select events once, including events with no category, with typed date and actor predicates. */
    public function externalEvents(array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'category.color AS cat_color')
            ->from(PlanningExternalEvent::class, 'r')->leftJoin('r.planningeventcategories', 'category');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(PlanningExternalEvent::class));
        $query->where($compiler->where($criteria))->orderBy('r.begin')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['cat_color' => $result['cat_color']];
            $this->em->detach($result[0]);
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
