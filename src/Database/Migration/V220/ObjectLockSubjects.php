<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Frozen 20261001 scope for the thirty core lockable objects. */
final class ObjectLockSubjects extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_objectlocks'];
    }

    protected static function targets(): array
    {
        return ['Budget' => 'budgets', 'Change' => 'changes', 'Contact' => 'contacts', 'Contract' => 'contracts', 'Document' => 'documents', 'CartridgeItem' => 'cartridgeitems', 'Computer' => 'computers', 'ConsumableItem' => 'consumableitems', 'Entity' => 'entities', 'Group' => 'groups', 'KnowbaseItem' => 'knowbaseitems', 'Line' => 'lines', 'Link' => 'links', 'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments', 'NetworkName' => 'networknames', 'Peripheral' => 'peripherals', 'Phone' => 'phones', 'Printer' => 'printers', 'Problem' => 'problems', 'Profile' => 'profiles', 'Project' => 'projects', 'Reminder' => 'reminders', 'RSSFeed' => 'rssfeeds', 'Software' => 'softwares', 'Supplier' => 'suppliers', 'Ticket' => 'tickets', 'User' => 'users', 'SoftwareLicense' => 'softwarelicenses', 'Certificate' => 'certificates'];
    }

    /** The locking owner already occupies users_id. */
    protected static function column(string $target): string
    {
        return 'subject_' . $target . '_id';
    }

    /** Copying the subject must not renew an automatically touched lock timestamp. */
    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        if (!$plan['glpi_objectlocks']['copy_legacy']) {
            return parent::apply($connection);
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL typed item reference DDL must run outside an application transaction');
        }
        $upgrade = function () use ($connection, $postgres): array {
            $snapshot = $connection->quoteIdentifier('port_lock_dates_' . bin2hex(random_bytes(8)));
            $connection->executeStatement('CREATE TEMPORARY TABLE ' . $snapshot . ' AS SELECT id, date_mod FROM glpi_objectlocks');
            try {
                return parent::apply($connection);
            } finally {
                // A changed timestamp is an explicit assignment on both providers;
                // the legacy auto-touch mechanism therefore preserves this value.
                $connection->executeStatement($postgres
                    ? 'UPDATE glpi_objectlocks r SET date_mod = d.date_mod FROM ' . $snapshot . ' d WHERE r.id = d.id AND r.date_mod IS DISTINCT FROM d.date_mod'
                    : 'UPDATE glpi_objectlocks r INNER JOIN ' . $snapshot . ' d ON r.id = d.id SET r.date_mod = d.date_mod WHERE NOT (r.date_mod <=> d.date_mod)');
                $connection->executeStatement('DROP TABLE ' . $snapshot);
            }
        };
        return $postgres ? $connection->transactional($upgrade) : $upgrade();
    }
}
