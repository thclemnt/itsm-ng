<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\ProjectTask;
use itsmng\Database\Entity\ProjectTaskTeam;
use itsmng\Database\Entity\ProjectTaskTicket;
use itsmng\Database\RecordCriteria;

/** Task listings with portable joins and translated dropdown labels. */
final class ProjectTaskRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Explicit groups take precedence; null selects the user or eligible central-profile users. */
    public function planning(int $user, ?array $groups, array $profileScope, \DateTimeInterface $begin, \DateTimeInterface $end, bool $showDone, bool $unplanned): array
    {
        if ($groups === [] || $begin > $end) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('task')->from(ProjectTask::class, 'task');
        if ($groups !== null) {
            $actor = 'team.itemtype = :actor_type AND team.items_id IN (:actors)';
            $query->setParameter('actor_type', 'Group')->setParameter('actors', array_values(array_map('intval', $groups)));
        } elseif ($user > 0) {
            $actor = 'team.itemtype = :actor_type AND team.items_id = :actor';
            $query->setParameter('actor_type', 'User')->setParameter('actor', $user, Types::INTEGER);
        } else {
            $scope = new RecordCriteria($query, $this->em->getClassMetadata(ProfileUser::class));
            $actor = 'team.itemtype = :actor_type AND team.items_id IN (SELECT IDENTITY(r.users) FROM ' . ProfileUser::class . ' r JOIN r.profiles profile WHERE profile.interface = :interface AND ' . $scope->where($profileScope) . ')';
            $query->setParameter('actor_type', 'User')->setParameter('interface', 'central');
        }
        $query->where('EXISTS (SELECT team.id FROM ' . ProjectTaskTeam::class . ' team WHERE IDENTITY(team.projecttasks) = task.id AND ' . $actor . ')');
        if (!$showDone) {
            $query->leftJoin('task.projectstates', 'state')
                ->andWhere('task.percent_done < 100 AND (state.is_finished IS NULL OR state.is_finished = :finished)')
                ->setParameter('finished', false, Types::BOOLEAN);
        }
        if ($unplanned) {
            $query->andWhere('task.plan_start_date IS NULL AND task.plan_end_date IS NULL AND task.planned_duration > 0')
                ->andWhere("DATE_ADD(task.date, task.planned_duration, 'SECOND') >= :begin")
                ->andWhere("DATE_SUB(task.date, task.planned_duration, 'SECOND') <= :end");
        } else {
            $query->andWhere('task.plan_end_date >= :begin AND task.plan_start_date <= :end');
        }
        $query->setParameter('begin', \DateTime::createFromInterface($begin), Types::DATETIME_MUTABLE)
            ->setParameter('end', \DateTime::createFromInterface($end), Types::DATETIME_MUTABLE)
            ->orderBy('task.plan_start_date')->addOrderBy('task.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $task) {
            $row = $records->toRow($task);
            if ($unplanned) {
                // Typed hydration supplies the creation date; presentation receives the legacy date format.
                $creation = \DateTimeImmutable::createFromInterface($task->date);
                $row['notp_date'] = $creation->setTimestamp($creation->getTimestamp() - $task->planned_duration)->format('Y-m-d H:i:s');
                $row['notp_edate'] = $creation->setTimestamp($creation->getTimestamp() + $task->planned_duration)->format('Y-m-d H:i:s');
            }
            $rows[] = $row;
            $this->em->detach($task);
        }
        return $rows;
    }

    public function forTeam(array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('task')->from(ProjectTask::class, 'task');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(ProjectTaskTeam::class));
        $query->where('task.id IN (SELECT IDENTITY(r.projecttasks) FROM ' . ProjectTaskTeam::class . ' r WHERE ' . $compiler->where($criteria) . ')')->orderBy('task.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $task) {
            $rows[] = $records->toRow($task);
            $this->em->detach($task);
        }
        return $rows;
    }

    public function effectiveDurations(array $taskIds): array
    {
        if (!$taskIds) {
            return [];
        }
        $results = $this->em->createQueryBuilder()
            ->select('r.id AS id', 'r.effective_duration + COALESCE(SUM(ticket.actiontime), 0) AS duration')
            ->from(ProjectTask::class, 'r')
            ->leftJoin(ProjectTaskTicket::class, 'link', 'WITH', 'IDENTITY(link.projecttasks) = r.id')
            ->leftJoin('link.tickets', 'ticket')
            ->where('r.id IN (:tasks)')->setParameter('tasks', array_values(array_unique($taskIds)))
            ->groupBy('r.id')->addGroupBy('r.effective_duration')->getQuery()->getScalarResult();
        $durations = [];
        foreach ($results as $row) {
            $durations[(int)$row['id']] = (int)$row['duration'];
        }
        return $durations;
    }

    public function listing(array $criteria, array $order, ?string $typeLanguage, ?string $stateLanguage): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('r', 'dtype.name AS tname', 'state.name AS sname', 'state.color AS color', 'father.name AS fname', 'father.id AS fID')
            ->from(ProjectTask::class, 'r')
            ->leftJoin('r.projecttasktypes', 'dtype')
            ->leftJoin('r.projectstates', 'state')
            ->leftJoin('r.projecttasks', 'father');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(ProjectTask::class));
        $query->where($compiler->where($criteria));
        foreach (['transname2' => [$typeLanguage, 'ProjectTaskType', 'IDENTITY(r.projecttasktypes)'], 'transname3' => [$stateLanguage, 'ProjectState', 'IDENTITY(r.projectstates)']] as $alias => [$language, $type, $id]) {
            if ($language !== null) {
                $join = $alias . '_row';
                $query->leftJoin(DropdownTranslation::class, $join, 'WITH', "$join.items_id = $id AND $join.itemtype = :{$alias}_type AND $join.language = :{$alias}_language AND $join.field = :{$alias}_field")
                    ->setParameter($alias . '_type', $type)->setParameter($alias . '_language', $language)->setParameter($alias . '_field', 'name')
                    ->addSelect($join . '.value AS ' . $alias);
            }
        }
        foreach ($order as $clause) {
            if (!preg_match('/^(\w+)(?: (ASC|DESC))?$/D', $clause, $parts)) {
                throw new \InvalidArgumentException('Invalid task listing order');
            }
            $expression = match ($parts[1]) {
                'tname' => 'dtype.name', 'sname' => 'state.name', 'fname' => 'father.name',
                default => $compiler->column($parts[1]),
            };
            $query->addOrderBy($expression, $parts[2] ?? 'ASC');
        }
        $query->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $task = $result[0];
            unset($result[0]);
            $rows[] = $records->toRow($task) + $result;
            $this->em->detach($task);
        }
        return $rows;
    }
}
