<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Task projections share the mapped parent relation across Ticket, Change and Problem. */
final class ITILTaskRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function definition(string $type): array
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $type) || !class_exists($class = 'itsmng\\Database\\Entity\\' . $type)) {
            throw new \InvalidArgumentException('Unsupported ITIL task type');
        }
        $metadata = $this->em->getClassMetadata($class);
        foreach ($metadata->associationMappings as $property => $association) {
            foreach ((new \ReflectionProperty($class, $property))->getAttributes(\itsmng\Database\Mapping\ITILStatisticsRelation::class) as $attribute) {
                if ($attribute->newInstance()->role === \itsmng\Database\Mapping\ITILStatisticsRole::Tasks && $association->isToOneOwningSide()) {
                    return [$class, $association->targetEntity, $property];
                }
            }
        }
        throw new \InvalidArgumentException('Unsupported ITIL task type');
    }

    public function parentTasks(string $type, int $parent): array
    {
        [$task, , $relation] = $this->definition($type);
        $query = $this->em->createQueryBuilder()->select('r')->from($task, 'r')
            ->where('IDENTITY(r.' . $relation . ') = :parent')->setParameter('parent', $parent, Types::INTEGER)->orderBy('r.id');
        return $this->rows($query);
    }

    public function taskList(string $type, array $statuses, bool $todo, int $user, ?array $groups, array $scope, ?int $start, ?int $limit): array
    {
        return $this->taskListQuery($type, $statuses, $todo, $user, $groups, $scope, $start, $limit)
            ?->getQuery()->getScalarResult() ?? [];
    }

    /** Count all eligible tasks, but read text only for the displayed homepage rows. */
    public function centralList(string $type, array $statuses, bool $todo, int $user, ?array $groups, array $scope, int $limit): array
    {
        $query = $this->taskListQuery($type, $statuses, $todo, $user, $groups, $scope, null, null);
        $total = $query === null ? 0 : (int)(clone $query)->select('COUNT(t.id)')->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();
        $rows = $total === 0 ? [] : $query
            ->addSelect('t.content, r.id AS parent_id, r.name AS parent_name, r.priority AS parent_priority')
            ->setMaxResults($limit > 0 ? $limit : null)->getQuery()->getScalarResult();
        return ['total' => $total, 'rows' => $rows];
    }

    private function taskListQuery(string $type, array $statuses, bool $todo, int $user, ?array $groups, array $scope, ?int $start, ?int $limit): ?QueryBuilder
    {
        [$task, $parent, $relation] = $this->definition($type);
        if ($groups === [] || ($groups === null && $user <= 0)) {
            return null;
        }
        $query = $this->em->createQueryBuilder()->select('t.id')->from($parent, 'r')
            ->join($task, 't', 'WITH', 'IDENTITY(t.' . $relation . ') = r.id');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata($parent)))->where($scope))
            ->andWhere('r.status IN (:statuses)')->setParameter('statuses', $statuses ?: [-1]);
        if ($todo) {
            $query->andWhere('t.state = :todo')->setParameter('todo', \Planning::TODO, Types::INTEGER);
        }
        if ($groups !== null) {
            $query->andWhere('IDENTITY(t.groups_tech) IN (:groups)')->setParameter('groups', $groups);
        } else {
            $query->andWhere('IDENTITY(t.technician) = :user')->setParameter('user', $user, Types::INTEGER);
        }
        return $query->addSelect('CASE WHEN t.date_mod IS NULL THEN 0 ELSE 1 END AS HIDDEN dated')
            ->orderBy('dated', 'DESC')->addOrderBy('t.date_mod', 'DESC')->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $start ?? 0))->setMaxResults($limit === null ? null : max(0, $limit));
    }

    public function calendarTasks(string $type, array $criteria): array
    {
        [$task, , $relation] = $this->definition($type);
        $query = $this->em->createQueryBuilder()->select('r')->from($task, 'r')->join('r.' . $relation, 'parent');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata($task)))->where($criteria))
            ->andWhere('parent.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)->orderBy('r.id');
        return $this->rows($query);
    }

    public function planningTasks(string $type, \DateTimeImmutable $begin, \DateTimeImmutable $end, bool $unplanned, int $user, array $groups, array $profileScope, bool $displayDone, array $closedStatuses): array
    {
        [$task, , $relation] = $this->definition($type);
        $query = $this->em->createQueryBuilder()->select('t')->from($task, 't')->join('t.' . $relation, 'parent')
            ->where('parent.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)
            ->setParameter('begin', $begin, Types::DATETIMETZ_IMMUTABLE)->setParameter('end', $end, Types::DATETIMETZ_IMMUTABLE);
        if ($unplanned) {
            $date = "DATE_SUB(t.date, t.actiontime, 'second')";
            $query->addSelect($date . ' AS notp_date', 't.date AS notp_edate')
                ->andWhere('t.begin IS NULL AND t.end IS NULL AND t.actiontime > 0 AND t.date >= :begin AND ' . $date . ' <= :end');
        } else {
            $query->andWhere('t.end >= :begin AND t.begin <= :end');
        }
        $actors = [];
        if ($user > 0) {
            $actors[] = 'IDENTITY(t.technician) = :user';
            $query->setParameter('user', $user, Types::INTEGER);
        }
        if ($groups) {
            $actors[] = 'IDENTITY(t.groups_tech) IN (:groups)';
            $query->setParameter('groups', $groups);
        }
        if (!$actors) {
            $scope = (new RecordCriteria($query, $this->em->getClassMetadata(Entity\ProfileUser::class)))->where($profileScope);
            $actors[] = 'EXISTS (SELECT r.id FROM ' . Entity\ProfileUser::class . ' r JOIN r.profiles p WHERE IDENTITY(r.users) = IDENTITY(t.technician) AND p.interface = :interface AND (' . $scope . '))';
            $query->setParameter('interface', 'central');
        }
        $query->andWhere('(' . implode(' OR ', $actors) . ')');
        if (!$displayDone) {
            $query->andWhere('(t.state = :todo OR (t.state = :info AND t.end > :now)) AND parent.status NOT IN (:closed)')
                ->setParameter('todo', \Planning::TODO, Types::INTEGER)->setParameter('info', \Planning::INFO, Types::INTEGER)
                ->setParameter('now', new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)->setParameter('closed', $closedStatuses ?: [-1]);
        }
        return $this->rows($query->orderBy('t.begin')->addOrderBy('t.id'));
    }

    private function rows(QueryBuilder $query): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = is_array($result) ? $result[0] : $result;
            $row = $records->toRow($record);
            if (is_array($result)) {
                foreach (['notp_date', 'notp_edate'] as $field) {
                    $date = $result[$field];
                    $row[$field] = $date === null ? null : ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
                }
            }
            $rows[] = $row;
            $this->em->detach($record);
        }
        return $rows;
    }
}
