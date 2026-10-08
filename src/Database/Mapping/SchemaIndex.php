<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
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
    ) {
    }

    public function name(AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? ($this->postgresqlName ?? $this->name) : $this->name;
    }

    public function addToMetadata(ClassMetadata $metadata, AbstractPlatform $platform): void
    {
        $name = $this->name($platform);
        if (isset($metadata->table['indexes'][$name]) || isset($metadata->table['uniqueConstraints'][$name])) {
            throw new LogicException('Duplicate entity-owned schema index: ' . $metadata->name . '.' . $name);
        }
        $metadata->table[$this->unique ? 'uniqueConstraints' : 'indexes'][$name] = ['columns' => $this->columns];
    }
}
