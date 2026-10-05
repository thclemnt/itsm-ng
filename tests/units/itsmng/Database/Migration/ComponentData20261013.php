<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\ComponentData20261013 as HistoricalComponents;
use itsmng\Database\Migration\HardDriveSubjects20261013;
use itsmng\Database\Migration\MemorySubjects20261013;
use itsmng\Database\Migration\MotherboardSubjects20261013;
use itsmng\Database\Migration\BatterySubjects20261014;
use itsmng\Database\Migration\PowerSupplySubjects20261014;
use itsmng\Database\Migration\ProcessorStagedTypedItemMigration20261012;
use itsmng\Database\Migration\ProcessorTypedItemMigration20261012;

class ComponentData20261013 extends \atoum\atoum\test
{
    public function foreignInspections(): array
    {
        $cases = [];
        foreach ([
            ['historical', 'Historical component inspection belongs to a different table.'],
            ['typed', 'Typed item inspection belongs to a different table.'],
            ['staged', 'Staged typed item inspection belongs to a different table.'],
        ] as [$planner, $diagnostic]) {
            foreach (['glpi_other', 'GLPI_OWNED', 'other.glpi_owned'] as $name) {
                $cases[] = [$planner, $diagnostic, $name];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider foreignInspections
     */
    public function testWrongOwnerRefusesBeforeAnyCatalogueRead(string $planner, string $diagnostic, string $name): void
    {
        $connection = new ComponentPlanningUnreadConnection();
        $table = new Table($name);
        $table->addColumn('items_id', 'bigint', ['notnull' => false]);
        $table->addIndex(['items_id'], 'preserved_owned_index');
        $plan = match ($planner) {
            'historical' => static fn () => HistoricalComponents::planInspectedTable($connection, ['table' => 'glpi_owned'], $table),
            'typed' => static fn () => (new ComponentPlanningTypedFixture())->inspect($connection, $table),
            'staged' => static fn () => (new ComponentPlanningStagedFixture())->inspect($connection, $table),
        };
        $this->exception($plan)->isInstanceOf(\InvalidArgumentException::class)->hasMessage($diagnostic);
        $this->integer($connection->reads)->isIdenticalTo(0);
        $this->string($table->getName())->isIdenticalTo($name);
        $this->boolean($table->hasColumn('items_id'))->isTrue();
        $this->boolean($table->hasIndex('preserved_owned_index'))->isTrue();
        $this->array($table->getColumns())->hasSize(1);
        $this->array($table->getIndexes())->hasSize(1);
    }

    public function publicPlanners(): array
    {
        return array_map(static fn ($class): array => [$class], [ProcessorTypedItemMigration20261012::class, ProcessorStagedTypedItemMigration20261012::class,
            MotherboardSubjects20261013::class, MemorySubjects20261013::class, HardDriveSubjects20261013::class,
            BatterySubjects20261014::class, PowerSupplySubjects20261014::class]);
    }

    /**
     * @dataProvider publicPlanners
     */
    public function testPublicPlannerKeepsItsOptionalIncomingContext(string $class): void
    {
        $method = new \ReflectionMethod($class, 'plan');
        $parameters = $method->getParameters();
        $this->boolean($method->isPublic())->isTrue();
        $this->array($parameters)->hasSize(2);
        $this->boolean($parameters[0]->isOptional())->isFalse();
        $this->boolean($parameters[1]->isOptional())->isTrue();
        $this->variable($parameters[1]->getDefaultValue())->isNull();
    }

    public function testInspectedTablePlanningRemainsAnInternalExtensionBoundary(): void
    {
        foreach ([ProcessorTypedItemMigration20261012::class, ProcessorStagedTypedItemMigration20261012::class] as $class) {
            $this->boolean((new \ReflectionMethod($class, 'planInspectedTable'))->isProtected())->isTrue();
        }
    }
}

/** Wrong-owner planning must not obtain a platform or schema manager. */
final class ComponentPlanningUnreadConnection extends Connection
{
    public int $reads = 0;

    public function __construct()
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        ++$this->reads;
        throw new \LogicException('Wrong-owner planning acquired a platform.');
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        ++$this->reads;
        throw new \LogicException('Wrong-owner planning acquired a catalogue.');
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
