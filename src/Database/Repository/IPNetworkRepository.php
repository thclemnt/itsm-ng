<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\IPNetwork;
use itsmng\Database\RecordCriteria;

final class IPNetworkRepository
{
    public function __construct(private EntityManager $em)
    {
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
