<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\Project;
use itsmng\Database\Entity\ProjectTask;
use itsmng\Database\Entity\ProjectTaskTicket;
use itsmng\Database\Entity\ProjectTeam;
use itsmng\Database\Entity\ProjectTaskTeam;
use itsmng\Database\RecordCriteria;
use itsmng\Database\EntityRegistry;

/** Project calculations through mapped associations, without per-task aggregate queries. */
final class ProjectRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Discovery only; showShort still loads each project's team and checks its rich link. */
    public function childIds(int $parent): array
    {
        $query = $this->em->createQueryBuilder()->select('child.id')->from(Project::class, 'child')
            ->where('child.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        if ($parent === 0) {
            $query->andWhere('child.projects IS NULL');
        } else {
            $query->andWhere('IDENTITY(child.projects) = :parent')->setParameter('parent', $parent, Types::BIGINT);
        }
        // The original find() call has no ordering; do not introduce a new sort.
        return array_map('intval', $query->getQuery()->getSingleColumnResult());
    }

    /** Visibility is an EXISTS predicate so several team memberships never duplicate a project. */
    public function visibleProjects(array $criteria, bool $readAll, int $user, array $groups, bool $active): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'state.is_finished AS is_finished')
            ->from(Project::class, 'r')->leftJoin('r.projectstates', 'state');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Project::class));
        $query->where($compiler->where($criteria));
        if ($active) {
            $query->andWhere('r.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN)
                ->andWhere('(state.is_finished IS NULL OR state.is_finished = :finished)')->setParameter('finished', false, Types::BOOLEAN);
        }
        $this->restrictVisibility($query, $readAll, $user, $groups);
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->orderBy('r.id')->getQuery()->toIterable() as $result) {
            $project = $result[0];
            $row = $records->toRow($project);
            $row['is_finished'] = $result['is_finished'] === null ? null : (int)$result['is_finished'];
            $rows[$row['id']] = $row;
            $this->em->detach($project);
        }
        return $rows;
    }

    /** Query must use r as its Project root alias. */
    public function restrictVisibility(QueryBuilder $query, bool $readAll, int $user, array $groups): void
    {
        if (!$readAll) {
            $ownership = [];
            $membership = [];
            if ($user > 0) {
                $ownership[] = 'IDENTITY(r.users) = :viewer';
                $membership[] = 'IDENTITY(team.user) = :viewer';
                $query->setParameter('viewer', $user, Types::INTEGER);
            }
            if ($groups) {
                $ownership[] = 'IDENTITY(r.groups) IN (:groups)';
                $membership[] = 'IDENTITY(team.group) IN (:groups)';
                $query->setParameter('groups', array_values(array_map('intval', $groups)));
            }
            if (!$membership) {
                $query->andWhere('1 = 0');
                return;
            }
            $ownership[] = 'EXISTS (SELECT team.id FROM ' . ProjectTeam::class . ' team WHERE IDENTITY(team.projects) = r.id AND (' . implode(' OR ', $membership) . '))';
            $query->andWhere('(' . implode(' OR ', $ownership) . ')');
        }
    }

    public function teamMembers(string $table, array $ids, array $fields): array
    {
        if (!$ids) {
            return [];
        }
        $class = EntityRegistry::tables()[$table];
        $query = $this->em->createQueryBuilder()->from($class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata($class));
        $query->select(...array_map($compiler->column(...), $fields));
        return $query->where($compiler->where(['id' => array_values(array_unique($ids))]))->getQuery()->getScalarResult();
    }

    public function projectTeamMemberIds(int $project, string $kind): array
    {
        $association = ProjectTeam::memberAssociation($kind);
        $query = $this->em->createQueryBuilder()->select('IDENTITY(team.' . $association . ') AS member_id')->from(ProjectTeam::class, 'team')
            ->where('IDENTITY(team.projects) = :parent')->setParameter('parent', $project, Types::INTEGER);
        return $this->teamIds($query->andWhere('team.' . $association . ' IS NOT NULL'));
    }

    public function taskTeamMemberIds(int $task, string $kind): array
    {
        $association = ProjectTaskTeam::memberAssociation($kind);
        $query = $this->em->createQueryBuilder()->select('IDENTITY(team.' . $association . ') AS member_id')->from(ProjectTaskTeam::class, 'team')
            ->where('IDENTITY(team.projecttasks) = :parent')->setParameter('parent', $task, Types::INTEGER);
        return $this->teamIds($query->andWhere('team.' . $association . ' IS NOT NULL'));
    }

    /** Selection only; the notification target still owns recipient admission and delivery hooks. */
    public function projectTeamRecipients(int $project, string $kind): array
    {
        return $this->teamRecipients(ProjectTeam::class, 'projects', $project, $kind);
    }

    public function taskTeamRecipients(int $task, string $kind): array
    {
        return $this->teamRecipients(ProjectTaskTeam::class, 'projecttasks', $task, $kind);
    }

    private function teamRecipients(string $teamClass, string $parentAssociation, int $parent, string $kind): array
    {
        $fields = match ($kind) {
            'User' => ['id', 'language'],
            'Contact' => ['id', 'name', 'firstname', 'email'],
            'Supplier' => ['id', 'name', 'email'],
            default => throw new InvalidArgumentException('Unsupported individual project notification recipient'),
        };
        return $this->em->createQueryBuilder()
            ->select(...array_map(static fn (string $field): string => 'recipient.' . $field . ' AS ' . $field, $fields))
            ->from($teamClass, 'team')->join('team.' . $teamClass::memberAssociation($kind), 'recipient')
            ->where('IDENTITY(team.' . $parentAssociation . ') = :parent')
            ->setParameter('parent', $parent, Types::BIGINT)->orderBy('team.id')
            ->getQuery()->getArrayResult();
    }

    private function teamIds(QueryBuilder $query): array
    {
        return array_map('intval', array_column($query->orderBy('team.id')->getQuery()->getScalarResult(), 'member_id'));
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
        $own = $this->em->createQueryBuilder()
            ->select('t.effective_duration')
            ->from(ProjectTask::class, 't')
            ->where('t.id = :task')
            ->setParameter('task', $task)
            ->getQuery()
            ->getOneOrNullResult();
        return (int)($own['effective_duration'] ?? 0) + $this->ticketDuration($task, false);
    }

    public function effectiveDuration(?int $project): int
    {
        // Sum task time separately so multiple tickets cannot multiply the task duration.
        $query = $this->em->createQueryBuilder()
            ->select('SUM(t.effective_duration)')
            ->from(ProjectTask::class, 't');
        $own = $this->forProject($query, $project)
            ->getQuery()
            ->getSingleScalarResult();
        return (int)$own + $this->ticketDuration($project, true);
    }

    private function ticketDuration(?int $id, bool $project): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('SUM(ticket.actiontime)')
            ->from(ProjectTaskTicket::class, 'l')
            ->innerJoin('l.projecttasks', 't')
            ->innerJoin('l.tickets', 'ticket');
        if ($project) {
            $this->forProject($query, $id);
        } else {
            $query->where('t.id = :id')
                ->setParameter('id', $id);
        }
        return (int)$query->getQuery()
            ->getSingleScalarResult();
    }

    public function plannedDuration(?int $project): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('SUM(t.planned_duration)')
            ->from(ProjectTask::class, 't');
        return (int)$this->forProject($query, $project)
            ->getQuery()
            ->getSingleScalarResult();
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
