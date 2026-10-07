<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonDevice;
use CommonITILActor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Entity\ItemOperatingSystem;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Entity\TicketSatisfaction;
use itsmng\Database\Entity\User;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\RecordCriteria;
use itsmng\Database\ReferenceValues;
use itsmng\Reporting\MonthSeries;

use function getItemForItemtype;
use function getSonsOf;

/** Aggregate parents once; audience/asset/task filters must not multiply averages. */
final class ITILStatisticsRepository
{
    private const METRICS = [
        'inter_total' => ['date', null, 'COUNT(r.id)'],
        'inter_solved' => ['solvedate', 'solved', 'COUNT(r.id)'],
        'inter_solved_late' => ['solvedate', 'solved', 'COUNT(r.id)'],
        'inter_closed' => ['closedate', 'closed', 'COUNT(r.id)'],
        'inter_solved_with_actiontime' => ['solvedate', 'solved', 'COUNT(r.id)'],
        'inter_avgsolvedtime' => ['solvedate', 'solved', 'AVG(r.solve_delay_stat)'],
        'inter_avgclosedtime' => ['closedate', 'closed', 'AVG(r.close_delay_stat)'],
        'inter_avgactiontime' => ['solvedate', null, 'AVG(r.actiontime)'],
        'inter_avgtakeaccount' => ['solvedate', 'solved', 'AVG(r.takeintoaccount_delay_stat)'],
        'inter_opensatisfaction' => ['closedate', 'closed', 'COUNT(r.id)'],
        'inter_answersatisfaction' => ['closedate', 'closed', 'COUNT(r.id)'],
        'inter_avgsatisfaction' => ['closedate', 'closed', 'AVG(survey.satisfaction)'],
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function monthly(string $type, string $metric, string $begin, string $end, string $dimension, mixed $value, mixed $secondary, ?array $entities, array $solved, array $closed, array $extra = []): array
    {
        [$class, $parent] = $definition = ITILStatisticsType::definition($this->em, $type);
        if (!isset(self::METRICS[$metric])) {
            return [];
        }
        [$date, $status, $aggregate] = self::METRICS[$metric];
        $metadata = $this->em->getClassMetadata($class);
        if (($metric === 'inter_avgtakeaccount' || str_contains($metric, 'satisfaction')) && $type !== 'Ticket') {
            throw new InvalidArgumentException('This statistics metric requires Ticket');
        }
        if (array_diff(array_keys($extra), ['WHERE'])) {
            throw new InvalidArgumentException('Statistics extensions accept mapped WHERE criteria only');
        }
        $first = MonthSeries::date($begin);
        $last = MonthSeries::date($end);
        if ($first !== null && $last !== null && $first > $last) {
            return [];
        }
        if ($entities === []) {
            return MonthSeries::fill([], $first, $last);
        }
        $query = $this->em->createQueryBuilder()
            ->from($class, 'r');
        $compiler = new RecordCriteria($query, $metadata);
        $query->where($compiler->where([$extra['WHERE'] ?? [], 'is_deleted' => false]))
            ->andWhere('r.' . $date . ' IS NOT NULL');
        if ($entities !== null) {
            $query->andWhere('IDENTITY(r.entities) IN (:entities)')
                ->setParameter('entities', array_map('intval', $entities));
        }
        if ($first !== null) {
            $query->andWhere('r.' . $date . ' >= :begin')
                ->setParameter('begin', $first, Types::DATETIMETZ_IMMUTABLE);
        }
        if ($last !== null) {
            // Date-only bounds include the whole day; timestamp bounds are exact.
            $dateOnly = strlen($end) === 10;
            $query->andWhere('r.' . $date . ($dateOnly ? ' < :end' : ' <= :end'))
                ->setParameter('end', $dateOnly ? $last->modify('+1 day') : $last, Types::DATETIMETZ_IMMUTABLE);
        }
        if ($status !== null) {
            $query->andWhere('r.status IN (:statuses)')
                ->setParameter('statuses', ($status === 'closed' ? $closed : $solved) ?: [-1]);
        }
        if ($metric === 'inter_solved_late') {
            $query->andWhere('r.time_to_resolve IS NOT NULL AND r.solvedate > r.time_to_resolve');
        }
        if ($metric === 'inter_solved_with_actiontime') {
            $query->andWhere('r.actiontime > 0');
        }
        if (str_contains($metric, 'satisfaction')) {
            $query->join(TicketSatisfaction::class, 'survey', 'WITH', 'IDENTITY(survey.tickets) = r.id');
            if ($metric !== 'inter_opensatisfaction') {
                $query->andWhere('survey.date_answered IS NOT NULL');
            }
        }
        if ($metric === 'inter_avgactiontime') {
            if ($dimension === 'technicien_followup') {
                $query->join($definition[5], 't', 'WITH', 'IDENTITY(t.' . $parent . ') = r.id AND ' . $this->selection('IDENTITY(t.author)', $value));
                if (!ReferenceValues::isEmptySelection($value)) {
                    $query->setParameter('dimension', (int)$value, Types::INTEGER);
                }
                $query->andWhere('t.actiontime > 0');
                $aggregate = 'AVG(t.actiontime)';
            } else {
                $query->andWhere('r.actiontime > 0');
            }
        }
        if (!($metric === 'inter_avgactiontime' && $dimension === 'technicien_followup')) {
            $this->dimension($query, $compiler, $type, $definition, $dimension, $value, $secondary);
        }
        $query->select('YEAR_MONTH(r.' . $date . ') AS month', $aggregate . ' AS value')
            ->groupBy('month')
            ->orderBy('month');
        $values = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $values[$row['month']] = str_starts_with($aggregate, 'COUNT') ? (int)$row['value'] : ($row['value'] === null ? null : (float)$row['value']);
        }
        return MonthSeries::fill($values, $first, $last);
    }

