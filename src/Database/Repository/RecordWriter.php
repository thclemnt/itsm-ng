<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\EntityRegistry;

/** Raw-value ORM persistence. Application callers must retain their lifecycle services. */
final class RecordWriter
{
    public function __construct(private EntityManager $em)
    {
    }

    public function insert(string $table, array $values): int
    {
        $class = EntityRegistry::TABLES[$table];
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
                $record->$field = new \DateTime();
            }
        }
        try {
            $this->assign($metadata, $record, $values);
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
        $class = EntityRegistry::TABLES[$table];
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
        return $columns;
    }

    public function delete(string $table, int $id): void
    {
        $record = $this->em->getRepository(EntityRegistry::TABLES[$table])->findOneBy(['id' => $id]);
        if ($record !== null) {
            $this->em->remove($record);
            $this->em->flush();
        }
    }

    private function assign(ClassMetadata $metadata, object $record, array $values): void
    {
        $associations = [];
        foreach ($metadata->associationMappings as $field => $mapping) {
            $associations[$mapping->joinColumns[0]->name] = $field;
        }
        foreach ($values as $column => $value) {
            if ($value instanceof \QueryExpression || $value instanceof \QueryParam) {
                throw new \InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            if (isset($associations[$column])) {
                $field = $associations[$column];
                $record->$field = $value === null ? null : $this->em->getReference($metadata->getAssociationTargetClass($field), (int)$value);
                continue;
            }
            $field = $metadata->getFieldName($column);
            $mapping = $metadata->getFieldMapping($field);
            if ($value !== null) {
                $value = match ($mapping->type) {
                    'boolean' => (bool)(int)$value,
                    'integer', 'smallint' => (int)$value,
                    'float' => (float)$value,
                    'date', 'datetime', 'datetimetz' => $value instanceof \DateTimeInterface ? \DateTime::createFromInterface($value) : new \DateTime((string)$value),
                    'json' => is_array($value) ? $value : json_decode((string)$value, true, flags: JSON_THROW_ON_ERROR),
                    default => is_scalar($value) || $value instanceof \Stringable ? (string)$value : throw new \InvalidArgumentException('Mapped fields require typed values, not SQL expressions.'),
                };
            }
            $record->$field = $value;
        }
    }
}
