<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use RuntimeException;

final class CronLogReferences
{
    public function plan(Connection $connection): array
    {
        if ($connection->fetchOne('SELECT COUNT(*) FROM glpi_crontasklogs l LEFT JOIN glpi_crontasks t ON t.id = l.crontasks_id WHERE t.id IS NULL')) {
            throw new RuntimeException('Orphaned cron task references; no log schema changes applied.');
        }
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'CRON_LOG_PARENTS'), 'cron log'))->plan($connection);
        $parents = $connection->fetchAllKeyValue('SELECT id, crontasklogs_id FROM glpi_crontasklogs');
        $finished = [];
        foreach ($parents as $id => $_) {
            $path = [];
            while ($id && !isset($finished[$id])) {
                if (isset($path[$id])) {
                    throw new RuntimeException('Cyclic cron log parents at ' . $id);
                }
                $path[$id] = true;
                $id = (int)($parents[$id] ?? 0);
            }
            $finished += $path;
        }
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection);
        return (new NullableReferences(ReferenceHistory::get('optional', 'CRON_LOG_PARENTS'), 'cron log'))->apply($connection);
    }
}
