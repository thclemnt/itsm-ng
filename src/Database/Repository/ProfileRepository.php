<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileRight;

use function importArrayFromDB;

final class ProfileRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Read the stored representation, after model input escaping and persistence. */
    public function helpdeskItemTypes(int $id): array
    {
        $profile = $this->em->getRepository(Profile::class)->find($id);
        return importArrayFromDB($profile?->helpdesk_item_type);
    }

    /** Match the full registered right set, including explicit zero-valued rights. */
    public function canManage(array $ids, array $rights, string $interface, bool $unrestricted): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $query = $this->manageable($rights, $interface, $unrestricted)->select('COUNT(p.id)');
        if ($ids) {
            $query->andWhere('p.id IN (:ids)')->setParameter('ids', $ids);
        }
        $expected = $ids ? count($ids) : (new RecordRepository($this->em))->countMatching('glpi_profiles', []);
        return (int)$query->getQuery()->getSingleScalarResult() === $expected;
    }

    public function manageableIds(array $rights, string $interface): array
    {
        return array_map('intval', array_column($this->manageable($rights, $interface, false)->select('p.id AS id')->getQuery()->getScalarResult(), 'id'));
    }

    public function clearOtherDefaults(int $selected): void
    {
        $this->em->createQueryBuilder()->update(Profile::class, 'p')->set('p.is_default', ':no')->where('p.id <> :selected')
            ->setParameter('no', false, Types::BOOLEAN)->setParameter('selected', $selected, Types::INTEGER)->getQuery()->execute();
    }

    public function defaultId(): int
    {
        $rows = $this->em->createQueryBuilder()->select('p.id AS id')->from(Profile::class, 'p')->where('p.is_default = :yes')
            ->setParameter('yes', true, Types::BOOLEAN)->setMaxResults(1)->getQuery()->getScalarResult();
        return (int)($rows[0]['id'] ?? 0);
    }

    private function manageable(array $rights, string $interface, bool $unrestricted): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Profile::class, 'p');
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
        return $query;
    }
}
