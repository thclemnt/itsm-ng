<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\Migration\SensorSubjects\Definition;

/** Forward schema history; the application release remains 2.2.0. */
final class SensorSubjects implements ReleaseMigration
{
    public const VERSION = 'schema.sensor_subjects.v1';

    public function version(): string
    {
        return self::VERSION;
    }

    public function plan(Connection $connection): array
    {
        return (new Definition())->plan($connection);
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        (new Definition())->apply($connection, $progress);
    }

    public function verify(Connection $connection): void
    {
        (new Definition())->verify($connection);
    }
}
