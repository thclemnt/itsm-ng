<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\JoinColumn;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use atoum\atoum\test;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\DocumentItem;
use itsmng\Database\Entity\ItemDeviceBattery;
use itsmng\Database\Entity\ItemDeviceHardDrive;
use itsmng\Database\Entity\ItemDeviceMemory;
use itsmng\Database\Entity\ItemDeviceMotherboard;
use itsmng\Database\Entity\ItemDevicePowerSupply;
use itsmng\Database\Entity\ItemDeviceProcessor as Processor;
use itsmng\Database\Entity\ItemDeviceSensor;
use itsmng\Database\Entity\ItemProject;
use itsmng\Database\Entity\ReservationItem;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\EntityScopeOwner;
use itsmng\Database\Migration\SensorSubjects\Definition as SensorSubjects;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\BatterySubjects;
use itsmng\Database\Migration\V220\HardDriveSubjects;
use itsmng\Database\Migration\V220\MemorySubjects;
use itsmng\Database\Migration\V220\MotherboardSubjects;
use itsmng\Database\Migration\V220\PowerSupplySubjects;
use itsmng\Database\Migration\V220\ProcessorSubjects;
use itsmng\Database\Orm;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 4) . '/fixtures/DisconnectedSchemaConnection.php';

class ItemDeviceProcessor extends test
{
    public function testComponentMetadataOwnsScopeForeignKeysAndFrozenStockDeclarations(): void
    {
        $families = [
            [Processor::class, ProcessorSubjects::class, ['Computer']],
            [ItemDeviceMotherboard::class, MotherboardSubjects::class, ['Computer']],
            [ItemDeviceMemory::class, MemorySubjects::class, ['Computer', 'NetworkEquipment', 'Peripheral', 'Printer']],
            [ItemDeviceHardDrive::class, HardDriveSubjects::class, ['Computer', 'Peripheral', 'NetworkEquipment', 'Printer', 'Phone']],
            [ItemDeviceBattery::class, BatterySubjects::class, ['Computer', 'Peripheral', 'Phone', 'Printer']],
            [ItemDevicePowerSupply::class, PowerSupplySubjects::class, ['Computer', 'NetworkEquipment', 'Enclosure']],
            [ItemDeviceSensor::class, SensorSubjects::class, ['Computer', 'Peripheral']],
        ];
        foreach ([new MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            try {
                $this->object($connection->getDatabasePlatform())->isIdenticalTo($platform);
                $em = new EntityManager($connection, Orm::configuration($platform));
                $schema = (new BaselineSchema())->build($platform);
                $historical = (new Baseline())->build($platform);
                $historicalSql = $historical->toSql($platform);
                foreach ($families as [$class, $migration, $kinds]) {
                    $metadata = $em->getClassMetadata($class);
                    $table = $schema->getTable($metadata->getTableName());
                    $scopeOwners = array_filter($metadata->associationMappings, static fn ($association): bool =>
                        (new ReflectionProperty($class, $association->fieldName))->getAttributes(EntityScopeOwner::class) !== []);
                    $this->boolean(count($scopeOwners) === 1)->isTrue('Exactly one property owns the component binding entity scope');
                    $scopeOwner = reset($scopeOwners);
                    $this->boolean($scopeOwner->isToOneOwningSide() && count($scopeOwner->joinColumns) === 1 && !$scopeOwner->joinColumns[0]->nullable)->isTrue('Cached entity scope belongs to a required owning definition association');
                    $definition = $em->getClassMetadata($scopeOwner->targetEntity);
                    $this->boolean($definition->hasField('designation') && $definition->hasAssociation('entities') && $definition->hasField('is_recursive'))->isTrue('The owning definition supplies its real entity and recursion scope');
                    $this->boolean(EntityRegistry::entityScopeOwner($table->getName()) === ['column' => $scopeOwner->joinColumns[0]->name, 'target' => $definition->getTableName()])->isTrue('Forwarding and replacement compatibility roles derive from the same property declaration');
                    $this->boolean((new ReflectionProperty($class, 'entities'))->getAttributes(EntityScopeOwner::class) === [])->isTrue('The cached entity projection does not own itself');
                    foreach ($metadata->associationMappings as $association) {
                        $property = new ReflectionProperty($class, $association->fieldName);
                        if ($property->getAttributes(DiscriminatedBy::class) !== []) {
                            $this->boolean($property->getAttributes(EntityScopeOwner::class) === [])->isTrue('An attached asset remains distinct from the definition scope owner');
                        }
                    }
                    $key = (new ReflectionProperty($class, 'items_id'))->getAttributes(DiscriminatorKey::class)[0]->newInstance();
                    $reference = EntityRegistry::discriminatedReferences($table->getName())['items_id'];
                    $this->boolean(array_keys($reference['selections']) === $kinds && $reference['empty_value'] === 0)->isTrue('Supported application kinds and stock policy belong to the entity properties');
                    $this->boolean($key->exactDiscriminator && $key->emptyValue === 0 && !$table->getColumn('items_id')->getNotnull() && $table->getColumn('items_id')->getDefault() === null)->isTrue('Exact nullable stock projection is an explicit property policy');
                    $frozen = clone $historical->getTable($table->getName());
                    $migration::configureTable($frozen, $platform);
                    $unquote = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
                    $this->boolean($unquote($frozen->getColumn('items_id')->getColumnDefinition()) === $unquote($key->declaration($platform, $metadata, 'items_id')))->isTrue('Current and frozen generated CASE shapes match for every kind');
                    $this->boolean(count($table->getForeignKeys()) === 4 + count($kinds))->isTrue('Every new subject has a real FK alongside device/entity/location/state ownership');
                    foreach ($reference['selections'] as $kind => $selection) {
                        $name = ForeignKeys::name($table->getName(), $selection['column']);
                        $this->boolean($table->hasForeignKey($name) && trim($table->getForeignKey($name)->getForeignTableName(), '`"') === $selection['target'])->isTrue('Every discriminator branch points to its actual target table');
                        $this->boolean($table->getColumn($selection['column'])->getNotnull() === false)->isTrue('An unselected association is nullable');
                    }
                    $this->boolean(str_contains($key->subjectCheckExpression($platform, $metadata, 'items_id'), 'IS NULL') && str_contains($key->subjectCheckExpression($platform, $metadata, 'items_id'), '>= 1'))->isTrue('Native CHECK predicate contains stock and positive selected-owner branches');
                }
                $this->array($historical->toSql($platform))->isIdenticalTo($historicalSql, 'Configuring frozen component copies leaves the original baseline unchanged');
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
                unset($em, $schema, $historical, $metadata, $definition);
            }
        }
    }

    public function componentTypes(): array
    {
        return array_map(static fn ($class): array => [$class], [Processor::class, ItemDeviceMotherboard::class, ItemDeviceMemory::class, ItemDeviceHardDrive::class, ItemDeviceBattery::class, ItemDevicePowerSupply::class, ItemDeviceSensor::class]);
    }

    private function selections(string $class): array
    {
        $selections = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                $column = $property->getAttributes(JoinColumn::class)[0]->newInstance()->name;
                foreach ($binding->values as $kind) {
                    $selections[$kind] = [$property->name, $column, $property->getType()->getName()];
                }
            }
        }
        $this->array($selections)->isNotEmpty();
        return $selections;
    }

    /**
     * @dataProvider componentTypes
     */
    public function testStockClearsOwnersButAbsentInputPreservesPayload(string $class): void
    {
        $record = new $class();
        $empty = array_fill_keys(array_column($this->selections($class), 1), null);
        foreach ([null, ''] as $kind) {
            foreach ([null, 0, '0'] as $identity) {
                $this->array($record->normalizeInput(['itemtype' => $kind, 'items_id' => $identity, 'serial' => null]))->isIdenticalTo(['itemtype' => null, 'serial' => null] + $empty);
            }
        }
        $this->array($record->normalizeInput(['serial' => null]))->isIdenticalTo(['serial' => null]);
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 1]))->isInstanceOf(InvalidArgumentException::class);
        $record->validateReference();
    }

    /**
     * @dataProvider componentTypes
     */
    public function testAssignedInputsSelectExactlyOnePropertyOwnedSubject(string $class): void
    {
        $record = new $class();
        $selections = $this->selections($class);
        $empty = array_fill_keys(array_column($selections, 1), null);
        foreach ($selections as $kind => [$property, $column, $target]) {
            $id = 4294995001;
            $selected = $empty;
            $selected[$column] = $id;
            $this->array($record->normalizeInput(['itemtype' => $kind, 'items_id' => $id]))->isIdenticalTo(['itemtype' => $kind] + $selected);
            $actual = $record->normalizeInput(['itemtype' => $kind, $column => $id]);
            $expected = ['itemtype' => $kind] + $selected;
            ksort($actual);
            ksort($expected);
            $this->array($actual)->isIdenticalTo($expected);
            foreach ([0, null, -1, true, false, 1.2, '1.2', '1e2', 'invalid'] as $invalid) {
                $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $invalid]))->isInstanceOf(InvalidArgumentException::class);
            }
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $column => $id + 1]))->isInstanceOf(InvalidArgumentException::class);
            foreach ([strtolower($kind), $kind . ' ', 'PluginAsset'] as $invalidKind) {
                $this->exception(static fn () => $record->normalizeInput(['itemtype' => $invalidKind, 'items_id' => $id]))->isInstanceOf(InvalidArgumentException::class);
            }
            foreach ($selections as $otherKind => [$otherProperty, $otherColumn]) {
                if ($otherKind !== $kind) {
                    $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $otherColumn => $id]))->isInstanceOf(InvalidArgumentException::class);
                }
            }
        }
    }

    /**
     * @dataProvider componentTypes
     */
    public function testCloningRetargetsAllOwnersAndPreservesSuppliedNull(string $class): void
    {
        $record = new $class();
        $selections = $this->selections($class);
        $empty = array_fill_keys(array_column($selections, 1), null);
        foreach ($selections as $kind => [$property, $column]) {
            $id = 4294995001;
            $selected = $empty;
            $selected[$column] = $id;
            $copy = ['itemtype' => $kind, 'items_id' => $id, 'serial' => null] + $selected;
            foreach ($selections as $newKind => [$newProperty, $newColumn]) {
                $expected = $empty;
                $expected[$newColumn] = $id + 1;
                $this->array($record->normalizeInput($class::withReference($copy, $newKind, $id + 1)))->isIdenticalTo(['itemtype' => $newKind, 'serial' => null] + $expected);
            }
            $this->array($record->normalizeInput($class::withReference($copy, '', 0)))->isIdenticalTo(['itemtype' => null, 'serial' => null] + $empty);
        }
    }

    /**
     * @dataProvider componentTypes
     */
    public function testDirectAssociationsRejectMissingZeroAndMultipleOwners(string $class): void
    {
        $record = new $class();
        $selections = $this->selections($class);
        foreach ($selections as $kind => [$property, $column, $target]) {
            $record->itemtype = $kind;
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(InvalidArgumentException::class);
            $subject = new $target();
            $subject->id = 0;
            $record->$property = $subject;
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(InvalidArgumentException::class);
            $subject->id = 4294995001;
            $record->validateReference();
            foreach ($selections as $otherKind => [$otherProperty, $otherColumn, $otherTarget]) {
                if ($otherKind === $kind) {
                    continue;
                }
                $other = new $otherTarget();
                $other->id = $subject->id;
                $record->$otherProperty = $other;
                $this->exception(static fn () => $record->validateReference())->isInstanceOf(InvalidArgumentException::class);
                $record->$otherProperty = null;
            }
            $record->itemtype = null;
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(InvalidArgumentException::class);
            $record->$property = null;
        }
        $record->validateReference();
    }

    public function testProcessorPayloadAndExactComputerKind(): void
    {
        $record = new Processor();
        foreach ([null, ''] as $kind) {
            foreach ([null, 0, '0'] as $identity) {
                $this->array($record->normalizeInput(['itemtype' => $kind, 'items_id' => $identity, 'frequency' => 3200]))->isIdenticalTo(['itemtype' => null, 'frequency' => 3200, 'computers_id' => null]);
            }
        }
        $this->array($record->normalizeInput(['itemtype' => 'Computer', 'items_id' => 4294990001]))->isIdenticalTo(['itemtype' => 'Computer', 'computers_id' => 4294990001]);
        $this->array($record->normalizeInput(['itemtype' => 'Computer', 'computers_id' => 4294990001]))->isIdenticalTo(['itemtype' => 'Computer', 'computers_id' => 4294990001]);
        foreach (['Phone', 'PluginAsset', 'computer', 0, false] as $kind) {
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => 9]))->isInstanceOf(InvalidArgumentException::class);
        }
        foreach ([null, 0, -1, true, false, 1.2, '1.2', '1e2', 'not an ID'] as $id) {
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => 'Computer', 'items_id' => $id]))->isInstanceOf(InvalidArgumentException::class);
        }
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 9]))->isInstanceOf(InvalidArgumentException::class);
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => '', 'computers_id' => 9]))->isInstanceOf(InvalidArgumentException::class);
        $copy = ['itemtype' => 'Computer', 'items_id' => 9, 'computers_id' => 9, 'deviceprocessors_id' => 11, 'serial' => null];
        $this->array($record->normalizeInput(Processor::withReference($copy, 'Computer', 12)))->isIdenticalTo(['itemtype' => 'Computer', 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => 12]);
        $this->array($record->normalizeInput(Processor::withReference($copy, '', 0)))->isIdenticalTo(['itemtype' => null, 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => null]);
    }

    public function testMandatoryFamiliesAndRootEntityZeroKeepTheirDifferentPolicies(): void
    {
        foreach ([ItemProject::class, DocumentItem::class, ReservationItem::class] as $class) {
            $required = new $class();
            $this->exception(static fn () => $required->normalizeInput(['itemtype' => null, 'items_id' => 0]))->isInstanceOf(InvalidArgumentException::class);
            $this->exception(static fn () => $required->normalizeInput(['itemtype' => 'Computer', 'items_id' => 0]))->isInstanceOf(InvalidArgumentException::class);
        }
        $document = new DocumentItem();
        $rootColumn = (new ReflectionProperty($document::class, $document::referenceAssociation('Entity')))->getAttributes(JoinColumn::class)[0]->newInstance()->name;
        $this->integer($document->normalizeInput(['itemtype' => 'Entity', 'items_id' => 0])[$rootColumn])->isIdenticalTo(0);
    }
}
