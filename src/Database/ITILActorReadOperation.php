<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\ITILActorRepository;

/** One loadActors invocation owns its three built-in relationship reads. */
final class ITILActorReadOperation
{
    private readonly RecordReadOperation $records;

    public function __construct(Connection $connection)
    {
        $this->records = new RecordReadOperation($connection);
    }

    public function actors(string $actorClass, int $item): array
    {
        if (!ITILActorRepository::supports($actorClass)) {
            throw new \InvalidArgumentException('Unsupported ITIL actor relation');
        }
        $rows = $this->records->actorRows($actorClass, $item);
        $actors = [];
        foreach ($rows as $row) {
            $actors[$row['type']][] = $row;
        }
        return $actors;
    }

    public function close(): void
    {
        $this->records->close();
    }
}
