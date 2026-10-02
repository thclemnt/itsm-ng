<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

final class GroupItemRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $type): bool
    {
        return isset(EntityRegistry::tables()[\getTableForItemType($type)]);
    }

    public function count(string $type, string $field, array $groups, bool $members, array $scope): int
    {
        return (int)$this->query($type, $field, $groups, $members, $scope)
            ->select($type === 'Consumable' ? 'COUNT(c.id)' : 'COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    public function ids(string $type, string $field, array $groups, bool $members, array $scope, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }
        $id = $type === 'Consumable' ? 'c.id' : 'r.id';
        $class = $type === 'Consumable' ? Entity\ConsumableItem::class : EntityRegistry::tables()[\getTableForItemType($type)];
        $query = $this->query($type, $field, $groups, $members, $scope)->select($id . ' AS id');
        if ($this->em->getClassMetadata($class)->hasField('name')) {
            $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN unnamed')->orderBy('unnamed')->addOrderBy('r.name');
        }
        return array_map('intval', array_column($query->addOrderBy($id)
            ->setMaxResults($limit)->setFirstResult(max(0, $offset))->getQuery()->getScalarResult(), 'id'));
    }

    private function query(string $type, string $field, array $groups, bool $members, array $scope): QueryBuilder
    {
        if (!in_array($field, ['groups_id', 'groups_id_tech'], true)) {
            throw new \InvalidArgumentException('Unsupported group assignment field');
        }
        $class = $type === 'Consumable' ? Entity\ConsumableItem::class : EntityRegistry::tables()[\getTableForItemType($type)];
        $query = $this->em->createQueryBuilder()->from($class, 'r')->setParameter('groups', array_values($groups) ?: [-1]);
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata($class));
        $query->where($compiler->where($scope));
        if ($type === 'Consumable') {
            return $query->innerJoin(Entity\Consumable::class, 'c', 'WITH', 'c.consumableitems = r.id')
                ->andWhere('IDENTITY(c.recipientGroup) IN (:groups)');
        }
        $group = $compiler->column($field);
        $predicate = $group . ' IN (:groups)';
        if ($members) {
            $user = $compiler->column(str_replace('groups', 'users', $field));
            $predicate .= ' OR (' . $group . ' IS NULL AND EXISTS (SELECT membership.id FROM ' . Entity\GroupMembership::class
                . ' membership WHERE membership.groups IN (:groups) AND IDENTITY(membership.users) = ' . $user . '))';
        }
        return $query->andWhere('(' . $predicate . ')');
    }
}
