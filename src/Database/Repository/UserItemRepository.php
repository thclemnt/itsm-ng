<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Inventory assignments and the related cleanup performed when a user is purged. */
final class UserItemRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $type): bool
    {
        return isset(EntityRegistry::TABLES[\getTableForItemType($type)]);
    }

    public function groups(int $user): array
    {
        return $this->em->createQueryBuilder()->select('g.id AS groups_id', 'g.name AS name')
            ->from(Entity\GroupMembership::class, 'membership')->innerJoin('membership.groups', 'g')
            ->where('IDENTITY(membership.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('g.id')->getQuery()->getScalarResult();
    }

    /** Stream each mapped record with labels; membership and entity filters stay in SQL. */
    public function items(string $type, string $field, array $actors, array $scope): iterable
    {
        if (!in_array($field, ['users_id', 'users_id_tech', 'groups_id', 'groups_id_tech'], true)) {
            throw new \InvalidArgumentException('Unsupported inventory assignment field');
        }
        if (!$actors) {
            return;
        }
        $class = EntityRegistry::TABLES[\getTableForItemType($type)];
        $metadata = $this->em->getClassMetadata($class);
        $criteria = [[$field => array_values($actors)], $scope];
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($metadata->hasField($flag)) {
                $criteria[$flag] = 0;
            }
        }
        $query = $this->em->createQueryBuilder()->select('r', 'entity.completename AS entity_name')
            ->from($class, 'r')->leftJoin('r.entities', 'entity')->orderBy('r.id');
        if ($metadata->hasAssociation('states')) {
            $query->addSelect('state.completename AS state_name')->leftJoin('r.states', 'state');
        }
        $query->where((new RecordCriteria($query, $metadata))->where($criteria));
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $result) {
            $row = $records->toRow($result[0]) + ['_entity_name' => $result['entity_name'], '_state_name' => $result['state_name'] ?? ''];
            $this->em->detach($result[0]);
            yield $row;
        }
    }

    public function releaseUserResources(int $user): void
    {
        // Private saved searches are deleted by their model hooks before this step.
        $this->em->createQueryBuilder()->update(Entity\SavedSearch::class, 's')->set('s.users_id', ':none')
            ->where('s.users_id = :user AND s.is_private = :public')
            ->setParameter('none', 0, Types::INTEGER)->setParameter('user', $user, Types::INTEGER)
            ->setParameter('public', false, Types::BOOLEAN)->getQuery()->execute();
        $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')
            ->set('c.items_id', ':none')->set('c.itemtype', ':type')->set('c.date_out', ':date')
            ->where('c.items_id = :user AND c.itemtype = :recipient')
            ->setParameter('none', 0, Types::INTEGER)->setParameter('type', null, Types::STRING)
            ->setParameter('date', null, Types::DATE_MUTABLE)->setParameter('user', $user, Types::INTEGER)
            ->setParameter('recipient', 'User', Types::STRING)->getQuery()->execute();
    }
}
