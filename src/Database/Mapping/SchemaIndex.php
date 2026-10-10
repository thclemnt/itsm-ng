<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
        if ($options !== []) {
            $definition['options'] = $options;
        }
        $metadata->table[$this->unique ? 'uniqueConstraints' : 'indexes'][$name] = $definition;
    }
}
