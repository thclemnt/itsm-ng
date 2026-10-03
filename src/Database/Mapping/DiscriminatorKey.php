<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\DBAL\Schema\Table;

/** Read-only legacy identity derived from entity-local discriminator associations. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class DiscriminatorKey
{
    public function __construct(public ?string $fallbackProperty = null, public ?int $emptyValue = null, public bool $exactDiscriminator = false, public array $emptyRequiredNullProperties = [])
    {
    }

    public function declaration(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        $column = $metadata->getColumnName($property);
        $cases = [];
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new \ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
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
            throw new \LogicException('Generated discriminator identity requires owning associations');
        }
        return 'BIGINT GENERATED ALWAYS AS (CASE ' . implode(' ', $cases) . ' ELSE '
            . ($this->fallbackProperty === null ? ($this->emptyValue === null ? 'NULL' : (string)$this->emptyValue) : $platform->quoteIdentifier($metadata->getColumnName($this->fallbackProperty))) . ' END) STORED';
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
            if (!$table->hasIndex($index)) {
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

    public function subjectCheckSql(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
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
        $attributes = (new \ReflectionClass($metadata->name))->getAttributes(RequiredSubjectConstraint::class);
        $suffix = $attributes ? $attributes[0]->newInstance()->suffix : 'typed_item_kind';
        return 'ALTER TABLE ' . $platform->quoteIdentifier($metadata->getTableName()) . ' ADD CONSTRAINT '
            . $platform->quoteIdentifier($metadata->getTableName() . '_' . $suffix) . ' CHECK (' . implode(' OR ', $branches) . ')';
    }

    /** Existing required-only callers retain their explicit admission contract. */
    public function configureRequiredTable(Table $table, AbstractPlatform $platform, ClassMetadata $metadata, string $property): void
    {
        if ($this->emptyValue !== null) {
            throw new \LogicException('Required subject schema cannot use an optional identity');
        }
        $this->configureSubjectTable($table, $platform, $metadata, $property);
    }

    public function requiredCheckSql(AbstractPlatform $platform, ClassMetadata $metadata, string $property): string
    {
        if ($this->emptyValue !== null) {
            throw new \LogicException('Required subject schema cannot use an optional identity');
        }
        return $this->subjectCheckSql($platform, $metadata, $property);
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
            throw new \LogicException('Subject schema requires declared owning branches and a coherent empty identity');
        }
        foreach ($this->emptyRequiredNullProperties as $emptyProperty) {
            if (!is_string($emptyProperty) || !$metadata->hasField($emptyProperty) || !$metadata->getFieldMapping($emptyProperty)->nullable) {
                throw new \LogicException('Empty subject state requires declared nullable scalar properties');
            }
        }
        $bindings = [];
        foreach ($metadata->associationMappings as $name => $association) {
            foreach ((new \ReflectionProperty($metadata->name, $name))->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn !== $metadata->getColumnName($property)) {
                    continue;
                }
                if (!$association->isToOneOwningSide() || $binding->emptyValue !== null || array_filter($binding->values, static fn ($value) => !is_string($value))) {
                    throw new \LogicException('Required subject schema needs owning, nonempty string discriminator branches');
                }
                $bindings[$name] = $binding;
            }
        }
        if (!$bindings) {
            throw new \LogicException('Required subject schema needs owning associations');
        }
        return $bindings;
    }
}
