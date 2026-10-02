<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Frozen 20261003 project asset upgrade; container and Project subject have separate ownership. */
final class ProjectAssets20261003 extends TypedItemMigration
{
    public const VERSION = '20261003_project_assets';

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return [];
        }
        $manager = $connection->createSchemaManager();
        $identity = isset($manager->listTableColumns('glpi_items_projects')['items_id']) ? 'items_id' : 'NULL AS items_id';
        $unsupported = $connection->fetchAllAssociative('SELECT id, itemtype, ' . $identity
            . ' FROM glpi_items_projects WHERE itemtype IS NOT NULL AND itemtype NOT IN (?) LIMIT 5', [array_keys(self::targets())], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        if ($unsupported) {
            throw new \RuntimeException('Unsupported project asset kinds in glpi_items_projects; samples: ' . json_encode($unsupported, JSON_THROW_ON_ERROR)
                . '. Resolve these links before adoption. Appliance plugin import requires a compatible historical application and legacy MySQL schema before switching to modernized source and db:migrate; the current appliances import CLI is unsafe before and after adoption.');
        }
        $entry = parent::plan($connection)['glpi_items_projects'];
        return ['glpi_items_projects' => [
            'columns' => $entry['sql'],
            'copy' => $entry['copy_legacy'] ? [$this->copySql()] : [],
            'projection' => $entry['key_sql'],
            'constraints' => $entry['constraint_sql'],
        ]];
    }

    /** Replan each completed DDL phase on retry using frozen inputs and the same ledger. */
    public function apply(Connection $connection, ?callable $progress = null): array
    {
        $plan = $this->plan($connection);
        if (!$plan) {
            return [];
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL project asset adoption must run outside an application transaction.');
        }
        $apply = function () use ($connection, $progress, $plan): array {
            $state = Ledger::state($connection, self::VERSION);
            if ($state === null) {
                $columns = $connection->createSchemaManager()->listTableColumns('glpi_items_projects');
                $state = ['complete' => false, 'phase' => 'audited', 'items_comment' => ($columns['items_id'] ?? null)?->getComment() ?? ''];
                Ledger::save($connection, self::VERSION, $state);
            }
            foreach (['columns', 'copy', 'projection', 'constraints'] as $phase) {
                $sql = $this->plan($connection)['glpi_items_projects'][$phase];
                foreach ($sql as $statement) {
                    $connection->executeStatement($statement);
                    $progress && $progress($phase, $statement);
                }
                $state['phase'] = $phase;
                Ledger::save($connection, self::VERSION, $state);
            }
            // Recover the original comment even if a retry found the projection
            // absent; no runtime metadata can rewrite this historical declaration.
            $platform = $connection->getDatabasePlatform();
            $table = $connection->createSchemaManager()->introspectTable('glpi_items_projects');
            if ($table->getColumn('items_id')->getComment() !== $state['items_comment']) {
                if ($platform->supportsInlineColumnComments()) {
                    self::configureTable($table);
                    $connection->executeStatement('ALTER TABLE glpi_items_projects MODIFY COLUMN items_id '
                        . $table->getColumn('items_id')->getColumnDefinition() . ' ' . $platform->getInlineColumnCommentSQL($state['items_comment']));
                } else {
                    $connection->executeStatement($platform->getCommentOnColumnSQL('glpi_items_projects', 'items_id', $state['items_comment']));
                }
            }
            Ledger::save($connection, self::VERSION, ['complete' => true]);
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }

    private function copySql(): string
    {
        $assignments = [];
        foreach (self::targets() as $kind => $target) {
            $assignments[] = self::column($target) . " = CASE WHEN itemtype = '" . $kind . "' THEN items_id ELSE NULL END";
        }
        return 'UPDATE glpi_items_projects SET ' . implode(', ', $assignments);
    }

    protected static function column(string $target): string
    {
        return $target === 'projects' ? 'subject_projects_id' : parent::column($target);
    }

    protected function tables(): array
    {
        return ['glpi_items_projects'];
    }

    protected static function targets(): array
    {
        return [
            'Computer' => 'computers',
            'Monitor' => 'monitors',
            'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals',
            'Phone' => 'phones',
            'Printer' => 'printers',
            'Software' => 'softwares',
            'SoftwareLicense' => 'softwarelicenses',
            'Certificate' => 'certificates',
            'Line' => 'lines',
            'DCRoom' => 'dcrooms',
            'Rack' => 'racks',
            'Enclosure' => 'enclosures',
            'Cluster' => 'clusters',
            'PDU' => 'pdus',
            'Domain' => 'domains',
            'Appliance' => 'appliances',
            'Project' => 'projects',
            'Item_DeviceMotherboard' => 'items_devicemotherboards',
            'Item_DeviceFirmware' => 'items_devicefirmwares',
            'Item_DeviceProcessor' => 'items_deviceprocessors',
            'Item_DeviceMemory' => 'items_devicememories',
            'Item_DeviceHardDrive' => 'items_deviceharddrives',
            'Item_DeviceNetworkCard' => 'items_devicenetworkcards',
            'Item_DeviceDrive' => 'items_devicedrives',
            'Item_DeviceBattery' => 'items_devicebatteries',
            'Item_DeviceGraphicCard' => 'items_devicegraphiccards',
            'Item_DeviceSoundCard' => 'items_devicesoundcards',
            'Item_DeviceControl' => 'items_devicecontrols',
            'Item_DevicePci' => 'items_devicepcis',
            'Item_DeviceCase' => 'items_devicecases',
            'Item_DevicePowerSupply' => 'items_devicepowersupplies',
            'Item_DeviceGeneric' => 'items_devicegenerics',
            'Item_DeviceSimcard' => 'items_devicesimcards',
            'Item_DeviceSensor' => 'items_devicesensors',
        ];
    }
}
