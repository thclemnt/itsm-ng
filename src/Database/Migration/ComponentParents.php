<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\Migration\ComponentParents\NetworkCardDefinition;
use itsmng\Database\Migration\ComponentParents\GenericDefinition;
use itsmng\Database\Migration\ComponentParents\SoundCardDefinition;
use itsmng\Database\Migration\ComponentParents\FirmwareDefinition;
use itsmng\Database\Migration\ComponentParents\DriveDefinition;
use itsmng\Database\Migration\ComponentParents\ControlDefinition;
use itsmng\Database\Migration\ComponentParents\CaseDefinition;
use itsmng\Database\Migration\ComponentParents\SimcardDefinition;
use itsmng\Database\Migration\ComponentParents\PciDefinition;

/** Frozen Computer branches; other configured component parents remain opaque. */
final class ComponentParents implements ReleaseMigration
{
    public const VERSION = 'schema.component_parents.v1';
    public const DEFINITIONS = [
        'glpi_items_devicenetworkcards' => NetworkCardDefinition::class,
        'glpi_items_devicegenerics' => GenericDefinition::class,
        'glpi_items_devicesoundcards' => SoundCardDefinition::class,
        'glpi_items_devicefirmwares' => FirmwareDefinition::class,
        'glpi_items_devicedrives' => DriveDefinition::class,
        'glpi_items_devicecontrols' => ControlDefinition::class,
        'glpi_items_devicecases' => CaseDefinition::class,
        'glpi_items_devicesimcards' => SimcardDefinition::class,
        'glpi_items_devicepcis' => PciDefinition::class,
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    public function plan(Connection $connection): array
    {
        $plans = [];
        foreach (self::DEFINITIONS as $class) {
            $plans += (new $class())->plan($connection);
        }
        return $plans;
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        // Audit all legacy tables before starting the first table's owned DDL.
        $this->plan($connection);
        foreach (self::DEFINITIONS as $table => $class) {
            (new $class())->apply($connection, $progress === null ? null :
                static fn (string $phase, string $sql) => $progress($table . '.' . $phase, $sql));
        }
    }

    public function verify(Connection $connection): void
    {
        (new GraphicCardParents())->verify($connection);
        foreach (self::DEFINITIONS as $class) {
            (new $class())->verify($connection);
        }
    }
}
