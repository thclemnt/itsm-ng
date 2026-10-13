<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

/** A wall-clock boundary, including 24:00:00. DateTime would lose that boundary. */
final class ClockTimeType extends Type
{
    public const NAME = 'itsm_clock_time';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getTimeTypeDeclarationSQL($column);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string)$value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string)$value;
        if (preg_match('/^(?:[01][0-9]|2[0-4]):[0-5][0-9]$/D', $value)) {
            $value .= ':00';
        }
        if (!preg_match('/^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)$/D', $value)) {
            throw new InvalidArgumentException('Invalid clock time boundary');
        }
        return $value;
    }
}
