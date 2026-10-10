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
    ) {
        if (($kind === ReferenceKind::Inherited) !== ($modeProperty !== null)) {
            throw new InvalidArgumentException('Only inherited references declare a mode property');
        }
        if ($nativeConstraint !== null && ($kind !== ReferenceKind::Inherited
            || !preg_match('/\A[a-z_][a-z_0-9]{0,62}\z/D', $nativeConstraint))) {
            throw new InvalidArgumentException('Inherited CHECK ownership requires an explicit native identifier.');
        }
    }

    /** Derive one named selection rule from this owning association's actual metadata. */
    public function nativeSelectionPolicy(ClassMetadata $metadata, ReflectionProperty $property, Table $table, AbstractPlatform $platform): ?array
    {
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
}
