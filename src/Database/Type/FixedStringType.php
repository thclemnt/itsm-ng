<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/** CHAR values expose their logical string, without storage padding, on either engine. */
final class FixedStringType extends StringType
{
    public const NAME = 'itsm_fixed_string';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['fixed' => true] + $column);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : rtrim((string)$value, ' ');
    }

    /** Scalar hydration does not run every PHP converter; field projections own the same semantics. */
    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return 'RTRIM(' . $sqlExpr . ')';
    }
}
