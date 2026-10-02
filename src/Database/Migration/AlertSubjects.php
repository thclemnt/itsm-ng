<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 scope for the ten core alert producers. */
final class AlertSubjects extends TypedItemMigration
{
    public const VERSION = '20261001_alert_subjects';

    protected function tables(): array
    {
        return ['glpi_alerts'];
    }

    protected static function targets(): array
    {
        return ['CartridgeItem' => 'cartridgeitems', 'ConsumableItem' => 'consumableitems', 'Certificate' => 'certificates', 'Contract' => 'contracts', 'Infocom' => 'infocoms', 'Reservation' => 'reservations', 'SoftwareLicense' => 'softwarelicenses', 'PlanningRecall' => 'planningrecalls', 'CronTask' => 'crontasks', 'User' => 'users'];
    }
}
