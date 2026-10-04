<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity\ItemDeviceHardDrive;
use itsmng\Database\Entity\ItemDeviceMemory;
use itsmng\Database\Entity\ItemDeviceMotherboard;
use itsmng\Database\Mapping\DiscriminatedBy;
use Doctrine\ORM\Mapping\JoinColumn;

// Pure property/input contract: no application bootstrap or database connection.
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 2));
}
if (!class_exists(Doctrine\ORM\Mapping\ClassMetadata::class)) {
    require GLPI_ROOT . '/vendor/autoload.php';
}
$assertions = 0;
$verify = static function (bool $ok, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $operation, string $message) use ($verify): void {
    try {
        $operation();
        throw new LogicException($message);
    } catch (InvalidArgumentException) {
        $verify(true, $message);
    }
};
$same = static function (array $actual, array $expected): bool {
    if (count($actual) !== count($expected)) {
        return false;
    }
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
            return false;
        }
    }
    return true;
};
foreach ([ItemDeviceMotherboard::class, ItemDeviceMemory::class, ItemDeviceHardDrive::class] as $class) {
    $record = new $class();
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
    $empty = array_fill_keys(array_column($selections, 1), null);
    foreach ([null, ''] as $kind) {
        foreach ([null, 0, '0'] as $identity) {
            $verify($record->normalizeInput(['itemtype' => $kind, 'items_id' => $identity, 'serial' => null]) === ['itemtype' => null, 'serial' => null] + $empty, 'Legacy stock clears every declared owner without losing supplied null payload');
        }
    }
    $verify($record->normalizeInput(['serial' => null]) === ['serial' => null], 'An absent endpoint input preserves ownership');
    foreach ($selections as $kind => [$property, $column, $target]) {
        $id = 4294995001;
        $selected = $empty;
        $selected[$column] = $id;
        $verify($record->normalizeInput(['itemtype' => $kind, 'items_id' => $id]) === ['itemtype' => $kind] + $selected, 'Wide legacy ID selects exactly its entity-declared owner');
        $verify($same($record->normalizeInput(['itemtype' => $kind, $column => $id]), ['itemtype' => $kind] + $selected), 'Canonical-only input retains every exact typed key/value of the selected owner');
        foreach ([0, null, -1, true, false, 1.2, '1.2', '1e2', 'invalid'] as $invalid) {
            $reject(fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $invalid]), 'Assigned components require positive integral identifiers');
        }
        $reject(fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $column => $id + 1]), 'Legacy and canonical selected identifiers cannot disagree');
        foreach ([strtolower($kind), $kind . ' ', 'PluginAsset'] as $invalidKind) {
            $reject(fn () => $record->normalizeInput(['itemtype' => $invalidKind, 'items_id' => $id]), 'Kinds are exact, not collation-folded or inferred');
        }
        $copy = ['itemtype' => $kind, 'items_id' => $id, 'serial' => null] + $selected;
        foreach ($selections as $newKind => [$newProperty, $newColumn]) {
            $expected = $empty;
            $expected[$newColumn] = $id + 1;
            $verify($record->normalizeInput($class::withReference($copy, $newKind, $id + 1)) === ['itemtype' => $newKind, 'serial' => null] + $expected, 'Clone/transfer retargeting clears all previous kind columns');
        }
        $verify($record->normalizeInput($class::withReference($copy, '', 0)) === ['itemtype' => null, 'serial' => null] + $empty, 'Returning a copied binding to stock removes every former owner');
        $record->itemtype = $kind;
        $reject(fn () => $record->validateReference(), 'Direct ORM selected kind requires its declared association');
        $subject = new $target();
        $subject->id = 0;
        $record->$property = $subject;
        $reject(fn () => $record->validateReference(), 'Direct ORM selected zero is not a stock shortcut');
        $subject->id = $id;
        $record->validateReference();
        $verify(true, 'Direct ORM accepts a positive selected subject');
        foreach ($selections as $otherKind => [$otherProperty, $otherColumn, $otherTarget]) {
            if ($otherKind === $kind) {
                continue;
            }
            $other = new $otherTarget();
            $other->id = $id;
            $record->$otherProperty = $other;
            $reject(fn () => $record->validateReference(), 'Two simultaneous subject owners are invalid');
            $record->$otherProperty = null;
            $reject(fn () => $record->normalizeInput(['itemtype' => $kind, 'items_id' => $id, $otherColumn => $id]), 'Input cannot retain an unselected canonical owner');
        }
        $record->itemtype = null;
        $reject(fn () => $record->validateReference(), 'Direct ORM stock cannot retain any subject');
        $record->$property = null;
    }
    $record->validateReference();
    $verify(true, 'Direct ORM stock is valid only with all owners cleared');
    $reject(fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 1]), 'Stock cannot retain a positive legacy subject');
}
echo "Component property-owned subject/stock inputs: $assertions assertions passed without bootstrap or connection.\n";
