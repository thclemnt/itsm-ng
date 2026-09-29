<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileRight;

final class ProfileRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Match the full registered right set, including explicit zero-valued rights. */
    public function canManage(array $ids, array $rights, string $interface, bool $unrestricted): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $query = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(Profile::class, 'p');
        if ($ids) {
            $query->where('p.id IN (:ids)')->setParameter('ids', $ids);
        }
        if (!$unrestricted) {
            $conditions = [];
            foreach ($rights as $name => $value) {
                $index = count($conditions);
                $conditions[] = '(r.name = :name' . $index . ' AND BIT_OR(r.rights, :right' . $index . ') = :right' . $index . ')';
                $query->setParameter('name' . $index, $name)->setParameter('right' . $index, (int)$value, Types::INTEGER);
            }
            $subquery = 'SELECT COUNT(r.id) FROM ' . ProfileRight::class . ' r WHERE r.profiles = p AND ('
                . ($conditions ? implode(' OR ', $conditions) : '1 = 0') . ')';
            $condition = '(p.interface = :interface AND (' . $subquery . ') = :rights_count)';
            $query->setParameter('interface', $interface)->setParameter('rights_count', count($rights), Types::INTEGER);
            if ($interface === 'central') {
                $condition = '(p.interface = :helpdesk OR ' . $condition . ')';
                $query->setParameter('helpdesk', 'helpdesk');
            }
            $query->andWhere($condition);
        }
        $expected = $ids ? count($ids) : (new RecordRepository($this->em))->countMatching('glpi_profiles', []);
        return (int)$query->getQuery()->getSingleScalarResult() === $expected;
    }
}
