<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Reporting\MonthSeries;

/** Distinct dimensions of visible ITIL parents, without hydrating their graph. */
final class ITILStatisticsOptionsRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function options(string $type, string $dimension, string $begin, string $end, ?array $entities, ?string $language = null): array
    {
        [$class, $parent, $users, $groups, $suppliers, $tasks] = ITILStatisticsType::definition($this->em, $type);
        $query = $this->em->createQueryBuilder()->from($class, 'r')->distinct();
        $this->scope($query, $begin, $end, $entities);
        if (in_array($dimension, ['requester', 'technician', 'recipient', 'task_author', 'user_title', 'user_category'], true)) {
            if ($dimension === 'recipient') {
                $query->leftJoin('r.recipient', 'u');
            } elseif ($dimension === 'task_author') {
                $query->join($tasks, 't', 'WITH', 'IDENTITY(t.' . $parent . ') = r.id')->join('t.author', 'u');
                // Preserve the historic eligibility rule for all three ITIL types.
                $query->andWhere('EXISTS (SELECT membership.id FROM ' . Entity\ProfileUser::class . ' membership JOIN membership.profiles profile JOIN ' . Entity\ProfileRight::class . ' permission WITH permission.profiles = profile WHERE membership.users = u AND permission.name = :right AND BIT_AND(permission.rights, :own) <> 0)')
                    ->setParameter('right', 'ticket')->setParameter('own', \Ticket::OWN, Types::INTEGER);
            } else {
                $query->leftJoin($users, 'a', 'WITH', 'IDENTITY(a.' . $parent . ') = r.id' . (in_array($dimension, ['requester', 'technician'], true) ? ' AND a.type = :role' : ''));
                if ($dimension === 'technician') {
                    $query->leftJoin('a.actor', 'u')->setParameter('role', \CommonITILActor::ASSIGN, Types::INTEGER);
                } else {
                    $query->join('a.actor', 'u');
                    if ($dimension === 'requester') {
                        $query->setParameter('role', \CommonITILActor::REQUESTER, Types::INTEGER);
                    }
                }
            }
            if ($dimension === 'user_title' || $dimension === 'user_category') {
                // These selectors historically include users in every actor role.
                $field = $dimension === 'user_title' ? 'usertitles' : 'usercategories';
                $query->leftJoin('u.' . $field, 'label');
                return $this->labels($query, $dimension === 'user_title' ? 'UserTitle' : 'UserCategory', $language);
            }
            $query->select('u.id AS id', 'u.name AS name', 'u.realname AS realname', 'u.firstname AS firstname');
            $this->orderNullable($query, ['u.realname', 'u.firstname', 'u.name', 'u.id']);
        } elseif ($dimension === 'requester_group' || $dimension === 'assigned_group') {
            $assigned = $dimension === 'assigned_group';
            $query->leftJoin($groups, 'a', 'WITH', 'IDENTITY(a.' . $parent . ') = r.id AND a.type = :role')
                ->setParameter('role', $assigned ? \CommonITILActor::ASSIGN : \CommonITILActor::REQUESTER, Types::INTEGER);
            $assigned ? $query->leftJoin('a.groups', 'g') : $query->join('a.groups', 'g');
            $query->select('g.id AS id', 'g.completename AS name');
            $this->orderNullable($query, ['g.completename', 'g.id']);
        } elseif ($dimension === 'supplier') {
            $query->leftJoin($suppliers, 'a', 'WITH', 'IDENTITY(a.' . $parent . ') = r.id AND a.type = :role')
                ->leftJoin('a.actor', 's')->setParameter('role', \CommonITILActor::ASSIGN, Types::INTEGER)
                ->select('s.id AS id', 's.name AS name');
            $this->orderNullable($query, ['s.name', 's.id']);
        } elseif (in_array($dimension, ['priority', 'urgency', 'impact'], true)) {
            $query->select('r.' . $dimension . ' AS id')->orderBy('r.' . $dimension);
        } elseif ($dimension === 'request_type') {
            if ($type !== 'Ticket') {
                throw new \InvalidArgumentException('Request type statistics require Ticket');
            }
            $query->leftJoin('r.requesttypes', 'label');
            return $this->labels($query, 'RequestType', $language);
        } elseif ($dimension === 'solution_type') {
            $subject = Entity\ITILSolution::subjectAssociation($type);
            $query->join(Entity\ITILSolution::class, 'solution', 'WITH', 'IDENTITY(solution.' . $subject . ') = r.id')
                ->leftJoin('solution.solutiontypes', 'label');
            return $this->labels($query, 'SolutionType', $language);
        } else {
            throw new \InvalidArgumentException('Unsupported statistics option dimension');
        }
        return $this->rows($query);
    }

    private function scope(QueryBuilder $query, string $begin, string $end, ?array $entities): void
    {
        $first = MonthSeries::date($begin);
        $last = MonthSeries::date($end);
        $query->where('r.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        if ($entities === [] || ($first !== null && $last !== null && $first > $last)) {
            $query->andWhere('1 = 0');
        } elseif ($entities !== null) {
            $query->andWhere('IDENTITY(r.entities) IN (:entities)')->setParameter('entities', array_map('intval', $entities));
        }
        $dates = [];
        foreach (['date', 'closedate'] as $field) {
            $bounds = [];
            if ($first !== null) {
                $bounds[] = 'r.' . $field . ' >= :begin';
                $query->setParameter('begin', $first, Types::DATETIMETZ_IMMUTABLE);
            }
            if ($last !== null) {
                $day = strlen($end) === 10;
                $bounds[] = 'r.' . $field . ($day ? ' < :end' : ' <= :end');
                $query->setParameter('end', $day ? $last->modify('+1 day') : $last, Types::DATETIMETZ_IMMUTABLE);
            }
            if ($bounds) {
                $dates[] = '(' . implode(' AND ', $bounds) . ')';
            }
        }
        if ($dates) {
            $query->andWhere('(' . implode(' OR ', $dates) . ')');
        }
    }

    private function labels(QueryBuilder $query, string $type, ?string $language): array
    {
        $query->select('label.id AS id', 'label.name AS name');
        if ($language !== null) {
            $query->leftJoin(Entity\DropdownTranslation::class, 'translation', 'WITH', 'translation.items_id = label.id AND translation.itemtype = :label_type AND translation.language = :language AND translation.field = :field')
                ->addSelect('translation.value AS translated')->setParameter('label_type', $type)->setParameter('language', $language)->setParameter('field', 'name');
        }
        $this->orderNullable($query, ['label.id']);
        $rows = $this->rows($query);
        foreach ($rows as &$row) {
            $row['name'] = !empty($row['translated']) ? $row['translated'] : ($row['name'] ?: '&nbsp;');
            unset($row['translated']);
        }
        return $rows;
    }

    /** Explicit NULL ordering keeps empty choices first on both providers. */
    private function orderNullable(QueryBuilder $query, array $fields): void
    {
        foreach ($fields as $index => $field) {
            $query->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN null_order_' . $index)
                ->addOrderBy('null_order_' . $index)->addOrderBy($field);
        }
    }

    private function rows(QueryBuilder $query): array
    {
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['id'] = $row['id'] === null ? null : (int)$row['id'];
        }
        return $rows;
    }
}
