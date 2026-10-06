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
use itsmng\Database\Entity\CronTask;
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

    public function testCronTaskPreservesEveryCurrentColumnAndIndexAcrossProviders(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $manager = $this->manager($platform);
            $frozen = (new Baseline())->build($platform);
            $frozenSql = $frozen->toSql($platform);
            // The existing current identity policy widens frozen IDs before inspection.
            IdentifierColumns::configureSchema($frozen);
            $historical = $frozen->getTable('glpi_crontasks');
            $current = (new BaselineSchema($manager))->build($platform)->getTable('glpi_crontasks');
            $comparator = $manager->getConnection()->createSchemaManager()->createComparator();
            $this->boolean($comparator->compareTables($historical, $current)->isEmpty())->isTrue();
            $this->integer(count($current->getColumns()))->isIdenticalTo(16);
            $this->integer(count($current->getIndexes()))->isIdenticalTo(5);
            foreach ($historical->getColumns() as $column) {
                $actual = $current->getColumn($column->getName());
                $this->string(Type::lookupName($actual->getType()))->isIdenticalTo(Type::lookupName($column->getType()));
                $this->boolean($actual->getNotnull())->isIdenticalTo($column->getNotnull());
                $this->variable($actual->getDefault())->isEqualTo($column->getDefault());
                $this->variable($actual->getComment())->isIdenticalTo($column->getComment());
                $this->variable($actual->getLength())->isIdenticalTo($column->getLength());
                $this->boolean($actual->getAutoincrement())->isIdenticalTo($column->getAutoincrement());
                $this->variable($actual->getColumnDefinition())->isIdenticalTo($column->getColumnDefinition());
            }
            foreach ($historical->getIndexes() as $index) {
                $this->boolean($current->hasIndex($index->getName()))->isTrue();
                $this->array($current->getIndex($index->getName())->getColumns())->isIdenticalTo($index->getColumns());
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
