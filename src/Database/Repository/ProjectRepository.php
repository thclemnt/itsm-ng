<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Project;
use itsmng\Database\Entity\ProjectTask;
use itsmng\Database\Entity\ProjectTaskTicket;

/** Project calculations through mapped associations, without per-task aggregate queries. */
final class ProjectRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Preserve a ticket entry per task association, including tickets shared by tasks. */
    public function ticketIds(?int $project): array
    {
        $query = $this->em->createQueryBuilder()->select('IDENTITY(l.tickets) AS ticket_id')
            ->from(ProjectTaskTicket::class, 'l')->innerJoin('l.projecttasks', 't');
        $rows = $this->forProject($query, $project)->orderBy('l.id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'ticket_id'));
    }

    public function taskDuration(int $task): int
    {
        $own = $this->em->createQueryBuilder()->select('t.effective_duration')->from(ProjectTask::class, 't')
            ->where('t.id = :task')->setParameter('task', $task)->getQuery()->getOneOrNullResult();
        return (int)($own['effective_duration'] ?? 0) + $this->ticketDuration($task, false);
    }

    public function effectiveDuration(?int $project): int
    {
        // Sum task time separately so multiple tickets cannot multiply the task duration.
        $query = $this->em->createQueryBuilder()->select('SUM(t.effective_duration)')->from(ProjectTask::class, 't');
        $own = $this->forProject($query, $project)->getQuery()->getSingleScalarResult();
        return (int)$own + $this->ticketDuration($project, true);
    }

    private function ticketDuration(?int $id, bool $project): int
    {
        $query = $this->em->createQueryBuilder()->select('SUM(ticket.actiontime)')->from(ProjectTaskTicket::class, 'l')
            ->innerJoin('l.projecttasks', 't')->innerJoin('l.tickets', 'ticket');
        if ($project) {
            $this->forProject($query, $id);
        } else {
            $query->where('t.id = :id')->setParameter('id', $id);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    public function plannedDuration(?int $project): int
    {
        $query = $this->em->createQueryBuilder()->select('SUM(t.planned_duration)')->from(ProjectTask::class, 't');
        return (int)$this->forProject($query, $project)->getQuery()->getSingleScalarResult();
    }

    private function forProject(QueryBuilder $query, ?int $project): QueryBuilder
    {
        return $project === null
            ? $query->where('IDENTITY(t.projects) IS NULL')
            : $query->where('IDENTITY(t.projects) = :project')->setParameter('project', $project);
    }

    public function taskProgress(int $task): int
    {
        $average = $this->em->createQueryBuilder()->select('AVG(t.percent_done)')->from(ProjectTask::class, 't')
            ->where('IDENTITY(t.projecttasks) = :task')->setParameter('task', $task)->getQuery()->getSingleScalarResult();
        return (int)round((float)$average);
    }

    public function projectProgress(int $project): int
    {
        $projects = $this->em->createQueryBuilder()->select('SUM(p.percent_done) AS total, COUNT(p.percent_done) AS count')
            ->from(Project::class, 'p')->where('IDENTITY(p.projects) = :project')->setParameter('project', $project)
            ->andWhere('p.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)
            ->getQuery()->getSingleResult();
        $tasks = $this->em->createQueryBuilder()->select('SUM(t.percent_done) AS total, COUNT(t.percent_done) AS count')
            ->from(ProjectTask::class, 't')->where('IDENTITY(t.projects) = :project')->setParameter('project', $project)
            ->getQuery()->getSingleResult();
        $count = (int)$projects['count'] + (int)$tasks['count'];
        return $count ? (int)round(((int)$projects['total'] + (int)$tasks['total']) / $count) : 0;
    }
}