    private function dimension(QueryBuilder $query, RecordCriteria $compiler, string $type, array $definition, string $dimension, mixed $value, mixed $secondary): void
    {
        [, $parent, $users, $groups, $suppliers, $tasks] = $definition;
        if ($dimension === '') {
            return;
        }
        if (in_array($dimension, ['technicien', 'user', 'usertitles_id', 'usercategories_id'], true)) {
            $condition = $this->selection('IDENTITY(a.actor)', $value);
            if ($dimension === 'usertitles_id' || $dimension === 'usercategories_id') {
                $field = $dimension === 'usertitles_id' ? 'usertitles' : 'usercategories';
                $condition = 'EXISTS (SELECT u.id FROM ' . User::class . ' u WHERE u.id = IDENTITY(a.actor) AND ' . $this->selection('IDENTITY(u.' . $field . ')', $value) . ')';
            }
            $query->andWhere(
                'EXISTS (SELECT a.id FROM ' . $users . ' a WHERE IDENTITY(a.' . $parent . ') = r.id AND a.type = :role AND ' . $condition . ')'
            )
                ->setParameter('role', $dimension === 'technicien' ? CommonITILActor::ASSIGN : CommonITILActor::REQUESTER, Types::INTEGER);
        } elseif ($dimension === 'technicien_followup') {
            $query->andWhere(
                'EXISTS (SELECT t.id FROM ' . $tasks . ' t WHERE IDENTITY(t.' . $parent . ') = r.id AND ' . $this->selection('IDENTITY(t.author)', $value) . ')'
            );
        } elseif (in_array($dimension, ['group', 'groups_id_assign', 'group_tree', 'groups_tree_assign'], true)) {
            $ids = str_contains($dimension, 'tree') && $value != $secondary ? getSonsOf('glpi_groups', $value) : [(int)$value];
            $query->andWhere(
                'EXISTS (SELECT g.id FROM ' . $groups . ' g WHERE IDENTITY(g.' . $parent . ') = r.id AND g.type = :role AND IDENTITY(g.groups) IN (:dimension))'
            )
                ->setParameter(
                    'role',
                    in_array($dimension, ['group', 'group_tree'], true) ? CommonITILActor::REQUESTER : CommonITILActor::ASSIGN,
                    Types::INTEGER
                )
                ->setParameter('dimension', array_values($ids) ?: [-1]);
            return;
        } elseif ($dimension === 'suppliers_id_assign') {
            $query->andWhere(
                'EXISTS (SELECT a.id FROM ' . $suppliers . ' a WHERE IDENTITY(a.' . $parent . ') = r.id AND a.type = :role AND ' . $this->selection('IDENTITY(a.actor)', $value) . ')'
            )
                ->setParameter('role', CommonITILActor::ASSIGN, Types::INTEGER);
        } elseif ($dimension === 'solutiontypes_id') {
            $subject = ITILSolution::subjectAssociation($type);
            $query->andWhere(
                'EXISTS (SELECT s.id FROM ' . ITILSolution::class . ' s WHERE IDENTITY(s.' . $subject . ') = r.id AND ' . $this->selection('IDENTITY(s.solutiontypes)', $value) . ')'
            );
        } elseif ($dimension === 'itilcategories_tree' || $dimension === 'locations_tree') {
            $table = $dimension === 'itilcategories_tree' ? 'glpi_itilcategories' : 'glpi_locations';
            $field = $dimension === 'itilcategories_tree' ? 'itilcategories_id' : 'locations_id';
            $ids = $value == $secondary ? [(int)$value] : array_values(getSonsOf($table, $value));
            $query->andWhere($compiler->where([$field => $ids]));
            return;
        } elseif (in_array($dimension, ['device', 'comp_champ'], true)) {
            $this->computerDimension($query, $definition, $dimension, (int)$value, (string)$secondary);
            return;
        } elseif (in_array($dimension, ['requesttypes_id', 'urgency', 'impact', 'priority', 'users_id_recipient', 'type', 'itilcategories_id', 'locations_id'], true)) {
            $query->andWhere($compiler->where([$dimension => $value]));
            return;
        } else {
            throw new InvalidArgumentException('Unsupported statistics dimension');
        }
        if (!ReferenceValues::isEmptySelection($value)) {
            $query->setParameter('dimension', (int)$value, Types::INTEGER);
        }
    }

