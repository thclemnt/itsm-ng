<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\FieldMapping;
use itsmng\Database\BooleanValue;

/** Physical current-schema storage; the ORM property still owns its boolean domain. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BooleanStorage
{
    public function __construct(public readonly string $mysqlType)
    {
        if (!in_array($mysqlType, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            throw new \InvalidArgumentException('BooleanStorage requires an integer MySQL storage type.');
        }
    }

    /** Project DBAL DDL without changing ORM hydration or the metadata-derived CHECK. */
    public function configure(Column $column, AbstractPlatform $platform, FieldMapping $field): void
    {
        if ($field->type !== Types::BOOLEAN) {
            throw new \InvalidArgumentException('BooleanStorage requires an ORM boolean field.');
        }
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $column->setType(Type::getType($mysql ? $this->mysqlType : Types::BOOLEAN));
        $default = $column->getDefault();
        if ($default !== null) {
            $default = BooleanValue::normalize($default, (bool)$field->nullable, $field->fieldName);
            $column->setDefault($mysql ? (string)(int)$default : $default);
        }
    }
}
