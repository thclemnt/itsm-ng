<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use atoum\atoum\test;
use itsmng\Database\BaselineSchema;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\CurrentSchema as Projection;
use itsmng\Database\Entity\BudgetType;
use itsmng\Database\Entity\Calendar;
use itsmng\Database\Entity\CalendarHoliday;
use itsmng\Database\Entity\CalendarSegment;
use itsmng\Database\Entity\ComputerModel;
use itsmng\Database\Entity\ComputerType;
use itsmng\Database\Entity\Config;
use itsmng\Database\Entity\ContactType;
use itsmng\Database\Entity\ContractType;
use itsmng\Database\Entity\CronTask;
use itsmng\Database\Entity\CronTaskLog;
use itsmng\Database\Entity\DeviceBatteryModel;
use itsmng\Database\Entity\DeviceBatteryType;
use itsmng\Database\Entity\DeviceCaseModel;
use itsmng\Database\Entity\DeviceCaseType;
use itsmng\Database\Entity\DeviceControlModel;
use itsmng\Database\Entity\DeviceDriveModel;
use itsmng\Database\Entity\DeviceFirmwareModel;
use itsmng\Database\Entity\DeviceFirmwareType;
use itsmng\Database\Entity\DeviceGenericModel;
use itsmng\Database\Entity\DeviceGenericType;
use itsmng\Database\Entity\DeviceGraphicCardModel;
use itsmng\Database\Entity\DeviceHardDriveModel;
use itsmng\Database\Entity\DeviceMemoryModel;
use itsmng\Database\Entity\DeviceMemoryType;
use itsmng\Database\Entity\DeviceMotherBoardModel;
use itsmng\Database\Entity\DeviceNetworkCardModel;
use itsmng\Database\Entity\DevicePciModel;
use itsmng\Database\Entity\DevicePowerSupplyModel;
use itsmng\Database\Entity\DeviceProcessorModel;
use itsmng\Database\Entity\DeviceSensorModel;
use itsmng\Database\Entity\DeviceSensorType;
use itsmng\Database\Entity\DeviceSimcardType;
use itsmng\Database\Entity\DeviceSoundCardModel;
use itsmng\Database\Entity\DomainRecordType;
use itsmng\Database\Entity\DomainRelation;
use itsmng\Database\Entity\DomainType;
use itsmng\Database\Entity\EnclosureModel;
use itsmng\Database\Entity\Holiday;
use itsmng\Database\Entity\IPAddress;
use itsmng\Database\Entity\MonitorModel;
use itsmng\Database\Entity\MonitorType;
use itsmng\Database\Entity\NetworkEquipmentModel;
use itsmng\Database\Entity\NetworkEquipmentType;
use itsmng\Database\Entity\Notification;
use itsmng\Database\Entity\NotificationChatConfig;
use itsmng\Database\Entity\NotificationNotificationTemplate;
use itsmng\Database\Entity\NotificationTarget;
use itsmng\Database\Entity\NotificationTemplate;
use itsmng\Database\Entity\NotificationTemplateTranslation;
use itsmng\Database\Entity\OidcConfig;
use itsmng\Database\Entity\OidcMapping;
use itsmng\Database\Entity\OidcUser;
use itsmng\Database\Entity\PDUModel;
use itsmng\Database\Entity\PassiveDCEquipmentModel;
use itsmng\Database\Entity\PeripheralModel;
use itsmng\Database\Entity\PeripheralType;
use itsmng\Database\Entity\PhoneModel;
use itsmng\Database\Entity\PhoneType;
use itsmng\Database\Entity\PrinterModel;
use itsmng\Database\Entity\PrinterType;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\ProjectTaskType;
use itsmng\Database\Entity\ProjectType;
use itsmng\Database\Entity\RackModel;
use itsmng\Database\Entity\SupplierType;
use itsmng\Database\Entity\User as UserEntity;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\NonNegative;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\IdentifierColumns;
use itsmng\Database\Migration\V220\NotificationRecipients;
use itsmng\Database\Migration\V220\UserAuthenticationSources;
use itsmng\Database\NativeCheckCatalog;
use itsmng\Database\NativeNonNegativeSchema;
use itsmng\Database\NativeSubjectSchema;
use itsmng\Database\Orm as ApplicationOrm;
use itsmng\Database\PhysicalIndexSchema;
use itsmng\Database\PluginImportMutation;
use itsmng\Database\SubjectPolicyExpression;
use itsmng\Database\Type\ClockTimeType;
use mock\Doctrine\DBAL\Connection;
use tests\fixtures\DisconnectedSchemaConnection;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\ItemDeviceGraphicCard;
use itsmng\Database\Entity\SLM as SLMRecord;
use itsmng\Database\Migration\V220\EntityParents;
use itsmng\Database\Migration\V220\ServiceLevelCalendars;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\NativeReferenceSchema;
use itsmng\Database\Migration\V220\EntityConfigurationReferences;

require_once dirname(__DIR__, 3) . '/fixtures/DisconnectedSchemaConnection.php';

class CurrentSchema extends test
{
    public function testNonnegativeDomainsUseTheirActualMappedIntegerProperties(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $owner = new BaselineSchema($manager);
            $frozen = (new Baseline())->toSql($platform);
            $schema = $owner->build($platform);
            $policies = $owner->nonNegativePolicies();
            $postgres = $platform instanceof PostgreSQLPlatform;
            if (!$postgres) {
                $this->array($policies)->isEmpty();
            } else {
                $this->integer(count($policies['glpi_ipaddresses']))->isIdenticalTo(5);
                $this->integer(count($policies['glpi_ipnetworks']))->isIdenticalTo(13);
                foreach ($policies as $table => $fields) {
                    foreach ($fields as $column => $policy) {
                        $this->string($policy['column'])->isIdenticalTo($column);
                        $this->string($policy['check'])->isIdenticalTo($platform->quoteIdentifier($column) . ' >= 0');
                        $this->string($policy['type'])->isIdenticalTo($column === 'version' ? Types::SMALLINT : Types::BIGINT);
                        $this->boolean($policy['nullable'])->isIdenticalTo($column === 'version');
                        $actual = $schema->getTable($table)->getColumn($column);
                        $this->boolean($actual->getNotnull())->isIdenticalTo(!$policy['nullable']);
                        $this->variable($actual->getDefault())->isEqualTo('0');
                    }
                }
                $this->string($policies['glpi_ipaddresses']['version']['constraint'])->isIdenticalTo('glpi_ipaddresses_version_check');
                $this->string($policies['glpi_ipnetworks']['gateway_3']['constraint'])->isIdenticalTo('glpi_ipnetworks_gateway_3_check');
                $metadata = $manager->getClassMetadata(IPAddress::class);
                $metadata->fieldMappings['version']->nullable = false;
                $metadata->fieldMappings['binary_0']->type = Types::INTEGER;
                $owner->build($platform);
                $this->boolean($owner->nonNegativePolicies()['glpi_ipaddresses']['version']['nullable'])->isFalse();
                $this->string($owner->nonNegativePolicies()['glpi_ipaddresses']['binary_0']['type'])->isIdenticalTo(Types::INTEGER);
                $fresh = new BaselineSchema($this->manager($platform));
                $fresh->build($platform);
                $this->array($fresh->nonNegativePolicies())->isIdenticalTo($policies);
                $property = new ReflectionProperty(IPAddress::class, 'version');
                $declaration = $property->getAttributes(NonNegative::class)[0]->newInstance();
                $metadata->fieldMappings['version']->type = Types::STRING;
                $this->exception(static fn () => $declaration->policy($metadata, $property, $platform))->isInstanceOf(LogicException::class);
                $metadata->fieldMappings['version']->type = Types::SMALLINT;
                $metadata->fieldMappings['version']->generated = ORM\ClassMetadata::GENERATED_ALWAYS;
                $this->exception(static fn () => $declaration->policy($metadata, $property, $platform))->isInstanceOf(LogicException::class);
                $metadata->fieldMappings['version']->generated = null;
                $metadata->fieldMappings['version']->columnDefinition = 'SMALLINT';
                $this->exception(static fn () => $declaration->policy($metadata, $property, $platform))->isInstanceOf(LogicException::class);
                $association = new ReflectionProperty(IPAddress::class, 'entities');
                $this->exception(static fn () => $declaration->policy($metadata, $association, $platform))->isInstanceOf(LogicException::class);
            }
            $this->array((new Baseline())->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($manager->isOpen())->isTrue();
        }
    }

    public function testNonnegativeNativeChecksRequireExactCurrentEnforcement(): void
    {
        $owner = new BaselineSchema($this->manager(new PostgreSQLPlatform()));
        $owner->build(new PostgreSQLPlatform());
        $policies = $owner->nonNegativePolicies();
        $checks = [];
        foreach ($policies as $table => $fields) {
            foreach ($fields as $column => $policy) {
                // Synthetic OIDs belong only to this fixture, never production declarations.
                $checks[$table][$policy['constraint']] = [
                    'clause' => '(' . $column . ' >= 0)', 'enforced' => 'true', 'validated' => true,
                    'checked_columns' => json_encode([$column]), 'integer_ge_oids' => '[430,542]',
                    'native_nodes' => '{OPEXPR :opno 430 :args ({VAR :varattno 7} {CONST :consttype 23})}',
                ];
            }
        }
        $this->array(NativeNonNegativeSchema::compare($policies, $checks))->isEmpty();
        foreach ($policies as $table => $fields) {
            foreach ($fields as $column => $policy) {
                $selected = [$table => [$column => $policy]];
                $name = $policy['constraint'];
                $diagnostic = ['Changed, missing or unenforced native nonnegative CHECK: ' . $table . '.' . $name];
                $this->array(NativeNonNegativeSchema::compare($selected, []))->isIdenticalTo($diagnostic);
                foreach ([
                    ['clause', $column . ' > 0'], ['clause', $column . ' >= 1'],
                    ['enforced', false], ['validated', false],
                    ['checked_columns', '[]'], ['checked_columns', '["other"]'],
                    ['checked_columns', json_encode([$column, 'other'])],
                    ['integer_ge_oids', '[99999]'],
                    ['native_nodes', '{OPEXPR :opno 99999 :args ({VAR :varattno 7} {CONST :consttype 23})}'],
                    ['native_nodes', '{OPEXPR :opno 430 :args ({FUNCEXPR :funcid 9000 :args ({VAR :varattno 7})} {CONST :consttype 23})}'],
                    ['native_nodes', '{BOOLEXPR :args ({OPEXPR :opno 430 :args ({VAR :varattno 7} {CONST :consttype 23})})}'],
                ] as [$key, $value]) {
                    $changed = $checks;
                    $changed[$table][$name][$key] = $value;
                    $this->array(NativeNonNegativeSchema::compare($selected, $changed))->isIdenticalTo($diagnostic);
                }
            }
        }
    }

    private function manager(AbstractPlatform $platform, bool $fixture = false): EntityManager
    {
        $configuration = ApplicationOrm::configuration($platform);
        if ($fixture) {
            $configuration->setMetadataDriverImpl(new CurrentDeclarationDriver($configuration->getMetadataDriverImpl()));
        }
        return new EntityManager(new DisconnectedSchemaConnection($platform), $configuration);
    }

