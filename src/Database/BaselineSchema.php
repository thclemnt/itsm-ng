<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use ReflectionProperty;

/** Current required schema; installation separately replays immutable migration history. */
final class BaselineSchema
{
    private array $subjectPolicies = [];

    public function __construct(private readonly ?EntityManager $metadataManager = null)
    {
    }

    /** Native policies from the same authoritative metadata snapshot as the current schema. */
    public function subjectPolicies(): array
    {
        return $this->subjectPolicies;
    }

    public function build(AbstractPlatform $platform, bool $foreignKeys = true): Schema
    {
        if ($this->metadataManager !== null
            && $this->metadataManager->getConnection()->getDatabasePlatform()::class !== $platform::class) {
            throw new InvalidArgumentException('Current schema metadata must use the selected platform.');
        }
        $this->subjectPolicies = [];
        // Explicit version keeps standalone metadata inspection offline.
        $connection = $this->metadataManager?->getConnection()
            ?? DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $manager = $this->metadataManager ?? new EntityManager($connection, Orm::configuration($platform));
        try {
            $metadata = $manager->getMetadataFactory()->getAllMetadata();
            $schema = (new SchemaTool($manager))->getSchemaFromMetadata($metadata);
            foreach ($metadata as $entity) {
                $table = $schema->getTable($entity->getTableName());
                // SchemaTool removes indexes covered by another constraint.
                // Explicit declarations still own their physical names and storage.
                foreach ($entity->table['indexes'] ?? [] as $name => $index) {
                    if (!$table->hasIndex($name)) {
                        $table->addIndex($index['columns'], $name, $index['flags'] ?? [], $index['options'] ?? []);
                    }
                }
                if (!$foreignKeys) {
                    foreach ($table->getForeignKeys() as $key) {
                        $table->removeForeignKey($key->getName());
                    }
                }
                foreach ($entity->fieldMappings as $property => $field) {
                    $declaration = new ReflectionProperty($entity->name, $property);
                    foreach ($declaration->getAttributes(BooleanStorage::class) as $attribute) {
                        $attribute->newInstance()->configure($table->getColumn($field->columnName), $platform, $field);
                    }
                    foreach ($declaration->getAttributes(DiscriminatorKey::class) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->fallbackProperty !== null) {
                            continue;
                        }
                        $discriminators = [];
                        foreach ($entity->associationMappings as $association => $mapping) {
                            foreach ((new ReflectionProperty($entity->name, $association))->getAttributes(DiscriminatedBy::class) as $binding) {
                                $binding = $binding->newInstance();
                                if ($binding->legacyColumn === $field->columnName) {
                                    $discriminators[] = $entity->getColumnName($binding->discriminator);
                                }
                            }
                        }
                        $this->subjectPolicies[$entity->getTableName()][$field->columnName] = [
                            'projection' => $key->projectionExpression($platform, $entity, $property),
                            'constraint' => $key->subjectConstraintName($entity),
                            'check' => $key->subjectCheckExpression($platform, $entity, $property),
                            'discriminators' => array_values(array_unique($discriminators)),
                        ];
                    }
                }
            }
            return $schema;
        } finally {
            if ($this->metadataManager === null) {
                $connection->close();
            }
        }
    }
}
