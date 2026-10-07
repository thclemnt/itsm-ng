<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use RuntimeException;

/** CHECK syntax without enforcement is not a supported installation capability. */
final class CheckConstraintSupport
{
    public static function version(string $raw, bool $maria): string
    {
        // MariaDB may include MySQL's compatibility prefix 5.5.5-.
        if ($maria) {
            $raw = preg_replace('/^5\.5\.5-/', '', $raw);
        }
        if (!preg_match('/^([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $raw, $match)) {
            throw new RuntimeException('Cannot establish database CHECK enforcement from server version: ' . $raw);
        }
        return $match[1];
    }

    public static function supportsVersion(string $raw, bool $maria): bool
    {
        // MariaDB enforces CHECK from 10.2.1; CHECK_CONSTRAINTS (with
        // TABLE_NAME) first appears 10.2.22 and is required for native inspection.
        return version_compare(self::version($raw, $maria), $maria ? '10.2.22' : '8.0.16', '>=');
    }

    public static function assertSupported(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        $maria = $platform instanceof MariaDBPlatform;
        $version = $connection->getServerVersion();
        if (!self::supportsVersion($version, $maria)) {
            throw new RuntimeException('Enforced CHECK constraints with native inspection require MySQL 8.0.16 or later, or MariaDB 10.2.22 or later; found ' . $version . '. Upgrade the database engine before installation or migration.');
        }
        MySQLConnection::assertStrict($connection);
        if ($maria && (int)$connection->fetchOne('SELECT @@SESSION.check_constraint_checks') !== 1) {
            throw new RuntimeException('MariaDB check_constraint_checks is disabled for this connection. Enable CHECK enforcement before installation, migration or schema validation.');
        }
    }
}
