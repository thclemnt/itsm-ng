<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

/** Public ORM release history; helper checkpoints are not application releases. */
final class History
{
    public const VERSIONS = [Version220::VERSION];

    /** Readiness is read-only and also refuses an incomplete transition journal. */
    public static function pendingVersions(Connection $connection): array
    {
        $states = Ledger::states($connection);
        $complete = ($states[Version220::VERSION]['complete'] ?? false) === true;
        return $complete && Version220::pendingPhases($states) === [] ? [] : self::VERSIONS;
    }

    public static function isInstalling(Connection $connection): bool
    {
        return Version220::isInstalling($connection);
    }

    public function plan(Connection $connection): array
    {
        return (new Version220())->plan($connection);
    }

    /** Frozen installation input, also used by the interrupted-install contract. */
    public function baseline(Connection $connection, ?callable $progress = null): void
    {
        (new Version220())->baseline($connection, $progress);
    }

    public function install(\DBAdapter $database, string $language, ?callable $progress = null): void
    {
        (new Version220())->install($database, $language, $progress);
    }

    public function upgrade(Connection $connection, ?callable $progress = null, ?callable $onComplete = null): void
    {
        (new Version220())->upgrade($connection, $progress, $onComplete);
    }
}
