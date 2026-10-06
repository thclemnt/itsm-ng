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

    /**
     * Expand API ports without encoding address identities in a concatenated string.
     * Parent admission belongs to the caller; retain the historical unfiltered child view.
     * The first name is deterministic, and every address contains its complete membership list.
     */
    public function apiDetailsForPorts(array $ports): array
    {
        $ports = array_values(array_unique(array_map('intval', $ports)));
        if ($ports === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select(
                'n.id',
                'n.items_id AS port_id',
                'n.name',
                'fqdnRecord.id AS fqdns_id',
                'fqdnRecord.name AS fqdn_name',
                'fqdnRecord.fqdn AS fqdn'
            )
            ->from(Entity\NetworkName::class, 'n')->leftJoin('n.fqdns_id', 'fqdnRecord')
            ->where('n.itemtype = :type AND n.items_id IN (:ports)')
            ->setParameter('type', 'NetworkPort', Types::STRING)
            ->setParameter('ports', $ports, ArrayParameterType::INTEGER)
            ->orderBy('n.items_id')->addOrderBy('n.id')->getQuery()->getArrayResult();
        $details = [];
        $namePorts = [];
        foreach ($rows as $row) {
            $port = (int)$row['port_id'];
            if (isset($details[$port])) {
                continue;
            }
            $namePorts[(int)$row['id']] = $port;
            $details[$port] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'fqdns_id' => $row['fqdns_id'],
                'FQDN' => ['id' => $row['fqdns_id'], 'name' => $row['fqdn_name'], 'fqdn' => $row['fqdn']],
                'IPAddress' => [],
            ];
        }
        if ($namePorts === []) {
            return [];
        }
        $addresses = $this->em->createQueryBuilder()
            ->select('a.id', 'a.items_id AS name_id', 'a.name')
            ->from(Entity\IPAddress::class, 'a')
            ->where('a.itemtype = :type AND a.items_id IN (:names)')
            ->setParameter('type', 'NetworkName', Types::STRING)
            ->setParameter('names', array_keys($namePorts), ArrayParameterType::INTEGER)
            ->orderBy('a.items_id')->addOrderBy('a.id')->getQuery()->getArrayResult();
        $networks = [];
        if ($addresses !== []) {
            $memberships = $this->em->createQueryBuilder()
                ->select(
                    'addressRecord.id AS address_id',
                    'network.id',
                    'network.completename',
                    'network.name',
                    'network.address',
                    'network.netmask',
                    'network.gateway',
                    'parentNetwork.id AS ipnetworks_id',
                    'network.comment'
                )
                ->from(Entity\IPAddressIPNetwork::class, 'link')->innerJoin('link.ipnetworks', 'network')
                ->innerJoin('link.ipaddresses', 'addressRecord')->leftJoin('network.parent', 'parentNetwork')
                ->where('addressRecord.id IN (:addresses)')
                ->setParameter('addresses', array_column($addresses, 'id'), ArrayParameterType::INTEGER)
                ->orderBy('address_id')->addOrderBy('network.id')->addOrderBy('link.id')
                ->getQuery()->getArrayResult();
            foreach ($memberships as $network) {
                $address = (int)$network['address_id'];
                unset($network['address_id']);
                $networks[$address][] = $network;
            }
        }
        foreach ($addresses as $address) {
            $details[$namePorts[(int)$address['name_id']]]['IPAddress'][] = [
                // The previous public representation exposed concatenated identifiers as strings.
                'id' => (string)$address['id'],
                'name' => $address['name'],
                'IPNetwork' => $networks[(int)$address['id']] ?? [],
            ];
        }
        return $details;
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
