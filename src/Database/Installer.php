<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Shared safeguards for the CLI and web PostgreSQL installers. */
final class Installer
{
    public static function checkPostgres(\DBAdapter $database): void
    {
        if (!$database->connected) {
            throw new \RuntimeException($database->error());
        }
        if (version_compare($database->getVersion(), '14', '<')) {
            throw new \RuntimeException('PostgreSQL 14 or later is required.');
        }
        if (count($database->listTables()) > 0) {
            throw new \RuntimeException('PostgreSQL installation requires an empty schema. Use a new database.');
        }
    }

    public static function installPostgres(\DBAdapter $database, string $language): void
    {
        self::checkPostgres($database);
        $database->getDoctrineConnection()->transactional(static function () use ($database, $language): void {
            \Toolbox::createSchema($language, $database);
        });
    }
}
