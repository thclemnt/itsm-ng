<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Entity\CronTask;

/** Installation preferences applied after frozen seed replay. */
final class InitialData
{
    public static function enableSystemCron(DBAdapter $database): void
    {
        Orm::create($database)->createQueryBuilder()->update(CronTask::class, 'r')->set('r.mode', ':mode')
            ->where('r.name <> :watcher AND BIT_AND(r.allowmode, :mode) = :mode')
            ->setParameter('mode', 2, Types::INTEGER)->setParameter('watcher', 'watcher', Types::STRING)
            ->getQuery()->execute();
    }
}
