<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use itsmng\Database\BooleanValue;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\LegacyInput;
use QueryExpression;
use QueryParam;
use Stringable;

/** Raw-value ORM persistence. Application callers must retain their lifecycle services. */
final class RecordWriter
{
    public function __construct(private EntityManager $em)
    {
    }

    public function insert(string $table, array $values): int
    {
        $class = EntityRegistry::tables()[$table];
        $metadata = $this->em->getClassMetadata($class);
        $record = new $class();
        $generatorType = $metadata->generatorType;
        $generator = $metadata->idGenerator;
        if ($metadata->identifier === ['id'] && array_key_exists('id', $values) && $values['id'] !== null) {
            $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            $metadata->setIdGenerator(new AssignedGenerator());
        } elseif ($metadata->identifier === ['id'] && $metadata->usesIdGenerator()) {
            unset($values['id']);
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (($mapping->options['default'] ?? null) === 'CURRENT_TIMESTAMP' && !array_key_exists($mapping->columnName, $values)) {
                $record->$field = new DateTime();
            }
        }
        try {
            $this->assign($metadata, $record, $values, applyDefaults: true);
            $this->em->persist($record);
            $this->em->flush();
            return (int)$record->id;
        } finally {
            // Explicit-ID imports must not change later inserts in this unit of work.
            $metadata->setIdGeneratorType($generatorType);
            $metadata->setIdGenerator($generator);
        }
    }

    /** @return string[] Changed physical column names for application history. */
    public function update(string $table, int $id, array $values): array
    {
        $class = EntityRegistry::tables()[$table];
        // Some legacy tables use an alternate/composite PK and a unique numeric id.
        $record = $this->em->getRepository($class)->findOneBy(['id' => $id]);
        if ($record === null) {
            return [];
        }
        unset($values['id']);
        $metadata = $this->em->getClassMetadata($class);
        $this->assign($metadata, $record, $values);
        $this->em->getUnitOfWork()->computeChangeSets();
        $columns = [];
        foreach (array_keys($this->em->getUnitOfWork()->getEntityChangeSet($record)) as $field) {
            $columns[] = $metadata->hasAssociation($field)
                ? $metadata->getAssociationMapping($field)->joinColumns[0]->name
                : $metadata->getColumnName($field);
        }
        $this->em->flush();
        return $record instanceof LegacyInput ? $record->legacyChanges($columns) : $columns;
    }

    public function delete(string $table, int $id): void
    {
        $record = $this->em->getRepository(EntityRegistry::tables()[$table])->findOneBy(['id' => $id]);
        if ($record !== null) {
            $this->em->remove($record);
            $this->em->flush();
        }
    }

    private function assign(ClassMetadata $metadata, object $record, array $values, bool $applyDefaults = false): void
    {
        if ($record instanceof LegacyInput) {
            $values = $record->normalizeInput($values);
        }
        // Reject invalid flags before any managed property is changed. A caller
        // may retain this unit of work after a rejected assignment.
        foreach ($metadata->fieldMappings as $mapping) {
            if ($mapping->type === 'boolean' && array_key_exists($mapping->columnName, $values)) {
                $values[$mapping->columnName] = BooleanValue::normalize($values[$mapping->columnName], (bool)$mapping->nullable, $metadata->getTableName() . '.' . $mapping->columnName);
            }
        }
        $associations = [];
        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            $join = $mapping->joinColumns[0];
            $associations[$join->name] = $field;
            // Physical insertion defaults must not masquerade as supplied
            // canonical values while an entity resolves its legacy input.
            if ($applyDefaults && array_key_exists('default', $join->options ?? [])
                && !array_key_exists($join->name, $values)) {
                $values[$join->name] = $join->options['default'];
            }
        }
        foreach ($values as $column => $value) {
            if ($value instanceof QueryExpression || $value instanceof QueryParam) {
                throw new InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            if (isset($associations[$column])) {
                $field = $associations[$column];
                if ($value === null && !$metadata->getAssociationMapping($field)->joinColumns[0]->nullable) {
                    throw new InvalidArgumentException('Required relationship cannot be NULL: ' . $metadata->getTableName() . '.' . $column);
                }
                $selfId = $values['id'] ?? ($record->id ?? null);
                $self = $metadata->identifier === ['id'] && $metadata->getAssociationTargetClass($field) === $metadata->name
                    && $selfId !== null && $value !== null && (int)$value === (int)$selfId;
                $record->$field = $value === null ? null : ($self ? $record : $this->em->getReference($metadata->getAssociationTargetClass($field), (int)$value));
                continue;
            }
            $field = $metadata->getFieldName($column);
            $mapping = $metadata->getFieldMapping($field);
            if ($mapping->enumType !== null) {
                $enum = $mapping->enumType;
                $record->$field = $value === null ? null : ($value instanceof $enum ? $value : $enum::from($value));
                continue;
            }
            if ($mapping->type === 'boolean') {
                $record->$field = $value;
                continue;
            }
            if ($value !== null) {
                $value = match ($mapping->type) {
                    'integer', 'smallint', 'bigint' => (int)$value,
                    'float' => (float)$value,
                    'date', 'datetime', 'datetimetz' => $value instanceof DateTimeInterface ? DateTime::createFromInterface($value) : new DateTime((string)$value),
                    'json' => is_array($value) ? $value : json_decode((string)$value, true, flags: JSON_THROW_ON_ERROR),
                    default => is_scalar($value) || $value instanceof Stringable ? (string)$value : throw new InvalidArgumentException('Mapped fields require typed values, not SQL expressions.'),
                };
            }
            $record->$field = $value;
        }
    }
}
