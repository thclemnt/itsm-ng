<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Installation and upgrade publish configuration under the same owned boundary. */
final class ReleasePublication
{
    /** Configuration and its audit rows must participate in the publication frame. */
    public static function assertStorage(\Doctrine\DBAL\Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        foreach (['glpi_configs', 'glpi_logs'] as $table) {
            $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Canonical release publication requires ' . $table . ' to use InnoDB; found ' . $engine . '. Stop application writers and restore the supported transactional table engine before retrying the installation or upgrade.');
            }
        }
    }

    /** Caller owns History's final publication frame. */
    public static function publish(\DBAdapter $database, array $installation = []): void
    {
        $target = $installation + ['version' => ITSM_VERSION, 'itsmversion' => ITSM_VERSION, 'dbversion' => ITSM_SCHEMA_VERSION, 'itsmdbversion' => ITSM_SCHEMA_VERSION];
        $target = array_map(static fn ($value): string => (string)(is_bool($value) ? (int)$value : $value), $target);
        $connection = $database->getDoctrineConnection();
        self::assertStorage($connection);
        $current = $connection->fetchAllKeyValue('SELECT name, value FROM glpi_configs WHERE context = ? AND name IN (?)', ['core', array_keys($target)], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);
        $values = [];
        foreach ($target as $name => $value) {
            if (($current[$name] ?? null) !== $value) {
                $values[$name] = $value;
            }
        }
        // Config's mapped writes retain its update/add hooks and audit
        // history. An idempotent retry does not create duplicate audit entries.
        $configuredDatabase = $GLOBALS['DB'] ?? null;
        try {
            $GLOBALS['DB'] = $database;
            $connection = $database->getDoctrineConnection();
            TransactionOwnership::assertManaged($connection);
            $scope = $connection->captureManagedTransactionScope();
            $level = $connection->getTransactionNestingLevel();
            $assertOwner = static function () use ($database, $connection, $scope, $level): void {
                if (($GLOBALS['DB'] ?? null) !== $database || $database->getDoctrineConnection() !== $connection) {
                    throw new TransactionOwnershipMismatch('A release publication callback changed the configured writer.');
                }
                $scope->assertActive();
                if ($connection->getTransactionNestingLevel() !== $level) {
                    throw new TransactionOwnershipMismatch('A release publication callback changed the owned frame depth.');
                }
            };
            foreach ($values as $name => $value) {
                $assertOwner();
                \Config::setConfigurationValues('core', [$name => $value]);
                $assertOwner();
            }
            $assertOwner();
            $published = $connection->fetchAllKeyValue('SELECT name, value FROM glpi_configs WHERE context = ? AND name IN (?)', ['core', array_keys($target)], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);
            foreach ($target as $name => $value) {
                if (($published[$name] ?? null) !== $value) {
                    throw new \RuntimeException('Canonical release publication was rejected for ' . $name . '. Resolve the configuration lifecycle veto and retry the installation or upgrade.');
                }
            }
        } finally {
            $GLOBALS['DB'] = $configuredDatabase;
        }
    }
}
