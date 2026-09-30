<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\IPNetwork;
use itsmng\Database\Entity\IPAddress;
use itsmng\Database\RecordCriteria;

final class IPNetworkRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Maintenance operation: callers rebuild all nodes in the same transaction. */
    public function resetTree(): array
    {
        $this->em->createQueryBuilder()->update(IPNetwork::class, 'r')->set('r.parent', 'NULL')
            ->set('r.level', '1')->set('r.completename', 'r.name')
            ->set('r.ancestors_cache', 'NULL')->set('r.sons_cache', 'NULL')->getQuery()->execute();
        return array_map('intval', array_column($this->em->createQueryBuilder()->select('r.id')->from(IPNetwork::class, 'r')
            ->orderBy('r.id')->getQuery()->getScalarResult(), 'id'));
    }

    /** Membership is determined by address words, without a visibility filter. */
    public function containedAddresses(int $network): array
    {
        $query = $this->em->createQueryBuilder()->select('a.id')->from(IPAddress::class, 'a')
            ->join(IPNetwork::class, 'n', 'WITH', 'n.id = :network AND n.version = a.version')
            ->setParameter('network', $network, Types::INTEGER)->where('n.version IN (4, 6)');
        for ($word = 0; $word < 4; ++$word) {
            $match = 'BIT_AND(a.binary_' . $word . ', n.netmask_' . $word . ') = n.address_' . $word;
            $query->andWhere($word === 3 ? $match : '(n.version = 4 OR ' . $match . ')');
        }
        return array_map('intval', array_column($query->orderBy('a.id')->getQuery()->getScalarResult(), 'id'));
    }

    /** Match every address word, retaining nearest-network ordering on both engines. */
    public function matching(string $relation, ?array $address, ?array $mask, int $version, array $entities, array $fields, array $excluded = [], array $criteria = []): array
    {
        $query = $this->em->createQueryBuilder()->from(IPNetwork::class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(IPNetwork::class));
        foreach ($fields as $index => $field) {
            $query->addSelect($compiler->column($field) . ' AS field_' . $index);
        }
        $query->where('IDENTITY(r.entities) IN (:entities) AND r.version = :version')
            ->setParameter('entities', $entities, ArrayParameterType::INTEGER)
            ->setParameter('version', $version, Types::SMALLINT);
        for ($word = $version === 4 ? 3 : 0; $word < 4; ++$word) {
            if ($address !== null && $mask !== null) {
                $query->setParameter('mask' . $word, $mask[$word], Types::BIGINT);
                $common = $relation === 'contains' ? 'r.netmask_' . $word : ':mask' . $word;
                if ($relation === 'contains') {
                    $query->setParameter('address' . $word, $address[$word], Types::BIGINT);
                    $network = 'BIT_AND(:address' . $word . ', r.netmask_' . $word . ')';
                } else {
                    $query->setParameter('network' . $word, $address[$word] & $mask[$word], Types::BIGINT);
                    $network = ':network' . $word;
                }
                $query->andWhere('BIT_AND(r.address_' . $word . ', ' . $common . ') = ' . $network);
                $query->andWhere($relation === 'equals'
                    ? 'r.netmask_' . $word . ' = :mask' . $word
                    : 'BIT_AND(:mask' . $word . ', r.netmask_' . $word . ') = ' . $common);
            }
            $query->addSelect('BIT_COUNT(r.netmask_' . $word . ') AS HIDDEN specificity_' . $word)
                ->addOrderBy('specificity_' . $word, $relation === 'contains' ? 'DESC' : 'ASC');
        }
        $query->addOrderBy('r.id', 'ASC');
        if ($excluded) {
            $query->andWhere('r.id NOT IN (:excluded)')->setParameter('excluded', array_values($excluded), ArrayParameterType::INTEGER);
        }
        $query->andWhere($compiler->where($criteria));
        return array_map(static function (array $row) use ($fields): mixed {
            $values = [];
            foreach ($fields as $index => $field) {
                $values[$field] = $row['field_' . $index];
            }
            return count($fields) === 1 ? reset($values) : $values;
        }, $query->getQuery()->getScalarResult());
    }
}
