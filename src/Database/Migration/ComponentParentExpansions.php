<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\Migration\ComponentParentExpansions\FirmwareDefinition;
use itsmng\Database\Migration\ComponentParentExpansions\NetworkCardDefinition;
use itsmng\Database\Migration\ComponentParentExpansions\SimcardDefinition;

/** One forward release; all writers must remain stopped/drained through proof and retries. */
final class ComponentParentExpansions implements ReleaseMigration
{
    public const VERSION = 'schema.component_parent_expansions.v1';
    public const DEFINITIONS = [FirmwareDefinition::class, NetworkCardDefinition::class, SimcardDefinition::class];

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
        // Audit every family before the first family begins owned DDL.
        $this->plan($connection);
        foreach (self::DEFINITIONS as $class) {
            (new $class())->apply($connection, $progress === null ? null :
                static fn (string $phase, string $sql) => $progress($class::TABLE . '.' . $phase, $sql));
        }
    }

    public function verify(Connection $connection): void
    {
        $superseded = [];
        foreach (self::DEFINITIONS as $class) {
            (new $class())->verify($connection);
            $superseded[] = $class::PREDECESSOR;
        }
        (new GraphicCardParents())->verify($connection);
        foreach (ComponentParents::DEFINITIONS as $class) {
            if (!in_array($class, $superseded, true)) {
                (new $class())->verify($connection);
            }
        }
    }
}
