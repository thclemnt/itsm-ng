<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CronTask as LegacyCronTask;
use CronTaskLog;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\CronTask;

final class CronTaskRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function usedItemtypes(): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT t.itemtype')->from(CronTask::class, 't')
            ->orderBy('t.itemtype')->getQuery()->getScalarResult(), 'itemtype');
    }

    /** Plugin class prefixes are literal strings, never LIKE patterns. */
    private function prefix(QueryBuilder $query, string $value, string $parameter): string
    {
        $query->setParameter($parameter, strtolower($value));
        return 'SUBSTRING(LOWER(t.itemtype), 1, ' . strlen($value) . ') = :' . $parameter;
    }

    private function plugin(QueryBuilder $query, string $plugin, string $parameter): string
    {
        return '(' . $this->prefix($query, 'Plugin' . $plugin, $parameter . '_legacy') . ' OR '
            . $this->prefix($query, 'GlpiPlugin\\' . $plugin . '\\', $parameter . '_namespace') . ')';
    }

    public function forPlugin(string $plugin): array
    {
        if ($plugin === '') {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('t')->from(CronTask::class, 't');
        $query->where($this->plugin($query, $plugin, 'plugin'))->orderBy('t.id');
        return $this->rows($query);
    }

    /** Normal runs observe windows/locks; forced runs only require an allowed mode and an idle task. */
    public function next(int $mode, string $name, array $activePlugins, array $locks = [], ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();
        $query = $this->em->createQueryBuilder()->select('t')->from(CronTask::class, 't');
        $plugins = ['(NOT (' . $this->prefix($query, 'Plugin', 'legacy') . ') AND NOT ('
            . $this->prefix($query, 'GlpiPlugin\\', 'namespaced') . '))'];
        foreach (array_values(array_unique($activePlugins)) as $index => $plugin) {
            $plugins[] = $this->plugin($query, $plugin, 'active' . $index);
        }
        $query->where('(' . implode(' OR ', $plugins) . ')');
        if ($name !== '') {
            $query->andWhere('t.name = :name')->setParameter('name', $name);
        }
        if ($mode < 0) {
            $query->andWhere('t.state <> :running AND BIT_AND(t.allowmode, :mode) <> 0')
                ->setParameter('running', LegacyCronTask::STATE_RUNNING, Types::INTEGER)->setParameter('mode', -$mode, Types::INTEGER);
        } else {
            $query->andWhere('t.state = :waiting')->setParameter('waiting', LegacyCronTask::STATE_WAITING, Types::INTEGER);
            if ($mode > 0) {
                $query->andWhere('t.mode = :mode')->setParameter('mode', $mode, Types::INTEGER);
            }
            if ($locks) {
                $query->andWhere('t.name NOT IN (:locks)')->setParameter('locks', array_values($locks));
            }
            $query->andWhere('((t.hourmin < t.hourmax AND t.hourmin <= :hour AND t.hourmax > :hour) OR '
                . '(t.hourmin > t.hourmax AND (t.hourmin <= :hour OR t.hourmax > :hour)))')
                ->setParameter('hour', (int)$now->format('G'), Types::INTEGER)
                ->andWhere("(t.lastrun IS NULL OR EPOCH_SECONDS(t.lastrun) + t.frequency <= :now)")
                ->setParameter('now', $now->getTimestamp(), Types::BIGINT);
        }
        $query->addSelect("LOCATE('plugin', LOWER(t.itemtype)) AS HIDDEN plugin_order")
            ->addSelect('CASE WHEN t.lastrun IS NULL THEN 0 ELSE 1 END AS HIDDEN run_order')
            ->addSelect("EPOCH_SECONDS(t.lastrun) + t.frequency AS HIDDEN due")
            ->orderBy('plugin_order')->addOrderBy('run_order')->addOrderBy('due')->addOrderBy('t.id')->setMaxResults(1);
        $rows = $this->rows($query);
        return $rows[0] ?? null;
    }

    /** Preserve the strict two-frequency OR two-hour watcher threshold. */
    public function overdue(?DateTimeImmutable $now = null): array
    {
        return $this->rows($this->overdueQuery($now)->select('t'));
    }

    /** Public health needs names only, including independently registered duplicate names. */
    public function overdueNames(?DateTimeImmutable $now = null): array
    {
        return array_column($this->overdueQuery($now)->select('t.name AS name')
            ->getQuery()->getScalarResult(), 'name');
    }

    private function overdueQuery(?DateTimeImmutable $now): QueryBuilder
    {
        // Operational status follows the selected database clock, not the session or PHP clock.
        $clock = $now === null ? 'CURRENT_EPOCH_SECONDS()' : ':now';
        $query = $this->em->createQueryBuilder()->from(CronTask::class, 't')
            ->where('t.state = :running')->setParameter('running', LegacyCronTask::STATE_RUNNING, Types::INTEGER)
            ->andWhere('t.lastrun IS NOT NULL')
            ->andWhere('(EPOCH_SECONDS(t.lastrun) + 2 * t.frequency < ' . $clock
                . ' OR EPOCH_SECONDS(t.lastrun) + 7200 < ' . $clock . ')')->orderBy('t.id');
        if ($now !== null) {
            $query->setParameter('now', $now->getTimestamp(), Types::BIGINT);
        }
        return $query;
    }

    /** Decide whether to notify without dispatching notifications or running a task. */
    public function needsErrorNotification(int $task, int $threshold = 5, ?DateTimeImmutable $now = null): bool
    {
        $cutoff = ($now ?? new DateTimeImmutable())->modify('-1 day');
        if ((new RecordRepository($this->em))->countMatching('glpi_alerts', [
            'crontasks_id' => $task, 'date' => ['>', $cutoff],
        ])) {
            return false;
        }
        $errors = 0;
        foreach ((new CronLogRepository($this->em))->history($task, $threshold * 2, 0) as $row) {
            $errors += (int)$row['state'] === CronTaskLog::STATE_ERROR ? 1 : 0;
        }
        return $errors >= $threshold;
    }

    private function rows(QueryBuilder $query): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $task) {
            $rows[] = $records->toRow($task);
            $this->em->detach($task);
        }
        return $rows;
    }
}
