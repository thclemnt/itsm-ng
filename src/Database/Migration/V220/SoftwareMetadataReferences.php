<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use RuntimeException;

final class SoftwareMetadataReferences
{
    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'SOFTWARE_METADATA'), 'software metadata'))->plan($connection);
        $parents = $connection->fetchAllKeyValue('SELECT id, softwarelicenses_id FROM glpi_softwarelicenses');
        $finished = [];
        foreach ($parents as $id => $_) {
            $path = [];
            while ($id && !isset($finished[$id])) {
                if (isset($path[$id])) {
                    throw new RuntimeException('Cyclic software license parents at ' . $id);
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
        return (new NullableReferences(ReferenceHistory::get('optional', 'SOFTWARE_METADATA'), 'software metadata'))->apply($connection);
    }
}
