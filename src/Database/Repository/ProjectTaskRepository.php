<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\Entity\ProjectState;
use itsmng\Database\Entity\ProjectTask;
use itsmng\Database\Entity\ProjectTaskType;
use itsmng\Database\Entity\ProjectTaskTeam;
use itsmng\Database\Entity\ProjectTaskTicket;
use itsmng\Database\RecordCriteria;

/** Task listings with portable joins and translated dropdown labels. */
final class ProjectTaskRepository
{
    public function __construct(private EntityManager $em)
    {
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
            ->leftJoin(ProjectTaskType::class, 'dtype', 'WITH', 'dtype.id = r.projecttasktypes_id')
            ->leftJoin(ProjectState::class, 'state', 'WITH', 'state.id = r.projectstates_id')
            ->leftJoin('r.projecttasks', 'father');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(ProjectTask::class));
        $query->where($compiler->where($criteria));
        foreach (['transname2' => [$typeLanguage, 'ProjectTaskType', 'r.projecttasktypes_id'], 'transname3' => [$stateLanguage, 'ProjectState', 'r.projectstates_id']] as $alias => [$language, $type, $id]) {
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
