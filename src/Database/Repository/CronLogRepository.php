<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CronTask as LegacyCronTask;
use CronTaskLog as LegacyCronTaskLog;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\CronTask;
use itsmng\Database\Entity\CronTaskLog;

final class CronLogRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function start(int $task, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        $minute = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
        return $this->em->createQueryBuilder()->update(CronTask::class, 't')
            ->set('t.state', ':running')->set('t.lastrun', ':time')
            ->where('t.id = :id AND t.state <> :running')->setParameter('id', $task, Types::INTEGER)
            ->setParameter('running', LegacyCronTask::STATE_RUNNING, Types::INTEGER)
            ->setParameter('time', $minute, Types::DATETIMETZ_IMMUTABLE)->getQuery()->execute() > 0;
    }

    public function finish(int $task, int $state): bool
    {
        return $this->em->createQueryBuilder()->update(CronTask::class, 't')->set('t.state', ':state')
            ->where('t.id = :id AND t.state = :running')->setParameter('id', $task, Types::INTEGER)
            ->setParameter('state', $state, Types::INTEGER)->setParameter('running', LegacyCronTask::STATE_RUNNING, Types::INTEGER)
            ->getQuery()->execute() > 0;
    }

    public function statistics(int $task): array
    {
        $row = $this->em->createQueryBuilder()->select(
            'MIN(l.date) AS datemin',
            'MIN(l.elapsed) AS elapsedmin',
            'MAX(l.elapsed) AS elapsedmax',
            'SUM(l.elapsed) AS elapsedtot',
            'AVG(l.elapsed) AS elapsedavg',
            'MIN(l.volume) AS volmin',
            'MAX(l.volume) AS volmax',
            'SUM(l.volume) AS voltot',
            'AVG(l.volume) AS volavg'
        )
            ->from(CronTaskLog::class, 'l')->where('IDENTITY(l.task) = :task AND l.state = :stop')
            ->setParameter('task', $task, Types::INTEGER)->setParameter('stop', LegacyCronTaskLog::STATE_STOP, Types::INTEGER)
            ->getQuery()->getSingleResult();
        if ($row['datemin'] !== null) {
            $row['datemin'] = (new DateTimeImmutable($row['datemin']))->format('Y-m-d H:i:s');
        }
        return $row;
    }

    public function history(int $task, int $limit, int $offset): array
    {
        return (new RecordRepository($this->em))->matching('glpi_crontasklogs', [
            'crontasks_id' => $task, 'state' => [LegacyCronTaskLog::STATE_STOP, LegacyCronTaskLog::STATE_ERROR],
        ], 'id DESC', max(1, $limit), max(0, $offset));
    }

    public function details(int $task, int $root): array
    {
        if ($root <= 0) {
            return [];
        }
        return (new RecordRepository($this->em))->matching('glpi_crontasklogs', [
            'crontasks_id' => $task, 'OR' => ['id' => $root, 'crontasklogs_id' => $root],
        ], 'id');
    }

    /** An explicitly removed message passes its children to its own parent. */
    public function preserveChildren(int $log, ?int $parent): void
    {
        $this->em->createQueryBuilder()->update(CronTaskLog::class, 'l')->set('l.parent', ':parent')
            ->where('IDENTITY(l.parent) = :log')->setParameter('log', $log, Types::INTEGER)
            ->setParameter('parent', $parent === $log ? null : $parent)->getQuery()->execute();
    }

    /** Expire leaves in bounded batches; newer children keep their parent readable. */
    public function expire(int $task, int $days, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $cutoff = $now->setTimestamp($now->getTimestamp() - $days * DAY_TIMESTAMP);
        return $this->em->getConnection()->transactional(function () use ($task, $cutoff): int {
            // Serialize retention with other cleanup/claim operations for this task.
            $state = $this->em->createQueryBuilder()->select('t.state')->from(CronTask::class, 't')
                ->where('t.id = :task')->setParameter('task', $task, Types::INTEGER)->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if ($state === null) {
                return 0;
            }
            $activeRoot = 0;
            if ((int)$state['state'] === LegacyCronTask::STATE_RUNNING) {
                $activeRoot = (int)$this->em->createQueryBuilder()->select('MAX(l.id)')->from(CronTaskLog::class, 'l')
                    ->where('IDENTITY(l.task) = :task AND l.state = :start')
                    ->setParameter('task', $task, Types::INTEGER)->setParameter('start', LegacyCronTaskLog::STATE_START, Types::INTEGER)
                    ->getQuery()->getSingleScalarResult();
            }
            $deleted = 0;
            do {
                $rows = $this->em->createQueryBuilder()->select('l.id')->from(CronTaskLog::class, 'l')
                    ->where('IDENTITY(l.task) = :task AND l.date < :cutoff')
                    ->andWhere('l.id <> :active')->setParameter('active', $activeRoot, Types::INTEGER)
                    ->andWhere('NOT EXISTS (SELECT c.id FROM ' . CronTaskLog::class . ' c WHERE IDENTITY(c.parent) = l.id)')
                    ->setParameter('task', $task, Types::INTEGER)->setParameter('cutoff', $cutoff, Types::DATETIMETZ_IMMUTABLE)
                    ->orderBy('l.id')->setMaxResults(1000)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getScalarResult();
                $ids = array_map('intval', array_column($rows, 'id'));
                if ($ids) {
                    $deleted += $this->em->createQueryBuilder()->delete(CronTaskLog::class, 'l')
                        ->where('l.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
                }
            } while ($ids);
            return $deleted;
        });
    }
}
