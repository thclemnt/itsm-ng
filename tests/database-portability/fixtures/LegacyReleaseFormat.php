<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;

/**
 * Historical publication input, not a current installer or upgrade routine.
 * Release 1871d3f461's Update and Toolbox both publish these four fields.
 * Its 2.1.2 predecessor and 2.1.3 SQL dumps are byte-identical
 * (SHA256 501eae1681a2cfdc36ce98953d3e14177ce84616ce891836de6104fdd6608d09).
 */
final class LegacyReleaseFormat
{
    public static function publish(Connection $connection, string $application = '2.1.3', string $format = '2.1.3'): void
    {
        foreach (['version' => $application, 'itsmversion' => $application, 'dbversion' => $format, 'itsmdbversion' => $format] as $name => $value) {
            $connection->update('glpi_configs', ['value' => $value], ['context' => 'core', 'name' => $name]);
        }
    }
}
