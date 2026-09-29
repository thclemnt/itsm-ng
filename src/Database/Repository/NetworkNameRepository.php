<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;

final class NetworkNameRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function names(string $type, int $id, ?array $entities): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\NetworkName::class, 'n')
            ->where('n.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)
            ->setParameter('parent', $id, Types::INTEGER);
        if ($type === 'FQDN') {
            $query->andWhere('IDENTITY(n.fqdns_id) = :parent');
        } elseif ($type === 'NetworkEquipment') {
            $query->innerJoin(Entity\NetworkPort::class, 'port', 'WITH', 'port.id = n.items_id AND n.itemtype = :portType')
                ->andWhere('port.items_id = :parent AND port.itemtype = :type AND port.is_deleted = :deleted')
                ->setParameter('portType', 'NetworkPort', Types::STRING)->setParameter('type', $type, Types::STRING);
            $this->scope($query, 'port', $entities);
        } else {
            $query->andWhere('n.items_id = :parent AND n.itemtype = :type')->setParameter('type', $type, Types::STRING);
        }
        $this->scope($query, 'n', $entities);
        return $query;
    }

    private function scope(QueryBuilder $query, string $alias, ?array $entities): void
    {
        if ($entities === []) {
            $query->andWhere('1 = 0');
        } elseif ($entities !== null) {
            $query->andWhere('IDENTITY(' . $alias . '.entities) IN (:entities)')
                ->setParameter('entities', array_map('intval', $entities), ArrayParameterType::INTEGER);
        }
    }

    public function countForItem(string $type, int $id, ?array $entities): int
    {
        return (int)$this->names($type, $id, $entities)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
    }

    /** One name per page slot, ordered by its first active address or first alias. */
    public function identifiersForItem(string $type, int $id, string $order, ?int $limit, int $offset, ?array $entities): array
    {
        $query = $this->names($type, $id, $entities)->select('n.id');
        if ($order === 'alias') {
            $condition = 'IDENTITY(a.networknames_id) = n.id';
            if ($entities !== null) {
                $condition .= $entities === [] ? ' AND 1 = 0' : ' AND IDENTITY(a.entities) IN (:entities)';
            }
            $query->leftJoin(Entity\NetworkAlias::class, 'a', 'WITH', $condition)
                ->addSelect('MIN(a.name) AS HIDDEN first_alias', 'CASE WHEN COUNT(a.name) = 0 THEN 1 ELSE 0 END AS HIDDEN missing_alias')
                ->groupBy('n.id')->orderBy('missing_alias')->addOrderBy('first_alias');
        } elseif ($order === 'ip') {
            // Pick one complete address tuple; independent MINs could form a nonexistent address.
            $less = [];
            $equal = [];
            foreach (['binary_3', 'binary_2', 'binary_1', 'binary_0', 'id'] as $field) {
                $less[] = '(' . implode(' AND ', [...$equal, 'earlier.' . $field . ' < ip.' . $field]) . ')';
                $equal[] = 'earlier.' . $field . ' = ip.' . $field;
            }
            $condition = 'ip.items_id = n.id AND ip.itemtype = :addressType AND ip.is_deleted = :deleted AND NOT EXISTS (SELECT earlier.id FROM '
                . Entity\IPAddress::class . ' earlier WHERE earlier.items_id = n.id AND earlier.itemtype = :addressType AND earlier.is_deleted = :deleted AND (' . implode(' OR ', $less) . '))';
            $query->leftJoin(Entity\IPAddress::class, 'ip', 'WITH', $condition)
                ->setParameter('addressType', 'NetworkName', Types::STRING)
                ->addSelect('CASE WHEN ip.id IS NULL THEN 1 ELSE 0 END AS HIDDEN missing_ip')->orderBy('missing_ip');
            foreach (['binary_3', 'binary_2', 'binary_1', 'binary_0'] as $field) {
                $query->addOrderBy('ip.' . $field);
            }
        } else {
            $query->orderBy('n.name');
        }
        $query->addOrderBy('n.id')->setFirstResult(max(0, $offset));
        if ($limit !== null) {
            $query->setMaxResults(max(1, $limit));
        }
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'));
    }

    private function aliases(int $domain, ?array $entities): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\NetworkAlias::class, 'a')->innerJoin('a.networknames_id', 'n')
            ->where('IDENTITY(a.fqdns_id) = :domain')->setParameter('domain', $domain, Types::INTEGER);
        $this->scope($query, 'a', $entities);
        $this->scope($query, 'n', $entities);
        return $query;
    }

    public function countAliasesForDomain(int $domain, ?array $entities): int
    {
        return (int)$this->aliases($domain, $entities)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    }

    public function aliasesForDomain(int $domain, string $order, int $limit, int $offset, ?array $entities): array
    {
        return $this->aliases($domain, $entities)
            ->select('a.id AS alias_id', 'a.name AS alias', 'n.id AS address_id', 'a.comment AS comment')
            ->orderBy($order === 'realname' ? 'n.name' : 'a.name')->addOrderBy('a.id')
            ->setMaxResults(max(1, $limit))->setFirstResult(max(0, $offset))->getQuery()->getScalarResult();
    }
}
