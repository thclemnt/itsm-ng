<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use LogicException;
use ReflectionProperty;
use itsmng\Database\ReferenceMode;

/** Semantics live beside the owning Doctrine association, which owns its target/type. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ReferencePolicy
{
    public function __construct(
        public ReferenceKind $kind,
        public ?string $modeProperty = null,
        public bool $emptyZero = true,
        public ?UserReferenceAction $userPurge = null,
        public ?string $nativeConstraint = null,
        public ?string $excludedByBooleanProperty = null,
    ) {
        if (($kind === ReferenceKind::Inherited) !== ($modeProperty !== null)) {
            throw new InvalidArgumentException('Only inherited references declare a mode property');
        }
        if ($excludedByBooleanProperty !== null && ($kind !== ReferenceKind::EmptySelection || $nativeConstraint === null)) {
            throw new InvalidArgumentException('Boolean exclusion requires a named empty-selection CHECK.');
        }
        if ($nativeConstraint !== null && (!in_array($kind, [ReferenceKind::Inherited, ReferenceKind::RootParent, ReferenceKind::EmptySelection], true)
            || ($kind === ReferenceKind::EmptySelection && $excludedByBooleanProperty === null)
            || !preg_match('/\A[a-z_][a-z_0-9]{0,62}\z/D', $nativeConstraint))) {
            throw new InvalidArgumentException('Native reference CHECK ownership requires an explicit supported policy and identifier.');
        }
    }

    /** Derive one named selection rule from this owning association's actual metadata. */
    public function nativeSelectionPolicy(ClassMetadata $metadata, ReflectionProperty $property, Table $table, AbstractPlatform $platform): ?array
    {
        if ($this->nativeConstraint !== null && $this->kind !== ReferenceKind::Inherited) {
            return $this->integerSelectionPolicy($metadata, $property, $table, $platform);
        }
        if ($this->kind !== ReferenceKind::Inherited) {
            return null;
        }
        if ($this->nativeConstraint === null || $table->getName() !== $metadata->getTableName()
            || !$metadata->hasAssociation($property->name) || !$metadata->hasField($this->modeProperty)) {
            throw new LogicException('Inherited native CHECK requires named owning association and mode metadata.');
        }
        $association = $metadata->getAssociationMapping($property->name);
        $mode = $metadata->getFieldMapping($this->modeProperty);
        if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1
            || !$association->joinColumns[0]->nullable || $association->joinColumns[0]->columnDefinition !== null
            || $mode->type !== Types::STRING || $mode->nullable || $mode->enumType !== ReferenceMode::class
            || $mode->notInsertable || $mode->notUpdatable || ($mode->generated ?? ClassMetadata::GENERATED_NEVER) !== ClassMetadata::GENERATED_NEVER
            || $mode->columnDefinition !== null
            || ($mode->options['fixed'] ?? false) || $mode->length === null) {
            throw new LogicException('Inherited native CHECK requires nullable scalar owning join and writable enum varchar mode.');
        }
        $modeColumn = trim($mode->columnName, '`"');
        $selectedColumn = trim($association->joinColumns[0]->name, '`"');
        if (!$table->hasColumn($modeColumn) || !$table->hasColumn($selectedColumn) || $modeColumn === $selectedColumn) {
            throw new LogicException('Inherited native CHECK columns must belong to the mapped table.');
        }
        $idType = Type::lookupName($table->getColumn($selectedColumn)->getType());
        if (!in_array($idType, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            throw new LogicException('Inherited selected identity requires an integer column.');
        }
        $choices = [ReferenceMode::Explicit->value, ReferenceMode::Inherit->value];
        if (!$this->emptyZero) {
            $choices[] = ReferenceMode::Unchanged->value;
        }
        if (!in_array($mode->options['default'] ?? null, $choices, true)
            || $mode->length < max(array_map('strlen', $choices))) {
            throw new LogicException('Inherited mode default and varchar length must cover its declared choices.');
        }
        $modeSql = $platform->quoteIdentifier($modeColumn);
        $selectedSql = $platform->quoteIdentifier($selectedColumn);
        $explicit = $platform->quoteStringLiteral(ReferenceMode::Explicit->value);
        $selection = $this->emptyZero ? '(' . $selectedSql . ' IS NULL OR ' . $selectedSql . ' > 0)'
            : '(' . $selectedSql . ' IS NOT NULL AND ' . $selectedSql . ' >= 0)';
        $check = $modeSql . ' IN (' . implode(', ', array_map($platform->quoteStringLiteral(...), $choices)) . ')'
            . ' AND ((' . $modeSql . ' = ' . $explicit . ' AND ' . $selection . ') OR ('
            . $modeSql . ' <> ' . $explicit . ' AND ' . $selectedSql . ' IS NULL))';
        return ['constraint' => $this->nativeConstraint, 'check' => $check,
            'mode_column' => $modeColumn, 'selected_column' => $selectedColumn,
            'string_selections' => [$modeColumn => $choices], 'integer_types' => [$selectedColumn => $idType]];
    }
    /** Only the declared root pair or nonnullable boolean exclusion enters this finite family. */
    private function integerSelectionPolicy(ClassMetadata $metadata, ReflectionProperty $property, Table $table, AbstractPlatform $platform): array
    {
        if ($table->getName() !== $metadata->getTableName() || !$metadata->hasAssociation($property->name)) {
            throw new LogicException('Native integer reference CHECK requires its actual mapped owning table.');
        }
        $association = $metadata->getAssociationMapping($property->name);
        if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1
            || !$association->joinColumns[0]->nullable || $association->joinColumns[0]->columnDefinition !== null) {
            throw new LogicException('Native integer reference CHECK requires a single nullable owning join.');
        }
        $selected = trim($association->joinColumns[0]->name, '`"');
        if (!$table->hasColumn($selected)) {
            throw new LogicException('Native reference selection column must belong to its mapped table.');
        }
        $selectedType = Type::lookupName($table->getColumn($selected)->getType());
        if (!in_array($selectedType, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)
            || $table->getColumn($selected)->getNotnull()) {
            throw new LogicException('Native reference selection requires a nullable integer column.');
        }
        $selectedSql = $platform->quoteIdentifier($selected);
        $policy = ['kind' => $this->kind->value, 'constraint' => $this->nativeConstraint,
            'selected_column' => $selected, 'integer_types' => [$selected => $selectedType],
            'string_selections' => [], 'boolean_columns' => [], 'integer_pairs' => [],
            'column_nullable' => [$selected => true]];
        if ($this->kind === ReferenceKind::RootParent) {
            $identifiers = $metadata->getIdentifierFieldNames();
            if ($association->targetEntity !== $metadata->name || count($identifiers) !== 1
                || !$metadata->hasField($identifiers[0])) {
                throw new LogicException('Root parent CHECK requires its actual single integer self identifier.');
            }
            $identifier = $metadata->getFieldMapping($identifiers[0]);
            $root = trim($identifier->columnName, '`"');
            if (!in_array($identifier->type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)
                || $identifier->nullable || $identifier->notInsertable || $identifier->notUpdatable
                || ($identifier->generated ?? ClassMetadata::GENERATED_NEVER) !== ClassMetadata::GENERATED_NEVER
                || $identifier->columnDefinition !== null || !$table->hasColumn($root)
                || !$table->getColumn($root)->getNotnull() || $root === $selected
                || trim($association->joinColumns[0]->referencedColumnName, '`"') !== $root
                || Type::lookupName($table->getColumn($root)->getType()) !== $identifier->type
                || $selectedType !== $identifier->type) {
                throw new LogicException('Root parent CHECK requires writable integer identity and matching self join.');
            }
            $rootSql = $platform->quoteIdentifier($root);
            $policy['integer_types'][$root] = $identifier->type;
            $policy['integer_pairs'] = [[$selected, $root]];
            $policy['column_nullable'][$root] = false;
            $policy['check'] = "($rootSql = 0 AND $selectedSql IS NULL) OR ($rootSql > 0 AND $selectedSql IS NOT NULL AND $selectedSql >= 0 AND $selectedSql <> $rootSql)";
            return $policy;
        }
        if ($this->kind !== ReferenceKind::EmptySelection || !$metadata->hasField($this->excludedByBooleanProperty)) {
            throw new LogicException('Boolean-excluded selection CHECK requires its declared mapped flag.');
        }
        $flag = $metadata->getFieldMapping($this->excludedByBooleanProperty);
        $flagColumn = trim($flag->columnName, '`"');
        if ($flag->type !== Types::BOOLEAN || $flag->nullable || $flag->notInsertable || $flag->notUpdatable
            || ($flag->generated ?? ClassMetadata::GENERATED_NEVER) !== ClassMetadata::GENERATED_NEVER
            || $flag->columnDefinition !== null || !$table->hasColumn($flagColumn)
            || !$table->getColumn($flagColumn)->getNotnull()
            || Type::lookupName($table->getColumn($flagColumn)->getType()) !== Types::BOOLEAN || $flagColumn === $selected) {
            throw new LogicException('Boolean exclusion CHECK requires a writable required boolean flag.');
        }
        $policy['boolean_columns'] = [$flagColumn];
        $policy['column_nullable'][$flagColumn] = false;
        $policy['check'] = "$selectedSql IS NULL OR ($selectedSql > 0 AND NOT " . $platform->quoteIdentifier($flagColumn) . ')';
        return $policy;
    }

}
