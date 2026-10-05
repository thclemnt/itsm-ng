<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\ComponentData20261013;
use itsmng\Database\Migration\HardDriveSubjects20261013;
use itsmng\Database\Migration\MemorySubjects20261013;
use itsmng\Database\Migration\MotherboardSubjects20261013;
use itsmng\Database\Migration\BatterySubjects20261014;
use itsmng\Database\Migration\PowerSupplySubjects20261014;
use itsmng\Database\Migration\ProcessorStagedTypedItemMigration20261012;
use itsmng\Database\Migration\ProcessorTypedItemMigration20261012;

if (!class_exists(Connection::class)) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}

/** Wrong-owner rejection must precede any connection or catalogue acquisition. */
final class ComponentPlanningUnreadConnection extends Connection
{
    public int $reads = 0;

    public function __construct()
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        ++$this->reads;
        throw new LogicException('Wrong-owner planning acquired a platform.');
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        ++$this->reads;
        throw new LogicException('Wrong-owner planning acquired a catalogue.');
    }
}

final class ComponentPlanningTypedFixture extends ProcessorTypedItemMigration20261012
{
    protected function tables(): array
    {
        return ['glpi_owned'];
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers'];
    }

    public function inspect(Connection $connection, Table $table): array
    {
        return $this->planInspectedTable($connection, $table);
    }
}

final class ComponentPlanningStagedFixture extends ProcessorStagedTypedItemMigration20261012
{
    protected function version(): string
    {
        return 'fixture_owned';
    }

    protected function table(): string
    {
        return 'glpi_owned';
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers'];
    }

    public function inspect(Connection $connection, Table $table): array
    {
        return $this->planInspectedTable($connection, $table);
    }
}

$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$typed = new ComponentPlanningTypedFixture();
$staged = new ComponentPlanningStagedFixture();
$planners = [
    [static fn (Connection $connection, Table $table): array => ComponentData20261013::planInspectedTable($connection, ['table' => 'glpi_owned'], $table), 'Historical component inspection belongs to a different table.'],
    [$typed->inspect(...), 'Typed item inspection belongs to a different table.'],
    [$staged->inspect(...), 'Staged typed item inspection belongs to a different table.'],
];
foreach ($planners as [$plan, $diagnostic]) {
    foreach (['glpi_other', 'GLPI_OWNED', 'other.glpi_owned'] as $name) {
        $connection = new ComponentPlanningUnreadConnection();
        $table = new Table($name);
        $table->addColumn('items_id', 'bigint', ['notnull' => false]);
        $table->addIndex(['items_id'], 'preserved_owned_index');
        $rejected = false;
        try {
            $plan($connection, $table);
        } catch (InvalidArgumentException $error) {
            $rejected = $error->getMessage() === $diagnostic;
        }
        $verify($rejected, 'An unrelated, case-different or namespace-different inspection cannot enter the frozen planner');
        $verify($connection->reads === 0, 'Wrong table ownership is rejected before platform, ledger or catalogue acquisition');
        $verify(
            $table->getName() === $name && $table->hasColumn('items_id') && $table->hasIndex('preserved_owned_index')
            && count($table->getColumns()) === 1 && count($table->getIndexes()) === 1,
            'Rejected inspection retains its complete fixture shape'
        );
    }
}
foreach ([ProcessorTypedItemMigration20261012::class, ProcessorStagedTypedItemMigration20261012::class,
    MotherboardSubjects20261013::class, MemorySubjects20261013::class, HardDriveSubjects20261013::class,
    BatterySubjects20261014::class, PowerSupplySubjects20261014::class] as $class) {
    $method = new ReflectionMethod($class, 'plan');
    $parameters = $method->getParameters();
    $verify(
        $method->isPublic() && count($parameters) === 2 && !$parameters[0]->isOptional()
        && $parameters[1]->isOptional() && $parameters[1]->getDefaultValue() === null,
        'Public typed planning retains its connection and optional incoming-context contract'
    );
}
foreach ([ProcessorTypedItemMigration20261012::class, ProcessorStagedTypedItemMigration20261012::class] as $class) {
    $verify((new ReflectionMethod($class, 'planInspectedTable'))->isProtected(), 'Borrowed typed inspection remains an internal extension boundary');
}
echo "Pure component planning ownership: $assertions assertions passed without connecting.\n";
