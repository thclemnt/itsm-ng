<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Entity-local input policies only: no application bootstrap or connection.
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 2));
}
if (!class_exists(Doctrine\ORM\Mapping\ClassMetadata::class)) {
    require GLPI_ROOT . '/vendor/autoload.php';
}
$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
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
$processor = new itsmng\Database\Entity\ItemDeviceProcessor();
foreach ([null, ''] as $kind) {
    foreach ([null, 0, '0'] as $identity) {
        $values = $processor->normalizeInput(['itemtype' => $kind, 'items_id' => $identity, 'frequency' => 3200]);
        $verify($values === ['itemtype' => null, 'frequency' => 3200, 'computers_id' => null], 'Legacy stock normalizes to the owning null selection without changing payload');
    }
}
$verify($processor->normalizeInput(['serial' => null]) === ['serial' => null], 'An absent subject input does not clear or invent a reference');
$verify($processor->normalizeInput(['itemtype' => 'Computer', 'items_id' => 4294990001]) === ['itemtype' => 'Computer', 'computers_id' => 4294990001], 'Assigned wide identity selects the declared owning Computer column');
$verify($processor->normalizeInput(['itemtype' => 'Computer', 'computers_id' => 4294990001]) === ['itemtype' => 'Computer', 'computers_id' => 4294990001], 'Canonical-only assignment retains the same declaration');
foreach (['Phone', 'PluginAsset', 'computer', 0, false] as $kind) {
    $reject(fn () => $processor->normalizeInput(['itemtype' => $kind, 'items_id' => 9]), 'Unsupported processor subject is refused');
}
foreach ([null, 0, -1, true, false, 1.2, '1.2', '1e2', 'not an ID'] as $id) {
    $reject(fn () => $processor->normalizeInput(['itemtype' => 'Computer', 'items_id' => $id]), 'Assigned processor requires a positive integer identifier');
}
$reject(fn () => $processor->normalizeInput(['itemtype' => null, 'items_id' => 9]), 'Stock cannot retain a legacy recipient');
$reject(fn () => $processor->normalizeInput(['itemtype' => '', 'computers_id' => 9]), 'Stock cannot retain a canonical recipient');
$reject(fn () => $processor->normalizeInput(['itemtype' => 'Computer', 'items_id' => 9, 'computers_id' => 10]), 'Canonical and legacy identities cannot disagree');
$copy = ['itemtype' => 'Computer', 'items_id' => 9, 'computers_id' => 9, 'deviceprocessors_id' => 11, 'serial' => null];
$verify($processor->normalizeInput($processor::withReference($copy, 'Computer', 12)) === ['itemtype' => 'Computer', 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => 12], 'Cloning rebinds the subject while retaining device and supplied null payload');
$verify($processor->normalizeInput($processor::withReference($copy, '', 0)) === ['itemtype' => null, 'deviceprocessors_id' => 11, 'serial' => null, 'computers_id' => null], 'Returning a copied assignment to stock clears its former canonical owner');
$processor->validateReference();
$verify(true, 'Native unassigned Processor accepts its declared stock state');
$processor->itemtype = 'Computer';
$reject(fn () => $processor->validateReference(), 'Native assigned Processor requires an owning Computer');
$processor->computer = new itsmng\Database\Entity\Computer();
$processor->computer->id = 0;
$reject(fn () => $processor->validateReference(), 'Native Computer subject zero is invalid');
$processor->computer->id = 12;
$processor->validateReference();
$verify(true, 'Native assigned Processor accepts its positive owning Computer');
$processor->itemtype = null;
$reject(fn () => $processor->validateReference(), 'Native stock cannot retain its Computer');
foreach ([itsmng\Database\Entity\ItemProject::class, itsmng\Database\Entity\DocumentItem::class, itsmng\Database\Entity\ReservationItem::class] as $class) {
    $required = new $class();
    $reject(fn () => $required->normalizeInput(['itemtype' => null, 'items_id' => 0]), 'Existing mandatory family continues to reject a stock subject');
    $reject(fn () => $required->normalizeInput(['itemtype' => 'Computer', 'items_id' => 0]), 'Existing mandatory Computer subject continues to reject zero');
}
$document = new itsmng\Database\Entity\DocumentItem();
$rootColumn = (new ReflectionProperty($document::class, $document::referenceAssociation('Entity')))->getAttributes(Doctrine\ORM\Mapping\JoinColumn::class)[0]->newInstance()->name;
$verify($document->normalizeInput(['itemtype' => 'Entity', 'items_id' => 0])[$rootColumn] === 0, 'An existing selected root Entity zero remains a real subject');
echo "Processor and existing mandatory/root entity input policies: $assertions assertions passed without bootstrap or connection.\n";
