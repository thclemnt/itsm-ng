<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use LogicException;

/** An entity-owned physical index used by ORM metadata and current schema inspection. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class SchemaIndex
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique = false,
        public readonly ?string $postgresqlName = null,
        public readonly array $options = [],
        public readonly ?array $postgresqlOptions = null,
        public readonly ?string $platform = null,
        public readonly array $flags = [],
        public readonly ?array $prefixLengths = null,
    ) {
        if ($platform !== null && !is_a($platform, AbstractPlatform::class, true)) {
            throw new InvalidArgumentException('Schema indexes require a DBAL platform class.');
        }
    }

    public function name(AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? ($this->postgresqlName ?? $this->name) : $this->name;
    }

    public function addToMetadata(ClassMetadata $metadata, AbstractPlatform $platform): void
    {
        $platformClass = $this->platform;
        if ($platformClass !== null && !$platform instanceof $platformClass) {
            return;
        }
        if ($this->prefixLengths !== null) {
            $this->prefixDefinition($metadata);
            if ($platform instanceof PostgreSQLPlatform) {
                // PostgreSQL owns native left() keys, never fictitious DBAL columns.
                return;
            }
        }
        $name = $this->name($platform);
        if (isset($metadata->table['indexes'][$name]) || isset($metadata->table['uniqueConstraints'][$name])) {
            throw new LogicException('Duplicate entity-owned schema index: ' . $metadata->name . '.' . $name);
        }
        $definition = ['columns' => $this->columns];
        if ($this->flags !== []) {
            $definition['flags'] = $this->flags;
        }
        $options = $platform instanceof PostgreSQLPlatform
            ? ($this->postgresqlOptions ?? $this->options)
            : $this->options;
        if ($this->prefixLengths !== null) {
            $options['lengths'] = $this->prefixLengths;
        }
        if ($options !== []) {
            $definition['options'] = $options;
        }
        $metadata->table[$this->unique ? 'uniqueConstraints' : 'indexes'][$name] = $definition;
    }

    /** The same ordered scalar tuple projects to native PostgreSQL prefix keys. */
    public function nativePrefixPolicy(ClassMetadata $metadata, AbstractPlatform $platform): ?array
    {
        if ($this->prefixLengths === null || !$platform instanceof PostgreSQLPlatform) {
            return null;
        }
        return [...$this->prefixDefinition($metadata), 'lengths' => $this->prefixLengths];
    }

    private function prefixDefinition(ClassMetadata $metadata): array
    {
        if ($this->unique || $this->flags || $this->options || $this->postgresqlOptions || $this->platform !== null
            || !array_is_list($this->columns) || !array_is_list($this->prefixLengths)
            || !$this->columns || count($this->columns) !== count($this->prefixLengths)) {
            throw new LogicException('Prefix keys require one unfiltered ordinary scalar index declaration.');
        }
        $columns = $sourceTypes = [];
        foreach ($this->columns as $position => $column) {
            if (!is_string($column)) {
                throw new LogicException('Prefix keys require scalar column names.');
            }
            $name = trim($column, '`"');
            $matches = array_filter($metadata->fieldMappings, static fn ($field): bool => trim($field->columnName, '`"') === $name);
            $length = $this->prefixLengths[$position];
            if (count($matches) !== 1 || !is_int($length) || $length <= 0 || $length > 2147483647 || in_array($name, $columns, true)) {
                throw new LogicException('Prefix keys require unique owning scalar columns and positive PostgreSQL-integer lengths.');
            }
            $field = reset($matches);
            if (!in_array($field->type, [Types::STRING, Types::TEXT], true)
                || ($field->type === Types::STRING && ($field->length === null || $length > $field->length))
                || ($field->options['fixed'] ?? false) || $field->notInsertable || $field->notUpdatable || $field->columnDefinition !== null) {
                throw new LogicException('Prefix keys require mapped writable text or bounded varchar fields.');
            }
            $columns[] = $name;
            $sourceTypes[] = $field->type === Types::TEXT ? 'text' : 'varchar';
        }
        return ['columns' => $columns, 'sourceTypes' => $sourceTypes];
    }
}
