<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use itsmng\Database\BaselineSchema;
use itsmng\Database\CurrentSchema as Projection;
use itsmng\Database\Entity\Config;
use itsmng\Database\Entity\CronTask;
use itsmng\Database\Entity\CronTaskLog;
use itsmng\Database\Entity\DeviceBatteryModel;
use itsmng\Database\Entity\DeviceCaseModel;
use itsmng\Database\Entity\DeviceControlModel;
use itsmng\Database\Entity\DeviceDriveModel;
use itsmng\Database\Entity\DeviceFirmwareModel;
use itsmng\Database\Entity\DeviceGenericModel;
use itsmng\Database\Entity\DeviceGraphicCardModel;
use itsmng\Database\Entity\DeviceHardDriveModel;
use itsmng\Database\Entity\DeviceMemoryModel;
use itsmng\Database\Entity\DeviceMotherBoardModel;
use itsmng\Database\Entity\DeviceNetworkCardModel;
use itsmng\Database\Entity\DevicePciModel;
use itsmng\Database\Entity\DevicePowerSupplyModel;
use itsmng\Database\Entity\DeviceProcessorModel;
use itsmng\Database\Entity\DeviceSensorModel;
use itsmng\Database\Entity\DeviceSoundCardModel;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\IdentifierColumns;
use itsmng\Database\Orm as ApplicationOrm;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 3) . '/fixtures/DisconnectedSchemaConnection.php';

class CurrentSchema extends \atoum\atoum\test
{
    private function manager(AbstractPlatform $platform, bool $fixture = false): EntityManager
    {
        $configuration = ApplicationOrm::configuration($platform);
        if ($fixture) {
            $configuration->setMetadataDriverImpl(new CurrentDeclarationDriver($configuration->getMetadataDriverImpl()));
        }
        return new EntityManager(new DisconnectedSchemaConnection($platform), $configuration);
    }

