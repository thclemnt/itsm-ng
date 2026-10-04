<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\ItemDeviceHardDrive;
use itsmng\Database\Entity\ItemDeviceMemory;
use itsmng\Database\Entity\ItemDeviceMotherboard;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\HardDriveSubjects20261013;
use itsmng\Database\Migration\MemorySubjects20261013;
use itsmng\Database\Migration\MotherboardSubjects20261013;
use itsmng\Database\Orm;

$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$autoload = $argv[3] ?? $source . '/vendor/autoload.php';
if ($source === false || !is_file($autoload)) {
    exit("Usage: php component-subjects-metadata.php [config] [source] [vendor/autoload.php]\n");
}
require $autoload;
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('itsmng\\', $source . '/src');
$loader->register(true);
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$families = [
    [ItemDeviceMotherboard::class, MotherboardSubjects20261013::class, ['Computer']],
    [ItemDeviceMemory::class, MemorySubjects20261013::class, ['Computer', 'NetworkEquipment', 'Peripheral', 'Printer']],
    [ItemDeviceHardDrive::class, HardDriveSubjects20261013::class, ['Computer', 'Peripheral', 'NetworkEquipment', 'Printer', 'Phone']],
];
foreach ([new MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
    $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
    $em = new EntityManager($connection, Orm::configuration($platform));
    $schema = (new BaselineSchema())->build($platform);
    $historical = (new Baseline20261001())->build($platform);
    foreach ($families as [$class, $migration, $kinds]) {
        $metadata = $em->getClassMetadata($class);
        $table = $schema->getTable($metadata->getTableName());
        $key = (new ReflectionProperty($class, 'items_id'))->getAttributes(DiscriminatorKey::class)[0]->newInstance();
        $reference = EntityRegistry::discriminatedReferences($table->getName())['items_id'];
        verify(array_keys($reference['selections']) === $kinds && $reference['empty_value'] === 0, 'Supported application kinds and stock policy belong to the entity properties');
        verify($key->exactDiscriminator && $key->emptyValue === 0 && !$table->getColumn('items_id')->getNotnull() && $table->getColumn('items_id')->getDefault() === null, 'Exact nullable stock projection is an explicit property policy');
        $frozen = clone $historical->getTable($table->getName());
        $migration::configureTable($frozen, $platform);
        $unquote = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
        verify($unquote($frozen->getColumn('items_id')->getColumnDefinition()) === $unquote($key->declaration($platform, $metadata, 'items_id')), 'Current and frozen generated CASE shapes match for every kind');
        verify(count($table->getForeignKeys()) === 4 + count($kinds), 'Every new subject has a real FK alongside device/entity/location/state ownership');
        foreach ($reference['selections'] as $kind => $selection) {
            $name = ForeignKeys::name($table->getName(), $selection['column']);
            verify($table->hasForeignKey($name) && trim($table->getForeignKey($name)->getForeignTableName(), '`"') === $selection['target'], 'Every discriminator branch points to its actual target table');
            verify($table->getColumn($selection['column'])->getNotnull() === false, 'An unselected association is nullable');
        }
        verify(str_contains($key->subjectCheckSql($platform, $metadata, 'items_id'), 'IS NULL') && str_contains($key->subjectCheckSql($platform, $metadata, 'items_id'), '>= 1'), 'Native CHECK contains stock and positive selected-owner branches');
    }
    verify(!$connection->isConnected(), 'Metadata inspection never connects or loads application configuration');
}
verify(count(EntityRegistry::tables()) === 357 && array_sum(array_map(count(...), ForeignKeys::relations())) === 1080, 'Ten real component subject associations extend the existing 357-table model');
echo "Nonconnecting component subjects: both platform declarations, ten FKs and frozen/current projections passed.\n";
