<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Entity\ItemDeviceProcessor;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\ProcessorSubjects;
use itsmng\Database\Migration\V220\TypedItemMigration;
use itsmng\Database\Orm;

// This control never loads application bootstrap or database configuration.
// The optional source/autoloader arguments permit comparing two frozen trees
// against the same installed dependencies without copying a vendor directory.
$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$autoload = $argv[3] ?? $source . '/vendor/autoload.php';
if ($source === false || !is_file($autoload)) {
    exit("Usage: php processor-subjects-metadata.php [--required-shapes|--check] [source] [vendor/autoload.php]\n");
}
require $autoload;
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('itsmng\\', $source . '/src');
$loader->register(true);
$mode = ($argv[1] ?? null) === '--required-shapes' ? '--required-shapes' : '--check';
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function outcome(callable $operation): array
{
    try {
        return ['value' => $operation()];
    } catch (InvalidArgumentException $error) {
        return ['exception' => $error::class, 'message' => $error->getMessage()];
    }
}
$shapes = [];
foreach ([new MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
    $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
    $em = new EntityManager($connection, Orm::configuration($platform));
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $current = (new BaselineSchema())->build($platform);
    $legacy = (new Baseline())->build($platform);
    foreach ($metadata as $record) {
        foreach ($record->fieldMappings as $property => $field) {
            $attributes = (new ReflectionProperty($record->name, $property))->getAttributes(DiscriminatorKey::class);
            if (!$attributes) {
                continue;
            }
            $key = $attributes[0]->newInstance();
            if ($key->emptyValue !== null || $key->fallbackProperty !== null) {
                continue;
            }
            $table = $current->getTable($record->getTableName());
            $shapes[$platform::class]['current'][$table->getName()] = [
                'sql' => $platform->getCreateTableSQL($table),
                'check' => $key->requiredCheckSql($platform, $record, $property),
                'projection' => $field->columnDefinition,
            ];
            if (method_exists($record->name, 'withReference')) {
                $entity = new $record->name();
                $kind = array_key_first(EntityRegistry::discriminatedReferences($table->getName())['items_id']['selections']);
                $association = $entity::referenceAssociation($kind);
                $column = $record->associationMappings[$association]->joinColumns[0]->name;
                $guards = [];
                foreach ([['itemtype' => null], ['itemtype' => ''], ['itemtype' => 'Unsupported'],
                    ['itemtype' => $kind, 'items_id' => false], ['itemtype' => $kind, 'items_id' => -1],
                    ['itemtype' => $kind, 'items_id' => 0], ['itemtype' => $kind],
                    ['itemtype' => $kind, 'items_id' => 7, $column => 8],
                    ['itemtype' => $kind, 'items_id' => 7]] as $input) {
                    $guards[] = outcome(static fn () => $entity->normalizeInput($input));
                }
                $guards[] = outcome(static fn () => $entity::withReference([], '', 0));
                $shapes[$platform::class]['guards'][$table->getName()] = $guards;
            }
        }
    }
    // Frozen required projection declarations exercise the late-static dispatch
    // separately from current metadata; only the new optional stage is excluded.
    foreach (glob($source . '/src/Database/Migration/V220/*.php') as $file) {
        $class = 'itsmng\\Database\\Migration\\V220\\' . basename($file, '.php');
        if (!class_exists($class) || !is_subclass_of($class, TypedItemMigration::class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->getMethod('allowsEmptyReference')->invoke(null)) {
            continue;
        }
        foreach ($reflection->getMethod('tables')->invoke(new $class()) as $name) {
            $table = clone $legacy->getTable($name);
            $class::configureTable($table);
            $shapes[$platform::class]['frozen'][$class][$name] = [$platform->getCreateTableSQL($table), $class::checkSql($name)];
        }
    }
    foreach ($shapes[$platform::class] as &$section) {
        ksort($section);
    }
    unset($section);
    verify(!$connection->isConnected(), 'Metadata and SQL declaration inspection never connects');
    if ($mode === '--check') {
        $record = $em->getClassMetadata(ItemDeviceProcessor::class);
        $key = (new ReflectionProperty(ItemDeviceProcessor::class, 'items_id'))->getAttributes(DiscriminatorKey::class)[0]->newInstance();
        $table = $current->getTable('glpi_items_deviceprocessors');
        $frozen = clone $legacy->getTable($table->getName());
        ProcessorSubjects::configureTable($frozen, $platform);
        // Quoting belongs to the provider; the CASE structure must match exactly.
        $unquote = static fn ($sql) => str_replace(['`', '"'], '', $sql);
        verify($unquote($frozen->getColumn('items_id')->getColumnDefinition()) === $unquote($key->declaration($platform, $record, 'items_id')), 'Frozen and current optional identity have identical CASE structure');
        verify(!$table->getColumn('items_id')->getNotnull() && $table->getColumn('items_id')->getDefault() === null && count($table->getForeignKeys()) === 5, 'Optional projection and five owning FKs belong to current metadata');
        verify(str_contains($key->subjectCheckSql($platform, $record, 'items_id'), 'IS NULL') && str_contains($key->subjectCheckSql($platform, $record, 'items_id'), '>= 1'), 'Current stock and positive selected identifier are explicit CHECK branches');
    }
}
if ($mode === '--required-shapes') {
    echo json_encode($shapes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit;
}
verify(count(EntityRegistry::tables()) === 357 && array_sum(array_map(count(...), ForeignKeys::relations())) === 1087, 'One new owning Computer association extends the audited 357-table model');
$reference = EntityRegistry::discriminatedReferences('glpi_items_deviceprocessors')['items_id'];
verify($reference['empty_value'] === 0 && array_keys($reference['selections']) === ['Computer'], 'Stock identity and supported parent derive from the owning metadata');
$processor = new ItemDeviceProcessor();
foreach ([null, ''] as $kind) {
    verify($processor->normalizeInput(['itemtype' => $kind, 'items_id' => 0]) === ['itemtype' => null, 'computers_id' => null], 'Both legacy stock spellings normalize to canonical NULL stock');
}
verify($processor->normalizeInput(ItemDeviceProcessor::withReference(['computers_id' => 7], '', 0)) === ['itemtype' => null, 'computers_id' => null], 'Optional cloning removes the source owner before selecting stock');
foreach ([['itemtype' => null, 'items_id' => 7], ['itemtype' => '', 'computers_id' => 7],
    ['itemtype' => 'Phone', 'items_id' => 7], ['itemtype' => 'Computer', 'items_id' => 0],
    ['itemtype' => 'Computer', 'items_id' => false], ['itemtype' => 'Computer', 'items_id' => 7, 'computers_id' => 8]] as $input) {
    verify(isset(outcome(static fn () => $processor->normalizeInput($input))['exception']), 'Optional selection rejects contradictory or invalid endpoints');
}
$processor->validateReference();
$processor->computer = new Computer();
$processor->computer->id = 7;
verify(isset(outcome(static fn () => $processor->validateReference())['exception']), 'Direct ORM stock cannot retain an owner');
$processor->itemtype = 'Computer';
$processor->validateReference();
$processor->computer->id = 0;
verify(isset(outcome(static fn () => $processor->validateReference())['exception']), 'Direct ORM selected parent must have a positive identifier');
echo "Nonconnecting Processor metadata, optional normalization, frozen/current projection and 1087 owning associations passed.\n";
