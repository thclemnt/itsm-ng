<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonITILActor;
use CommonITILObject;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Search;
use Ticket;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Complete ticket records for authorized collection reads; actor joins never multiply pages. */
final class TicketCollectionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function page(TicketVisibility $visibility, array $params, ?array $parent = null): array
    {
        $metadata = $this->em->getClassMetadata(Entity\Ticket::class);
        $query = $this->em->createQueryBuilder()->from(Entity\Ticket::class, 'r');
        $compiler = new RecordCriteria($query, $metadata, legacyValues: false);
        $deleted = filter_var($params['is_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($deleted === null) {
            throw new InvalidArgumentException('is_deleted must be a boolean.');
        }
        $query->where($compiler->where(['entities_id' => $visibility->entities ?: [-1], 'is_deleted' => $deleted]));
        $this->visibility($query, $visibility);
        if ($parent !== null) {
            $this->parent($query, $parent);
        }
        $filters = $params['searchText'] ?? [];
        if (is_array($filters)) {
            if (array_keys($filters) === ['all']) {
                // The collection API historically combines name and comment with AND.
                $filters = ['name' => $filters['all']];
                if ($metadata->hasField('comment')) {
                    $filters['comment'] = $filters['name'];
                }
            }
            foreach ($filters as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (!is_scalar($value)) {
                    throw new InvalidArgumentException('Collection text filters must be scalar.');
                }
                $property = $metadata->fieldNames[$field] ?? null;
                if ($property !== null && $metadata->getTypeOfField($property) === Types::BOOLEAN && strcasecmp((string)$value, 'null') !== 0) {
                    $boolean = filter_var(is_string($value) ? trim($value, '^$ ') : $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolean === null) {
                        throw new InvalidArgumentException('Boolean text filter requires true/false or 1/0: ' . $field);
                    }
                    $query->andWhere($compiler->where([$field => $boolean]));
                    continue;
                }
                // Patterns are bound values, so quotes need no SQL escaping. Backslashes
                // remain literal LIKE characters; the existing search syntax owns anchors.
                $pattern = Search::makeTextSearchValue(str_replace('\\', '\\\\', (string)$value));
                $query->andWhere($compiler->where([$field => $pattern === null || $pattern === '' ? null : ['LIKE', $pattern]]));
            }
        }
        $total = (int)(clone $query)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $query->select('r');
        $sort = $params['sort'] ?? 'id';
        $compiler->order([$sort . ' ' . strtoupper($params['order'] ?? 'ASC')]);
        if ($sort !== 'id') {
            $query->addOrderBy('r.id');
        }
        $query->setFirstResult(max(0, (int)($params['start'] ?? 0)))->setMaxResults(max(1, (int)($params['list_limit'] ?? 50)));
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    private function visibility(QueryBuilder $query, TicketVisibility $access): void
    {
        if ($access->has(Ticket::READALL)) {
            return;
        }
        $userRoles = $groupRoles = [];
        $allowed = [];
        if ($access->has(Ticket::READMY)) {
            $userRoles = [CommonITILActor::REQUESTER, CommonITILActor::OBSERVER];
            $allowed[] = 'IDENTITY(r.recipient) = :viewer';
        }
        if ($access->has(Ticket::READGROUP)) {
            $groupRoles = [CommonITILActor::REQUESTER, CommonITILActor::OBSERVER];
        }
        if ($access->has(Ticket::OWN) || $access->has(Ticket::READASSIGN)) {
            $userRoles[] = CommonITILActor::ASSIGN;
        }
        if ($access->has(Ticket::READASSIGN)) {
            $groupRoles[] = CommonITILActor::ASSIGN;
            if ($access->has(Ticket::ASSIGN)) {
                $allowed[] = 'r.status = :incoming';
                $query->setParameter('incoming', CommonITILObject::INCOMING, Types::INTEGER);
            }
        }
        if ($userRoles && $access->user > 0) {
            $allowed[] = 'EXISTS (SELECT actor.id FROM ' . Entity\TicketUser::class . ' actor WHERE actor.tickets = r AND IDENTITY(actor.actor) = :viewer AND actor.type IN (:userRoles))';
            $query->setParameter('userRoles', array_values(array_unique($userRoles)));
        }
        if ($groupRoles && $access->groups) {
            $allowed[] = 'EXISTS (SELECT membership.id FROM ' . Entity\GroupTicket::class . ' membership WHERE membership.tickets = r AND IDENTITY(membership.groups) IN (:viewerGroups) AND membership.type IN (:groupRoles))';
            $query->setParameter('viewerGroups', $access->groups)->setParameter('groupRoles', array_values(array_unique($groupRoles)));
        }
        if ($access->validate && $access->user > 0) {
            $allowed[] = 'EXISTS (SELECT validation.id FROM ' . Entity\TicketValidation::class . ' validation WHERE validation.tickets = r AND IDENTITY(validation.validator) = :viewer)';
        }
        if ($access->has(Ticket::READMY) || ($access->user > 0 && ($userRoles || $access->validate))) {
            $query->setParameter('viewer', $access->user, Types::BIGINT);
        }
        $query->andWhere($allowed ? '(' . implode(' OR ', $allowed) . ')' : '1 = 0');
    }

    /**
     * The controller checks parent existence and read authorization. A route without
     * a role qualifier includes every direct owning role declared for that target;
     * actor membership is a separate relationship and is not implicitly traversed.
     */
    private function parent(QueryBuilder $query, array $parent): void
    {
        $class = EntityRegistry::tables()[$parent['table']] ?? throw new InvalidArgumentException('Parent collection requires a mapped record.');
        $parentMetadata = $this->em->getClassMetadata($class);
        $metadata = $this->em->getClassMetadata(Entity\Ticket::class);
        $owners = [];
        foreach ($metadata->associationMappings as $field => $mapping) {
            if ($mapping->isToOneOwningSide() && $mapping->targetEntity === $class) {
                $owners[] = 'IDENTITY(r.' . $field . ') = :parentId';
            }
        }
        if ($owners) {
            $query->andWhere('(' . implode(' OR ', $owners) . ')')->setParameter('parentId', $parent['id'], Types::BIGINT);
            return;
        }
        $tickets = [];
        foreach ($parentMetadata->associationMappings as $field => $mapping) {
            if ($mapping->isToOneOwningSide() && $mapping->targetEntity === Entity\Ticket::class) {
                $tickets[] = 'parent.' . $field . ' = r';
            }
        }
        if ($tickets) {
            $query->andWhere('EXISTS (SELECT parent.id FROM ' . $class . ' parent WHERE parent.id = :parentId AND (' . implode(' OR ', $tickets) . '))')
                ->setParameter('parentId', $parent['id'], Types::BIGINT);
            return;
        }
        $bindings = $this->em->getClassMetadata(Entity\ItemTicket::class);
        foreach (EntityRegistry::discriminatedReferences($bindings->getTableName())['items_id']['selections'] as $kind => $selection) {
            if ($selection['target'] === $parentMetadata->getTableName()) {
                $association = Entity\ItemTicket::referenceAssociation($kind);
                $query->andWhere('EXISTS (SELECT binding.id FROM ' . Entity\ItemTicket::class . ' binding WHERE binding.tickets = r AND binding.itemtype = :parentKind AND IDENTITY(binding.' . $association . ') = :parentId)')
                    ->setParameter('parentId', $parent['id'], Types::BIGINT)->setParameter('parentKind', $kind, Types::STRING);
                return;
            }
        }
        throw new InvalidArgumentException('No ticket relationship is defined for parent ' . $parentMetadata->getTableName() . '.');
    }
}
