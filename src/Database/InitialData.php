<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Types\Types;
use itsmng\Database\Entity\CronTask;
use itsmng\Database\Repository\RecordWriter;

/** Import raw, translated installation values before relationship migrations and FK enforcement. */
final class InitialData
{
    public static function load(\DBAdapter $database, array $tables, ?callable $progress = null): void
    {
        foreach (array_keys($tables) as $table) {
            if (!isset(EntityRegistry::TABLES[$table])) {
                throw new \InvalidArgumentException('Unmapped installation table: ' . $table);
            }
        }
        $em = Orm::create($database);
        try {
            $database->getDoctrineConnection()->transactional(static function () use ($em, $tables, $progress): void {
                $writer = new RecordWriter($em);
                foreach ($tables as $table => $rows) {
                    foreach ($rows as $row) {
                        // Raw values preserve literal NULL/backslashes and temporary legacy sentinels.
                        $writer->insert($table, $row);
                        // Seed order can reference parents loaded later; discard placeholder proxies.
                        $em->clear();
                        if ($progress !== null) {
                            $progress();
                        }
                    }
                }
            });
        } finally {
            $em->clear();
        }
    }

    public static function enableSystemCron(\DBAdapter $database): void
    {
        Orm::create($database)->createQueryBuilder()->update(CronTask::class, 'r')->set('r.mode', ':mode')
            ->where('r.name <> :watcher AND BIT_AND(r.allowmode, :mode) = :mode')
            ->setParameter('mode', 2, Types::INTEGER)->setParameter('watcher', 'watcher', Types::STRING)
            ->getQuery()->execute();
    }
}
