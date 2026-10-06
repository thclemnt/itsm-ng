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

    public function ownedTableProvider(): array
    {
        return [
            ['glpi_crontasks', 16, 5, [], []],
            ['glpi_configs', 4, 2, [], []],
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
                $historical->addForeignKeyConstraint($target, [$column], ['id'],
                    ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'],
                    \itsmng\Database\ForeignKeys::name($table, $column));
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
            "CASE itemtype WHEN 'Computer'::text THEN computers_id ELSE NULL::bigint END", true
        ))->isTrue();
        $this->boolean($compare(
            "CAST(`itemtype` AS BINARY) IN ('Computer') AND `computers_id` >= 1",
            "((cast(`itemtype` as char charset binary) = _utf8mb4'Computer') and (`computers_id` >= 1))", false
        ))->isTrue();
        $this->boolean($compare(
            "itemtype IS NULL OR (itemtype IS NOT NULL AND itemtype = 'Computer' AND computers_id >= 1) OR (itemtype IS NOT NULL AND itemtype = 'Peripheral' AND peripherals_id >= 1)",
            "(itemtype IS NULL AND (itemtype IS NULL OR itemtype = '')) OR (itemtype IS NOT NULL AND ((itemtype = 'Computer' AND computers_id >= 1) OR (itemtype = 'Peripheral' AND peripherals_id >= 1)))", true
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
