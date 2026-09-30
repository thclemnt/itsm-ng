<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Config;

/** Configuration names and contexts are literal values, including the string NULL. */
final class ConfigurationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function values(string $context, array $names = []): array
    {
        $query = $this->em->createQueryBuilder()->select('r.name', 'r.value')->from(Config::class, 'r')
            ->where('r.context = :context')->setParameter('context', $context, Types::STRING)->orderBy('r.id');
        if ($names) {
            $query->andWhere('r.name IN (:names)')->setParameter('names', array_values($names), ArrayParameterType::STRING);
        }
        $values = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }
}