    public function testNativePrefixInspectionRequiresEveryPhysicalSemantic(): void
    {
        $policy = ['columns' => ['field', 'value'], 'sourceTypes' => ['varchar', 'varchar'], 'lengths' => [50, 50]];
        $key = ['source' => 'field', 'source_type' => 'varchar', 'source_type_matches' => true, 'expression' => '"left"((field)::text, 50)',
            'attribute' => 0, 'options' => 0, 'collation' => true, 'opclass' => true];
        $physical = ['usable' => true, 'unique' => false, 'primary' => false, 'method' => 'btree',
            'predicate' => null, 'expressions' => 'left(field::text, 50), left(value::text, 50)',
            'native_prefix' => ['namespace' => true, 'live' => true, 'key_count' => 2, 'total_count' => 2,
                'function_oid' => '3060', 'nodes' => '({FUNCEXPR :funcid 3060 } {FUNCEXPR :funcid 3060 })',
                'keys' => [$key, array_replace($key, ['source' => 'value', 'expression' => 'pg_catalog.left(value::text, 50)'])]]];
        $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $physical))->isTrue();
        $textPolicy = ['columns' => ['ldap_value'], 'sourceTypes' => ['text'], 'lengths' => [200]];
        $textPhysical = $physical;
        $textPhysical['native_prefix']['key_count'] = $textPhysical['native_prefix']['total_count'] = 1;
        $textPhysical['native_prefix']['nodes'] = '({FUNCEXPR :funcid 3060 })';
        $textPhysical['native_prefix']['keys'] = [array_replace($key, ['source' => 'ldap_value',
            'source_type' => 'text', 'expression' => '"left"(ldap_value, 200)'])];
        $this->boolean(PhysicalIndexSchema::coversNativePrefix($textPolicy, $textPhysical))->isTrue();
        $textPhysical['native_prefix']['keys'][0]['source_type'] = 'varchar';
        $this->boolean(PhysicalIndexSchema::coversNativePrefix($textPolicy, $textPhysical))->isFalse('Exact mapped source type is required');
        foreach (['left(field, 50)', '(left((field)::pg_catalog.text, 50))', 'pg_catalog."left"("field"::text, 50)'] as $expression) {
            $changed = $physical;
            $changed['native_prefix']['keys'][0]['expression'] = $expression;
            $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isTrue($expression);
        }
        foreach (['usable' => false, 'unique' => true, 'primary' => true, 'method' => 'hash',
            'predicate' => 'rules_id > 0', 'expressions' => null] as $property => $value) {
            $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, array_replace($physical, [$property => $value])))->isFalse($property);
        }
        foreach (['namespace' => false, 'live' => false, 'key_count' => 1, 'total_count' => 3,
            'nodes' => '({FUNCEXPR :funcid 9999 } {FUNCEXPR :funcid 3060 })'] as $property => $value) {
            $changed = $physical;
            $changed['native_prefix'][$property] = $value;
            $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isFalse($property);
        }
        foreach (['source' => 'value', 'source_type' => 'text', 'source_type_matches' => false, 'attribute' => 1, 'options' => 1,
            'collation' => false, 'opclass' => false] as $property => $value) {
            $changed = $physical;
            $changed['native_prefix']['keys'][0][$property] = $value;
            $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isFalse($property);
        }
        foreach (['left(field::text, 49)', 'left(value::text, 50)', 'left(field::varchar(1)::text, 50)',
            'left(field::text COLLATE "C", 50)', 'left(lower(field::text), 50)', "left(field::text, 50) || ''",
            'left(field::text, 50) DESC', 'left(field::text, 50);'] as $expression) {
            $changed = $physical;
            $changed['native_prefix']['keys'][0]['expression'] = $expression;
            $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isFalse($expression);
        }
        $changed = $physical;
        $changed['native_prefix']['keys'][0]['options'] = 2;
        $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isFalse('NULLS FIRST');
        $changed['native_prefix']['nodes'] .= ' {FUNCEXPR :funcid 3060 }';
        $this->boolean(PhysicalIndexSchema::coversNativePrefix($policy, $changed))->isFalse('Additional function node');
    }

    public function testCurrentCheckFamiliesShareOneNativeOwnershipSnapshot(): void
    {
        $platform = new PostgreSQLPlatform();
        $connection = new Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
        $this->calling($connection)->getDatabasePlatform = $platform;
        $queries = [];
        $projection = 'CASE WHEN kind IN (1) THEN selected_id ELSE NULL END';
        $clause = 'selected_id IS NULL OR selected_id > 0';
        $row = ['table_name' => 'fixture', 'constraint_name' => 'fixture_selection', 'clause' => $clause,
            'enforced' => true, 'validated' => true, 'native_nodes' => 'fixture nodes',
            'checked_columns' => '["selected_id"]', 'integer_ge_oids' => '["524"]'];
        $this->calling($connection)->fetchAllAssociative = static function (string $sql) use (&$queries, $row, $projection): array {
            $queries[] = $sql;
            if (str_contains($sql, 'FROM pg_catalog.pg_constraint')) {
                return [$row];
            }
            if (str_contains($sql, 'pg_catalog.pg_attribute')) {
                return [['table_name' => 'fixture', 'column_name' => 'items_id', 'generated' => 's', 'expression' => $projection]];
            }
            return [];
        };
        $snapshot = NativeCheckCatalog::snapshot($connection);
        $this->array($snapshot['checks']['fixture']['fixture_selection'])->isIdenticalTo($row);
        $this->boolean($snapshot['mysql'])->isFalse();
        $this->boolean($snapshot['ansi_quotes'])->isFalse();
        $this->array(BooleanDomainSchema::differences($connection, new Schema(), $snapshot))->isEmpty();
        $policies = ['fixture' => ['items_id' => ['constraint' => 'fixture_selection', 'projection' => $projection,
            'check' => $clause, 'discriminators' => [], 'integer_discriminators' => ['kind']]]];
        $this->array(NativeSubjectSchema::differences($connection, $policies, $snapshot))->isEmpty();
        $reads = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'FROM pg_catalog.pg_constraint')));
        $this->integer(count($reads))->isIdenticalTo(1);
        $this->string($reads[0])->contains('c.conbin::text AS native_nodes')
            ->contains('WITH ORDINALITY')->contains('pg_catalog.pg_operator')->contains('pg_catalog.pg_table_is_visible');
    }

    public function testInheritedChecksAcceptCapturedPostgreSQLArraySerialization(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-reference-pg-width.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['pg14_policies', 'pg18_policies'] as $version) {
            $this->integer(count($facts[$version]))->isIdenticalTo(6);
            foreach ($facts[$version] as $entry) {
                $policy = $entry['policy'];
                $row = $entry['actual'];
                $selected = ['glpi_entities' => ['captured' => $policy]];
                $snapshot = ['mysql' => false, 'ansi_quotes' => false,
                    'checks' => ['glpi_entities' => [$policy['constraint'] => $row]]];
                $this->boolean(SubjectPolicyExpression::equivalent(
                    $policy['check'],
                    $row['clause'],
                    true,
                    integerTypes: $policy['integer_types'],
                    stringSelections: $policy['string_selections']
                ))->isTrue();
                $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
                $diagnostic = 'Changed, missing or unenforced native inherited reference CHECK: glpi_entities.' . $policy['constraint'];
                $positionedNodes = preg_replace('/ :list_start -?[0-9]+ :list_end -?[0-9]+/', '', $row['native_nodes']);
                $positionedNodes = str_replace(':multidims false :location', ':multidims false :list_start -1 :list_end -1 :location', $positionedNodes);
                foreach (['', ':list_start 0 :list_end 27', ':list_start -2 :list_end -3'] as $positions) {
                    $rendered = $snapshot;
                    $rendered['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] =
                        str_replace(' :list_start -1 :list_end -1', $positions === '' ? '' : ' ' . $positions, $positionedNodes);
                    $this->array(NativeReferenceSchema::compare($selected, $rendered))->isEmpty();
                }
                foreach ([$row['native_nodes'] . ' {ARRAY}', $row['native_nodes'] . ' {ARRAYEXPR}',
                    preg_replace('/\{(?:ARRAYEXPR|ARRAY) :array_typeid/', '{ARRAY_UNKNOWN :array_typeid', $row['native_nodes'])] as $nodes) {
                    $damaged = $snapshot;
                    $damaged['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] = $nodes;
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
                foreach ([
                    ':list_start -1', ':list_end -1',
                    ':list_start nope :list_end -1', ':list_start -1 :list_end nope',
                    ':list_end -1 :list_start -1', ':list_start -1 :list_start -1 :list_end -1',
                ] as $positions) {
                    $damaged = $snapshot;
                    $damaged['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] =
                        str_replace(':list_start -1 :list_end -1', $positions, $positionedNodes);
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
            }
        }
    }

    public function testInheritedChecksAcceptCapturedPostgreSQLWidthRewrite(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-reference-pg-width.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->integer(count($facts['six_policies']))->isIdenticalTo(6);
        foreach ($facts['six_policies'] as $entry) {
            $policy = $entry['policy'];
            $row = $entry['actual'];
            $selected = ['glpi_entities' => ['captured' => $policy]];
            $snapshot = ['mysql' => false, 'ansi_quotes' => false,
                'checks' => ['glpi_entities' => [$policy['constraint'] => $row]]];
            $equivalent = static fn (string $clause, array $modes): bool => SubjectPolicyExpression::equivalent(
                $policy['check'],
                $clause,
                true,
                integerTypes: $policy['integer_types'],
                stringSelections: $modes
            );
            $this->boolean($equivalent($row['clause'], $policy['string_selections']))->isTrue();
            $this->boolean($equivalent($row['clause'], []))->isFalse();
            $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
            $diagnostic = 'Changed, missing or unenforced native inherited reference CHECK: glpi_entities.' . $policy['constraint'];
            $olderTag = $snapshot;
            $olderTag['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] =
                str_replace('{ARRAYEXPR :array_typeid', '{ARRAY :array_typeid', $row['native_nodes']);
            $this->array(NativeReferenceSchema::compare($selected, $olderTag))->isEmpty();
            foreach ([true => ':list_start -1 :list_end -1', false => ':list_start -1'] as $admitted => $positions) {
                $annotated = $snapshot;
                $annotated['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] =
                    str_replace(':multidims false :location', ':multidims false ' . $positions . ' :location', $row['native_nodes']);
                $this->array(NativeReferenceSchema::compare($selected, $annotated))
                    ->isIdenticalTo($admitted ? [] : [$diagnostic]);
            }
            foreach ([
                str_replace('::character varying', '::character varying(1)', $row['clause']),
                str_replace('::character varying', '::integer', $row['clause']),
                str_replace("('explicit'::character varying)::text", "lower('explicit'::character varying)::text", $row['clause']),
                str_replace("('explicit'::character varying)::text", "('explicit'::character varying)::text COLLATE \"C\"", $row['clause']),
                str_replace("('explicit'::character varying)::text", "('explicit'::character varying)", $row['clause']),
                $row['clause'] . ' OR 1 = 1',
            ] as $clause) {
                $this->boolean($equivalent($clause, $policy['string_selections']))->isFalse();
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['clause'] = $clause;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach ([
                str_replace(':consttypmod -1', ':consttypmod 5', $row['native_nodes']),
                str_replace(':resulttypmod -1', ':resulttypmod 5', $row['native_nodes']),
                str_replace(':array_typeid 1009', ':array_typeid 999', $row['native_nodes']),
                str_replace(':element_typeid 25', ':element_typeid 999', $row['native_nodes']),
                str_replace(':constcollid 100', ':constcollid 999', $row['native_nodes']),
                str_replace(':relabelformat 1', ':relabelformat 2', $row['native_nodes']),
                str_replace(':opno 98', ':opno 999', $row['native_nodes']),
                $row['native_nodes'] . ' {RELABELTYPE :arg {CONST}}',
                $row['native_nodes'] . ' {FUNCEXPR :funcid 999}',
                $row['native_nodes'] . ' {CASETESTEXPR :typeId 1043 :typeMod -1 :collation 0}',
                $row['native_nodes'] . ' {ARRAYEXPR}',
            ] as $nodes) {
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] = $nodes;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
            $identity = json_decode($row['reference_text_coercion'], true, 512, JSON_THROW_ON_ERROR);
            foreach ([null, array_replace($identity, ['binary' => false]), array_replace($identity, ['varchar' => '999'])] as $changed) {
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['reference_text_coercion'] = $changed;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
        }
    }

    public function testInheritedChecksAcceptCapturedPostgreSQLVarcharArrayRendering(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-reference-pg.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->integer(count($facts['six_policies']))->isIdenticalTo(6);
        foreach ($facts['six_policies'] as $entry) {
            $policy = $entry['policy'];
            $row = $entry['actual'];
            $selected = ['glpi_entities' => ['captured' => $policy]];
            $snapshot = ['mysql' => false, 'ansi_quotes' => false,
                'checks' => ['glpi_entities' => [$policy['constraint'] => $row]]];
            $equivalent = static fn (string $clause, array $modes): bool => SubjectPolicyExpression::equivalent(
                $policy['check'],
                $clause,
                true,
                integerTypes: $policy['integer_types'],
                stringSelections: $modes
            );
            $this->boolean($equivalent($row['clause'], $policy['string_selections']))->isTrue();
            $this->boolean($equivalent($row['clause'], []))->isFalse('Varchar-array rendering is authorized only by declared mode policy');
            $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
            $diagnostic = 'Changed, missing or unenforced native inherited reference CHECK: glpi_entities.' . $policy['constraint'];
            $plain = $snapshot;
            unset($plain['checks']['glpi_entities'][$policy['constraint']]['reference_text_coercion']);
            $plain['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] = '{BOOLEXPR {OPEXPR :opno 98 {VAR} {CONST}} {NULLTEST {VAR}}}';
            $this->array(NativeReferenceSchema::compare($selected, $plain))->isEmpty('Old native shapes do not require coercion identity facts');
            foreach (['{CASETESTEXPR :typeId 1043 :typeMod -1 :collation 0}', '{ARRAYCOERCEEXPR}'] as $orphan) {
                $damaged = $plain;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] .= ' ' . $orphan;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }

            foreach ([
                str_replace('::character varying', '::character varying(1)', $row['clause']),
                str_replace('::character varying', '::varchar(1)', $row['clause']),
                str_replace('::character varying', '::integer', $row['clause']),
                str_replace('::text[]', '::integer[]', $row['clause']),
                str_replace('::text[]', '::varchar(1)[]', $row['clause']),
                str_replace('::text[]', '', $row['clause']),
                str_replace('(' . $policy['mode_column'] . ')::text', '(' . $policy['mode_column'] . ')::varchar(1)::text', $row['clause']),
                str_replace("'explicit'::character varying", "lower('explicit')::character varying", $row['clause']),
                $row['clause'] . ' OR 1 = 1',
            ] as $clause) {
                $this->boolean($equivalent($clause, $policy['string_selections']))->isFalse();
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['clause'] = $clause;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach ([
                str_replace(':consttypmod -1', ':consttypmod 5', $row['native_nodes']),
                str_replace(':typeMod -1', ':typeMod 5', $row['native_nodes']),
                str_replace(':resulttypmod -1', ':resulttypmod 5', $row['native_nodes']),
                str_replace(':array_typeid 1015', ':array_typeid 999', $row['native_nodes']),
                str_replace(':element_typeid 1043', ':element_typeid 999', $row['native_nodes']),
                str_replace(':typeId 1043', ':typeId 999', $row['native_nodes']),
                str_replace(':multidims false', ':multidims true', $row['native_nodes']),
                str_replace(':opno 98', ':opno 999', $row['native_nodes']),
                $row['native_nodes'] . $row['native_nodes'],
                $row['native_nodes'] . ' {CASETESTEXPR :typeId 1043 :typeMod -1 :collation 0}',
                $row['native_nodes'] . ' {ARRAYCOERCEEXPR}',
                $row['native_nodes'] . ' {FUNCEXPR :funcid 999}',
            ] as $nodes) {
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['native_nodes'] = $nodes;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach ([null, ['varchar' => '999', 'varchar_array' => '1015', 'text' => '25', 'text_array' => '1009', 'binary' => true],
                array_replace($row['reference_text_coercion'], ['binary' => false])] as $identity) {
                $damaged = $snapshot;
                $damaged['checks']['glpi_entities'][$policy['constraint']]['reference_text_coercion'] = $identity;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
        }
        $modes = ['calendar_mode' => ['explicit', 'inherit']];
        $expected = "calendar_mode IN ('explicit', 'inherit')";
        $this->boolean(SubjectPolicyExpression::equivalent(
            $expected,
            "calendar_mode = ANY ((ARRAY['explicit'::varchar, 'inherit'::varchar])::text[]))",
            true,
            stringSelections: $modes
        ))->isFalse('Trailing token');
        $this->boolean(SubjectPolicyExpression::equivalent(
            $expected,
            "calendar_mode = ANY ((ARRAY['explicit'::varchar, 'inherit'::varchar])::text[])",
            true,
            stringSelections: $modes
        ))->isTrue();
        $this->boolean(SubjectPolicyExpression::equivalent(
            $expected,
            "calendar_mode = ANY (ARRAY['explicit'::text, 'inherit'::text])",
            true,
            stringSelections: $modes
        ))->isTrue('Existing direct text-array form remains supported');
        $this->boolean(SubjectPolicyExpression::equivalent(
            $expected,
            "calendar_mode = ANY (ARRAY[('explicit'::text), 'inherit'::text::text])",
            true,
            stringSelections: $modes
        ))->isTrue('Prior text literal forms remain unchanged');
        $this->boolean(SubjectPolicyExpression::equivalent("calendar_mode = 'explicit'", "calendar_mode = 'explicit'::character varying", true, stringSelections: $modes))
            ->isFalse('Literal varchar casts are not accepted outside declared ARRAY choice context');
    }

    public function testInheritedChecksDeriveFromSixOwningModeDeclarations(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $owner = new BaselineSchema($manager);
            $schema = $owner->build($platform);
            $policies = array_map(static fn (array $fields): array => array_filter($fields, static fn (array $policy): bool => !isset($policy['kind'])), $owner->referencePolicies());
            $this->integer(count($policies['glpi_entities']))->isIdenticalTo(6);
            $snapshot = ['mysql' => !$platform instanceof PostgreSQLPlatform, 'ansi_quotes' => false, 'checks' => []];
            $metadata = $manager->getClassMetadata(EntityRecord::class);
            foreach ($policies['glpi_entities'] as $property => $policy) {
                $this->array(array_keys($policy['string_selections']))->isIdenticalTo([$policy['mode_column']]);
                $historical = EntityConfigurationReferences::checkSql($policy['selected_column']);
                $historical = substr($historical, strpos($historical, ' CHECK (') + 8, -1);
                $this->boolean(SubjectPolicyExpression::equivalent(
                    $policy['check'],
                    $historical,
                    !$snapshot['mysql'],
                    integerTypes: $policy['integer_types'],
                    stringSelections: $policy['string_selections']
                ))->isTrue();
                $row = ['clause' => $historical, 'enforced' => true, 'validated' => true,
                    'checked_columns' => json_encode([$policy['mode_column'], $policy['selected_column']]),
                    'native_nodes' => '{BOOLEXPR {OPEXPR :opno 10 {VAR} {CONST}} {NULLTEST {VAR}}}',
                    'reference_operator_oids' => '["10"]'];
                $snapshot['checks']['glpi_entities'][$policy['constraint']] = $row;
                $selected = ['glpi_entities' => [$property => $policy]];
                $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
                $diagnostic = 'Changed, missing or unenforced native inherited reference CHECK: glpi_entities.' . $policy['constraint'];
                foreach ([
                    $historical . ' OR 1 = 1',
                    str_replace("<> 'explicit'", "= 'explicit'", $historical),
                    str_replace('> 0', '>= 0', $historical),
                    str_replace(' IS NULL', ' IS NOT NULL', $historical),
                    str_replace("'inherit'", "'arbitrary'", $historical),
                ] as $changedClause) {
                    if ($changedClause === $historical) {
                        continue;
                    }
                    $damaged = $snapshot;
                    $damaged['checks']['glpi_entities'][$policy['constraint']]['clause'] = $changedClause;
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
                foreach (['enforced', 'validated'] as $flag) {
                    if ($flag === 'validated' && $snapshot['mysql']) {
                        continue;
                    }
                    $damaged = $snapshot;
                    $damaged['checks']['glpi_entities'][$policy['constraint']][$flag] = false;
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
                $damaged = $snapshot;
                unset($damaged['checks']['glpi_entities'][$policy['constraint']]);
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                if (!$snapshot['mysql']) {
                    foreach (['native_nodes' => '{FUNCEXPR :funcid 10}', 'reference_operator_oids' => '["999"]',
                        'checked_columns' => '["wrong"]'] as $fact => $value) {
                        $damaged = $snapshot;
                        $damaged['checks']['glpi_entities'][$policy['constraint']][$fact] = $value;
                        $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                    }
                }
                $declaration = (new ReflectionProperty(EntityRecord::class, $property))->getAttributes(ReferencePolicy::class)[0]->newInstance();
                $changed = new ReferencePolicy(
                    ReferenceKind::Inherited,
                    $declaration->modeProperty,
                    $declaration->emptyZero,
                    nativeConstraint: 'changed_native_name'
                );
                $newPolicy = $changed->nativeSelectionPolicy(
                    $metadata,
                    new ReflectionProperty(EntityRecord::class, $property),
                    $schema->getTable('glpi_entities'),
                    $platform
                );
                $this->string($newPolicy['constraint'])->isIdenticalTo('changed_native_name');
                $this->string($newPolicy['check'])->isIdenticalTo($policy['check']);
            }
            $this->array(NativeReferenceSchema::compare($policies, $snapshot))->isEmpty();
        }
    }

    public function testRootAndCalendarChecksDeriveFromTheirOwningMetadata(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $owner = new BaselineSchema($manager);
            $schema = $owner->build($platform);
            $all = $owner->referencePolicies();
            $this->integer(count($all['glpi_entities']))->isIdenticalTo(7);
            $this->integer(count($all['glpi_slms']))->isIdenticalTo(1);
            foreach ([[EntityRecord::class, 'parent', EntityParents::checkSql()],
                [SLMRecord::class, 'calendars', ServiceLevelCalendars::checkSql()]] as [$class, $property, $historical]) {
                $metadata = $manager->getClassMetadata($class);
                $table = $schema->getTable($metadata->getTableName());
                $declaration = (new ReflectionProperty($class, $property))->getAttributes(ReferencePolicy::class)[0]->newInstance();
                $policy = $declaration->nativeSelectionPolicy($metadata, new ReflectionProperty($class, $property), $table, $platform);
                $this->array($policy)->isIdenticalTo($all[$table->getName()][$property]);
                $historical = substr($historical, strpos($historical, ' CHECK (') + 8, -1);
                $this->boolean(SubjectPolicyExpression::equivalent(
                    $policy['check'],
                    $historical,
                    $platform instanceof PostgreSQLPlatform,
                    integerTypes: $policy['integer_types'],
                    integerPairs: $policy['integer_pairs'],
                    booleanColumns: $policy['boolean_columns']
                ))->isTrue();
                // Names derive from actual metadata, including a renamed table/join/identifier/flag.
                $metadata->table['name'] = 'renamed_owner';
                $renamed = new Table('renamed_owner', array_map(static fn (Column $column): Column => clone $column, array_values($table->getColumns())));
                $selected = $policy['selected_column'];
                $renamed->renameColumn($selected, 'renamed_selection');
                $metadata->associationMappings[$property]->joinColumns[0]->name = 'renamed_selection';
                if ($class === EntityRecord::class) {
                    $id = $metadata->getIdentifierFieldNames()[0];
                    $oldId = trim($metadata->fieldMappings[$id]->columnName, '`"');
                    $renamed->renameColumn($oldId, 'renamed_identity');
                    $metadata->fieldMappings[$id]->columnName = 'renamed_identity';
                    $metadata->associationMappings[$property]->joinColumns[0]->referencedColumnName = 'renamed_identity';
                } else {
                    $oldFlag = trim($metadata->fieldMappings['use_ticket_calendar']->columnName, '`"');
                    $renamed->renameColumn($oldFlag, 'renamed_flag');
                    $metadata->fieldMappings['use_ticket_calendar']->columnName = 'renamed_flag';
                }
                $changed = $declaration->nativeSelectionPolicy($metadata, new ReflectionProperty($class, $property), $renamed, $platform);
                $this->string($changed['selected_column'])->isIdenticalTo('renamed_selection');
                $this->string($changed['check'])->contains($class === EntityRecord::class ? 'renamed_identity' : 'renamed_flag');
                $renamed->getColumn('renamed_selection')->setNotnull(true);
                $this->exception(static fn () => $declaration->nativeSelectionPolicy(
                    $metadata,
                    new ReflectionProperty($class, $property),
                    $renamed,
                    $platform
                ))->isInstanceOf(LogicException::class);
            }
        }
        foreach ([static fn () => new ReferencePolicy(ReferenceKind::Audience, nativeConstraint: 'wrong_kind'),
            static fn () => new ReferencePolicy(ReferenceKind::RootParent, excludedByBooleanProperty: 'flag'),
            static fn () => new ReferencePolicy(ReferenceKind::EmptySelection, nativeConstraint: 'no_flag')] as $invalid) {
            $this->exception($invalid)->isInstanceOf(InvalidArgumentException::class);
        }
    }

    public function testNativeReferenceMetadataRejectsUnsupportedOwnedShapes(): void
    {
        foreach (['required_join', 'foreign_root', 'wrong_identifier', 'nullable_flag', 'wrong_flag_type', 'readonly_flag', 'missing_flag'] as $variant) {
            $platform = new PostgreSQLPlatform();
            $manager = $this->manager($platform);
            $class = in_array($variant, ['required_join', 'foreign_root', 'wrong_identifier'], true) ? EntityRecord::class : SLMRecord::class;
            $property = $class === EntityRecord::class ? 'parent' : 'calendars';
            $metadata = $manager->getClassMetadata($class);
            $table = (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata())->getTable($metadata->getTableName());
            $declaration = (new ReflectionProperty($class, $property))->getAttributes(ReferencePolicy::class)[0]->newInstance();
            if ($variant === 'required_join') {
                $metadata->associationMappings[$property]->joinColumns[0]->nullable = false;
            } elseif ($variant === 'foreign_root') {
                $association = $metadata->associationMappings[$property];
                $mapping = $association->toArray();
                $mapping['targetEntity'] = Calendar::class;
                // Doctrine association targets are readonly constructor arguments.
                $metadata->associationMappings[$property] = $association::fromMappingArray($mapping);
                $this->array($metadata->associationMappings[$property]->toArray())->isIdenticalTo($mapping);
            } elseif ($variant === 'wrong_identifier') {
                $metadata->fieldMappings[$metadata->getIdentifierFieldNames()[0]]->type = Types::STRING;
            } elseif ($variant === 'nullable_flag') {
                $table->getColumn('use_ticket_calendar')->setNotnull(false);
            } elseif ($variant === 'wrong_flag_type') {
                $metadata->fieldMappings['use_ticket_calendar']->type = Types::INTEGER;
            } elseif ($variant === 'readonly_flag') {
                $metadata->fieldMappings['use_ticket_calendar']->notUpdatable = true;
            } else {
                $declaration = new ReferencePolicy(ReferenceKind::EmptySelection, nativeConstraint: 'declared_selection', excludedByBooleanProperty: 'missing');
            }
            $this->exception(static fn () => $declaration->nativeSelectionPolicy(
                $metadata,
                new ReflectionProperty($class, $property),
                $table,
                $platform
            ))->isInstanceOf(LogicException::class);
        }
    }

    public function testIntegerPairAndBooleanGrammarRequireExplicitOwnership(): void
    {
        $root = '(id = 0 AND parent IS NULL) OR (id > 0 AND parent IS NOT NULL AND parent >= 0 AND parent <> id)';
        $types = ['id' => Types::BIGINT, 'parent' => Types::BIGINT];
        $this->boolean(SubjectPolicyExpression::equivalent($root, $root, true, integerTypes: $types))->isFalse();
        $this->boolean(SubjectPolicyExpression::equivalent(
            $root,
            str_replace('parent <> id', 'id <> parent', $root),
            true,
            integerTypes: $types,
            integerPairs: [['parent', 'id']]
        ))->isTrue();
        foreach ([str_replace('parent <> id', 'parent <> other', $root),
            str_replace('parent >= 0', 'parent > 0', $root), str_replace('id = 0', 'id >= 0', $root),
            str_replace('parent <> id', 'parent = id', $root), $root . ' OR 1 = 1'] as $damaged) {
            $this->boolean(SubjectPolicyExpression::equivalent(
                $root,
                $damaged,
                true,
                integerTypes: $types,
                integerPairs: [['parent', 'id']]
            ))->isFalse();
        }
        $calendar = 'selected IS NULL OR (selected > 0 AND NOT flag)';
        $this->boolean(SubjectPolicyExpression::equivalent(
            $calendar,
            $calendar,
            true,
            integerTypes: ['selected' => Types::BIGINT]
        ))->isFalse();
        foreach ([true, false] as $postgres) {
            // Literal TRUE remains the declared positive boolean value, not a column.
            $this->boolean(SubjectPolicyExpression::equivalent(
                'flag = TRUE',
                'flag = true',
                $postgres,
                booleanColumns: ['flag']
            ))->isTrue();
            foreach (['flag = `true`', 'flag = "true"', "flag = 'true'"] as $quotedTrue) {
                $this->boolean(SubjectPolicyExpression::equivalent(
                    'flag = TRUE',
                    $quotedTrue,
                    $postgres,
                    booleanColumns: ['flag']
                ))->isFalse();
            }
            foreach (['NOT flag', 'flag = FALSE', ...($postgres ? [] : ['flag = 0'])] as $falseForm) {
                $this->boolean(SubjectPolicyExpression::equivalent(
                    $calendar,
                    str_replace('NOT flag', $falseForm, $calendar),
                    $postgres,
                    integerTypes: ['selected' => Types::BIGINT],
                    booleanColumns: ['flag']
                ))->isTrue();
            }
            foreach (['NOT other', 'flag = TRUE', 'flag IS NULL', 'NOT (selected > 0)', 'flag = 2',
                'flag = `false`', 'flag = `true`', 'flag = "false"', 'flag = "true"',
                "flag = 'false'", "flag = 'true'", 'COALESCE(flag, FALSE) = FALSE'] as $damaged) {
                $this->boolean(SubjectPolicyExpression::equivalent(
                    $calendar,
                    str_replace('NOT flag', $damaged, $calendar),
                    $postgres,
                    integerTypes: ['selected' => Types::BIGINT],
                    booleanColumns: ['flag']
                ))->isFalse();
            }
        }
        $this->boolean(SubjectPolicyExpression::equivalent(
            'flag = TRUE',
            'flag = "true"',
            false,
            true,
            booleanColumns: ['flag']
        ))->isFalse();
        foreach (['flag = "false"', 'flag = "true"'] as $quoted) {
            $this->boolean(SubjectPolicyExpression::equivalent(
                $calendar,
                str_replace('NOT flag', $quoted, $calendar),
                false,
                true,
                integerTypes: ['selected' => Types::BIGINT],
                booleanColumns: ['flag']
            ))->isFalse();
        }
    }

    public function testCapturedMySQL84CalendarSelectionRequiresDeclaredFalseEquality(): void
    {
        // ROOT CI job114343313026, MySQL8.4.11: exact enforced CHECK_CLAUSE.
        $actual = '((`calendars_id` is null) or ((`calendars_id` > 0) and (0 = `use_ticket_calendar`)))';
        $owner = new BaselineSchema($this->manager(new MySQLPlatform()));
        $owner->build(new MySQLPlatform());
        $policy = $owner->referencePolicies()['glpi_slms']['calendars'];
        $policies = ['glpi_slms' => ['calendars' => $policy]];
        $snapshot = ['mysql' => true, 'ansi_quotes' => false,
            'checks' => ['glpi_slms' => [$policy['constraint'] => ['clause' => $actual, 'enforced' => 'YES']]]];
        $this->array(NativeReferenceSchema::compare($policies, $snapshot))->isEmpty();
        $diagnostic = 'Changed, missing or unenforced native reference CHECK: glpi_slms.glpi_slms_calendar_selection';
        foreach (['0 = `other`', '`false` = `use_ticket_calendar`', 'FALSE = `use_ticket_calendar`',
            '`0` = `use_ticket_calendar`', "'0' = `use_ticket_calendar`",
            '1 = `use_ticket_calendar`', '2 = `use_ticket_calendar`',
            'NULL = `use_ticket_calendar`', '0 = `calendars_id`', '0 <> `use_ticket_calendar`',
            '0 >= `use_ticket_calendar`', '0 = COALESCE(`use_ticket_calendar`, 0)',
            '0 = CAST(`use_ticket_calendar` AS BINARY)'] as $changed) {
            $damaged = $snapshot;
            $damaged['checks']['glpi_slms'][$policy['constraint']]['clause'] = str_replace('0 = `use_ticket_calendar`', $changed, $actual);
            $this->array(NativeReferenceSchema::compare($policies, $damaged))->isIdenticalTo([$diagnostic]);
        }
        foreach (['clause' => $actual . ' OR 1 = 1', 'enforced' => 'NO'] as $fact => $value) {
            $damaged = $snapshot;
            $damaged['checks']['glpi_slms'][$policy['constraint']][$fact] = $value;
            $this->array(NativeReferenceSchema::compare($policies, $damaged))->isIdenticalTo([$diagnostic]);
        }
        $damaged = $snapshot;
        $damaged['checks']['glpi_slms'][$policy['constraint']]['clause'] = '(' . $actual . ') AND other_owner_id = 0';
        $this->array(NativeReferenceSchema::compare($policies, $damaged))->isIdenticalTo([$diagnostic]);
        $undeclared = $policies;
        $undeclared['glpi_slms']['calendars']['boolean_columns'] = [];
        $this->array(NativeReferenceSchema::compare($undeclared, $snapshot))->isIdenticalTo([$diagnostic]);
        $this->boolean(SubjectPolicyExpression::equivalent('NOT flag', '0 = flag', true, booleanColumns: ['flag']))->isFalse();
    }

    public function testCapturedRootAndCalendarChecksBindActualPostgreSQLAndMariaFacts(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-root-slm.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($facts['postgres'] as $provider => $capture) {
            $owner = new BaselineSchema($this->manager(new PostgreSQLPlatform()));
            $owner->build(new PostgreSQLPlatform());
            $all = $owner->referencePolicies();
            $this->integer(count($capture['checks']))->isIdenticalTo(2);
            foreach ($capture['checks'] as $row) {
                $this->string($row['server_version_num'])->startWith($provider === 'pg14' ? '14' : '18');
                $operators = [];
                foreach ($capture['operator_catalog'] as $operation) {
                    // These raw records are ROOT captures, not a handwritten OID allowlist.
                    // Assert the same finite builtin signature filter used by NativeCheckCatalog.
                    $this->string($operation['function_namespace'])->isIdenticalTo('pg_catalog');
                    $this->string($operation['result_type'])->isIdenticalTo($row['selection_type_identity']['boolean']);
                    $this->string($operation['function_result'])->isIdenticalTo($row['selection_type_identity']['boolean']);
                    $this->boolean($operation['returns_set'])->isFalse();
                    $this->string($operation['variadic_type'])->isIdenticalTo('0');
                    $this->string($operation['volatility'])->isIdenticalTo('i');
                    $this->string($operation['function_arguments'])->isIdenticalTo($operation['left_type'] . ' ' . $operation['right_type']);
                    $operators[$operation['oid']] = array_intersect_key(
                        $operation,
                        array_flip(['name', 'left_type', 'right_type', 'function_oid'])
                    );
                }
                // Adapt aliases to the current snapshot; the raw captured fixture is preserved.
                $row['selection_operator_bindings'] = $operators;
                $row['reference_text_coercion'] = $row['selection_type_identity'];
                $table = $row['table_name'];
                $property = $table === 'glpi_entities' ? 'parent' : 'calendars';
                $policy = $all[$table][$property];
                $selected = [$table => [$property => $policy]];
                $snapshot = ['mysql' => false, 'ansi_quotes' => false, 'checks' => [$table => [$policy['constraint'] => $row]]];
                $diagnostic = 'Changed, missing or unenforced native reference CHECK: ' . $table . '.' . $policy['constraint'];
                $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
                foreach (['enforced' => false, 'validated' => false, 'clause' => $row['clause'] . ' OR 1 = 1',
                    'selection_operator_bindings' => [], 'selection_column_identity' => []] as $fact => $value) {
                    $damaged = $snapshot;
                    $damaged['checks'][$table][$policy['constraint']][$fact] = $value;
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
                preg_match('/:opfuncid ([0-9]+)/', $row['native_nodes'], $function);
                foreach ([':opfuncid ' . $function[1] => ':opfuncid 999', ':varno 1' => ':varno 2',
                    ':vartypmod -1' => ':vartypmod 1', ':varcollid 0' => ':varcollid 100',
                    ':opresulttype 16' => ':opresulttype 20', ':opretset false' => ':opretset true',
                    ':opcollid 0' => ':opcollid 100', ':inputcollid 0' => ':inputcollid 100'] as $from => $to) {
                    $damaged = $snapshot;
                    $damaged['checks'][$table][$policy['constraint']]['native_nodes'] = str_replace($from, $to, $row['native_nodes']);
                    $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                }
                $damaged = $snapshot;
                $damaged['checks'][$table][$policy['constraint']]['selection_column_identity'][$policy['selected_column']]['not_null'] = true;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
                unset($damaged['checks'][$table][$policy['constraint']]);
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
        }
        $settings = $facts['maria'][0][0];
        $this->string($settings['version'])->startWith('10.11.');
        $this->string($settings['foreign_key_checks'])->isIdenticalTo('1');
        $this->string($settings['check_constraint_checks'])->isIdenticalTo('1');
        $this->integer(count($facts['maria'][3]))->isIdenticalTo(2);
        $columns = [];
        foreach ($facts['maria'][4] as $column) {
            $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
        }
        $owner = new BaselineSchema($this->manager(new MariaDBPlatform()));
        $owner->build(new MariaDBPlatform());
        $all = $owner->referencePolicies();
        foreach ($facts['maria'][3] as $captured) {
            $table = $captured['TABLE_NAME'];
            $property = $table === 'glpi_entities' ? 'parent' : 'calendars';
            $policy = $all[$table][$property];
            foreach ($policy['column_nullable'] as $column => $nullable) {
                $this->string($columns[$table][$column]['IS_NULLABLE'])->isIdenticalTo($nullable ? 'YES' : 'NO');
                $this->string($columns[$table][$column]['EXTRA'])->isIdenticalTo('');
            }
            $snapshot = ['mysql' => true, 'ansi_quotes' => in_array('ANSI_QUOTES', explode(',', $settings['sql_mode']), true),
                'checks' => [$table => [$captured['CONSTRAINT_NAME'] => ['clause' => $captured['CHECK_CLAUSE'], 'enforced' => 'YES']]]];
            $selected = [$table => [$property => $policy]];
            $this->array(NativeReferenceSchema::compare($selected, $snapshot))->isEmpty();
            $diagnostic = 'Changed, missing or unenforced native reference CHECK: ' . $table . '.' . $policy['constraint'];
            foreach ([$captured['CHECK_CLAUSE'] . ' OR 1 = 1',
                $table === 'glpi_entities' ? str_replace(' <> `id`', ' = `id`', $captured['CHECK_CLAUSE'])
                    : str_replace('`use_ticket_calendar` = 0', '`use_ticket_calendar` = 1', $captured['CHECK_CLAUSE'])] as $clause) {
                $damaged = $snapshot;
                $damaged['checks'][$table][$policy['constraint']]['clause'] = $clause;
                $this->array(NativeReferenceSchema::compare($selected, $damaged))->isIdenticalTo([$diagnostic]);
            }
        }
    }

    /** Controlled facts exercise guard rejection; these are not native capture claims. */
    public function testControlledIntegerReferenceNativeShapesRejectUnboundFacts(): void
    {
        $platform = new PostgreSQLPlatform();
        $owner = new BaselineSchema($this->manager($platform));
        $owner->build($platform);
        $all = $owner->referencePolicies();
        $var = static fn (int $attribute, int $type): string => '{VAR :varno 1 :varattno ' . $attribute . ' :vartype ' . $type
            . ' :vartypmod -1 :varcollid 0 :varlevelsup 0 :varnosyn 1 :varattnosyn ' . $attribute . ' :location -1}';
        $zero = '{CONST :consttype 23 :consttypmod -1 :constcollid 0 :constlen 4 :constbyval true :constisnull false :location -1 :constvalue 4 [ 0 0 0 0 0 0 0 0 ]}';
        $op = static fn (int $oid, string $left, string $right): string => '{OPEXPR :opno ' . $oid . ' :opfuncid ' . ($oid + 100)
            . ' :opresulttype 16 :opretset false :opcollid 0 :inputcollid 0 :args (' . $left . ' ' . $right . ') :location -1}';
        $null = static fn (string $arg, int $kind): string => '{NULLTEST :arg ' . $arg . ' :nulltesttype ' . $kind . ' :argisrow false :location -1}';
        $junction = static fn (string $kind, array $args): string => '{BOOLEXPR :boolop ' . $kind . ' :args (' . implode(' ', $args) . ') :location -1}';
        $operators = [];
        foreach ([1001 => ['=', 20, 23], 1002 => ['>', 20, 23], 1003 => ['>=', 20, 23], 1004 => ['<>', 20, 20]] as $oid => [$name, $left, $right]) {
            $operators[$oid] = ['name' => $name, 'left_type' => (string)$left, 'right_type' => (string)$right, 'function_oid' => (string)($oid + 100)];
        }
        foreach (['glpi_entities' => 'parent', 'glpi_slms' => 'calendars'] as $table => $property) {
            $policy = $all[$table][$property];
            $identity = [];
            $attributes = [];
            foreach ($policy['column_nullable'] as $column => $nullable) {
                $attribute = count($attributes) + 1;
                $type = in_array($column, $policy['boolean_columns'], true) ? 16 : 20;
                $attributes[$column] = $var($attribute, $type);
                $identity[$column] = ['attribute_number' => (string)$attribute, 'type_oid' => (string)$type,
                    'type_modifier' => '-1', 'collation_oid' => '0', 'not_null' => !$nullable, 'generated' => ''];
            }
            $selected = $attributes[$policy['selected_column']];
            if ($table === 'glpi_entities') {
                $id = $attributes['id'];
                $nodes = $junction('or', [$junction('and', [$op(1001, $id, $zero), $null($selected, 0)]),
                    $junction('and', [$op(1002, $id, $zero), $null($selected, 1), $op(1003, $selected, $zero), $op(1004, $selected, $id)])]);
            } else {
                $nodes = $junction('or', [$null($selected, 0), $junction('and', [$op(1002, $selected, $zero),
                    $junction('not', [$attributes['use_ticket_calendar']])])]);
            }
            $row = ['clause' => $policy['check'], 'enforced' => true, 'validated' => true,
                'checked_columns' => array_keys($identity), 'selection_column_identity' => $identity,
                'reference_text_coercion' => ['boolean' => '16', 'smallint' => '21', 'integer' => '23', 'bigint' => '20'],
                'selection_operator_bindings' => $operators, 'native_nodes' => $nodes];
            $snapshot = ['mysql' => false, 'ansi_quotes' => false, 'checks' => [$table => [$policy['constraint'] => $row]]];
            $selectedPolicies = [$table => [$property => $policy]];
            $diagnostic = 'Changed, missing or unenforced native reference CHECK: ' . $table . '.' . $policy['constraint'];
            $this->array(NativeReferenceSchema::compare($selectedPolicies, $snapshot))->isEmpty();
            $unsupported = $selectedPolicies;
            $unsupported[$table][$property]['kind'] = ReferenceKind::Audience->value;
            $this->array(NativeReferenceSchema::compare($unsupported, $snapshot))->isIdenticalTo([$diagnostic]);
            $pg18 = $snapshot;
            $pg18['checks'][$table][$policy['constraint']]['native_nodes'] = str_replace(
                [':varcollid 0 :varlevelsup 0', ':varlevelsup 0 :varnosyn 1'],
                [':varcollid 0 :varnullingrels (b) :varlevelsup 0', ':varlevelsup 0 :varreturningtype 0 :varnosyn 1'],
                $nodes
            );
            $this->array(NativeReferenceSchema::compare($selectedPolicies, $pg18))->isEmpty();
            foreach ([ ':varnullingrels (b)' => ':varnullingrels (b 1)', ':varreturningtype 0' => ':varreturningtype 1'] as $from => $to) {
                $damaged = $pg18;
                $damaged['checks'][$table][$policy['constraint']]['native_nodes'] = str_replace($from, $to, $pg18['checks'][$table][$policy['constraint']]['native_nodes']);
                $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach (['enforced' => false, 'validated' => false, 'clause' => $policy['check'] . ' OR 1 = 1',
                'checked_columns' => ['wrong'], 'native_nodes' => '{FUNCEXPR :funcid 1}',
                'selection_column_identity' => [], 'selection_operator_bindings' => []] as $fact => $value) {
                $damaged = $snapshot;
                $damaged['checks'][$table][$policy['constraint']][$fact] = $value;
                $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach ([':varno 1' => ':varno 2', ':vartypmod -1' => ':vartypmod 1', ':varcollid 0' => ':varcollid 100',
                ':varlevelsup 0' => ':varlevelsup 1', ':opfuncid 1102' => ':opfuncid 999',
                ':opresulttype 16' => ':opresulttype 20', ':opretset false' => ':opretset true',
                ':inputcollid 0' => ':inputcollid 100', ':opcollid 0' => ':opcollid 100',
                ':constisnull false' => ':constisnull true', ':argisrow false' => ':argisrow true',
                ':constvalue 4 [ 0' => ':constvalue 4 [ 1'] as $from => $to) {
                $damaged = $snapshot;
                $damaged['checks'][$table][$policy['constraint']]['native_nodes'] = str_replace($from, $to, $nodes);
                $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
            }
            foreach (['type_oid' => '23', 'type_modifier' => '1', 'collation_oid' => '100',
                'not_null' => true, 'generated' => 's', 'attribute_number' => '99'] as $fact => $value) {
                $damaged = $snapshot;
                $damaged['checks'][$table][$policy['constraint']]['selection_column_identity'][$policy['selected_column']][$fact] = $value;
                $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
            }
            $damaged = $snapshot;
            $damaged['checks'][$table][$policy['constraint']]['selection_operator_bindings'][1002]['function_oid'] = '999';
            $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
            unset($damaged['checks'][$table][$policy['constraint']]);
            $this->array(NativeReferenceSchema::compare($selectedPolicies, $damaged))->isIdenticalTo([$diagnostic]);
        }
    }

    public function testInheritedModeGrammarIsBoundedByDeclaredColumnAndChoices(): void
    {
        $modes = ['calendar_mode' => ['explicit', 'inherit']];
        $expected = "calendar_mode IN ('explicit', 'inherit') AND calendar_mode <> 'explicit'";
        $native = "calendar_mode::text = ANY (ARRAY['explicit'::text, 'inherit'::text]::text[]) AND calendar_mode::text <> 'explicit'::text";
        $this->boolean(SubjectPolicyExpression::equivalent($expected, $native, true, stringSelections: $modes))->isTrue();
        $this->boolean(SubjectPolicyExpression::equivalent($expected, $native, true))->isFalse();
        foreach ([str_replace("'inherit'::text", "'unchanged'::text", $native),
            str_replace('calendar_mode', 'wrong_mode', $native), str_replace('::text[]', '::integer[]', $native),
            str_replace('calendar_mode::text', 'lower(calendar_mode)', $native),
            str_replace('calendar_mode::text', 'calendar_mode::varchar(1)', $native),
            $native . ' OR 1 = 1'] as $changed) {
            $this->boolean(SubjectPolicyExpression::equivalent($expected, $changed, true, stringSelections: $modes))->isFalse();
        }
        foreach (['missing_name', 'nullable_mode', 'wrong_enum', 'required_join', 'wrong_type', 'short_mode', 'wrong_default'] as $variant) {
            $platform = new MySQLPlatform();
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(EntityRecord::class);
            $table = (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata())->getTable('glpi_entities');
            $attribute = (new ReflectionProperty(EntityRecord::class, 'calendar'))->getAttributes(ReferencePolicy::class)[0]->newInstance();
            if ($variant === 'missing_name') {
                $attribute = new ReferencePolicy(ReferenceKind::Inherited, 'calendar_mode');
            } elseif ($variant === 'nullable_mode') {
                $metadata->fieldMappings['calendar_mode']->nullable = true;
            } elseif ($variant === 'wrong_enum') {
                $metadata->fieldMappings['calendar_mode']->enumType = null;
            } elseif ($variant === 'required_join') {
                $metadata->associationMappings['calendar']->joinColumns[0]->nullable = false;
            } elseif ($variant === 'wrong_type') {
                $table->modifyColumn('calendars_id', ['type' => Type::getType(Types::STRING)]);
            } elseif ($variant === 'short_mode') {
                $metadata->fieldMappings['calendar_mode']->length = 1;
            } else {
                $metadata->fieldMappings['calendar_mode']->options['default'] = 'arbitrary';
            }
            $this->exception(static fn () => $attribute->nativeSelectionPolicy(
                $metadata,
                new ReflectionProperty(EntityRecord::class, 'calendar'),
                $table,
                $platform
            ))->isInstanceOf(LogicException::class);
        }
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
            $connection = new Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
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
            $catalog = PhysicalIndexSchema::catalog($connection, ['glpi_items_devicesensors']);
            foreach ($values as $key => $value) {
                $this->boolean($catalog['glpi_items_devicesensors']['fixture_' . $key]['usable'])->isIdenticalTo($value === $usable);
            }
            $this->integer(count($queries))->isIdenticalTo(1);
            $this->string($queries[0][0])->contains($fragment)->notContains('IS_VISIBLE =')->notContains('IGNORED =');
            $this->array($queries[0][1])->isIdenticalTo([['glpi_items_devicesensors']]);
            $this->array($queries[0][2])->isIdenticalTo([ArrayParameterType::STRING]);
        }
    }

    public function testPhysicalCoverageCannotIntroduceUndeclaredUniqueness(): void
    {
        $platform = new PostgreSQLPlatform();
        $connection = new Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
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
            $this->array(PhysicalIndexSchema::differences($connection, $schema))
                ->isIdenticalTo(['Missing physical index coverage: physical_fixture.expected']);
        }
        $table->addUniqueIndex(['computers_id'], 'declared_unique');
        $this->array(PhysicalIndexSchema::differences($connection, $schema))
            ->isEmpty('A separately declared unique constraint also supports the same FK lookup');
    }

    public function testPhysicalIndexCoverageUsesColumnAndNativeSemantics(): void
    {
        $required = new Index('expected', ['"computers_id"']);
        $physical = ['columns' => ['computers_id', 'is_deleted'], 'lengths' => [null, null],
            'unique' => false, 'primary' => false, 'method' => 'btree', 'usable' => true,
            'predicate' => null, 'expressions' => null, 'standard_equality' => true, 'nulls_not_distinct' => false];
        $coverage = PhysicalIndexSchema::covers(...);
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
        $unique = new Index('unique', ['computers_id'], true);
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
        $diff = (new Comparator(new PostgreSQLPlatform()))->compareTables($declared, $changed);
        $this->integer(count($diff->getModifiedIndexes()))->isIdenticalTo(1, 'A named UNIQUE replacement still changes permitted rows');
        $this->boolean($diff->getModifiedIndexes()[0]->isUnique())->isTrue();
        $this->boolean($coverage($unique, array_replace($one, ['nulls_not_distinct' => true])))->isFalse();
        $primary = new Index('primary', ['computers_id'], true, true);
        $this->boolean($coverage($primary, $one))->isFalse();
        $this->boolean($coverage($primary, array_replace($one, ['primary' => true])))->isTrue();
        $prefix = new Index('prefix', ['name'], false, false, [], ['lengths' => [50]]);
        $text = array_replace($physical, ['columns' => ['name'], 'lengths' => [100]]);
        $this->boolean($coverage($prefix, $text))->isTrue();
        $this->boolean($coverage($prefix, array_replace($text, ['lengths' => [20]])))->isFalse();
        $fulltext = new Index('fulltext', ['name'], false, false, ['fulltext']);
        $text = array_replace($text, ['lengths' => [null], 'method' => 'fulltext']);
        $this->boolean($coverage($fulltext, $text))->isTrue();
        $this->boolean($coverage($fulltext, array_replace($text, ['method' => 'btree'])))->isFalse();
        $this->array(PhysicalIndexSchema::missing(['fixture' => [$required]], ['fixture' => ['expected' => array_replace($physical, ['columns' => ['items_id']])]]))
            ->isIdenticalTo(['fixture' => [$required]], 'An expected name on wrong columns cannot substitute for coverage');
    }

    public function testImportStorageAdmissionIncludesUnreferencedAuditTables(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new Connection([], (new DisconnectedSchemaConnection($platform))->getDriver());
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
                PluginImportMutation::assertTransactionalCore($connection, $aggregate);
                foreach (['MyISAM', null] as $nontransactional) {
                    $engine = $nontransactional;
                    if ($platform instanceof PostgreSQLPlatform) {
                        PluginImportMutation::assertTransactionalCore($connection, $aggregate);
                    } else {
                        $this->exception(static fn () => PluginImportMutation::assertTransactionalCore($connection, $aggregate))
                            ->isInstanceOf(RuntimeException::class)
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
            ['glpi_notificationchatconfigs', 5, 1, [], []],
            ['glpi_notificationtargets', 7, 5, [], ['notifications_id' => 'glpi_notifications', 'groups_id' => 'glpi_groups', 'profiles_id' => 'glpi_profiles']],
            ['glpi_notifications', 11, 8, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_notificationtemplates', 7, 5, [], []],
            ['glpi_notificationtemplatetranslations', 6, 2, [], ['`notificationtemplates_id`' => 'glpi_notificationtemplates']],
            ['glpi_notifications_notificationtemplates', 4, 5, [], [
                '`notifications_id`' => 'glpi_notifications', '`notificationtemplates_id`' => 'glpi_notificationtemplates',
            ]],
            ['glpi_oidc_config', 11, 1, [], []],
            ['glpi_oidc_mapping', 10, 1, [], []],
            ['glpi_oidc_users', 3, 2, [], ['user_id' => 'glpi_users']],
            ['glpi_profiles', 17, 8, ['tickettemplates_id', 'changetemplates_id', 'problemtemplates_id'], [
                '`tickettemplates_id`' => 'glpi_tickettemplates', '`changetemplates_id`' => 'glpi_changetemplates',
                '`problemtemplates_id`' => 'glpi_problemtemplates',
            ]],
            ['glpi_profilerights', 4, 3, [], ['`profiles_id`' => 'glpi_profiles']],
            ['glpi_profiles_users', 7, 6, [], [
                '`users_id`' => 'glpi_users', '`profiles_id`' => 'glpi_profiles', '`entities_id`' => 'glpi_entities',
            ]],
            ['glpi_calendars', 8, 6, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_holidays', 10, 8, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_calendarsegments', 7, 4, [], [
                '`calendars_id`' => 'glpi_calendars', '`entities_id`' => 'glpi_entities',
            ], ['begin' => ClockTimeType::NAME, 'end' => ClockTimeType::NAME]],
            ['glpi_calendars_holidays', 3, 4, [], [
                '`calendars_id`' => 'glpi_calendars', '`holidays_id`' => 'glpi_holidays',
            ]],
            ['glpi_domaintypes', 5, 3, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_domainrelations', 5, 3, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_domainrecordtypes', 5, 3, [], ['`entities_id`' => 'glpi_entities']],
            ['glpi_budgettypes', 5, 4, [], []],
            ['glpi_contacttypes', 5, 4, [], []],
            ['glpi_contracttypes', 5, 4, [], []],
            ['glpi_suppliertypes', 5, 4, [], []],
            ['glpi_projecttypes', 5, 4, [], []],
            ['glpi_projecttasktypes', 5, 4, [], []],
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
            ['glpi_devicebatterytypes', 5, 4, [], []],
            ['glpi_devicecasetypes', 5, 4, [], []],
            ['glpi_devicefirmwaretypes', 5, 4, [], []],
            ['glpi_devicegenerictypes', 3, 2, [], []],
            ['glpi_devicememorytypes', 5, 4, [], []],
            ['glpi_devicesensortypes', 3, 2, [], []],
            ['glpi_devicesimcardtypes', 5, 4, [], []],
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

    public function testOwnedTablesPreserveEveryCurrentColumnAndIndexAcrossProviders(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform);
            $frozenSql = $frozen->toSql($platform);
            // The existing current identity policy widens frozen IDs before inspection.
            IdentifierColumns::configureSchema($frozen);
            $schema = (new BaselineSchema($manager))->build($platform);
            $comparator = new Comparator($platform);
            foreach ($this->ownedTableProvider() as $case) {
                [$table, $columnCount, $indexCount, $emptyReferences, $references] = $case;
                $logicalTypes = $case[5] ?? [];
                $historical = clone $frozen->getTable($table);
                if ($table === 'glpi_oidc_users') {
                    $historical->addUniqueIndex(['user_id'], 'oidc_users_user');
                }
                if ($table === 'glpi_notificationtargets') {
                    NotificationRecipients::configureTable($historical);
                    // Frozen SQL uses unquoted names and an equality for profile type.
                    // The current metadata uses provider quoting and IN for every branch.
                    // Assert the independently spelled equivalent expression below as well.
                    $quote = $platform->quoteIdentifier(...);
                    $expression = 'BIGINT GENERATED ALWAYS AS (CASE WHEN ' . $quote('type')
                        . ' IN (3, 5, 6) THEN ' . $quote('groups_id') . ' WHEN ' . $quote('type')
                        . ' IN (2) THEN ' . $quote('profiles_id') . ' ELSE ' . $quote('recipient_code') . ' END) STORED';
                    $historical->getColumn('items_id')->setColumnDefinition($expression);
                }
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
                        ForeignKeys::name($table, trim($column, '`'))
                    );
                }
                foreach ($logicalTypes as $column => $type) {
                    // The existing entity clock mapping preserves 24:00:00 as a string.
                    // Its native declaration must remain identical to frozen TIME.
                    $historicalColumn = $historical->getColumn($column);
                    $logical = Type::getType($type);
                    $this->string($logical->getSQLDeclaration($historicalColumn->toArray(true), $platform))
                        ->isIdenticalTo($historicalColumn->getType()->getSQLDeclaration($historicalColumn->toArray(true), $platform));
                    $historicalColumn->setType($logical);
                }
                $current = $schema->getTable($table);
                $this->boolean($comparator->compareTables($historical, $current)->isEmpty())->isTrue($table . ' on ' . $platform::class);
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
                    $default = static fn ($value) => $value instanceof DefaultExpression
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
                $columns = static fn (Index $index): array => array_map(
                    static fn (IndexedColumn $column): array => [
                        $column->getColumnName()->getIdentifier()->getValue(), $column->getLength(),
                    ],
                    $index->getIndexedColumns(),
                );
                foreach ($historical->getIndexes() as $index) {
                    $this->boolean($current->hasIndex($index->getName()))->isTrue();
                    $this->array($columns($current->getIndex($index->getName())))->isIdenticalTo($columns($index));
                }
                $this->array($current->getOptions())->isEqualTo($historical->getOptions());
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozenSql);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testNotificationDeclarationsOwnCurrentSchemaWithoutChangingHistory(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $notification = $manager->getClassMetadata(Notification::class);
            $notification->fieldMappings['event']->length = 173;
            $notification->fieldMappings['is_active']->options['default'] = true;
            $notification->associationMappings['entities']->joinColumns[0]->nullable = true;
            $notification->associationMappings['entities']->joinColumns[0]->options['default'] = null;
            $template = $manager->getClassMetadata(NotificationTemplate::class);
            $template->fieldMappings['name']->length = 171;
            unset($template->fieldMappings['css']);
            $translation = $manager->getClassMetadata(NotificationTemplateTranslation::class);
            $translation->fieldMappings['subject']->length = 177;
            $translation->fieldMappings['subject']->nullable = true;
            $translation->fieldMappings['subject']->options['default'] = null;
            $binding = $manager->getClassMetadata(NotificationNotificationTemplate::class);
            $binding->associationMappings['notificationtemplates']->joinColumns[0]->nullable = true;
            $binding->associationMappings['notificationtemplates']->joinColumns[0]->options['default'] = null;
            $unique = $platform instanceof PostgreSQLPlatform ? 'glpi_notifications_notificationtemplates_unicity' : 'unicity';
            unset($binding->table['uniqueConstraints'][$unique]);

            $chat = $manager->getClassMetadata(NotificationChatConfig::class);
            $chat->fieldMappings['hookurl']->length = 181;
            unset($chat->fieldMappings['value']);
            $target = $manager->getClassMetadata(NotificationTarget::class);
            $target->fieldMappings['recipient_code']->options['default'] = 7;
            $target->associationMappings['notifications']->joinColumns[0]->nullable = true;
            $target->associationMappings['notifications']->joinColumns[0]->options['default'] = null;
            $itemsIndex = $platform instanceof PostgreSQLPlatform ? 'glpi_notificationtargets_items' : 'items';
            unset($target->table['indexes'][$itemsIndex]);

            $changed = (new BaselineSchema($manager))->build($platform);
            $withoutKeys = (new BaselineSchema($manager))->build($platform, false);
            $table = $changed->getTable('glpi_notificationchatconfigs');
            $this->integer($table->getColumn('hookurl')->getLength())->isIdenticalTo(181);
            $this->boolean($table->hasColumn('value'))->isFalse();
            $table = $changed->getTable('glpi_notificationtargets');
            $this->variable($table->getColumn('recipient_code')->getDefault())->isEqualTo(7);
            $this->boolean($table->getColumn('notifications_id')->getNotnull())->isFalse();
            $this->variable($table->getColumn('notifications_id')->getDefault())->isNull();
            $this->boolean($table->hasIndex($itemsIndex))->isFalse();
            $this->string($table->getColumn('items_id')->getColumnDefinition())
                ->isIdenticalTo($target->fieldMappings['items_id']->columnDefinition);
            $table = $changed->getTable('glpi_notifications');
            $this->integer($table->getColumn('event')->getLength())->isIdenticalTo(173);
            $this->boolean($table->getColumn('entities_id')->getNotnull())->isFalse();
            $this->variable($table->getColumn('entities_id')->getDefault())->isNull();
            $this->variable($table->getColumn('is_active')->getDefault())
                ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? true : '1');
            $this->string(Type::lookupName($table->getColumn('is_active')->getType()))
                ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BOOLEAN : Types::SMALLINT);
            $table = $changed->getTable('glpi_notificationtemplates');
            $this->integer($table->getColumn('name')->getLength())->isIdenticalTo(171);
            $this->boolean($table->hasColumn('css'))->isFalse();
            $subject = $changed->getTable('glpi_notificationtemplatetranslations')->getColumn('subject');
            $this->integer($subject->getLength())->isIdenticalTo(177);
            $this->boolean($subject->getNotnull())->isFalse();
            $this->variable($subject->getDefault())->isNull();
            $table = $changed->getTable('glpi_notifications_notificationtemplates');
            $this->boolean($table->getColumn('notificationtemplates_id')->getNotnull())->isFalse();
            $this->variable($table->getColumn('notificationtemplates_id')->getDefault())->isNull();
            $this->boolean($table->hasIndex($unique))->isFalse();
            foreach ([Notification::class, NotificationTemplate::class, NotificationTemplateTranslation::class,
                NotificationNotificationTemplate::class, NotificationChatConfig::class, NotificationTarget::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $this->integer(count((new ReflectionClass($class))->getAttributes(SchemaOwner::class)))->isIdenticalTo(1);
                $owning = array_filter($metadata->associationMappings, static fn ($mapping): bool => $mapping->isToOneOwningSide());
                $this->integer(count($changed->getTable($metadata->getTableName())->getForeignKeys()))
                    ->isIdenticalTo(count($owning));
                $this->array($withoutKeys->getTable($metadata->getTableName())->getForeignKeys())->isEmpty();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testOidcPropertiesAndAssociationsOwnCurrentSchemaWithoutChangingHistory(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $postgres = $platform instanceof PostgreSQLPlatform;
            $manager = $this->manager($platform);
            $builder = new BaselineSchema($manager);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $original = $builder->build($platform);
            $config = $manager->getClassMetadata(OidcConfig::class);
            $mapping = $manager->getClassMetadata(OidcMapping::class);
            $user = $manager->getClassMetadata(OidcUser::class);
            $config->fieldMappings['Provider']->length = 173;
            $config->fieldMappings['Provider']->nullable = false;
            $config->fieldMappings['Provider']->options['default'] = 'Current provider';
            foreach (['is_activate' => true, 'is_forced' => true, 'sso_link_users' => false] as $property => $default) {
                $config->fieldMappings[$property]->nullable = true;
                $config->fieldMappings[$property]->options['default'] = $default;
            }
            $mapping->fieldMappings['name']->options['default'] = 'preferred_username';
            $instant = $mapping->fieldMappings['date_mod'];
            $instant->nullable = false;
            $instant->options['default'] = '2001-01-01 00:00:00';
            // The driver resolves NativeTimestamp when metadata loads. Simulate
            // the resolved declaration after editing its property-owned inputs.
            $timestamp = (new ReflectionClass(OidcMapping::class))->getProperty('date_mod')
                ->getAttributes(NativeTimestamp::class)[0]->newInstance();
            $instant->columnDefinition = $timestamp->declaration($platform, $instant);
            unset($user->table['uniqueConstraints']['oidc_users_user']);
            $join = $user->associationMappings['users']->joinColumns[0];
            $join->nullable = true;
            $join->options['default'] = null;
            $user->fieldMappings['update']->options['default'] = true;
            // A supplied manager's pending work must survive read-only inspection.
            $pending = new OidcConfig();
            $manager->persist($pending);
            $changed = $builder->build($platform);
            $withoutKeys = $builder->build($platform, false);
            $freshManager = $this->manager($platform);
            $fresh = (new BaselineSchema($freshManager))->build($platform);
            foreach ([OidcConfig::class, OidcMapping::class, OidcUser::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $name = $metadata->getTableName();
                $this->array($withoutKeys->getTable($name)->getForeignKeys())->isEmpty();
                $this->boolean((new Comparator($platform))->compareTables($original->getTable($name), $fresh->getTable($name))->isEmpty())
                    ->isTrue($name . ' must restore from fresh metadata');
            }
            $declaration = $changed->getTable('glpi_oidc_config');
            $this->integer($declaration->getColumn('Provider')->getLength())->isIdenticalTo(173);
            $this->boolean($declaration->getColumn('Provider')->getNotnull())->isTrue();
            $this->string($declaration->getColumn('Provider')->getDefault())->isIdenticalTo('Current provider');
            foreach (['is_activate' => true, 'is_forced' => true, 'sso_link_users' => false] as $property => $default) {
                $field = $config->fieldMappings[$property];
                $column = $declaration->getColumn($property);
                $this->string($field->type)->isIdenticalTo(Types::BOOLEAN);
                $this->variable($column->getDefault())->isIdenticalTo($postgres ? $default : (string)(int)$default);
                $this->boolean($column->getNotnull())->isFalse();
            }
            $declaration = $changed->getTable('glpi_oidc_mapping');
            $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo('preferred_username');
            $this->boolean($declaration->getColumn('date_mod')->getNotnull())->isTrue();
            $this->string($declaration->getColumn('date_mod')->getDefault())->isIdenticalTo('2001-01-01 00:00:00');
            $this->variable($declaration->getColumn('date_mod')->getColumnDefinition())
                ->isIdenticalTo($postgres ? null : "TIMESTAMP NOT NULL DEFAULT '2001-01-01 00:00:00'");
            $declaration = $changed->getTable('glpi_oidc_users');
            $this->boolean($declaration->hasIndex('oidc_users_user'))->isFalse('History must not recreate removed current uniqueness');
            $this->boolean($declaration->getColumn('user_id')->getNotnull())->isFalse();
            $this->variable($declaration->getColumn('user_id')->getDefault())->isNull();
            $this->variable($declaration->getColumn('update')->getDefault())->isIdenticalTo($postgres ? true : '1');
            $this->string($user->fieldMappings['update']->type)->isIdenticalTo(Types::BOOLEAN);
            $this->integer(count($declaration->getForeignKeys()))->isIdenticalTo(1);
            $this->boolean($declaration->hasForeignKey('fk_oidc_users_user_id'))->isTrue();
            $freshWithoutKeys = (new BaselineSchema($freshManager))->build($platform, false)->getTable('glpi_oidc_users');
            $this->array($freshWithoutKeys->getForeignKeys())->isEmpty();
            $this->boolean($freshWithoutKeys->getIndex('oidc_users_user')->isUnique())->isTrue();
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->contains($pending))->isTrue();
            $this->boolean($manager->isOpen())->isTrue();
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($freshManager->getConnection()->isConnected())->isFalse();
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
                MonitorModel::class,
                NetworkEquipmentModel::class,
                PeripheralModel::class,
                PhoneModel::class,
                PrinterModel::class,
                PassiveDCEquipmentModel::class,
                EnclosureModel::class,
                PDUModel::class,
                RackModel::class,
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
            $metadata = $manager->getClassMetadata(ComputerModel::class);
            $field = $metadata->fieldMappings['is_half_rack'];
            $this->string($field->type)->isIdenticalTo(Types::BOOLEAN);
            $this->boolean((new ComputerModel())->is_half_rack)->isFalse();
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
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testBooleanStorageRejectsNonBooleanFieldsAndDefaults(): void
    {
        $this->exception(static fn () => new BooleanStorage(Types::STRING))
            ->isInstanceOf(InvalidArgumentException::class);
        $storage = new BooleanStorage(Types::SMALLINT);
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(ComputerModel::class);
            $field = $metadata->fieldMappings['is_half_rack'];
            $field->type = Types::INTEGER;
            $this->exception(static fn () => (new BaselineSchema($manager))->build($platform))
                ->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('BooleanStorage requires an ORM boolean field.');
            $field->type = Types::BOOLEAN;
            $column = new Column('is_half_rack', Type::getType(Types::BOOLEAN), ['default' => 2]);
            $this->exception(static fn () => $storage->configure($column, $platform, $field))
                ->isInstanceOf(InvalidArgumentException::class);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testCalendarPropertiesAndAssociationsOwnCurrentExpectation(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            foreach ([Calendar::class, Holiday::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $metadata->fieldMappings['name']->length = 173;
                $metadata->associationMappings['entities']->joinColumns[0]->nullable = true;
                $metadata->associationMappings['entities']->joinColumns[0]->options['default'] = null;
                unset($metadata->fieldMappings['comment']);
            }
            $segment = $manager->getClassMetadata(CalendarSegment::class);
            $segment->fieldMappings['day']->options['comment'] = 'Current calendar day';
            $segment->fieldMappings['day']->options['default'] = 3;
            $segment->fieldMappings['begin']->nullable = false;
            $link = $manager->getClassMetadata(CalendarHoliday::class);
            unset($link->table['uniqueConstraints'][$platform instanceof PostgreSQLPlatform ? 'glpi_calendars_holidays_unicity' : 'unicity']);
            $changed = (new BaselineSchema($manager))->build($platform);
            $withoutKeys = (new BaselineSchema($manager))->build($platform, false);
            foreach ([Calendar::class, Holiday::class] as $class) {
                $table = $manager->getClassMetadata($class)->getTableName();
                $declaration = $changed->getTable($table);
                $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->boolean($declaration->getColumn('entities_id')->getNotnull())->isFalse();
                $this->variable($declaration->getColumn('entities_id')->getDefault())->isNull();
                $this->boolean($declaration->hasColumn('comment'))->isFalse();
            }
            $declaration = $changed->getTable('glpi_calendarsegments');
            $this->string($declaration->getColumn('day')->getComment())->isIdenticalTo('Current calendar day');
            $this->variable($declaration->getColumn('day')->getDefault())->isEqualTo(3);
            $this->boolean($declaration->getColumn('begin')->getNotnull())->isTrue();
            $this->boolean($changed->getTable('glpi_calendars_holidays')->hasIndex(
                $platform instanceof PostgreSQLPlatform ? 'glpi_calendars_holidays_unicity' : 'unicity'
            ))->isFalse();
            foreach ([Calendar::class, Holiday::class, CalendarSegment::class, CalendarHoliday::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $this->integer(count((new ReflectionClass($class))->getAttributes(SchemaOwner::class)))->isIdenticalTo(1);
                $this->integer(count($changed->getTable($metadata->getTableName())->getForeignKeys()))
                    ->isIdenticalTo(count($metadata->associationMappings));
                $this->array($withoutKeys->getTable($metadata->getTableName())->getForeignKeys())->isEmpty();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testProfileFamilyDeclarationsOwnCurrentSchemaWithoutChangingHistory(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $profile = $manager->getClassMetadata(Profile::class);
            $right = $manager->getClassMetadata(ProfileRight::class);
            $user = $manager->getClassMetadata(ProfileUser::class);
            $current = (new BaselineSchema($manager))->build($platform);
            foreach ([Profile::class => ['is_default', 'create_ticket_on_login'],
                ProfileUser::class => ['is_recursive', 'is_dynamic', 'is_default_profile']] as $class => $fields) {
                $table = $current->getTable($manager->getClassMetadata($class)->getTableName());
                foreach ($fields as $field) {
                    $this->string(Type::lookupName($table->getColumn($field)->getType()))
                        ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BOOLEAN : Types::SMALLINT);
                }
            }
            $unique = $platform instanceof PostgreSQLPlatform ? 'glpi_profilerights_unicity' : 'unicity';
            $interface = $platform instanceof PostgreSQLPlatform ? 'glpi_profiles_interface' : 'interface';
            $this->boolean($current->getTable('glpi_profilerights')->getIndex($unique)->isUnique())->isTrue();
            $profile->fieldMappings['name']->length = 173;
            $profile->fieldMappings['name']->nullable = false;
            $profile->fieldMappings['name']->options['default'] = 'Current profile';
            $profile->fieldMappings['ticket_status']->options['comment'] = 'Current status declaration';
            unset($profile->fieldMappings['comment'], $profile->table['indexes'][$interface]);
            $profile->table['indexes']['current_profile_name'] = ['columns' => ['name']];
            $right->fieldMappings['rights']->options['default'] = 7;
            unset($right->table['uniqueConstraints'][$unique]);
            $user->associationMappings['users']->joinColumns[0]->nullable = true;
            $user->associationMappings['users']->joinColumns[0]->options['default'] = null;
            $user->fieldMappings['is_dynamic']->options['default'] = true;

            $changed = (new BaselineSchema($manager))->build($platform);
            $withoutKeys = (new BaselineSchema($manager))->build($platform, false);
            $declaration = $changed->getTable('glpi_profiles');
            $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
            $this->boolean($declaration->getColumn('name')->getNotnull())->isTrue();
            $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo('Current profile');
            $this->string($declaration->getColumn('ticket_status')->getComment())->isIdenticalTo('Current status declaration');
            $this->boolean($declaration->hasColumn('comment'))->isFalse();
            $this->boolean($declaration->hasIndex($interface))->isFalse();
            $this->boolean($declaration->hasIndex('current_profile_name'))->isTrue();
            $this->variable($changed->getTable('glpi_profilerights')->getColumn('rights')->getDefault())->isEqualTo(7);
            $this->boolean($changed->getTable('glpi_profilerights')->hasIndex($unique))->isFalse();
            $this->boolean($changed->getTable('glpi_profiles_users')->getColumn('users_id')->getNotnull())->isFalse();
            $this->variable($changed->getTable('glpi_profiles_users')->getColumn('users_id')->getDefault())->isNull();
            $this->variable($changed->getTable('glpi_profiles_users')->getColumn('is_dynamic')->getDefault())
                ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? true : '1');
            foreach ([Profile::class, ProfileRight::class, ProfileUser::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $this->integer(count((new ReflectionClass($class))->getAttributes(SchemaOwner::class)))->isIdenticalTo(1);
                $this->integer(count($changed->getTable($metadata->getTableName())->getForeignKeys()))
                    ->isIdenticalTo(count($metadata->associationMappings));
                $this->array($withoutKeys->getTable($metadata->getTableName())->getForeignKeys())->isEmpty();
            }
            $freshManager = $this->manager($platform);
            $fresh = (new BaselineSchema($freshManager))->build($platform);
            $declaration = $fresh->getTable('glpi_profiles');
            $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(255);
            $this->boolean($declaration->getColumn('name')->getNotnull())->isFalse();
            $this->variable($declaration->getColumn('name')->getDefault())->isNull();
            $this->boolean($declaration->hasColumn('comment'))->isTrue();
            $this->boolean($declaration->hasIndex($interface))->isTrue();
            $this->boolean($declaration->hasIndex('current_profile_name'))->isFalse();
            $this->variable($fresh->getTable('glpi_profilerights')->getColumn('rights')->getDefault())->isEqualTo(0);
            $this->boolean($fresh->getTable('glpi_profilerights')->hasIndex($unique))->isTrue();
            $this->boolean($fresh->getTable('glpi_profiles_users')->getColumn('users_id')->getNotnull())->isTrue();
            $this->variable($fresh->getTable('glpi_profiles_users')->getColumn('users_id')->getDefault())->isEqualTo(0);
            $this->variable($fresh->getTable('glpi_profiles_users')->getColumn('is_dynamic')->getDefault())
                ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? false : '0');
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($freshManager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testDomainDropdownDeclarationsOwnSchemaWithoutChangingHistory(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $current = (new BaselineSchema($manager))->build($platform);
            foreach ([DomainType::class, DomainRelation::class, DomainRecordType::class] as $class) {
                $metadata = $manager->getClassMetadata($class);
                $table = $metadata->getTableName();
                $this->string(Type::lookupName($current->getTable($table)->getColumn('is_recursive')->getType()))
                    ->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BOOLEAN : Types::SMALLINT);
                $this->boolean($metadata->fieldMappings['is_recursive']->type === Types::BOOLEAN)->isTrue();
                // Changes belong to properties and indexes, not the historical overlay.
                $metadata->fieldMappings['name']->length = 173;
                $metadata->fieldMappings['name']->nullable = false;
                $metadata->fieldMappings['name']->options['default'] = 'Current domain label';
                $metadata->associationMappings['entities']->joinColumns[0]->nullable = true;
                $metadata->associationMappings['entities']->joinColumns[0]->options['default'] = null;
                $index = $platform instanceof PostgreSQLPlatform ? $table . '_name' : 'name';
                unset($metadata->table['indexes'][$index], $metadata->fieldMappings['comment']);
            }
            $changed = (new BaselineSchema($manager))->build($platform);
            $withoutKeys = (new BaselineSchema($manager))->build($platform, false);
            foreach ([DomainType::class, DomainRelation::class, DomainRecordType::class] as $class) {
                $table = $manager->getClassMetadata($class)->getTableName();
                $declaration = $changed->getTable($table);
                $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->integer(count((new ReflectionClass($class))->getAttributes(SchemaOwner::class)))->isIdenticalTo(1);
                $this->boolean($declaration->getColumn('name')->getNotnull())->isTrue();
                $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo('Current domain label');
                $this->boolean($declaration->getColumn('entities_id')->getNotnull())->isFalse();
                $this->variable($declaration->getColumn('entities_id')->getDefault())->isNull();
                $this->boolean($declaration->hasColumn('comment'))->isFalse();
                $this->boolean($declaration->hasIndex($platform instanceof PostgreSQLPlatform ? $table . '_name' : 'name'))->isFalse();
                $this->integer(count($declaration->getForeignKeys()))->isIdenticalTo(1);
                $this->array($withoutKeys->getTable($table)->getForeignKeys())->isEmpty();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
        }
    }

    public function typeDeclarationProvider(): array
    {
        return [
            'assets' => [[
                ComputerType::class, MonitorType::class,
                NetworkEquipmentType::class, PeripheralType::class,
                PhoneType::class, PrinterType::class,
            ], 'Current type', ['name', 'date_mod']],
            'administration' => [[
                BudgetType::class, ContactType::class, ContractType::class,
                SupplierType::class, ProjectType::class, ProjectTaskType::class,
            ], 'Current administration type', ['name', 'date_mod']],
            'devices' => [[
                DeviceBatteryType::class, DeviceCaseType::class, DeviceFirmwareType::class,
                DeviceGenericType::class, DeviceMemoryType::class, DeviceSensorType::class,
                DeviceSimcardType::class,
            ], 'Current device type', ['name', 'comment']],
        ];
    }

    /** @dataProvider typeDeclarationProvider */
    public function testTypePropertiesAndIndexesOwnCurrentExpectation(array $classes, string $label, array $indexColumns): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $types = [];
            foreach ($classes as $class) {
                $metadata = $manager->getClassMetadata($class);
                $table = $metadata->getTableName();
                $name = $metadata->fieldMappings['name'];
                $nullable = $name->nullable;
                $default = $name->options['default'] ?? null;
                $name->length = 173;
                $name->nullable = !$nullable;
                $name->options['default'] = $label;
                $metadata->fieldMappings['comment']->type = Types::STRING;
                $metadata->fieldMappings['comment']->length = 311;
                $index = $platform instanceof PostgreSQLPlatform ? $table . '_name' : 'name';
                unset($metadata->table['indexes'][$index]);
                $metadata->table['indexes'][$table . '_current_label']['columns'] = $indexColumns;
                $types[] = [$metadata, $nullable, $default, $index];
            }
            $current = (new BaselineSchema($manager))->build($platform);
            $freshManager = $this->manager($platform);
            $fresh = (new BaselineSchema($freshManager))->build($platform);
            foreach ($types as [$metadata, $nullable, $default, $index]) {
                $table = $metadata->getTableName();
                $declaration = $current->getTable($table);
                $this->integer($declaration->getColumn('name')->getLength())->isIdenticalTo(173);
                $this->boolean($declaration->getColumn('name')->getNotnull())->isIdenticalTo($nullable);
                $this->string($declaration->getColumn('name')->getDefault())->isIdenticalTo($label);
                $this->string(Type::lookupName($declaration->getColumn('comment')->getType()))->isIdenticalTo(Types::STRING);
                $this->integer($declaration->getColumn('comment')->getLength())->isIdenticalTo(311);
                $this->boolean($declaration->hasIndex($index))->isFalse();
                $this->array($declaration->getIndex($table . '_current_label')->getUnquotedColumns())->isIdenticalTo($indexColumns);
                $original = $fresh->getTable($table);
                $this->integer($original->getColumn('name')->getLength())->isIdenticalTo(255);
                $this->boolean($original->getColumn('name')->getNotnull())->isIdenticalTo(!$nullable);
                $this->variable($original->getColumn('name')->getDefault())->isIdenticalTo($default);
                $this->string(Type::lookupName($original->getColumn('comment')->getType()))->isIdenticalTo(Types::TEXT);
                $this->boolean($original->hasIndex($index))->isTrue();
                $this->boolean($original->hasIndex($table . '_current_label'))->isFalse();
                unset($metadata->table['indexes'][$table . '_current_label'], $metadata->fieldMappings['comment']);
            }
            $removed = (new BaselineSchema($manager))->build($platform);
            foreach ($types as [$metadata]) {
                $this->boolean($removed->getTable($metadata->getTableName())->hasColumn('comment'))->isFalse();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($freshManager->getConnection()->isConnected())->isFalse();
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

    public function testIdentityAndQueueMetadataOwnCurrentTables(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $classes = ['QueuedNotification', 'QueuedChat', 'User', 'UserEmail', 'UserTitle', 'AuthLDAP',
                'AuthLdapReplicate', 'AuthMail', 'Group', 'GroupMembership', 'Contact', 'ContactSupplier', 'Supplier'];
            $cases = [];
            foreach ($classes as $shortName) {
                $class = 'itsmng\\Database\\Entity\\' . $shortName;
                $metadata = $manager->getClassMetadata($class);
                $this->integer(count((new ReflectionClass($class))->getAttributes(SchemaOwner::class)))->isIdenticalTo(1);
                $property = isset($metadata->fieldMappings['name']) ? 'name' : 'id';
                $metadata->fieldMappings[$property]->options['comment'] = 'Current identity owner';
                $indexes = array_keys($metadata->table['indexes'] ?? []);
                $index = $indexes[0];
                unset($metadata->table['indexes'][$index]);
                $metadata->table['indexes']['current_identity_' . strtolower($shortName)] = ['columns' => ['id']];
                $cases[] = [$metadata, $property, $index, $shortName];
            }
            $current = (new BaselineSchema($manager))->build($platform);
            $withoutKeys = (new BaselineSchema($manager))->build($platform, false);
            $freshManager = $this->manager($platform);
            $fresh = (new BaselineSchema($freshManager))->build($platform);
            foreach ($cases as [$metadata, $property, $index, $shortName]) {
                $table = $current->getTable($metadata->getTableName());
                $this->string($table->getColumn($metadata->getColumnName($property))->getComment())->isIdenticalTo('Current identity owner');
                $this->boolean($table->hasIndex($index))->isFalse();
                $this->boolean($fresh->getTable($metadata->getTableName())->hasIndex($index))->isTrue();
                $this->boolean($table->hasIndex('current_identity_' . strtolower($shortName)))->isTrue();
                $this->array($withoutKeys->getTable($metadata->getTableName())->getForeignKeys())->isEmpty();
            }
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->getConnection()->isConnected())->isFalse();
            $this->boolean($freshManager->getConnection()->isConnected())->isFalse();
        }
    }

    public function testProviderFieldDimensionsPreserveHydrationAndStayOutOfPhysicalOptions(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $driver = new AttributeDriver([], $platform);
            $metadata = new \Doctrine\ORM\Mapping\ClassMetadata(ProviderFieldDimensions::class);
            $driver->loadMetadataForClass(ProviderFieldDimensions::class, $metadata);
            $body = $metadata->fieldMappings['body'];
            $word = $metadata->fieldMappings['word'];
            $this->variable($body->length)->isIdenticalTo($platform instanceof PostgreSQLPlatform ? null : 4294967295);
            $this->string($word->type)->isIdenticalTo($platform instanceof PostgreSQLPlatform ? Types::BIGINT : Types::INTEGER);
            $this->boolean(array_key_exists('length', $body->options ?? []))->isFalse();
            $this->boolean(array_key_exists('type', $word->options ?? []))->isFalse();
            $this->string((new ReflectionClass(ProviderFieldDimensions::class))->getProperty('word')->getType()->getName())->isIdenticalTo('int');
        }
        foreach ([ProviderAssociationDimension::class, InvalidProviderFieldLength::class,
            InvalidProviderHydrationType::class] as $class) {
            $driver = new AttributeDriver([], new PostgreSQLPlatform());
            $metadata = new \Doctrine\ORM\Mapping\ClassMetadata($class);
            $this->exception(static fn () => $driver->loadMetadataForClass($class, $metadata))
                ->isInstanceOf(\LogicException::class);
        }
    }

    public function testProviderColumnOptionsRejectAmbiguousOrNonOwningProperties(): void
    {
        $driver = new AttributeDriver([], new PostgreSQLPlatform());
        foreach ([UnmappedProviderColumn::class, InverseProviderColumn::class, CompositeProviderColumn::class] as $class) {
            $this->exception(static fn () => $driver->loadMetadataForClass($class, new ORM\ClassMetadata($class)))
                ->isInstanceOf(LogicException::class)
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
        $metadata->mapField(['fieldName' => 'active', 'type' => Types::BOOLEAN, 'nullable' => false, 'options' => ['default' => true]]);
        $second = $builder->build($platform, false);
        $this->integer($second->getTable('schema_owned_example')->getColumn('future')->getLength())->isIdenticalTo(39);
        $this->string($second->getTable('schema_owned_example')->getColumn('future')->getDefault())->isIdenticalTo('new');
        $this->string(Type::lookupName($second->getTable('schema_owned_example')->getColumn('active')->getType()))->isIdenticalTo(Types::BOOLEAN);
        $this->boolean($second->getTable('schema_owned_example')->getColumn('active')->getDefault())->isTrue();
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
        $compare = SubjectPolicyExpression::equivalent(...);
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
        $compare = SubjectPolicyExpression::equivalent(...);
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
        $nativeCompare = static fn (array $c, array $k): array => NativeSubjectSchema::compare($policies, $c, $k, false);
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
        $compare = SubjectPolicyExpression::equivalent(...);
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
            $compare = static fn (array $c, array $k): array => NativeSubjectSchema::compare($policies, $c, $k, $postgres);
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
            $this->array(NativeSubjectSchema::compare($policies, $columns, $checks, $postgres))->isIdenticalTo([
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
        $compare = static fn (array $checks): array => NativeSubjectSchema::compare([$table => ['items_id' => $policy]], $columns, $checks, true);
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

    public function testCurrentColumnsAndNativePoliciesUseOneFreshMetadataSnapshot(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $configuration = ApplicationOrm::configuration($platform);
            $configuration->setClassMetadataFactoryName(CurrentSchemaMetadataFactory::class);
            $connection = new DisconnectedSchemaConnection($platform);
            $manager = new EntityManager($connection, $configuration);
            $factory = $manager->getMetadataFactory();
            $builder = new BaselineSchema($manager);
            $frozen = (new Baseline())->build($platform)->toSql($platform);
            $first = $builder->build($platform);
            $policies = $builder->subjectPolicies();
            $this->integer($factory->enumerations)->isIdenticalTo(1, 'Columns and native policies share one enumeration');
            $this->array($policies)->hasKeys(['glpi_certificates_items', 'glpi_items_devicesensors']);
            $this->integer($first->getTable('glpi_configs')->getColumn('context')->getLength())->isIdenticalTo(150);

            // Reusing the builder must observe edits, without retaining a previous
            // snapshot or closing its caller-owned metadata connection/manager.
            $manager->getClassMetadata(Config::class)->fieldMappings['context']->length = 173;
            $second = $builder->build($platform);
            $this->integer($factory->enumerations)->isIdenticalTo(2);
            $this->integer($second->getTable('glpi_configs')->getColumn('context')->getLength())->isIdenticalTo(173);
            $this->array($builder->subjectPolicies())->isIdenticalTo($policies);
            $this->array((new Baseline())->build($platform)->toSql($platform))->isIdenticalTo($frozen);
            $this->boolean($manager->isOpen())->isTrue();
            $this->boolean($connection->isConnected())->isFalse();
        }
    }

    public function testSuppliedMetadataRequiresTheSelectedPlatform(): void
    {
        $builder = new BaselineSchema($this->manager(new PostgreSQLPlatform()));
        $this->exception(static fn () => $builder->build(new MariaDBPlatform()))
            ->isInstanceOf(InvalidArgumentException::class);
    }

    public function testNotificationFallbackOwnsRequiredHistoricalRecipientSemantics(): void
    {
        $historical = NotificationRecipients::checkSql();
        $historical = substr($historical, strpos($historical, ' CHECK (') + 8, -1);
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $builder = new BaselineSchema($this->manager($platform));
            $builder->build($platform);
            $policy = $builder->subjectPolicies()['glpi_notificationtargets']['items_id'];
            $this->string($policy['constraint'])->isIdenticalTo('glpi_notificationtargets_recipient_kind');
            $this->array($policy['discriminators'])->isEmpty();
            $this->array($policy['integer_discriminators'])->isIdenticalTo(['type']);
            $this->string($policy['integer_types']['recipient_code'])->isIdenticalTo('integer');
            $postgres = $platform instanceof PostgreSQLPlatform;
            $equivalent = static fn (string $expected, string $actual): bool => SubjectPolicyExpression::equivalent(
                $expected,
                $actual,
                $postgres,
                integerDiscriminators: $policy['integer_discriminators'],
                integerTypes: $policy['integer_types']
            );
            $this->boolean($equivalent($policy['check'], $historical))->isTrue();
            $projection = $policy['projection'];
            if ($postgres) {
                $projection = str_replace('ELSE "recipient_code" END', 'ELSE ("recipient_code")::bigint END', $projection);
            }
            $columns = ['glpi_notificationtargets' => ['items_id' => [
                'generated' => $postgres ? 's' : 'STORED GENERATED', 'expression' => $projection,
            ]]];
            $checks = ['glpi_notificationtargets' => ['glpi_notificationtargets_recipient_kind' => [
                'clause' => $historical, 'enforced' => true, 'validated' => true,
            ]]];
            $selected = ['glpi_notificationtargets' => ['items_id' => $policy]];
            // Numeric type has no pg_collation row; no deterministic-text check applies.
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, $postgres))->isEmpty();
            foreach ([
                str_replace('profiles_id > 0', 'profiles_id > -1', $historical),
                str_replace('profiles_id IS NOT NULL AND profiles_id > 0', '(profiles_id IS NULL OR profiles_id > 0)', $historical),
                str_replace('groups_id IS NOT NULL AND groups_id > 0', '(groups_id IS NULL OR groups_id > 0)', $historical),
                str_replace('recipient_code IS NOT NULL', 'recipient_code IS NULL', $historical),
                str_replace('groups_id IS NULL', 'groups_id IS NOT NULL', $historical),
                $historical . ' OR 1 = 1',
            ] as $weakened) {
                $changed = $checks;
                $changed['glpi_notificationtargets']['glpi_notificationtargets_recipient_kind']['clause'] = $weakened;
                $this->array(NativeSubjectSchema::compare($selected, $columns, $changed, $postgres))->isIdenticalTo([
                    'Changed, missing or unenforced native subject CHECK: glpi_notificationtargets.glpi_notificationtargets_recipient_kind',
                ]);
            }
            foreach (['enforced', 'validated'] as $flag) {
                if ($flag === 'validated' && !$postgres) {
                    continue;
                }
                $changed = $checks;
                $changed['glpi_notificationtargets']['glpi_notificationtargets_recipient_kind'][$flag] = false;
                $this->array(NativeSubjectSchema::compare($selected, $columns, $changed, $postgres))->isIdenticalTo([
                    'Changed, missing or unenforced native subject CHECK: glpi_notificationtargets.glpi_notificationtargets_recipient_kind',
                ]);
            }
            $this->array(NativeSubjectSchema::compare($selected, $columns, [], $postgres))->isIdenticalTo([
                'Changed, missing or unenforced native subject CHECK: glpi_notificationtargets.glpi_notificationtargets_recipient_kind',
            ]);
            $columns['glpi_notificationtargets']['items_id']['expression'] = '0';
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, $postgres))->isIdenticalTo([
                'Changed or missing native subject projection: glpi_notificationtargets.items_id',
            ]);
        }
    }

    public function testUserFallbackSubjectPolicyOwnsHistoricalAuthenticationSemantics(): void
    {
        $historical = UserAuthenticationSources::checkSql();
        $historical = substr($historical, strpos($historical, ' CHECK (') + 8, -1);
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $builder = new BaselineSchema($this->manager($platform));
            $builder->build($platform);
            $policy = $builder->subjectPolicies()['glpi_users']['auths_id'];
            $this->string($policy['constraint'])->isIdenticalTo('glpi_users_authentication_kind');
            $this->array($policy['discriminators'])->isEmpty();
            $this->array($policy['integer_discriminators'])->isIdenticalTo(['authtype']);
            $this->string($policy['integer_types']['auth_source_code'])->isIdenticalTo('integer');
            $postgres = $platform instanceof PostgreSQLPlatform;
            $equivalent = static fn (string $expected, string $actual): bool => SubjectPolicyExpression::equivalent(
                $expected,
                $actual,
                $postgres,
                integerDiscriminators: $policy['integer_discriminators'],
                integerTypes: $policy['integer_types']
            );
            $this->boolean($equivalent($policy['check'], $historical))->isTrue();
            $projection = $policy['projection'];
            if ($postgres) {
                $projection = str_replace('ELSE "auth_source_code" END', 'ELSE ("auth_source_code")::bigint END', $projection);
            }
            $columns = ['glpi_users' => ['auths_id' => [
                'generated' => $postgres ? 's' : 'STORED GENERATED', 'expression' => $projection,
            ]]];
            $checks = ['glpi_users' => ['glpi_users_authentication_kind' => [
                'clause' => $historical, 'enforced' => true, 'validated' => true,
            ]]];
            $selected = ['glpi_users' => ['auths_id' => $policy]];
            // Numeric authtype has no pg_collation row; no deterministic-text check applies.
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, $postgres))->isEmpty();
            foreach ([
                str_replace('authmails_id > 0', 'authmails_id > -1', $historical),
                str_replace('auth_source_code IS NOT NULL', 'auth_source_code IS NULL', $historical),
                str_replace('authldaps_id IS NULL', 'authldaps_id IS NOT NULL', $historical),
                $historical . ' OR 1 = 1',
            ] as $weakened) {
                $changed = $checks;
                $changed['glpi_users']['glpi_users_authentication_kind']['clause'] = $weakened;
                $this->array(NativeSubjectSchema::compare($selected, $columns, $changed, $postgres))->isIdenticalTo([
                    'Changed, missing or unenforced native subject CHECK: glpi_users.glpi_users_authentication_kind',
                ]);
            }
            foreach (['enforced', 'validated'] as $flag) {
                if ($flag === 'validated' && !$postgres) {
                    continue;
                }
                $changed = $checks;
                $changed['glpi_users']['glpi_users_authentication_kind'][$flag] = false;
                $this->array(NativeSubjectSchema::compare($selected, $columns, $changed, $postgres))->isIdenticalTo([
                    'Changed, missing or unenforced native subject CHECK: glpi_users.glpi_users_authentication_kind',
                ]);
            }
            $this->array(NativeSubjectSchema::compare($selected, $columns, [], $postgres))->isIdenticalTo([
                'Changed, missing or unenforced native subject CHECK: glpi_users.glpi_users_authentication_kind',
            ]);
            $columns['glpi_users']['auths_id']['expression'] = '0';
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, $postgres))->isIdenticalTo([
                'Changed or missing native subject projection: glpi_users.auths_id',
            ]);
        }
    }

    public function testNumericAuthenticationCatalogGrammarFailsClosed(): void
    {
        $compare = static fn (string $expected, string $actual): bool => SubjectPolicyExpression::equivalent(
            $expected,
            $actual,
            true,
            integerDiscriminators: ['authtype'],
            integerTypes: ['authtype' => 'integer', 'auth_source_code' => 'integer', 'authldaps_id' => 'bigint']
        );
        $this->boolean($compare('authtype IN (0, 3, 4)', 'authtype = ANY (ARRAY[0, 3, 4])'))->isTrue();
        $this->boolean($compare('authtype NOT IN (0, 2, 3)', 'authtype <> ALL (ARRAY[0, 2, 3])'))->isTrue();
        $this->boolean($compare('authtype NOT IN (0, 2, 3)', 'NOT (authtype IN (0, 2, 3))'))->isTrue();
        $this->boolean($compare('auth_source_code', '(auth_source_code)::bigint'))->isTrue();
        foreach ([
            'authtype = ALL (ARRAY[0, 3, 4])',
            'authtype <> ANY (ARRAY[0, 3, 4])',
            'authtype = ANY (ARRAY[0, 3, 5])',
            "authtype = ANY (ARRAY[0, 3, '4'])",
            'authtype = ANY (ARRAY[0, 3, 4]) OR 1 = 1',
            'authtype::smallint = ANY (ARRAY[0, 3, 4])',
            'other_type = ANY (ARRAY[0, 3, 4])',
            'authtype = ANY (SELECT 0)',
            'NOT (authtype = 0 AND auth_source_code IS NOT NULL)',
        ] as $changed) {
            $this->boolean($compare('authtype IN (0, 3, 4)', $changed))->isFalse();
        }
        $this->boolean($compare('authldaps_id', 'authldaps_id::integer'))->isFalse();
        $this->boolean(SubjectPolicyExpression::equivalent('authtype IN (0, 3, 4)', 'authtype = ANY (ARRAY[0, 3, 4])', true))->isFalse();
    }

    public function testFallbackPolicyRefusesUnsupportedMetadataContracts(): void
    {
        foreach (['fallback_type', 'nullable_kind', 'required_server'] as $variant) {
            $platform = new MySQLPlatform();
            $manager = $this->manager($platform);
            $metadata = $manager->getClassMetadata(UserEntity::class);
            if ($variant === 'fallback_type') {
                $metadata->fieldMappings['auth_source_code']->type = Types::STRING;
            } elseif ($variant === 'nullable_kind') {
                $metadata->fieldMappings['authtype']->nullable = true;
            } else {
                $metadata->associationMappings['authldap']->joinColumns[0]->nullable = false;
            }
            $key = (new ReflectionProperty(UserEntity::class, 'auths_id'))
                ->getAttributes(DiscriminatorKey::class)[0]->newInstance();
            $this->exception(static fn () => $key->subjectCheckExpression($platform, $metadata, 'auths_id'))
                ->isInstanceOf(LogicException::class);
        }
    }

    public function testNullableOpenSubjectUsesActualGraphicCardMetadataAndTotalNullBranches(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $builder = new BaselineSchema($manager);
            $schema = $builder->build($platform);
            $metadata = $manager->getClassMetadata(ItemDeviceGraphicCard::class);
            $key = (new ReflectionProperty(ItemDeviceGraphicCard::class, 'items_id'))->getAttributes(DiscriminatorKey::class)[0]->newInstance();
            $table = $metadata->getTableName();
            $policy = $builder->subjectPolicies()[$table]['items_id'];
            $this->boolean($metadata->fieldMappings['itemtype']->nullable)->isTrue();
            $this->integer($schema->getTable($table)->getColumn('itemtype')->getLength())->isIdenticalTo(255);
            $this->boolean($schema->getTable($table)->getColumn('itemtype')->getNotnull())->isFalse();
            $this->array($policy['string_selections'])->isIdenticalTo(['itemtype' => ['Computer']]);
            $this->array($policy['integer_types'])->isIdenticalTo(['opaque_parent_id' => Types::BIGINT, 'computers_id' => Types::BIGINT]);
            $kind = $platform->quoteIdentifier('itemtype');
            $exact = $platform instanceof PostgreSQLPlatform ? $kind : 'CAST(' . $kind . ' AS BINARY)';
            $owner = $platform->quoteIdentifier('computers_id');
            $opaque = $platform->quoteIdentifier('opaque_parent_id');
            $selected = $exact . " IN ('Computer') AND (" . $owner . ' IS NULL OR ' . $owner . ' > 0) AND ' . $opaque . ' IS NULL';
            $unselected = $exact . " NOT IN ('Computer')";
            $slots = $owner . ' IS NULL AND ' . $opaque . ' IS NOT NULL';
            $expected = '(' . $kind . ' IS NOT NULL AND ' . $selected . ') OR ((' . $kind . ' IS NULL OR ' . $unselected . ') AND ' . $slots . ')';
            $this->string($policy['check'])->isIdenticalTo($expected);
            $this->string($policy['projection'])->isIdenticalTo("CASE WHEN $exact IN ('Computer') THEN COALESCE($owner, 0) ELSE $opaque END");
            // Nullable metadata changes only total CHECK ownership, never projection routing.
            $metadata->fieldMappings['itemtype']->nullable = false;
            $nonnull = '(' . $selected . ') OR (' . $unselected . ' AND ' . $slots . ')';
            $this->string($key->subjectCheckExpression($platform, $metadata, 'items_id'))->isIdenticalTo($nonnull);
            $this->string($key->projectionExpression($platform, $metadata, 'items_id'))->isIdenticalTo($policy['projection']);
            $metadata->fieldMappings['itemtype']->nullable = true;
            $this->string($key->subjectCheckExpression($platform, $metadata, 'items_id'))->isIdenticalTo($expected);
            $this->boolean(SubjectPolicyExpression::equivalent(
                $expected,
                $nonnull,
                $platform instanceof PostgreSQLPlatform,
                integerTypes: $policy['integer_types'],
                stringSelections: $policy['string_selections']
            ))->isFalse();
            // The old rule admits UNKNOWN for (NULL kind, real typed owner, NULL opaque).
            // The total rule must be installed even if all present rows happen to be valid.
            $checks = [$table => [$policy['constraint'] => ['clause' => $nonnull, 'enforced' => true, 'validated' => true]]];
            if (!$platform instanceof PostgreSQLPlatform) {
                $this->array(NativeSubjectSchema::compare([$table => ['items_id' => $policy]], [], $checks, false, checksOnly: true))
                    ->isIdenticalTo(['Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']]);
            }
            $metadata->associationMappings['computer']->joinColumns[0]->nullable = false;
            $this->exception(static fn () => $key->subjectCheckExpression($platform, $metadata, 'items_id'))->isInstanceOf(LogicException::class);
        }
    }

    /** Controlled nullable admission rows, not captured native GraphicCard facts. */
    public function testNullableOpenSubjectUsesExistingFiniteNativeBounds(): void
    {
        $platform = new PostgreSQLPlatform();
        $manager = $this->manager($platform);
        $builder = new BaselineSchema($manager);
        $builder->build($platform);
        $metadata = $manager->getClassMetadata(ItemDeviceGraphicCard::class);
        $key = (new ReflectionProperty(ItemDeviceGraphicCard::class, 'items_id'))->getAttributes(DiscriminatorKey::class)[0]->newInstance();
        $table = $metadata->getTableName();
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $var = static fn (int $number, int $type, int $modifier, int $collation): string => '{VAR :varno 1 :varattno ' . $number
            . ' :vartype ' . $type . ' :vartypmod ' . $modifier . ' :varcollid ' . $collation . ' :varlevelsup 0}';
        $kind = $var(2, 1043, 259, 100);
        $owner = $var(3, 20, -1, 0);
        $opaque = $var(4, 20, -1, 0);
        $text = static fn (string $var, int $format): string => '{RELABELTYPE :arg ' . $var . ' :resulttype 25 :resulttypmod -1 :resultcollid 100 :relabelformat ' . $format . ' :location -1}';
        $literal = '{CONST :consttype 25 :consttypmod -1 :constcollid 100}';
        $zero = '{CONST :consttype 23 :consttypmod -1 :constcollid 0}';
        $op = static fn (int $number, int $function, int $collation, string $left, string $right): string => '{OPEXPR :opno ' . $number
            . ' :opfuncid ' . $function . ' :opresulttype 16 :opretset false :opcollid 0 :inputcollid ' . $collation . ' :args (' . $left . ' ' . $right . ')}';
        $null = static fn (string $arg, int $kind): string => '{NULLTEST :arg ' . $arg . ' :nulltesttype ' . $kind . ' :argisrow false :location -1}';
        $junction = static fn (string $kind, array $args): string => '{BOOLEXPR :boolop ' . $kind . ' :args (' . implode(' ', $args) . ') :location -1}';
        $selected = $op(98, 67, 100, $text($kind, 1), $literal);
        $unselected = $op(531, 157, 100, $text($kind, 1), $literal);
        $checkNodes = $junction('or', [$junction('and', [$null($kind, 1), $selected,
            $junction('or', [$null($owner, 0), $op(419, 477, 0, $owner, $zero)]), $null($opaque, 0)]),
            $junction('and', [$junction('or', [$null($kind, 0), $unselected]), $null($owner, 0), $null($opaque, 1)])]);
        $projectionNodes = '{CASEEXPR :casetype 20 :casecollid 0 :args ({CASEWHEN :expr '
            . $op(98, 67, 100, $text($kind, 2), $literal)
            . ' :result {COALESCEEXPR :coalescetype 20 :coalescecollid 0 :args (' . $owner
            . ' {CONST :consttype 20 :constcollid 0})}}) :defresult ' . $opaque . '}';
        $columns = [$table => ['items_id' => ['generated' => 's', 'expression' => $policy['projection'], 'native_nodes' => $projectionNodes],
            'itemtype' => ['attribute_number' => '2', 'type_oid' => '1043', 'type_modifier' => '259', 'collation_oid' => '100', 'deterministic' => true],
            'computers_id' => ['attribute_number' => '3', 'type_oid' => '20', 'type_modifier' => '-1', 'collation_oid' => '0'],
            'opaque_parent_id' => ['attribute_number' => '4', 'type_oid' => '20', 'type_modifier' => '-1', 'collation_oid' => '0']]];
        $check = ['clause' => $policy['check'], 'enforced' => true, 'validated' => true, 'native_nodes' => $checkNodes,
            'checked_columns' => ['itemtype', 'computers_id', 'opaque_parent_id'], 'reference_operator_oids' => ['98', '531', '419'],
            'subject_operator_functions' => ['98' => '67', '531' => '157', '419' => '477'],
            'reference_text_coercion' => ['boolean' => '16', 'binary' => true, 'varchar' => '1043', 'text' => '25', 'smallint' => '21', 'integer' => '23', 'bigint' => '20']];
        $selectedPolicies = [$table => ['items_id' => $policy]];
        $compare = static fn (array $rows, array $definition): array => NativeSubjectSchema::compare(
            $selectedPolicies,
            $rows,
            [$table => [$policy['constraint'] => $definition]],
            true
        );
        $this->array($compare($columns, $check))->isEmpty();
        $metadata->fieldMappings['itemtype']->nullable = false;
        $nonnull = $key->subjectCheckExpression($platform, $metadata, 'items_id');
        $this->array($compare($columns, array_replace($check, ['clause' => $nonnull])))->isIdenticalTo([
            'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']]);
        foreach (['enforced' => false, 'validated' => false, 'checked_columns' => ['itemtype', 'computers_id'],
            'native_nodes' => str_replace(':varattno 2', ':varattno 999', $checkNodes)] as $fact => $value) {
            $this->array($compare($columns, array_replace($check, [$fact => $value])))->contains(
                'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']
            );
        }
        foreach ([':vartypmod 259' => ':vartypmod 104', ':inputcollid 100' => ':inputcollid 999',
            ':opfuncid 67' => ':opfuncid 999', ':opretset false' => ':opretset true',
            ':opcollid 0' => ':opcollid 100', 'OPEXPR' => 'FUNCEXPR'] as $from => $to) {
            $this->array($compare($columns, array_replace($check, ['native_nodes' => str_replace($from, $to, $checkNodes)])))->contains(
                'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']
            );
        }
        $changed = $columns;
        $changed[$table]['itemtype']['deterministic'] = false;
        $this->array($compare($changed, $check))->isIdenticalTo(['Expected deterministic subject discriminator: ' . $table . '.itemtype']);
        $changed = $columns;
        $changed[$table]['items_id']['generated'] = '';
        $this->array($compare($changed, $check))->contains('Changed or missing native subject projection: ' . $table . '.items_id');
        $this->array(NativeSubjectSchema::compare(
            $selectedPolicies,
            $changed,
            [$table => [$policy['constraint'] => $check]],
            true,
            checksOnly: true
        ))->isEmpty();
    }

    public function testOpenStringSubjectPolicyBindsOnlyDeclaredLiteralChoices(): void
    {
        $choices = ['itemtype' => ['NetworkPort']];
        $types = ['networkports_id' => 'bigint', 'opaque_parent_id' => 'bigint'];
        $this->boolean(SubjectPolicyExpression::equivalent(
            "itemtype NOT IN ('NetworkPort')",
            "itemtype <> 'NetworkPort'::text",
            true,
            integerTypes: $types,
            stringSelections: $choices
        ))->isTrue();
        $this->boolean(SubjectPolicyExpression::equivalent(
            "CAST(itemtype AS BINARY) NOT IN ('NetworkPort')",
            "NOT (CAST(itemtype AS BINARY) IN ('NetworkPort'))",
            false,
            integerTypes: $types,
            stringSelections: $choices
        ))->isTrue();
        foreach (["itemtype NOT IN ('Unknown')", "itemtype NOT IN ('NetworkPort')", 'NOT (networkports_id > 0)'] as $sql) {
            $this->boolean(SubjectPolicyExpression::equivalent(
                $sql,
                $sql,
                true,
                integerTypes: $types
            ))->isFalse();
        }
        $this->boolean(SubjectPolicyExpression::equivalent(
            "CAST(other AS BINARY) NOT IN ('NetworkPort')",
            "CAST(other AS BINARY) NOT IN ('NetworkPort')",
            false,
            integerTypes: $types,
            stringSelections: $choices
        ))->isFalse();
    }

    public function testOpenStringSubjectPolicyRequiresBoundBuiltinNodes(): void
    {
        $platform = new PostgreSQLPlatform();
        $builder = new BaselineSchema($this->manager($platform));
        $builder->build($platform);
        $table = 'glpi_networknames';
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $this->array($policy['integer_types'])->isIdenticalTo([
            'opaque_parent_id' => 'bigint', 'networkports_id' => 'bigint',
        ]);
        $this->array($policy['string_selections'])->isIdenticalTo(['itemtype' => ['NetworkPort']]);
        // Deliberately controlled catalogue admission rows, not captured native facts.
        $variable = static fn (int $number, string $type, string $collation, int $modifier = -1): string =>
            '{VAR :varno 1 :varattno ' . $number . ' :vartype ' . $type
            . ' :vartypmod ' . $modifier . ' :varcollid ' . $collation . ' :varlevelsup 0}';
        $kind = $variable(2, '1043', '100', 104);
        $owner = $variable(3, '20', '0');
        $opaque = $variable(4, '20', '0');
        $literal = '{CONST :consttype 25 :consttypmod -1 :constcollid 100}';
        $selection = '{OPEXPR :opno 98 :opfuncid 67 :opresulttype 16 :opretset false :opcollid 0 :inputcollid 100 :args (' . $kind . ' ' . $literal . ')}';
        $checkNodes = '{BOOLEXPR :args (' . $selection . ' {NULLTEST :arg ' . $owner
            . '} {NULLTEST :arg ' . $opaque . '})}';
        $projectionNodes = '{CASEEXPR :casetype 20 :casecollid 0 :args ({CASEWHEN :expr ' . $selection
            . ' :result {COALESCEEXPR :coalescetype 20 :coalescecollid 0 :args (' . $owner
            . ' {CONST :consttype 20 :constcollid 0})}}) :defresult ' . $opaque . '}';
        $columns = [$table => [
            'items_id' => ['generated' => 's', 'expression' => $policy['projection'], 'native_nodes' => $projectionNodes],
            'itemtype' => ['attribute_number' => '2', 'type_oid' => '1043', 'type_modifier' => '104', 'collation_oid' => '100', 'deterministic' => true],
            'networkports_id' => ['attribute_number' => '3', 'type_oid' => '20', 'type_modifier' => '-1', 'collation_oid' => '0'],
            'opaque_parent_id' => ['attribute_number' => '4', 'type_oid' => '20', 'type_modifier' => '-1', 'collation_oid' => '0'],
        ]];
        $check = ['clause' => $policy['check'], 'enforced' => true, 'validated' => true,
            'native_nodes' => $checkNodes, 'checked_columns' => ['itemtype', 'networkports_id', 'opaque_parent_id'],
            'reference_operator_oids' => ['98', '521'], 'subject_operator_functions' => ['98' => '67', '521' => '147'],
            'reference_text_coercion' => ['boolean' => '16', 'binary' => true, 'varchar' => '1043', 'text' => '25',
                'smallint' => '21', 'integer' => '23', 'bigint' => '20']];
        $selected = [$table => ['items_id' => $policy]];
        $compare = static fn (array $rows, array $definition): array => NativeSubjectSchema::compare(
            $selected,
            $rows,
            [$table => [$policy['constraint'] => $definition]],
            true
        );
        $this->array($compare($columns, $check))->isEmpty();
        $stored = $columns;
        $stored[$table]['items_id'] = ['generated' => '', 'expression' => null];
        $this->array(NativeSubjectSchema::compare(
            $selected,
            $stored,
            [$table => [$policy['constraint'] => $check]],
            true,
            checksOnly: true
        ))->isEmpty();
        $this->array($compare($stored, $check))->contains(
            'Changed or missing native subject projection: ' . $table . '.items_id'
        );
        $invalidCheck = array_replace($check, ['native_nodes' => str_replace(':opno 98', ':opno 999999', $checkNodes)]);
        $this->array(NativeSubjectSchema::compare(
            $selected,
            $stored,
            [$table => [$policy['constraint'] => $invalidCheck]],
            true,
            checksOnly: true
        ))->contains(
            'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']
        );
        foreach ([
            ['native_nodes' => str_replace(':opno 98', ':opno 999999', $checkNodes)],
            ['native_nodes' => str_replace('OPEXPR', 'FUNCEXPR', $checkNodes)],
            ['native_nodes' => str_replace(':opfuncid 67', ':opfuncid 999999', $checkNodes)],
            ['native_nodes' => str_replace(':vartype 20', ':vartype 23', $checkNodes)],
            ['native_nodes' => str_replace(':vartypmod 104', ':vartypmod 204', $checkNodes)],
            ['native_nodes' => str_replace(':inputcollid 100', ':inputcollid 999', $checkNodes)],
            ['checked_columns' => ['itemtype', 'networkports_id']],
            ['reference_operator_oids' => []],
            ['reference_text_coercion' => ['binary' => false]],
        ] as $override) {
            $this->array($compare($columns, array_replace($check, $override)))->contains(
                'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint']
            );
        }
        foreach ([
            str_replace('COALESCEEXPR', 'FUNCEXPR', $projectionNodes),
            str_replace(':casetype 20', ':casetype 23', $projectionNodes),
            str_replace(':coalescetype 20', ':coalescetype 23', $projectionNodes),
            str_replace(':varattno 4', ':varattno 999', $projectionNodes),
        ] as $nodes) {
            $changed = $columns;
            $changed[$table]['items_id']['native_nodes'] = $nodes;
            $this->array($compare($changed, $check))->isIdenticalTo([
                'Changed or missing native subject projection: ' . $table . '.items_id',
            ]);
        }
    }

    public function testOpenStringSubjectAcceptsActualPostgreSQLCreationAndWidthRewrite(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-networkname-pg.json'), true, 512, JSON_THROW_ON_ERROR);
        $platform = new PostgreSQLPlatform();
        $builder = new BaselineSchema($this->manager($platform));
        $builder->build($platform);
        $table = 'glpi_networknames';
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $selected = [$table => ['items_id' => $policy]];
        $projectionError = 'Changed or missing native subject projection: ' . $table . '.items_id';
        $checkError = 'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'];
        foreach ($facts['engines'] as $engine) {
            $operators = $functions = [];
            foreach ($engine['operators'] as $operator) {
                if ($operator['function_namespace'] === 'pg_catalog') {
                    $operators[] = $operator['oid'];
                    $functions[$operator['oid']] = $operator['function_oid'];
                }
            }
            foreach ($engine['stages'] as $stage => $snapshot) {
                // Actual raw CHECK/projection nodes retain engine-specific VAR
                // annotations, attribute positions and width-recompiled types.
                $check = $engine['check_facts'][$snapshot['checks'][0]];
                $check['reference_operator_oids'] = $operators;
                $check['subject_operator_functions'] = $functions;
                $check['reference_text_coercion'] = $engine['reference_text_coercion'];
                $check['reference_text_coercion']['integer_to_bigint'] = $engine['integer_to_bigint_cast']['function_oid'];
                $check['reference_text_coercion']['boolean'] = $engine['boolean'];
                $columns = [$table => array_map(static fn (string $key): array => $engine['column_facts'][$key], $snapshot['columns'])];
                $checks = [$table => [$policy['constraint'] => $check]];
                $checkDifferences = NativeSubjectSchema::compare($selected, $columns, $checks, true, checksOnly: true);
                $differences = NativeSubjectSchema::compare($selected, $columns, $checks, true);
                if ($stage === 'integer-stored') {
                    // Before widening, native integer owners do not satisfy the
                    // final BIGINT domain declaration even with the same clause.
                    $this->array($checkDifferences)->isIdenticalTo([$checkError]);
                    $this->array($differences)->isIdenticalTo([$projectionError, $checkError]);
                } else {
                    $this->array($checkDifferences)->isEmpty();
                    foreach ([
                        preg_replace('/:relabelformat [12]/', ':relabelformat 0', $check['native_nodes']),
                        str_replace(':opresulttype 16', ':opresulttype 20', $check['native_nodes']),
                        str_replace(':opretset false', ':opretset true', $check['native_nodes']),
                        str_replace(':opcollid 0', ':opcollid 999', $check['native_nodes']),
                        str_replace(':resulttype 25', ':resulttype 23', $check['native_nodes']),
                        str_replace(':resulttypmod -1', ':resulttypmod 100', $check['native_nodes']),
                        str_replace(':resultcollid 100', ':resultcollid 999', $check['native_nodes']),
                    ] as $invalid) {
                        $damagedCheck = $checks;
                        $damagedCheck[$table][$policy['constraint']]['native_nodes'] = $invalid;
                        $this->array(NativeSubjectSchema::compare($selected, $columns, $damagedCheck, true, checksOnly: true))->isIdenticalTo([$checkError]);
                    }

                    $this->array($differences)->isIdenticalTo(str_ends_with($stage, '-generated') ? [] : [$projectionError]);
                    if (str_ends_with($stage, '-generated')) {
                        $nodes = $columns[$table]['items_id']['native_nodes'];
                        foreach ([
                            preg_replace('/\{FUNCEXPR :funcid [0-9]+/', '{FUNCEXPR :funcid 999999', $nodes),
                            str_replace(':funcformat 2', ':funcformat 1', $nodes),
                            str_replace(':opresulttype 16', ':opresulttype 20', $nodes),
                            str_replace(':opretset false', ':opretset true', $nodes),
                            str_replace(':opcollid 0', ':opcollid 999', $nodes),
                            str_replace(':casecollid 0', ':casecollid 100', $nodes),
                            str_replace(':coalescecollid 0', ':coalescecollid 100', $nodes),
                            str_replace(':relabelformat 2', ':relabelformat 1', $nodes),
                            str_replace(':funcretset false', ':funcretset true', $nodes),
                            str_replace(':constvalue 4 [ 0 0 0 0', ':constvalue 4 [ 1 0 0 0', $nodes),
                            preg_replace('/\{(?:CASEEXPR|CASE) /', '{CASE_UNKNOWN ', $nodes),
                        ] as $invalid) {
                            $damaged = $columns;
                            $damaged[$table]['items_id']['native_nodes'] = $invalid;
                            $this->array(NativeSubjectSchema::compare($selected, $damaged, $checks, true))->isIdenticalTo([$projectionError]);
                        }
                        $unbound = $checks;
                        unset($unbound[$table][$policy['constraint']]['reference_text_coercion']['integer_to_bigint']);
                        $this->array(NativeSubjectSchema::compare($selected, $columns, $unbound, true))->isIdenticalTo([$projectionError]);
                    }
                }
            }
        }
    }


    public function testOpenStringSubjectAcceptsActualMariaDBRendering(): void
    {
        $facts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/native-networkname-maria.json'), true, 512, JSON_THROW_ON_ERROR);
        $platform = new MariaDBPlatform();
        $builder = new BaselineSchema($this->manager($platform));
        $builder->build($platform);
        $table = 'glpi_networknames';
        $policy = $builder->subjectPolicies()[$table]['items_id'];
        $selected = [$table => ['items_id' => $policy]];
        $projectionError = 'Changed or missing native subject projection: ' . $table . '.items_id';
        $checkError = 'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $policy['constraint'];
        foreach ($facts['stages'] as $stage => $snapshot) {
            $columns = [];
            foreach ($snapshot['columns'] as $key) {
                $row = $facts['column_facts'][$key];
                $columns[$table][$row['COLUMN_NAME']] = ['generated' => $row['EXTRA'], 'expression' => $row['GENERATION_EXPRESSION']];
            }
            $row = $facts['check_facts'][$snapshot['checks'][0]];
            $checks = [$table => [$policy['constraint'] => ['clause' => $row['CHECK_CLAUSE'], 'enforced' => $facts['identity']['checks_enforced']]]];
            // This comparator owns expression/enforcement semantics; the frozen
            // migration separately validates the final BIGINT column shapes.
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, false, checksOnly: true))->isEmpty();
            $this->array(NativeSubjectSchema::compare($selected, $columns, $checks, false))->isIdenticalTo(str_ends_with($stage, '-generated') ? [] : [$projectionError]);
            $invalid = $checks;
            $invalid[$table][$policy['constraint']]['clause'] = str_replace('cast(`itemtype` as char charset binary)', '`itemtype`', $row['CHECK_CLAUSE']);
            $this->array(NativeSubjectSchema::compare($selected, $columns, $invalid, false, checksOnly: true))->isIdenticalTo([$checkError]);
            $invalid = $checks;
            $invalid[$table][$policy['constraint']]['enforced'] = false;
            $this->array(NativeSubjectSchema::compare($selected, $columns, $invalid, false, checksOnly: true))->isIdenticalTo([$checkError]);
            if (str_ends_with($stage, '-generated')) {
                $invalid = $columns;
                $invalid[$table]['items_id']['expression'] = str_replace('coalesce(`networkports_id`,0)', 'coalesce(`networkports_id`,1)', $columns[$table]['items_id']['expression']);
                $this->array(NativeSubjectSchema::compare($selected, $invalid, $checks, false))->isIdenticalTo([$projectionError]);
            }
        }
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
    public bool $active = false;
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

/** Records externally observable metadata enumeration work for one inspection. */
final class CurrentSchemaMetadataFactory extends ClassMetadataFactory
{
    public int $enumerations = 0;

    public function getAllMetadata(): array
    {
        ++$this->enumerations;
        return parent::getAllMetadata();
    }
}

#[ORM\Entity]
class ProviderFieldDimensions
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $body = null;

    #[ORM\Column(type: 'bigint')]
    #[PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['type' => Types::INTEGER, 'unsigned' => true])]
    public int $word = 0;
}

#[ORM\Entity]
class ProviderAssociationDimension
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'task_id')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => 17])]
    public ?CronTask $invalid = null;
}

#[ORM\Entity]
class InvalidProviderFieldLength
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'string')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => -1])]
    public string $invalid = '';
}

#[ORM\Entity]
class InvalidProviderHydrationType
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'integer')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['type' => Types::STRING])]
    public int $invalid = 0;
}
