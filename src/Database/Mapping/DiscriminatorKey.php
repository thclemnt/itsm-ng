<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/** Read-only legacy identity derived from entity-local discriminator associations. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DiscriminatorKey
{
    public function __construct(public ?string $fallbackProperty = null, public ?int $emptyValue = null, public bool $exactDiscriminator = false, public array $emptyRequiredNullProperties = [], public bool $openStringFallback = false)
    {
    }

    public function declaration(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        return 'BIGINT GENERATED ALWAYS AS (' . $this->projectionExpression($platform, $metadata, $property) . ') STORED';
    }

    public function projectionExpression(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        $column = $metadata->getColumnName($property);
        $cases = [];
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn === $column) {
                    $selection = $platform->quoteIdentifier($association->joinColumns[0]->name);
                    if ($binding->emptyValue !== null) {
                        $selection = 'COALESCE(' . $selection . ', ' . $binding->emptyValue . ')';
                    }
                    $kinds = array_map(static fn ($value) => is_int($value) ? (string)$value : $platform->quoteStringLiteral($value), $binding->values);
                    $cases[] = 'WHEN ' . $this->discriminatorSql($platform, $metadata, $binding->discriminator) . ' IN (' . implode(', ', $kinds) . ') THEN '
                        . $selection;
                }
            }
        }
        if (!$cases) {
            throw new LogicException('Generated discriminator identity requires owning associations');
        }
        return 'CASE ' . implode(' ', $cases) . ' ELSE '
            . ($this->fallbackProperty === null ? ($this->emptyValue === null ? 'NULL' : (string)$this->emptyValue) : $platform->quoteIdentifier($metadata->getColumnName($this->fallbackProperty))) . ' END';
    }

    /** Current owning schema derives from the key property, including optional stock. */
    public function configureSubjectTable(Table $table, AbstractPlatform $platform, ClassMetadata $metadata, string $property): void
    {
        $bindings = $this->subjectBindings($metadata, $property);
        foreach ($bindings as $name => $binding) {
            $join = $metadata->associationMappings[$name]->joinColumns[0];
            if (!$table->hasColumn($join->name)) {
                $table->addColumn('`' . $join->name . '`', 'bigint', ['notnull' => !$join->nullable]);
            }
            $index = $table->getName() . '_' . $join->name;
            // Legacy PostgreSQL names can say computers_id while indexing the
            // compatibility items_id column. Preserve that useful index and own
            // the typed lookup under a distinct, deterministic name.
            if ($table->hasIndex($index)
                && $table->getIndex($index)->getUnquotedColumns() !== [$join->name]) {
                $index = strlen($index) <= 57 ? $index . '_typed' : 'subject_' . sha1($index);
            }
            if ($table->hasIndex($index)) {
                if ($table->getIndex($index)->getUnquotedColumns() !== [$join->name]) {
                    throw new LogicException('Conflicting current subject index declaration: ' . $index);
                }
            } else {
                $table->addIndex([$join->name], $index);
            }
            $discriminator = $metadata->getFieldMapping($binding->discriminator);
            $table->getColumn($discriminator->columnName)->setNotnull(!$discriminator->nullable)->setLength($discriminator->length)
                ->setDefault($discriminator->options['default'] ?? null);
        }
        $column = $table->getColumn($metadata->getColumnName($property));
        $comment = (string)$column->getComment();
        $declaration = $this->declaration($platform, $metadata, $property);
        // DBAL's custom column definition bypasses its inline comment generation.
        if ($platform->supportsInlineColumnComments() && $comment !== '') {
            $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
        }
        $column->setNotnull(false)->setDefault(null)->setColumnDefinition($declaration);
    }

    public function subjectCheckExpression(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        if ($this->openStringFallback) {
            return $this->openStringCheckExpression($platform, $metadata, $property);
        }
        if ($this->fallbackProperty !== null) {
            return $this->fallbackCheckExpression($platform, $metadata, $property);
        }
        $bindings = $this->subjectBindings($metadata, $property);
        $columns = [];
        foreach ($bindings as $name => $binding) {
            $columns[$name] = $platform->quoteIdentifier($metadata->associationMappings[$name]->joinColumns[0]->name);
        }
        $branches = [];
        foreach ($bindings as $name => $binding) {
            $discriminator = $this->discriminatorSql($platform, $metadata, $binding->discriminator);
            $kinds = array_map($platform->quoteStringLiteral(...), $binding->values);
            $branch = [$discriminator . ' IS NOT NULL', $discriminator . ' IN (' . implode(', ', $kinds) . ')', $columns[$name] . ' IS NOT NULL', $columns[$name] . ' >= ' . $binding->minimumId];
            foreach ($columns as $otherName => $column) {
                if ($otherName !== $name) {
                    $branch[] = $column . ' IS NULL';
                }
            }
            $branches[] = '(' . implode(' AND ', $branch) . ')';
        }
        if ($this->emptyValue !== null) {
            $first = reset($bindings);
            $empty = [$platform->quoteIdentifier($metadata->getColumnName($first->discriminator)) . ' IS NULL'];
            foreach ($columns as $column) {
                $empty[] = $column . ' IS NULL';
            }
            foreach ($this->emptyRequiredNullProperties as $emptyProperty) {
                $empty[] = $platform->quoteIdentifier($metadata->getColumnName($emptyProperty)) . ' IS NULL';
            }
            $branches[] = '(' . implode(' AND ', $empty) . ')';
        }
        return implode(' OR ', $branches);
    }

    public function subjectConstraintName(ClassMetadata $metadata): string
    {
        $attributes = (new ReflectionClass($metadata->name))->getAttributes(RequiredSubjectConstraint::class);
        $suffix = $attributes ? $attributes[0]->newInstance()->suffix : 'typed_item_kind';
        return $metadata->getTableName() . '_' . $suffix;
    }


    /** An open parent discriminator constrains only its explicitly adopted owning branches. */
    private function openStringBindings(ClassMetadata $metadata, string $property): array
    {
        if ($this->fallbackProperty === null || !in_array($this->emptyValue, [null, 0], true) || !$this->exactDiscriminator
            || $this->emptyRequiredNullProperties || !$metadata->hasField($this->fallbackProperty)
            || !$metadata->getFieldMapping($this->fallbackProperty)->nullable
            || !in_array($metadata->getFieldMapping($this->fallbackProperty)->type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            throw new LogicException('Open subject requires an exact discriminator and nullable opaque integer fallback.');
        }
        $bindings = $kinds = [];
        $discriminator = null;
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn !== $metadata->getColumnName($property)) {
                    continue;
                }
                $field = $metadata->getFieldMapping($binding->discriminator);
                if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1
                    || !$association->joinColumns[0]->nullable || $binding->emptyValue !== 0 || $binding->minimumId !== 1
                    || $field->type !== Types::STRING
                    || ($discriminator !== null && $discriminator !== $binding->discriminator)) {
                    throw new LogicException('Open subject requires nullable owning branches and one string discriminator.');
                }
                foreach ($binding->values as $kind) {
                    if (!is_string($kind) || $kind === '' || isset($kinds[$kind])) {
                        throw new LogicException('Open subject kinds must be disjoint nonempty strings.');
                    }
                    $kinds[$kind] = true;
                }
                $bindings[$name] = $binding;
                $discriminator = $binding->discriminator;
            }
        }
        if (count($bindings) !== 1 || count($kinds) !== 1) {
            throw new LogicException('Open subject requires exactly one adopted owning branch and one string kind.');
        }
        return $bindings;
    }

    private function openStringCheckExpression(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        $bindings = $this->openStringBindings($metadata, $property);
        $columns = [];
        foreach ($bindings as $name => $binding) {
            $columns[$name] = $platform->quoteIdentifier($metadata->associationMappings[$name]->joinColumns[0]->name);
        }
        $fallback = $platform->quoteIdentifier($metadata->getColumnName($this->fallbackProperty));
        $branches = $kinds = [];
        foreach ($bindings as $name => $binding) {
            $discriminator = $this->discriminatorSql($platform, $metadata, $binding->discriminator);
            $values = array_map($platform->quoteStringLiteral(...), $binding->values);
            array_push($kinds, ...$values);
            $branch = [$discriminator . ' IN (' . implode(', ', $values) . ')',
                '(' . $columns[$name] . ' IS NULL OR ' . $columns[$name] . ' > 0)', $fallback . ' IS NULL'];
            foreach ($columns as $other => $column) {
                if ($name !== $other) {
                    $branch[] = $column . ' IS NULL';
                }
            }
            if ($metadata->getFieldMapping($binding->discriminator)->nullable) {
                // SQL CHECK accepts UNKNOWN: the adopted branch must be false
                // for NULL kinds, while the opaque branch owns those rows.
                array_unshift($branch, $platform->quoteIdentifier($metadata->getColumnName($binding->discriminator)) . ' IS NOT NULL');
            }
            $branches[] = '(' . implode(' AND ', $branch) . ')';
        }
        $unknown = [$discriminator . ' NOT IN (' . implode(', ', $kinds) . ')'];
        if ($metadata->getFieldMapping($binding->discriminator)->nullable) {
            $unknown[0] = '(' . $platform->quoteIdentifier($metadata->getColumnName($binding->discriminator)) . ' IS NULL OR ' . $unknown[0] . ')';
        }
        foreach ($columns as $column) {
            $unknown[] = $column . ' IS NULL';
        }
        $unknown[] = $fallback . ' IS NOT NULL';
        $branches[] = '(' . implode(' AND ', $unknown) . ')';
        return implode(' OR ', $branches);
    }

    /** Required or optional selected associations and an opaque integer fallback derive from their branches. */
    private function fallbackCheckExpression(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        if ($this->emptyValue !== null || $this->emptyRequiredNullProperties || $this->exactDiscriminator
            || !$metadata->hasField($this->fallbackProperty)
            || !$metadata->getFieldMapping($this->fallbackProperty)->nullable
            || !in_array($metadata->getFieldMapping($this->fallbackProperty)->type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            throw new LogicException('Fallback subject requires a nullable opaque integer field.');
        }
        $bindings = $columns = $kinds = [];
        $discriminatorProperty = null;
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn !== $metadata->getColumnName($property)) {
                    continue;
                }
                $field = $metadata->getFieldMapping($binding->discriminator);
                if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1
                    || !$association->joinColumns[0]->nullable || !in_array($binding->emptyValue, [null, 0], true) || $binding->minimumId !== 1
                    || $field->nullable || !in_array($field->type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)
                    || ($discriminatorProperty !== null && $discriminatorProperty !== $binding->discriminator)) {
                    throw new LogicException('Fallback subject requires nullable owning branches and one nonnull integer discriminator.');
                }
                foreach ($binding->values as $kind) {
                    if (!is_int($kind) || $kind < 0 || isset($kinds[$kind])) {
                        throw new LogicException('Fallback subject kinds must be disjoint nonnegative integers.');
                    }
                    $kinds[$kind] = $kind;
                }
                $discriminatorProperty = $binding->discriminator;
                $bindings[$name] = $binding;
                $columns[$name] = $platform->quoteIdentifier($association->joinColumns[0]->name);
            }
        }
        if (!$bindings) {
            throw new LogicException('Fallback subject requires declared owning server branches.');
        }
        $discriminator = $platform->quoteIdentifier($metadata->getColumnName($discriminatorProperty));
        $fallback = $platform->quoteIdentifier($metadata->getColumnName($this->fallbackProperty));
        $branches = [];
        foreach ($bindings as $name => $binding) {
            $branch = [$discriminator . ' IN (' . implode(', ', $binding->values) . ')'];
            if ($binding->emptyValue === null) {
                $branch[] = $columns[$name] . ' IS NOT NULL';
                $branch[] = $columns[$name] . ' > 0';
            } else {
                $branch[] = '(' . $columns[$name] . ' IS NULL OR ' . $columns[$name] . ' > 0)';
            }
            foreach ($columns as $other => $column) {
                if ($other !== $name) {
                    $branch[] = $column . ' IS NULL';
                }
            }
            $branch[] = $fallback . ' IS NULL';
            $branches[] = '(' . implode(' AND ', $branch) . ')';
        }
        $fallbackBranch = [$discriminator . ' NOT IN (' . implode(', ', $kinds) . ')'];
        foreach ($columns as $column) {
            $fallbackBranch[] = $column . ' IS NULL';
        }
        $fallbackBranch[] = $fallback . ' IS NOT NULL';
        $branches[] = '(' . implode(' AND ', $fallbackBranch) . ')';
        return implode(' OR ', $branches);
    }

    /** Exact kinds are a property policy; MySQL text collations may fold case or spaces. */
    private function discriminatorSql(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        $column = $platform->quoteIdentifier($metadata->getColumnName($property));
        return $this->exactDiscriminator && $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $column . ' AS BINARY)' : $column;
    }

    /** Fallback/numeric identities retain their separately owned semantics. */
    private function subjectBindings(ClassMetadata $metadata, string $property): array
    {
        if ($this->fallbackProperty !== null || ($this->emptyRequiredNullProperties && $this->emptyValue === null)) {
            throw new LogicException('Subject schema requires declared owning branches and a coherent empty identity');
        }
        foreach ($this->emptyRequiredNullProperties as $emptyProperty) {
            if (!is_string($emptyProperty) || !$metadata->hasField($emptyProperty) || !$metadata->getFieldMapping($emptyProperty)->nullable) {
                throw new LogicException('Empty subject state requires declared nullable scalar properties');
            }
        }
        $bindings = [];
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn !== $metadata->getColumnName($property)) {
                    continue;
                }
                if (!$association->isToOneOwningSide() || $binding->emptyValue !== null || array_filter($binding->values, static fn ($value) => !is_string($value))) {
                    throw new LogicException('Required subject schema needs owning, nonempty string discriminator branches');
                }
                $bindings[$name] = $binding;
            }
        }
        if (!$bindings) {
            throw new LogicException('Required subject schema needs owning associations');
        }
        return $bindings;
    }
}
