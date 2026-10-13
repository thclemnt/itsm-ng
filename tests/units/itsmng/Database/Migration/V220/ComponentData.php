<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use atoum\atoum\test;
use itsmng\Database\Migration\V220\ComponentData as HistoricalComponents;
use itsmng\Database\Migration\V220\HardDriveSubjects;
use itsmng\Database\Migration\V220\MemorySubjects;
use itsmng\Database\Migration\V220\MotherboardSubjects;
use itsmng\Database\Migration\V220\BatterySubjects;
use itsmng\Database\Migration\V220\PowerSupplySubjects;
use itsmng\Database\Migration\V220\StagedTypedItemMigration;
use itsmng\Database\Migration\V220\TypedItemMigration;

class ComponentData extends test
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
        $this->exception($plan)->isInstanceOf(InvalidArgumentException::class)->hasMessage($diagnostic);
        $this->integer($connection->reads)->isIdenticalTo(0);
        $this->string($table->getName())->isIdenticalTo($name);
        $this->boolean($table->hasColumn('items_id'))->isTrue();
        $this->boolean($table->hasIndex('preserved_owned_index'))->isTrue();
        $this->array($table->getColumns())->hasSize(1);
        $this->array($table->getIndexes())->hasSize(1);
    }

    public function publicPlanners(): array
    {
        return array_map(static fn ($class): array => [$class], [TypedItemMigration::class, StagedTypedItemMigration::class,
            MotherboardSubjects::class, MemorySubjects::class, HardDriveSubjects::class,
            BatterySubjects::class, PowerSupplySubjects::class]);
    }

    /**
     * @dataProvider publicPlanners
     */
    public function testPublicPlannerKeepsItsOptionalIncomingContext(string $class): void
    {
        $method = new ReflectionMethod($class, 'plan');
        $parameters = $method->getParameters();
        $this->boolean($method->isPublic())->isTrue();
        $this->array($parameters)->hasSize(2);
        $this->boolean($parameters[0]->isOptional())->isFalse();
        $this->boolean($parameters[1]->isOptional())->isTrue();
        $this->variable($parameters[1]->getDefaultValue())->isNull();
    }

    public function testInspectedTablePlanningRemainsAnInternalExtensionBoundary(): void
    {
        foreach ([TypedItemMigration::class, StagedTypedItemMigration::class] as $class) {
            $this->boolean((new ReflectionMethod($class, 'planInspectedTable'))->isProtected())->isTrue();
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
        throw new LogicException('Wrong-owner planning acquired a platform.');
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        ++$this->reads;
        throw new LogicException('Wrong-owner planning acquired a catalogue.');
    }
}

final class ComponentPlanningTypedFixture extends TypedItemMigration
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

final class ComponentPlanningStagedFixture extends StagedTypedItemMigration
{
    protected function phase(): string
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
