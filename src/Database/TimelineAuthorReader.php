<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\ORM\EntityManager;
use Toolbox;
use User;

/** Current scalar authors; each load completes before public model callbacks. */
final class TimelineAuthorReader
{
    public function load(User $model, $id, DBAdapter $database): bool
    {
        if ($id === null || strlen($id) == 0) {
            return false;
        }
        // Resolve the current route after each extensible display callback.
        $row = Orm::read($database, static fn (EntityManager $manager): ?array =>
            (new RecordReadOperation($manager->getConnection(), $manager))
                ->scalarRow('glpi_users', (int)Toolbox::cleanInteger($id)));
        if ($row === null) {
            return false;
        }
        $model->fields = $row;
        $model->post_getFromDB();
        return true;
    }
}
