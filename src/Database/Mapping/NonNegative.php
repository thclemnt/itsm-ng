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
use ReflectionNamedType;
use ReflectionProperty;

/** A mapped integer owns its existing PostgreSQL nonnegative domain. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class NonNegative
{
    public function __construct(public string $constraint)
    {
        if ($constraint === '' || strlen($constraint) > 63 || str_contains($constraint, "\0")) {
            throw new InvalidArgumentException('Nonnegative storage requires an explicit native CHECK name.');
        }
    }

    /** Native policy follows this build's scalar metadata; nullable integers still permit NULL. */
    public function policy(ClassMetadata $metadata, ReflectionProperty $property, AbstractPlatform $platform): ?array
    {
        if (!$metadata->hasField($property->name)) {
            throw new LogicException('Nonnegative storage requires an owning scalar integer property.');
        }
        $field = $metadata->getFieldMapping($property->name);
        $type = $property->getType();
        if (!in_array($field->type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)
            || !$type instanceof ReflectionNamedType || $type->getName() !== 'int'
            || $field->notInsertable || $field->notUpdatable || $field->columnDefinition !== null
            || ($field->generated !== null && $field->generated !== ClassMetadata::GENERATED_NEVER)) {
            throw new LogicException('Nonnegative storage requires a writable mapped integer with ordinary native storage.');
        }
        if (!$platform instanceof PostgreSQLPlatform) {
            // MySQL/MariaDB keep their existing property-owned unsigned storage.
            return null;
        }
        return [
            'constraint' => $this->constraint,
            'column' => $field->columnName,
            'type' => $field->type,
            'nullable' => (bool)$field->nullable,
            'check' => $platform->quoteIdentifier($field->columnName) . ' >= 0',
        ];
    }
}
