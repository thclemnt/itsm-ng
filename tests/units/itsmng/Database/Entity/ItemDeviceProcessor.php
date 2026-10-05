<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Entity;

use Doctrine\ORM\Mapping\JoinColumn;
use itsmng\Database\Entity\ItemDeviceProcessor as Processor;
use itsmng\Database\Entity\ItemDeviceHardDrive;
use itsmng\Database\Entity\ItemDeviceMemory;
use itsmng\Database\Entity\ItemDeviceMotherboard;
use itsmng\Database\Entity\ItemDeviceBattery;
use itsmng\Database\Entity\ItemDevicePowerSupply;
use itsmng\Database\Mapping\DiscriminatedBy;

class ItemDeviceProcessor extends \atoum\atoum\test
{
    public function componentTypes(): array
    {
        return array_map(static fn ($class): array => [$class], [Processor::class, ItemDeviceMotherboard::class, ItemDeviceMemory::class, ItemDeviceHardDrive::class, ItemDeviceBattery::class, ItemDevicePowerSupply::class]);
    }

    private function selections(string $class): array
    {
        $selections = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
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
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 1]))->isInstanceOf(\InvalidArgumentException::class);
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
                $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $invalid]))->isInstanceOf(\InvalidArgumentException::class);
            }
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $column => $id + 1]))->isInstanceOf(\InvalidArgumentException::class);
            foreach ([strtolower($kind), $kind . ' ', 'PluginAsset'] as $invalidKind) {
                $this->exception(static fn () => $record->normalizeInput(['itemtype' => $invalidKind, 'items_id' => $id]))->isInstanceOf(\InvalidArgumentException::class);
            }
            foreach ($selections as $otherKind => [$otherProperty, $otherColumn]) {
                if ($otherKind !== $kind) {
                    $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $otherColumn => $id]))->isInstanceOf(\InvalidArgumentException::class);
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
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(\InvalidArgumentException::class);
            $subject = new $target();
            $subject->id = 0;
            $record->$property = $subject;
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(\InvalidArgumentException::class);
            $subject->id = 4294995001;
            $record->validateReference();
            foreach ($selections as $otherKind => [$otherProperty, $otherColumn, $otherTarget]) {
                if ($otherKind === $kind) {
                    continue;
                }
                $other = new $otherTarget();
                $other->id = $subject->id;
                $record->$otherProperty = $other;
                $this->exception(static fn () => $record->validateReference())->isInstanceOf(\InvalidArgumentException::class);
                $record->$otherProperty = null;
            }
            $record->itemtype = null;
            $this->exception(static fn () => $record->validateReference())->isInstanceOf(\InvalidArgumentException::class);
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
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => 9]))->isInstanceOf(\InvalidArgumentException::class);
        }
        foreach ([null, 0, -1, true, false, 1.2, '1.2', '1e2', 'not an ID'] as $id) {
            $this->exception(static fn () => $record->normalizeInput(['itemtype' => 'Computer', 'items_id' => $id]))->isInstanceOf(\InvalidArgumentException::class);
        }
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 9]))->isInstanceOf(\InvalidArgumentException::class);
        $this->exception(static fn () => $record->normalizeInput(['itemtype' => '', 'computers_id' => 9]))->isInstanceOf(\InvalidArgumentException::class);
        $copy = ['itemtype' => 'Computer', 'items_id' => 9, 'computers_id' => 9, 'deviceprocessors_id' => 11, 'serial' => null];
        $this->array($record->normalizeInput(Processor::withReference($copy, 'Computer', 12)))->isIdenticalTo(['itemtype' => 'Computer', 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => 12]);
        $this->array($record->normalizeInput(Processor::withReference($copy, '', 0)))->isIdenticalTo(['itemtype' => null, 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => null]);
    }

    public function testMandatoryFamiliesAndRootEntityZeroKeepTheirDifferentPolicies(): void
    {
        foreach ([\itsmng\Database\Entity\ItemProject::class, \itsmng\Database\Entity\DocumentItem::class, \itsmng\Database\Entity\ReservationItem::class] as $class) {
            $required = new $class();
            $this->exception(static fn () => $required->normalizeInput(['itemtype' => null, 'items_id' => 0]))->isInstanceOf(\InvalidArgumentException::class);
            $this->exception(static fn () => $required->normalizeInput(['itemtype' => 'Computer', 'items_id' => 0]))->isInstanceOf(\InvalidArgumentException::class);
        }
        $document = new \itsmng\Database\Entity\DocumentItem();
        $rootColumn = (new \ReflectionProperty($document::class, $document::referenceAssociation('Entity')))->getAttributes(JoinColumn::class)[0]->newInstance()->name;
        $this->integer($document->normalizeInput(['itemtype' => 'Entity', 'items_id' => 0])[$rootColumn])->isIdenticalTo(0);
    }
}
