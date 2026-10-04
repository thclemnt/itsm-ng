<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\Alert;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Entity\Domain;
use itsmng\Database\Entity\ObjectLock;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\NativeTimestampSchema;
use itsmng\Database\Orm;

$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$autoload = $argv[3] ?? $source . '/vendor/autoload.php';
if ($source === false || !is_file($autoload)) {
    exit("Usage: php native-timestamps-metadata.php [config] [source] [vendor/autoload.php]\n");
}
require $autoload;
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('itsmng\\', $source . '/src');
$loader->register(true);
require __DIR__ . '/fixtures/NativeTemporalProbe.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$cohort = [Computer::class => ['date_mod', 'date_creation'], Alert::class => ['date'], Domain::class => ['date_expiration', 'date_mod', 'date_creation'], ObjectLock::class => ['date_mod']];
foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
    $connection = DriverManager::getConnection($platform instanceof PostgreSQLPlatform
        ? ['driver' => 'pdo_pgsql', 'serverVersion' => '16.0']
        : ['driver' => 'pdo_mysql', 'serverVersion' => $platform instanceof MariaDBPlatform ? '10.11.0-MariaDB' : '8.4.0']);
    try {
        $em = new EntityManager($connection, Orm::configuration($platform));
        $current = (new BaselineSchema())->build($platform, false);
        $historical = (new Baseline20261001())->build($platform);
        $declarations = NativeTimestampSchema::declarations($em->getMetadataFactory()->getAllMetadata());
        verify(array_sum(array_map('count', $declarations)) === 7, 'Exactly the first seven temporal properties own native storage');
        foreach ($cohort as $class => $properties) {
            $metadata = $em->getClassMetadata($class);
            $mapped = (new SchemaTool($em))->getSchemaFromMetadata([$metadata])->getTable($metadata->getTableName());
            foreach ($properties as $property) {
                $field = $metadata->getFieldMapping($property);
                $column = $mapped->getColumn($field->columnName);
                verify($field->type === Types::DATETIMETZ_MUTABLE && !$metadata->isVersioned && $metadata->versionField === null, 'Existing datetime hydration survives without an optimistic version field');
                if ($class === ObjectLock::class) {
                    verify(
                        $field->generated === Doctrine\ORM\Mapping\ClassMetadata::GENERATED_ALWAYS && !$field->notInsertable && !$field->notUpdatable
                        && !in_array('date_mod', itsmng\Database\EntityRegistry::readOnlyColumns($metadata->getTableName()), true),
                        'The automatic clock refreshes its managed outcome while keeping explicit writes enabled'
                    );
                }
                verify($platform instanceof AbstractMySQLPlatform ? str_starts_with($column->getColumnDefinition(), 'TIMESTAMP ') : $column->getColumnDefinition() === null, 'SchemaTool obtains native storage from the property without historical input');
                foreach ([$current->getTable($metadata->getTableName())->getColumn($field->columnName), $historical->getTable($metadata->getTableName())->getColumn($field->columnName)] as $expected) {
                    // The frozen Domain expiration declaration contains two
                    // spaces; SQL whitespace is not a different temporal policy.
                    $physical = static fn ($value): string => preg_replace('/\s+/', ' ', trim((string)$value));
                    verify(
                        $expected->getNotnull() === $column->getNotnull()
                        && $platform->getDefaultValueDeclarationSQL($expected->toArray(true)) === $platform->getDefaultValueDeclarationSQL($column->toArray(true))
                        && $expected->getComment() === $column->getComment() && $physical($expected->getColumnDefinition()) === $physical($column->getColumnDefinition()),
                        'Current property projection converges on the retained historical column semantics'
                    );
                }
            }
        }
        $config = Orm::configuration($platform);
        $config->setMetadataDriverImpl(new AttributeDriver([], $platform));
        $probeEm = new EntityManager($connection, $config);
        $probeMetadata = $probeEm->getClassMetadata(NativeTemporalProbe::class);
        $probe = (new SchemaTool($probeEm))->getSchemaFromMetadata([$probeMetadata])->getTable('glpi_native_temporal_probe');
        $policies = NativeTimestampSchema::declarations([$probeMetadata])['glpi_native_temporal_probe'];
        $stale = clone $probe;
        $stale->modifyColumn('nullable_instant', ['typeName' => Types::STRING, 'notnull' => true, 'default' => '2001-01-01 00:00:00', 'comment' => 'Old storage', 'columnDefinition' => 'DATETIME NOT NULL']);
        $stale->modifyColumn('unmarked_wall_time', ['comment' => 'Unmarked historical detail']);
        $stale->addIndex(['nullable_instant'], 'retained_temporal_index');
        NativeTimestampSchema::replaceOwnedColumns($stale, $probe, $policies);
        verify(
            $stale->getColumn('nullable_instant')->getTypeName() === Types::DATETIMETZ_MUTABLE
            && !$stale->getColumn('nullable_instant')->getNotnull() && $stale->getColumn('nullable_instant')->getDefault() === null
            && $stale->getColumn('nullable_instant')->getComment() === "Property's instant"
            && $stale->getColumn('nullable_instant')->getColumnDefinition() === $probe->getColumn('nullable_instant')->getColumnDefinition(),
            'Property metadata replaces a stale existing temporal definition, including default/null/comment'
        );
        verify($stale->hasIndex('retained_temporal_index') && $stale->getColumn('unmarked_wall_time')->getComment() === 'Unmarked historical detail', 'Owned temporal replacement preserves supporting indexes and unmarked historical fields');
        verify($probe->getColumn('unmarked_wall_time')->getColumnDefinition() === null, 'Unmarked datetimetz is not globally rewritten');
        $field = clone $probeMetadata->getFieldMapping('nullable_instant');
        $field->type = Types::INTEGER;
        $refused = false;
        try {
            (new NativeTimestamp())->declaration($platform, $field);
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        verify($refused, 'An incompatible scalar cannot be labelled as a native temporal property');
        $lock = $em->getClassMetadata(ObjectLock::class);
        $policy = $declarations[$lock->getTableName()]['date_mod'];
        $sql = (new BaselineSchema())->toSql($platform, false);
        foreach ($policy->touchSql($platform, $lock->getTableName(), 'date_mod') as $statement) {
            verify(count(array_filter($sql, static fn ($candidate) => $candidate === $statement)) === 1, 'Current touch DDL is emitted once from the property, replacing its inherited historical definition');
        }
    } finally {
        $connection->close();
    }
}
echo "Native timestamp property metadata, existing-column ownership, defaults/comments and unchanged hydration passed without connecting.\n";