    public function testSubjectIndexesRetainLegacyCoverageWithoutNameCollisions(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $schema = (new BaselineSchema($manager))->build($platform);
            foreach (['batteries', 'harddrives', 'memories', 'motherboards', 'powersupplies', 'processors', 'sensors'] as $family) {
                $name = 'glpi_items_device' . $family;
                $table = $schema->getTable($name);
                $legacy = $platform instanceof PostgreSQLPlatform ? $name . '_computers_id' : 'computers_id';
                $typed = $name . '_computers_id' . ($platform instanceof PostgreSQLPlatform ? '_typed' : '');
                $this->array($table->getIndex($legacy)->getUnquotedColumns())->isIdenticalTo(['items_id']);
                $this->array($table->getIndex($typed)->getUnquotedColumns())->isIdenticalTo(['computers_id']);
                $this->boolean($table->getIndex($typed)->isUnique())->isFalse();
            }
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testPhysicalCatalogVisibilityMatchesSupportedServerCapabilities(): void
    {
        foreach ([
            [new MariaDBPlatform(), '5.5.5-10.2.22-MariaDB', "'NO' AS visible", 'NO'],
            [new MariaDBPlatform(), '10.5.29-MariaDB', "'NO' AS visible", 'NO'],
            [new MariaDBPlatform(), '10.6.0-MariaDB', 'IGNORED AS visible', 'NO'],
            [new MySQLPlatform(), '8.0.16', 'IS_VISIBLE AS visible', 'YES'],
        ] as [$platform, $version, $fragment, $usable]) {
            $connection = new \mock\Doctrine\DBAL\Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
            $this->calling($connection)->getDatabasePlatform = $platform;
            $this->calling($connection)->getServerVersion = $version;
            $queries = [];
            $values = ['YES', 'NO', null, '', 'true', 1, 0];
            $this->calling($connection)->fetchAllAssociative = static function (string $sql, array $parameters, array $types) use (&$queries, $values): array {
                $queries[] = [$sql, $parameters, $types];
                return array_map(static fn ($value, $key): array => [
                    'table_name' => 'glpi_items_devicesensors', 'index_name' => 'fixture_' . $key,
                    'column_name' => 'computers_id', 'non_unique' => 1, 'access_method' => 'BTREE',
                    'prefix_length' => null, 'visible' => $value,
                ], $values, array_keys($values));
            };
            $catalog = \itsmng\Database\PhysicalIndexSchema::catalog($connection, ['glpi_items_devicesensors']);
            foreach ($values as $key => $value) {
                $this->boolean($catalog['glpi_items_devicesensors']['fixture_' . $key]['usable'])->isIdenticalTo($value === $usable);
            }
            $this->integer(count($queries))->isIdenticalTo(1);
            $this->string($queries[0][0])->contains($fragment)->notContains('IS_VISIBLE =')->notContains('IGNORED =');
            $this->array($queries[0][1])->isIdenticalTo([['glpi_items_devicesensors']]);
            $this->array($queries[0][2])->isIdenticalTo([\Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    public function testPhysicalCoverageCannotIntroduceUndeclaredUniqueness(): void
    {
        $platform = new PostgreSQLPlatform();
        $connection = new \mock\Doctrine\DBAL\Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
        $this->calling($connection)->getDatabasePlatform = $platform;
        $schema = new Schema();
        $table = $schema->createTable('physical_fixture');
        $table->addColumn('computers_id', 'bigint');
        $table->addIndex(['computers_id'], 'expected');
        foreach (['expected', 'renamed_unique'] as $name) {
            $this->calling($connection)->fetchAllAssociative = [[
                'table_name' => 'physical_fixture', 'index_name' => $name, 'column_name' => 'computers_id',
                'is_unique' => true, 'is_primary' => false, 'is_valid' => true, 'is_ready' => true,
                'access_method' => 'btree', 'predicate' => null, 'expressions' => null,
                'default_operator_class' => true, 'column_collation' => true, 'nulls_not_distinct' => false,
            ]];
            $this->array(\itsmng\Database\PhysicalIndexSchema::differences($connection, $schema))
                ->isIdenticalTo(['Missing physical index coverage: physical_fixture.expected']);
        }
        $table->addUniqueIndex(['computers_id'], 'declared_unique');
        $this->array(\itsmng\Database\PhysicalIndexSchema::differences($connection, $schema))
            ->isEmpty('A separately declared unique constraint also supports the same FK lookup');
    }

    public function testPhysicalIndexCoverageUsesColumnAndNativeSemantics(): void
    {
        $required = new \Doctrine\DBAL\Schema\Index('expected', ['"computers_id"']);
        $physical = ['columns' => ['computers_id', 'is_deleted'], 'lengths' => [null, null],
            'unique' => false, 'primary' => false, 'method' => 'btree', 'usable' => true,
            'predicate' => null, 'expressions' => null, 'standard_equality' => true, 'nulls_not_distinct' => false];
        $coverage = \itsmng\Database\PhysicalIndexSchema::covers(...);
        $this->boolean($coverage($required, $physical))->isTrue('A real wider leading-column index covers lookup');
        foreach ([
            ['columns' => ['items_id', 'computers_id']],
            ['columns' => ['is_deleted', 'computers_id']],
            ['predicate' => 'computers_id IS NOT NULL'],
            ['expressions' => '(computers_id + 0)'],
            ['method' => 'hash'], ['method' => 'fulltext'], ['usable' => false], ['standard_equality' => false],
            ['lengths' => [10, null]],
        ] as $damage) {
            $this->boolean($coverage($required, array_replace($physical, $damage)))->isFalse();
        }
        $unique = new \Doctrine\DBAL\Schema\Index('unique', ['computers_id'], true);
        $this->boolean($coverage($unique, $physical))->isFalse();
        $this->boolean($coverage($unique, array_replace($physical, ['unique' => true])))->isFalse('Wider uniqueness is weaker');
        $one = array_replace($physical, ['columns' => ['computers_id'], 'lengths' => [null], 'unique' => true]);
        $this->boolean($coverage($unique, $one))->isTrue();
        $declared = new Table('physical_fixture');
        $declared->addColumn('computers_id', 'bigint');
        $declared->addIndex(['computers_id'], 'expected');
        $changed = clone $declared;
        $changed->dropIndex('expected');
        $changed->addUniqueIndex(['computers_id'], 'expected');
        $diff = (new \Doctrine\DBAL\Schema\Comparator(new PostgreSQLPlatform()))->compareTables($declared, $changed);
        $this->integer(count($diff->getModifiedIndexes()))->isIdenticalTo(1, 'A named UNIQUE replacement still changes permitted rows');
        $this->boolean($diff->getModifiedIndexes()[0]->isUnique())->isTrue();
        $this->boolean($coverage($unique, array_replace($one, ['nulls_not_distinct' => true])))->isFalse();
        $primary = new \Doctrine\DBAL\Schema\Index('primary', ['computers_id'], true, true);
        $this->boolean($coverage($primary, $one))->isFalse();
        $this->boolean($coverage($primary, array_replace($one, ['primary' => true])))->isTrue();
        $prefix = new \Doctrine\DBAL\Schema\Index('prefix', ['name'], false, false, [], ['lengths' => [50]]);
        $text = array_replace($physical, ['columns' => ['name'], 'lengths' => [100]]);
        $this->boolean($coverage($prefix, $text))->isTrue();
        $this->boolean($coverage($prefix, array_replace($text, ['lengths' => [20]])))->isFalse();
        $fulltext = new \Doctrine\DBAL\Schema\Index('fulltext', ['name'], false, false, ['fulltext']);
        $text = array_replace($text, ['lengths' => [null], 'method' => 'fulltext']);
        $this->boolean($coverage($fulltext, $text))->isTrue();
        $this->boolean($coverage($fulltext, array_replace($text, ['method' => 'btree'])))->isFalse();
        $this->array(\itsmng\Database\PhysicalIndexSchema::missing(['fixture' => [$required]], ['fixture' => ['expected' => array_replace($physical, ['columns' => ['items_id']])]]))
            ->isIdenticalTo(['fixture' => [$required]], 'An expected name on wrong columns cannot substitute for coverage');
    }

    public function testImportStorageAdmissionIncludesUnreferencedAuditTables(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new \mock\Doctrine\DBAL\Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
            $this->calling($connection)->getDatabasePlatform = $platform;
            $queries = 0;
            $engine = 'InnoDB';
            $assertions = $this;
            $this->calling($connection)->fetchAllAssociative = static function (string $sql) use ($assertions, &$queries, &$engine): array {
                ++$queries;
                $assertions->string($sql)->isIdenticalTo('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
                return [
                    ['TABLE_NAME' => 'glpi_logs', 'ENGINE' => $engine],
                    ['TABLE_NAME' => 'glpi_appliances', 'ENGINE' => 'innodb'],
                    ['TABLE_NAME' => 'unowned_plugin_export', 'ENGINE' => 'MyISAM'],
                ];
            };
            foreach (['Domains', 'Appliance'] as $aggregate) {
                $engine = 'InnoDB';
                \itsmng\Database\PluginImportMutation::assertTransactionalCore($connection, $aggregate);
                foreach (['MyISAM', null] as $nontransactional) {
                    $engine = $nontransactional;
                    if ($platform instanceof PostgreSQLPlatform) {
                        \itsmng\Database\PluginImportMutation::assertTransactionalCore($connection, $aggregate);
                    } else {
                        $this->exception(static fn () => \itsmng\Database\PluginImportMutation::assertTransactionalCore($connection, $aggregate))
                            ->isInstanceOf(\RuntimeException::class)
                            ->hasMessage($aggregate . ' lifecycle import requires transactional core tables: glpi_logs must use InnoDB; found ' . ($engine ?? 'no transactional engine') . '. Reconcile this table before importing; audit and hooks cannot roll back otherwise.');
                    }
                }
            }
            $this->integer($queries)->isIdenticalTo($platform instanceof PostgreSQLPlatform ? 0 : 6);
        }
    }

    public function ownedTableProvider(): array
    {
        return [
            ['glpi_crontasks', 16, 5, [], []],
            ['glpi_configs', 4, 2, [], []],
            ['glpi_computertypes', 5, 4, [], []],
            ['glpi_computermodels', 14, 5, [], []],
            ['glpi_monitormodels', 14, 5, [], []],
            ['glpi_networkequipmentmodels', 14, 5, [], []],
            ['glpi_peripheralmodels', 14, 5, [], []],
            ['glpi_phonemodels', 6, 5, [], []],
            ['glpi_printermodels', 6, 5, [], []],
            ['glpi_passivedcequipmentmodels', 14, 5, [], []],
            ['glpi_enclosuremodels', 14, 5, [], []],
            ['glpi_pdumodels', 15, 4, [], []],
            ['glpi_rackmodels', 6, 3, [], []],
            ['glpi_devicebatterymodels', 4, 3, [], []],
            ['glpi_devicecasemodels', 4, 3, [], []],
            ['glpi_devicecontrolmodels', 4, 3, [], []],
            ['glpi_devicedrivemodels', 4, 3, [], []],
            ['glpi_devicefirmwaremodels', 4, 3, [], []],
            ['glpi_devicegenericmodels', 4, 3, [], []],
            ['glpi_devicegraphiccardmodels', 4, 3, [], []],
            ['glpi_deviceharddrivemodels', 4, 3, [], []],
            ['glpi_devicememorymodels', 4, 3, [], []],
            ['glpi_devicemotherboardmodels', 4, 3, [], []],
            ['glpi_devicenetworkcardmodels', 4, 3, [], []],
            ['glpi_devicepcimodels', 4, 3, [], []],
            ['glpi_devicepowersupplymodels', 4, 3, [], []],
            ['glpi_deviceprocessormodels', 4, 3, [], []],
            ['glpi_devicesensormodels', 4, 3, [], []],
            ['glpi_devicesoundcardmodels', 4, 3, [], []],
            ['glpi_monitortypes', 5, 4, [], []],
            ['glpi_networkequipmenttypes', 5, 4, [], []],
            ['glpi_peripheraltypes', 5, 4, [], []],
            ['glpi_phonetypes', 5, 4, [], []],
            ['glpi_printertypes', 5, 4, [], []],
            // DBAL also retains the implicit parent-reference index when composing FKs.
            ['glpi_crontasklogs', 8, 5, ['crontasklogs_id'], [
                'crontasks_id' => 'glpi_crontasks', 'crontasklogs_id' => 'glpi_crontasklogs',
            ]],
        ];
    }

    /** @dataProvider ownedTableProvider */
    public function testOwnedTablesPreserveEveryCurrentColumnAndIndexAcrossProviders(string $table, int $columnCount, int $indexCount, array $emptyReferences, array $references): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform);
            $frozenSql = $frozen->toSql($platform);
            // The existing current identity policy widens frozen IDs before inspection.
            IdentifierColumns::configureSchema($frozen);
            $historical = $frozen->getTable($table);
            // Already-installed current policies, independent of the new owner declaration.
            foreach ($emptyReferences as $column) {
                $historical->getColumn($column)->setNotnull(false)->setDefault(null);
            }
            foreach ($references as $column => $target) {
                $historical->addForeignKeyConstraint(
                    $target,
                    [$column],
                    ['id'],
                    ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'],
                    \itsmng\Database\ForeignKeys::name($table, $column)
                );
            }
            $current = (new BaselineSchema($manager))->build($platform)->getTable($table);
            $comparator = new \Doctrine\DBAL\Schema\Comparator($platform);
            $this->boolean($comparator->compareTables($historical, $current)->isEmpty())->isTrue();
            $this->integer(count($current->getColumns()))->isIdenticalTo($columnCount);
            $this->integer(count($current->getIndexes()))->isIdenticalTo($indexCount);
            $this->integer(count($current->getForeignKeys()))->isIdenticalTo(count($references));
            foreach ($historical->getForeignKeys() as $foreignKey) {
                $this->boolean($current->hasForeignKey($foreignKey->getName()))->isTrue();
            }
            foreach ($historical->getColumns() as $column) {
                $actual = $current->getColumn($column->getName());
                $this->string(Type::lookupName($actual->getType()))->isIdenticalTo(Type::lookupName($column->getType()));
                $this->boolean($actual->getNotnull())->isIdenticalTo($column->getNotnull());
                $default = static fn ($value) => $value instanceof \Doctrine\DBAL\Schema\DefaultExpression
                    ? $value->toSQL($platform) : $value;
                $this->variable($default($actual->getDefault()))->isEqualTo($default($column->getDefault()));
                $this->variable($actual->getComment())->isIdenticalTo($column->getComment());
                $this->variable($actual->getLength())->isIdenticalTo($column->getLength());
                $this->boolean($actual->getAutoincrement())->isIdenticalTo($column->getAutoincrement());
                $this->variable($actual->getColumnDefinition())->isIdenticalTo($column->getColumnDefinition());
                $this->variable($actual->getCharset())->isIdenticalTo($column->getCharset());
                $this->variable($actual->getCollation())->isIdenticalTo($column->getCollation());
            }
            // These physical names are lowercase on both providers; compare
            // identifiers and prefix lengths, not DBAL's original quote markers.
            $columns = static fn (\Doctrine\DBAL\Schema\Index $index): array => array_map(
                static fn (\Doctrine\DBAL\Schema\Index\IndexedColumn $column): array => [
                    $column->getColumnName()->getIdentifier()->getValue(), $column->getLength(),
                ],
                $index->getIndexedColumns(),
            );
            foreach ($historical->getIndexes() as $index) {
                $this->boolean($current->hasIndex($index->getName()))->isTrue();
                $this->array($columns($current->getIndex($index->getName())))->isIdenticalTo($columns($index));
            }
            $this->array($current->getOptions())->isEqualTo($historical->getOptions());
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozenSql);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testExistingPropertyAndIndexEditsDriveCurrentExpectationOnly(): void
    {
        foreach ([new PostgreSQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(CronTask::class);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $metadata->fieldMappings['name']->length = 173;
            $metadata->fieldMappings['param']->nullable = false;
            $metadata->fieldMappings['state']->options['default'] = 2;
            $metadata->fieldMappings['frequency']->type = Types::BIGINT;
            $metadata->fieldMappings['lastcode']->options['comment'] = 'Current declaration changed';
            unset($metadata->fieldMappings['comment']);
            $modeIndex = $platform instanceof PostgreSQLPlatform ? 'glpi_crontasks_mode' : 'mode';
            unset($metadata->table['indexes'][$modeIndex]);
            $uniqueIndex = $platform instanceof PostgreSQLPlatform ? 'glpi_crontasks_unicity' : 'unicity';
            $metadata->table['uniqueConstraints'][$uniqueIndex]['columns'] = ['name', 'itemtype'];
            $metadata->table['indexes']['current_state_index'] = ['columns' => ['state', 'name']];
            $current = (new BaselineSchema($manager))->build($platform)->getTable('glpi_crontasks');
            $this->integer($current->getColumn('name')->getLength())->isIdenticalTo(173);
            $this->boolean($current->getColumn('param')->getNotnull())->isTrue();
            $this->integer((int)$current->getColumn('state')->getDefault())->isIdenticalTo(2);
            $this->string(Type::lookupName($current->getColumn('frequency')->getType()))->isIdenticalTo(Types::BIGINT);
            $this->string($current->getColumn('lastcode')->getComment())->isIdenticalTo('Current declaration changed');
            $this->boolean($current->hasColumn('comment'))->isFalse();
            $this->boolean($current->hasIndex($modeIndex))->isFalse();
            $this->array($current->getIndex($uniqueIndex)->getColumns())->isIdenticalTo(['name', 'itemtype']);
            $this->array($current->getIndex('current_state_index')->getColumns())->isIdenticalTo(['state', 'name']);
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $fresh = (new BaselineSchema())->build($platform)->getTable('glpi_crontasks');
            $this->integer($fresh->getColumn('name')->getLength())->isIdenticalTo(150);
            $this->boolean($fresh->hasColumn('comment'))->isTrue();
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testAssetModelPropertiesAndIndexesOwnCurrentSchema(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $models = [];
            foreach ([
                \itsmng\Database\Entity\MonitorModel::class,
                \itsmng\Database\Entity\NetworkEquipmentModel::class,
                \itsmng\Database\Entity\PeripheralModel::class,
                \itsmng\Database\Entity\PhoneModel::class,
                \itsmng\Database\Entity\PrinterModel::class,
                \itsmng\Database\Entity\PassiveDCEquipmentModel::class,
                \itsmng\Database\Entity\EnclosureModel::class,
                \itsmng\Database\Entity\PDUModel::class,
                \itsmng\Database\Entity\RackModel::class,
                DeviceBatteryModel::class,
                DeviceCaseModel::class,
                DeviceControlModel::class,
                DeviceDriveModel::class,
                DeviceFirmwareModel::class,
                DeviceGenericModel::class,
                DeviceGraphicCardModel::class,
                DeviceHardDriveModel::class,
                DeviceMemoryModel::class,
                DeviceMotherBoardModel::class,
                DeviceNetworkCardModel::class,
                DevicePciModel::class,
                DevicePowerSupplyModel::class,
                DeviceProcessorModel::class,
                DeviceSensorModel::class,
                DeviceSoundCardModel::class,
            ] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $table = $metadata->getTableName();
                $metadata->fieldMappings['name']->length = 173;
                $metadata->fieldMappings['name']->nullable = false;
                $metadata->fieldMappings['name']->options['default'] = 'Current model';
                $oldIndex = array_key_first(array_filter($metadata->table['indexes'] ?? [], static fn ($index) => $index['columns'] === ['product_number']));
                $this->variable($oldIndex)->isNotNull();
                unset($metadata->table['indexes'][$oldIndex]);
                $metadata->table['uniqueConstraints'][$table . '_current_model_name'] = ['columns' => ['name', 'product_number']];
                $flags = [];
                foreach ($metadata->fieldMappings as $property => $field) {
                    if ($field->type === Types::BOOLEAN) {
                        $field->nullable = true;
                        $field->options['default'] = true;
                        $flags[] = $property;
                    }
                }
                $models[] = [$metadata, $oldIndex, $flags];
            }
            $current = (new BaselineSchema($manager))->build($platform);
            $freshManager = $this->manager($platform);
            $fresh = (new BaselineSchema($freshManager))->build($platform);
            foreach ($models as [$metadata, $oldIndex, $flags]) {
                $table = $metadata->getTableName();
                $declaration = $current->getTable($table);
                $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->boolean($declaration->getColumn('name')->getNotnull())->isTrue();
                $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo('Current model');
                $this->boolean($declaration->hasIndex($oldIndex))->isFalse();
                $this->array($declaration->getIndex($table . '_current_model_name')->getUnquotedColumns())->isIdenticalTo(['name', 'product_number']);
                $this->boolean($declaration->getIndex($table . '_current_model_name')->isUnique())->isTrue();
                $this->integer($fresh->getTable($table)->getColumn('name')->getLength())->isIdenticalTo(255);
                $this->boolean($fresh->getTable($table)->getColumn('name')->getNotnull())->isFalse();
                $this->boolean($fresh->getTable($table)->hasIndex($oldIndex))->isTrue();
                $this->boolean($fresh->getTable($table)->hasIndex($table . '_current_model_name'))->isFalse();
                foreach ($flags as $property) {
                    $field = $metadata->fieldMappings[$property];
                    $column = $declaration->getColumn($field->columnName);
                    $this->string($field->type)->isIdenticalTo(Types::BOOLEAN);
                    $this->string(Type::lookupName($column->getType()))->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BOOLEAN : Types::SMALLINT);
                    $this->boolean($column->getNotnull())->isFalse();
                    $this->variable($column->getDefault())->isIdenticalTo($platform instanceof PostgreSQLPlatform ? true : '1');
                    $this->variable($fresh->getTable($table)->getColumn($field->columnName)->getDefault())->isIdenticalTo($platform instanceof PostgreSQLPlatform ? false : '0');
                }
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($freshManager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testComputerModelOwnsBooleanStorageDefaultsAndNullability(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\ComputerModel::class);
            $field = $metadata->fieldMappings['is_half_rack'];
            $this->string($field->type)->isIdenticalTo(Types::BOOLEAN);
            $this->boolean((new \itsmng\Database\Entity\ComputerModel())->is_half_rack)->isFalse();
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $builder = new BaselineSchema($manager);
            $metadata->fieldMappings['name']->length = 173;
            $metadata->fieldMappings['weight']->options['default'] = '7';
            foreach ([[false, false], [true, false], [null, true]] as [$default, $nullable]) {
                $field->nullable = $nullable;
                $field->options['default'] = $default;
                $field->options['comment'] = 'Current rack flag';
                $current = $builder->build($platform)->getTable('glpi_computermodels');
                $column = $current->getColumn('is_half_rack');
                $this->string(Type::lookupName($column->getType()))->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BOOLEAN : Types::SMALLINT);
                $this->variable($column->getDefault())->isIdenticalTo($default === null || $platform instanceof PostgreSQLPlatform ? $default : (string)(int)$default);
                $this->boolean($column->getNotnull())->isIdenticalTo(!$nullable);
                $this->string($column->getComment())->isIdenticalTo('Current rack flag');
                $this->integer($current->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->string($current->getColumn('weight')->getDefault())->isIdenticalTo('7');
                $this->string($field->type)->isIdenticalTo(Types::BOOLEAN, 'The physical projection must not change ORM hydration');
                $this->variable($field->options['default'])->isIdenticalTo($default);
            }
            $sql = implode("\n", $builder->toSql($platform));
            if ($platform instanceof PostgreSQLPlatform) {
                $this->string($sql)->notContains('glpi_computermodels_is_half_rack_boolean');
            } else {
                $this->string($sql)->contains('ADD CONSTRAINT `glpi_computermodels_is_half_rack_boolean` CHECK (`is_half_rack` IS NULL OR `is_half_rack` IN (0, 1))');
                $field->nullable = false;
                $field->options['default'] = false;
                $this->string(implode("\n", $builder->toSql($platform)))
                    ->contains('ADD CONSTRAINT `glpi_computermodels_is_half_rack_boolean` CHECK (`is_half_rack` IS NOT NULL AND `is_half_rack` IN (0, 1))');
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testBooleanStorageRejectsNonBooleanFieldsAndDefaults(): void
    {
        $this->exception(static fn () => new \itsmng\Database\Mapping\BooleanStorage(Types::STRING))
            ->isInstanceOf(\InvalidArgumentException::class);
        $storage = new \itsmng\Database\Mapping\BooleanStorage(Types::SMALLINT);
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\ComputerModel::class);
            $field = $metadata->fieldMappings['is_half_rack'];
            $field->type = Types::INTEGER;
            $this->exception(static fn () => (new BaselineSchema($manager))->build($platform))
                ->isInstanceOf(\InvalidArgumentException::class)
                ->hasMessage('BooleanStorage requires an ORM boolean field.');
            $field->type = Types::BOOLEAN;
            $column = new \Doctrine\DBAL\Schema\Column('is_half_rack', Type::getType(Types::BOOLEAN), ['default' => 2]);
            $this->exception(static fn () => $storage->configure($column, $platform, $field))
                ->isInstanceOf(\InvalidArgumentException::class);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testAssetTypePropertiesAndIndexesOwnCurrentExpectation(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $tables = [];
            foreach ([
                \itsmng\Database\Entity\ComputerType::class, \itsmng\Database\Entity\MonitorType::class,
                \itsmng\Database\Entity\NetworkEquipmentType::class, \itsmng\Database\Entity\PeripheralType::class,
                \itsmng\Database\Entity\PhoneType::class, \itsmng\Database\Entity\PrinterType::class,
            ] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $table = $metadata->getTableName();
                $tables[] = $table;
                $metadata->fieldMappings['name']->length = 173;
                $metadata->fieldMappings['name']->nullable = false;
                $metadata->fieldMappings['name']->options['default'] = 'Current type';
                $metadata->fieldMappings['comment']->type = Types::STRING;
                $metadata->fieldMappings['comment']->length = 311;
                $index = $platform instanceof PostgreSQLPlatform ? $table . '_name' : 'name';
                unset($metadata->table['indexes'][$index]);
                $metadata->table['indexes'][$table . '_current_label']['columns'] = ['name', 'date_mod'];
            }
            $current = (new BaselineSchema($manager))->build($platform);
            $fresh = (new BaselineSchema($this->manager($platform)))->build($platform);
            foreach ($tables as $table) {
                $declaration = $current->getTable($table);
                $index = $platform instanceof PostgreSQLPlatform ? $table . '_name' : 'name';
                $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->boolean($declaration->getColumn('name')->getNotnull())->isTrue();
                $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo('Current type');
                $this->string(Type::lookupName($declaration->getColumn('comment')->getType()))->isIdenticalTo(Types::STRING);
                $this->integer($declaration->getColumn('comment')->getLength())->isIdenticalTo(311);
                $this->boolean($declaration->hasIndex($index))->isFalse();
                $this->array($declaration->getIndex($table . '_current_label')->getColumns())->isIdenticalTo(['name', 'date_mod']);
                $this->integer($fresh->getTable($table)->getColumn('name')->getLength())->isIdenticalTo(255);
                $this->boolean($fresh->getTable($table)->getColumn('name')->getNotnull())->isFalse();
                $this->boolean($fresh->getTable($table)->hasIndex($index))->isTrue();
                $this->boolean($fresh->getTable($table)->hasIndex($table . '_current_label'))->isFalse();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testConfigPropertyAndIndexEditsRemainIndependentOfFrozenHistory(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(Config::class);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $metadata->fieldMappings['context']->length = 173;
            $metadata->fieldMappings['context']->nullable = false;
            $metadata->fieldMappings['context']->options['default'] = 'current';
            $metadata->fieldMappings['value']->type = Types::STRING;
            $metadata->fieldMappings['value']->length = 311;
            $unique = $platform instanceof PostgreSQLPlatform ? 'glpi_configs_unicity' : 'unicity';
            $metadata->table['uniqueConstraints'][$unique]['columns'] = ['name', 'context'];
            $metadata->table['indexes']['current_config_name'] = ['columns' => ['name']];
            $current = (new BaselineSchema($manager))->build($platform)->getTable('glpi_configs');
            $this->integer($current->getColumn('context')->getLength())->isIdenticalTo(173);
            $this->boolean($current->getColumn('context')->getNotnull())->isTrue();
            $this->string($current->getColumn('context')->getDefault())->isIdenticalTo('current');
            $this->string(Type::lookupName($current->getColumn('value')->getType()))->isIdenticalTo(Types::STRING);
            $this->integer($current->getColumn('value')->getLength())->isIdenticalTo(311);
            $this->array($current->getIndex($unique)->getColumns())->isIdenticalTo(['name', 'context']);
            $this->array($current->getIndex('current_config_name')->getColumns())->isIdenticalTo(['name']);
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $fresh = (new BaselineSchema($this->manager($platform)))->build($platform)->getTable('glpi_configs');
            $this->integer($fresh->getColumn('context')->getLength())->isIdenticalTo(150);
            $this->boolean($fresh->getColumn('context')->getNotnull())->isFalse();
            $this->boolean($fresh->hasIndex('current_config_name'))->isFalse();
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testCronLogJoinColumnOptionsAreCurrentMetadataAuthority(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(CronTaskLog::class);
            $join = $metadata->associationMappings['task']->joinColumns[0];
            $this->variable($join->options['default'] ?? null)->isIdenticalTo($platform instanceof PostgreSQLPlatform ? 0 : null);
            $join->options['default'] = 17;
            $join->options['comment'] = 'Current task reference';
            $join->nullable = true;
            $metadata->fieldMappings['content']->length = 173;
            $index = $platform instanceof PostgreSQLPlatform ? 'glpi_crontasklogs_date' : 'date';
            unset($metadata->table['indexes'][$index]);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $current = (new BaselineSchema($manager))->build($platform)->getTable('glpi_crontasklogs');
            $this->integer((int)$current->getColumn('crontasks_id')->getDefault())->isIdenticalTo(17);
            $this->string($current->getColumn('crontasks_id')->getComment())->isIdenticalTo('Current task reference');
            $this->boolean($current->getColumn('crontasks_id')->getNotnull())->isFalse();
            $this->integer($current->getColumn('content')->getLength())->isIdenticalTo(173);
            $this->boolean($current->hasIndex($index))->isFalse();
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $fresh = (new BaselineSchema($this->manager($platform)))->build($platform)->getTable('glpi_crontasklogs');
            $this->boolean($fresh->getColumn('crontasks_id')->getNotnull())->isTrue();
            $this->variable($fresh->getColumn('crontasks_id')->getDefault())->isEqualTo($platform instanceof PostgreSQLPlatform ? 0 : null);
            $this->integer($fresh->getColumn('content')->getLength())->isIdenticalTo(255);
            $this->boolean($fresh->hasIndex($index))->isTrue();
            $this->array((new BaselineSchema($manager))->build($platform, false)->getTable('glpi_crontasklogs')->getForeignKeys())->isEmpty();
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testProviderColumnOptionsRejectAmbiguousOrNonOwningProperties(): void
    {
        $driver = new \itsmng\Database\Mapping\AttributeDriver([], new PostgreSQLPlatform());
        foreach ([UnmappedProviderColumn::class, InverseProviderColumn::class, CompositeProviderColumn::class] as $class) {
            $this->exception(static fn () => $driver->loadMetadataForClass($class, new ORM\ClassMetadata($class)))
                ->isInstanceOf(\LogicException::class)
                ->hasMessage('Provider column options require a scalar field or a single-column owning to-one association: ' . $class . '::$invalid.');
        }
    }

    public function testNewOwnedTableAndAddedScalarComeFromActualMetadata(): void
    {
        $platform = new PostgreSQLPlatform();
        $manager = $this->manager($platform, true);
        $builder = new BaselineSchema($manager);
        $first = $builder->build($platform, false);
        $this->boolean($first->hasTable('schema_owned_example'))->isTrue();
        $this->boolean($first->getTable('schema_owned_example')->hasColumn('future'))->isFalse();
        $this->array($first->getTable('schema_owned_example')->getForeignKeys())->isEmpty();
        $withForeignKeys = $builder->build($platform);
        $this->integer(count($withForeignKeys->getTable('schema_owned_example')->getForeignKeys()))->isIdenticalTo(1);
        $metadata = $manager->getClassMetadata(CurrentDeclaration::class);
        $metadata->mapField(['fieldName' => 'future', 'type' => Types::STRING, 'length' => 39, 'nullable' => false, 'options' => ['default' => 'new']]);
        $second = $builder->build($platform, false);
        $this->integer($second->getTable('schema_owned_example')->getColumn('future')->getLength())->isIdenticalTo(39);
        $this->string($second->getTable('schema_owned_example')->getColumn('future')->getDefault())->isIdenticalTo('new');
        $this->boolean($first->getTable('schema_owned_example')->hasColumn('future'))->isFalse();
        $this->boolean((new Baseline())->build($platform)->hasTable('schema_owned_example'))->isFalse();
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    public function testProjectionRetainsConfigurationNamespacesSequencesAndIncomingReferences(): void
    {
        $configuration = new SchemaConfig();
        $configuration->setName('application');
        $configuration->setMaxIdentifierLength(31);
        $configuration->setDefaultTableOptions(['engine' => 'InnoDB']);
        $schema = new Schema([], [], $configuration, ['application', 'empty_namespace']);
        $original = $schema->createTable('application.jobs');
        $original->addColumn('id', Types::BIGINT);
        $original->addColumn('obsolete', Types::STRING);
        $original->setPrimaryKey(['id']);
        $child = $schema->createTable('application.logs');
        $child->addColumn('job', Types::BIGINT);
        $child->addForeignKeyConstraint('application.jobs', ['job'], ['id']);
        $sequence = $schema->createSequence('application.reserved', 7, 31);
        $declaration = new Table('jobs');
        $declaration->addColumn('id', Types::BIGINT);
        $declaration->addColumn('current', Types::STRING);
        $declaration->setPrimaryKey(['id']);
        $projected = Projection::replaceTables($schema, [$declaration], $configuration);
        $this->object($projected->getTable('application.logs'))->isIdenticalTo($child);
        $this->object($projected->getSequence('application.reserved'))->isIdenticalTo($sequence);
        $this->array($projected->getNamespaces())->isIdenticalTo($schema->getNamespaces());
        $this->string($projected->getName())->isIdenticalTo($schema->getName());
        $this->boolean($projected->getTable('application.jobs')->hasColumn('obsolete'))->isFalse();
        $this->boolean($original->hasColumn('obsolete'))->isTrue();
        $this->integer(count($projected->getTable('application.logs')->getForeignKeys()))->isIdenticalTo(1);
        // A later option change proves the exact configuration object is retained.
        $configuration->setDefaultTableOptions(['engine' => 'InnoDB', 'comment' => 'same configuration']);
        $created = $projected->createTable('application.after_projection');
        $this->string($created->getOption('comment'))->isIdenticalTo('same configuration');
        $created->addColumn('a_long_property_name_for_generated_index', Types::INTEGER);
        $created->addIndex(['a_long_property_name_for_generated_index']);
        $index = array_values($created->getIndexes())[0];
        $this->integer(strlen($index->getName()))->isLessThanOrEqualTo(31);
    }

    public function testNativeSubjectExpressionsPreserveMeaningAcrossProviderFormatting(): void
    {
        $compare = \itsmng\Database\SubjectPolicyExpression::equivalent(...);
        $this->boolean($compare(
            "CASE WHEN itemtype IN ('Computer') THEN computers_id ELSE NULL END",
            "CASE itemtype WHEN 'Computer'::text THEN computers_id ELSE NULL::bigint END",
            true
        ))->isTrue();
        $this->boolean($compare(
            "CAST(`itemtype` AS BINARY) IN ('Computer') AND `computers_id` >= 1",
            "((cast(`itemtype` as char charset binary) = _utf8mb4'Computer') and (`computers_id` >= 1))",
            false
        ))->isTrue();
        $this->boolean($compare(
            "itemtype IS NULL OR (itemtype IS NOT NULL AND itemtype = 'Computer' AND computers_id >= 1) OR (itemtype IS NOT NULL AND itemtype = 'Peripheral' AND peripherals_id >= 1)",
            "(itemtype IS NULL AND (itemtype IS NULL OR itemtype = '')) OR (itemtype IS NOT NULL AND ((itemtype = 'Computer' AND computers_id >= 1) OR (itemtype = 'Peripheral' AND peripherals_id >= 1)))",
            true
        ))->isTrue();
        $expected = "itemtype IS NOT NULL AND itemtype = 'Computer' AND computers_id IS NOT NULL AND computers_id >= 1";
        foreach ([
            "itemtype IS NOT NULL AND itemtype = 'computer' AND computers_id IS NOT NULL AND computers_id >= 1",
            "itemtype IS NOT NULL AND itemtype = 'Computer' AND computers_id IS NOT NULL AND computers_id >= 0",
            "itemtype IS NOT NULL OR itemtype = 'Computer' AND computers_id IS NOT NULL AND computers_id >= 1",
            "itemtype = 'Computer' AND computers_id IS NOT NULL AND computers_id >= 1",
            $expected . ' OR 1 = 1',
            $expected . ' /* ignored? */',
            'lower(itemtype) = \'computer\'',
            '1 = 1',
        ] as $changed) {
            $this->boolean($compare($expected, $changed, true))->isFalse();
        }
        $this->boolean($compare("CAST(itemtype AS BINARY) = 'Computer'", "itemtype = 'Computer'", false))->isFalse();
        $this->boolean($compare("itemtype = 'Computer'", '"itemtype" = \'Computer\'', false))->isFalse();
        $this->boolean($compare("itemtype = 'Computer'", '"itemtype" = \'Computer\'', false, true))->isTrue();
        $this->boolean($compare("CASE WHEN itemtype = 'Computer' THEN computers_id ELSE NULL END", "CASE WHEN itemtype = 'Computer' THEN peripherals_id ELSE NULL END", true))->isFalse();
        $this->boolean($compare("itemtype = 'Computer'", "itemtype::text = 'Computer'::text", true))->isTrue();
        $this->boolean($compare("itemtype = 'Computer'", "itemtype::varchar(1) = 'Computer'", true))->isFalse();
    }

    public function testMySQL84CatalogLiteralDelimitersPreserveSubjectPolicies(): void
    {
        // Byte-for-byte catalog values from MySQL 8.4.11, CI run 37539940295
        // (both PHP 8.2 and 8.3). Expected policy comes from current metadata.
        $native = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/mysql84-subject-certificate.json'), true, 512, JSON_THROW_ON_ERROR);
        $builder = new BaselineSchema($this->manager(new MySQLPlatform()));
        $builder->build(new MySQLPlatform());
        $table = $native['table'];
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $projection = $native['columns'][0]['GENERATION_EXPRESSION'];
        $check = $native['checks'][0]['CHECK_CLAUSE'];
        $compare = \itsmng\Database\SubjectPolicyExpression::equivalent(...);
        $this->boolean($compare($policy['projection'], $projection, false))->isTrue();
        $this->boolean($compare($policy['check'], $check, false))->isTrue();
        // This encoding is accepted only in actual MySQL-family catalogs.
        $this->boolean($compare($projection, $projection, false))->isFalse();
        $this->boolean($compare($policy['projection'], $projection, true))->isFalse();
        foreach ([$projection => $policy['projection'], $check => $policy['check']] as $actual => $expected) {
            foreach ([
                str_replace('Computer', 'computer', $actual),
                str_replace('`computers_id`', '`peripherals_id`', $actual),
                str_replace('cast(`itemtype` as char charset binary)', '`itemtype`', $actual),
                str_replace("Computer", "Com\\puter", $actual),
                str_replace("Computer", "Com'puter", $actual),
                str_replace("Computer", "Com\\'puter", $actual),
                str_replace("Computer", "Com\\nputer", $actual),
                str_replace("Computer", "Com\nputer", $actual),
                str_replace("\\'Computer\\'", "\\'Computer'", $actual),
                str_replace("\\'Computer\\'", "\\\\'Computer\\\\'", $actual),
                substr($actual, 0, strpos($actual, 'Computer') + strlen('Computer')),
            ] as $changed) {
                $this->boolean($compare($expected, $changed, false))->isFalse();
            }
        }
        $this->boolean($compare($policy['projection'], str_replace('else NULL', 'else 0', $projection), false))->isFalse();
        $this->boolean($compare($policy['check'], str_replace('>= 1', '>= 0', $check), false))->isFalse();

        $columns = [$table => ['items_id' => ['generated' => $native['columns'][0]['EXTRA'], 'expression' => $projection]]];
        $checks = [$table => [$policy['constraint'] => ['clause' => $check, 'enforced' => $native['checks'][0]['ENFORCED']]]];
        $policies = [$table => ['items_id' => $policy]];
        $nativeCompare = static fn (array $c, array $k): array => \itsmng\Database\NativeSubjectSchema::compare($policies, $c, $k, false);
        $this->array($nativeCompare($columns, $checks))->isEmpty();
        $checks[$table][$policy['constraint']]['enforced'] = 'NO';
        $this->array($nativeCompare($columns, $checks))->isIdenticalTo([
            'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'],
        ]);
        $checks[$table][$policy['constraint']]['enforced'] = 'YES';
        $checks[$table][$policy['constraint']]['clause'] = str_replace('>= 1', '>= 0', $check);
        $this->array($nativeCompare($columns, $checks))->isIdenticalTo([
            'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'],
        ]);
    }

    public function testNativeSubjectCaseOrderingAndStockFallbackRequireProof(): void
    {
        $compare = \itsmng\Database\SubjectPolicyExpression::equivalent(...);
        $expected = "CASE WHEN itemtype = 'User' THEN users_id WHEN itemtype = 'Group' THEN groups_id ELSE 0 END";
        $reordered = "CASE itemtype WHEN 'Group'::text THEN groups_id WHEN 'User'::text THEN users_id ELSE (0)::bigint END";
        $this->boolean($compare($expected, $reordered, true))->isTrue();
        $this->boolean($compare($expected, str_replace("'Group'", "'User'", $reordered), true))->isFalse();
        $this->boolean($compare($expected, str_replace('groups_id', 'users_id', $reordered), true))->isFalse();
        $overlap = "CASE WHEN itemtype IN ('User', 'Group') THEN users_id WHEN itemtype = 'User' THEN groups_id ELSE 0 END";
        $this->boolean($compare($expected, $overlap, true))->isFalse();
        $caseInsensitive = "CASE WHEN itemtype = 'User' THEN users_id WHEN itemtype = 'user' THEN groups_id ELSE 0 END";
        $swapped = "CASE WHEN itemtype = 'user' THEN groups_id WHEN itemtype = 'User' THEN users_id ELSE 0 END";
        $this->boolean($compare($caseInsensitive, $swapped, false))->isFalse();
        $this->boolean($compare(str_replace('itemtype', 'CAST(itemtype AS BINARY)', $caseInsensitive), str_replace('itemtype', 'CAST(itemtype AS BINARY)', $swapped), false))->isTrue();

        // Actual PostgreSQL consumable shape; CASE and COALESCE differ when a
        // selected association is NULL, so syntax normalization alone is unsafe.
        $native = "COALESCE(CASE itemtype WHEN 'User'::text THEN users_id WHEN 'Group'::text THEN groups_id ELSE NULL::bigint END, (0)::bigint)";
        $guard = "(itemtype IS NOT NULL AND itemtype = 'User' AND users_id IS NOT NULL AND users_id >= 1 AND groups_id IS NULL) OR (itemtype IS NOT NULL AND itemtype = 'Group' AND groups_id IS NOT NULL AND groups_id >= 1 AND users_id IS NULL) OR (itemtype IS NULL AND users_id IS NULL AND groups_id IS NULL AND date_out IS NULL)";
        $this->boolean($compare($expected, $native, true))->isFalse();
        $this->boolean($compare($expected, $native, true, false, $guard))->isTrue();
        foreach ([
            '1 = 1',
            str_replace('users_id IS NOT NULL AND ', '', $guard),
            $guard . ' OR users_id IS NULL',
        ] as $unproven) {
            $this->boolean($compare($expected, $native, true, false, $unproven))->isFalse();
        }
        foreach ([
            str_replace('(0)::bigint', '(1)::bigint', $native),
            str_replace('users_id', 'groups_id', $native),
            str_replace('NULL::bigint', '(1)::bigint', $native),
            substr($native, 0, -1) . ', 0)',
        ] as $changed) {
            $this->boolean($compare($expected, $changed, true, false, $guard))->isFalse();
        }
        $this->boolean($compare('users_id', 'users_id::bigint', true))->isFalse();
        $this->boolean($compare('0', str_repeat('COALESCE(', 129) . '0' . str_repeat(', 0)', 129), true))->isFalse();
    }

    public function testCurrentSubjectPoliciesInspectNativeEnforcementWithoutReceipts(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $builder = new BaselineSchema($this->manager($platform));
            $schema = $builder->build($platform);
            $all = $builder->subjectPolicies();
            $table = 'glpi_certificates_items';
            $policy = $all[$table]['items_id'];
            $this->array($all)->hasKeys([$table, 'glpi_items_devicesensors', 'glpi_items_devicememories']);
            $this->string($schema->getTable($table)->getColumn('items_id')->getColumnDefinition())->contains($policy['projection']);
            $postgres = $platform instanceof PostgreSQLPlatform;
            $columns = [$table => [
                'items_id' => ['generated' => $postgres ? 's' : 'STORED GENERATED', 'expression' => $policy['projection']],
                'itemtype' => ['deterministic' => true],
            ]];
            $checks = [$table => [$policy['constraint'] => ['clause' => $policy['check'], 'enforced' => 'YES', 'validated' => true]]];
            $policies = [$table => ['items_id' => $policy]];
            $compare = static fn (array $c, array $k): array => \itsmng\Database\NativeSubjectSchema::compare($policies, $c, $k, $postgres);
            $this->array($compare($columns, $checks))->isEmpty();
            $changed = $columns;
            $changed[$table]['items_id']['expression'] = '0';
            $this->array($compare($changed, $checks))->isIdenticalTo(['Changed or missing native subject projection: ' . $table . '.items_id']);
            $changed = $columns;
            $changed[$table]['items_id']['generated'] = '';
            $this->array($compare($changed, $checks))->isIdenticalTo(['Changed or missing native subject projection: ' . $table . '.items_id']);
            $this->array($compare($columns, []))->isIdenticalTo(['Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']]);
            foreach (['clause' => '1 = 1', 'enforced' => 'NO', ...($postgres ? ['validated' => false] : [])] as $field => $value) {
                $changed = $checks;
                $changed[$table][$policy['constraint']][$field] = $value;
                $this->array($compare($columns, $changed))->isIdenticalTo(['Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']]);
            }
            if ($postgres) {
                $changed = $columns;
                $changed[$table]['itemtype']['deterministic'] = false;
                $this->array($compare($changed, $checks))->isIdenticalTo(['Expected deterministic subject discriminator: ' . $table . '.itemtype']);
            }
            // A future current policy must reject the old native declaration,
            // even if old migration receipts (not inputs here) remain complete.
            $policies[$table]['items_id']['projection'] = str_replace('computers_id', 'peripherals_id', $policy['projection']);
            $this->array(\itsmng\Database\NativeSubjectSchema::compare($policies, $columns, $checks, $postgres))->isIdenticalTo([
                'Changed or missing native subject projection: ' . $table . '.items_id',
            ]);
        }
    }

    public function testStockCoalesceDependsOnEnforcedCurrentCheck(): void
    {
        $builder = new BaselineSchema($this->manager(new PostgreSQLPlatform()));
        $builder->build(new PostgreSQLPlatform());
        $table = 'glpi_consumables';
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $columns = [$table => [
            'items_id' => ['generated' => 's', 'expression' => "COALESCE(CASE itemtype WHEN 'User'::text THEN users_id WHEN 'Group'::text THEN groups_id ELSE NULL::bigint END, (0)::bigint)"],
            'itemtype' => ['deterministic' => true],
        ]];
        $check = ['clause' => $policy['check'], 'enforced' => true, 'validated' => true];
        $compare = static fn (array $checks): array => \itsmng\Database\NativeSubjectSchema::compare([$table => ['items_id' => $policy]], $columns, $checks, true);
        $this->array($compare([$table => [$policy['constraint'] => $check]]))->isEmpty();
        $failures = [$compare([])];
        foreach (['clause' => '1 = 1', 'enforced' => false, 'validated' => false] as $field => $value) {
            $changed = $check;
            $changed[$field] = $value;
            $failures[] = $compare([$table => [$policy['constraint'] => $changed]]);
        }
        foreach ($failures as $differences) {
            $this->array($differences)->isIdenticalTo([
                'Changed or missing native subject projection: ' . $table . '.items_id',
                'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'],
            ]);
        }
    }

    public function testSuppliedMetadataRequiresTheSelectedPlatform(): void
    {
        $builder = new BaselineSchema($this->manager(new PostgreSQLPlatform()));
        $this->exception(static fn () => $builder->build(new MariaDBPlatform()))
            ->isInstanceOf(\InvalidArgumentException::class);
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'schema_owned_example')]
#[SchemaOwner]
class CurrentDeclaration
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'crontasks_id', nullable: false, onDelete: 'RESTRICT')]
    public CronTask $task;

    public string $future = '';
}

final class CurrentDeclarationDriver implements MappingDriver
{
    public function __construct(private MappingDriver $delegate)
    {
    }

    public function getAllClassNames(): array
    {
        return [...$this->delegate->getAllClassNames(), CurrentDeclaration::class];
    }

    public function isTransient(string $className): bool
    {
        return $className !== CurrentDeclaration::class && $this->delegate->isTransient($className);
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        $this->delegate->loadMetadataForClass($className, $metadata);
    }
}

#[ORM\Entity]
class UnmappedProviderColumn
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $invalid;
}

#[ORM\Entity]
class InverseProviderColumn
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\OneToOne(targetEntity: CronTaskLog::class, mappedBy: 'task')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public ?CronTaskLog $invalid = null;
}

#[ORM\Entity]
class CompositeProviderColumn
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id')]
    #[ORM\JoinColumn(name: 'task_name', referencedColumnName: 'name')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public ?CronTask $invalid = null;
}