    private function selection(string $identity, mixed $value): string
    {
        return $identity . (ReferenceValues::isEmptySelection($value) ? ' IS NULL' : ' = :dimension');
    }

    private function computerDimension(QueryBuilder $query, array $definition, string $dimension, int $value, string $classification): void
    {
        $target = getItemForItemtype($classification);
        if (!$target) {
            throw new InvalidArgumentException('Unknown computer statistics classification');
        }
        $subquery = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(Computer::class, 'c')
            ->where('c.is_template = :template')
            ->setParameter('template', false, Types::BOOLEAN);
        $column = $target->getForeignKeyField();
        $empty = false;
        if ($dimension === 'device') {
            if (!$target instanceof CommonDevice) {
                throw new InvalidArgumentException('Statistics device must be a component');
            }
            $table = 'glpi_items_' . substr($target->getTable(), strlen('glpi_'));
            $class = EntityRegistry::tables()[$table] ?? throw new InvalidArgumentException('Unmapped statistics component');
            $association = $this->association($class, $column);
            $subquery->join($class, 'd', 'WITH', "d.items_id = c.id AND d.itemtype = 'Computer'")
                ->andWhere('IDENTITY(d.' . $association . ') = :classification');
        } elseif (EntityRegistry::hasPolicy('glpi_items_operatingsystems', $column, ReferenceKind::EmptySelection)) {
            $subquery->resetDQLPart('from')
                ->from(ItemOperatingSystem::class, 'o')
                ->join('o.computer', 'c');
            $association = $this->association(ItemOperatingSystem::class, $column);
            $empty = $value === 0 && EntityRegistry::hasPolicy('glpi_items_operatingsystems', $column, ReferenceKind::EmptySelection);
            $subquery->andWhere('IDENTITY(o.' . $association . ')' . ($empty ? ' IS NULL' : ' = :classification'));
        } else {
            $association = $this->association(Computer::class, $column);
            $empty = $value === 0 && EntityRegistry::hasPolicy('glpi_computers', $column, ReferenceKind::EmptySelection);
            $subquery->andWhere('IDENTITY(c.' . $association . ')' . ($empty ? ' IS NULL' : ' = :classification'));
        }
        $query->andWhere(
            'EXISTS (SELECT i.id FROM ' . $definition[6] . ' i WHERE IDENTITY(i.' . $definition[1] . ") = r.id AND i.itemtype = 'Computer' AND i.items_id IN (" . $subquery->getDQL() . '))'
        )
            ->setParameter('template', false, Types::BOOLEAN);
        if (!$empty) {
            $query->setParameter('classification', $value, Types::BIGINT);
        }
    }

    private function association(string $class, string $column): string
    {
        foreach ($this->em->getClassMetadata($class)->associationMappings as $field => $mapping) {
            if ($mapping->joinColumns[0]->name === $column) {
                return $field;
            }
        }
        throw new InvalidArgumentException('Unmapped statistics association');
    }
}
