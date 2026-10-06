<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/** Provider-specific SQL options augment the owning ORM declaration. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class PlatformOptions
{
    /** @param class-string<AbstractPlatform> $platform */
    public function __construct(public readonly string $platform, public readonly array $options)
    {
        if (!is_a($platform, AbstractPlatform::class, true)) {
            throw new \InvalidArgumentException('Schema options require a DBAL platform class.');
        }
    }

    public static function forDeclaration(\ReflectionProperty|\ReflectionClass $declaration, AbstractPlatform $platform): array
    {
        $options = [];
        foreach ($declaration->getAttributes(self::class) as $attribute) {
            $optionsForPlatform = $attribute->newInstance();
            $platformClass = $optionsForPlatform->platform;
            if (!$platform instanceof $platformClass) {
                continue;
            }
            if (array_intersect_key($options, $optionsForPlatform->options)) {
                throw new \LogicException('Overlapping provider schema options: ' . $declaration->name);
            }
            $options += $optionsForPlatform->options;
        }
        return $options;
    }
}
