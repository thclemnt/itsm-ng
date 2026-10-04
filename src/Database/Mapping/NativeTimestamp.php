<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;

/** Native instant storage; the ORM field still owns hydration, defaults and nullability. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NativeTimestamp
{
    /** An explicit name owns automatic touch on both providers, without an ORM version field. */
    public function __construct(public readonly ?string $touchTrigger = null)
    {
        if ($touchTrigger === '') {
            throw new \InvalidArgumentException('Automatic timestamp touch requires an explicit trigger name.');
        }
    }

    /** A writable generated automatic clock owns its successful native readback. */
    public function ownsWritableClock(FieldMapping $field): bool
    {
        return $this->touchTrigger !== null && $field->generated === ClassMetadata::GENERATED_ALWAYS
            && !$field->notInsertable && !$field->notUpdatable;
    }

    public function declaration(AbstractPlatform $platform, FieldMapping $field): ?string
    {
        if (!in_array($field->type, [Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE], true)) {
            throw new \InvalidArgumentException('NativeTimestamp requires an ORM datetime with timezone field.');
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            // PostgreSQL already gives datetimetz native instant storage. Keep
            // its ordinary ORM null/default/comment declaration intact.
            return null;
        }
        $nullable = (bool)($field->nullable ?? false);
        $options = $field->options ?? [];
        $declaration = 'TIMESTAMP ' . ($nullable ? 'NULL' : 'NOT NULL');
        $default = $options['default'] ?? null;
        if ($default instanceof DefaultExpression) {
            $declaration .= ' DEFAULT ' . $default->toSQL($platform);
        } elseif ($default === $platform->getCurrentTimestampSQL()) {
            $declaration .= ' DEFAULT ' . $platform->getCurrentTimestampSQL();
        } elseif ($default !== null) {
            if (!is_scalar($default)) {
                throw new \InvalidArgumentException('Native timestamp default must be an SQL expression or a scalar value.');
            }
            $declaration .= ' DEFAULT ' . $platform->quoteStringLiteral((string)$default);
        } elseif ($nullable) {
            $declaration .= ' DEFAULT NULL';
        }
        if ($this->touchTrigger !== null) {
            $declaration .= ' ON UPDATE ' . $platform->getCurrentTimestampSQL();
        }
        if (($options['comment'] ?? '') !== '') {
            $declaration .= ' ' . $platform->getInlineColumnCommentSQL($options['comment']);
        }
        return $declaration;
    }

    /** Updating another value touches the instant; an explicit new instant remains accepted. */
    public function touchBody(AbstractPlatform $platform, string $column): string
    {
        $quoted = $platform->quoteIdentifier($column);
        return 'BEGIN IF NEW IS DISTINCT FROM OLD AND NEW.' . $quoted . ' IS NOT DISTINCT FROM OLD.' . $quoted
            . ' THEN NEW.' . $quoted . ' = CURRENT_TIMESTAMP; END IF; RETURN NEW; END';
    }

    /** Current DDL owns these exact names; historical snapshots retain their older definitions. */
    public function touchStatementPrefixes(AbstractPlatform $platform): array
    {
        if (!$platform instanceof PostgreSQLPlatform || $this->touchTrigger === null) {
            return [];
        }
        $name = $platform->quoteIdentifier($this->touchTrigger);
        return ['CREATE OR REPLACE FUNCTION ' . $name . '() ', 'CREATE TRIGGER ' . $name . ' '];
    }

    public function touchSql(AbstractPlatform $platform, string $table, string $column): array
    {
        if (!$platform instanceof PostgreSQLPlatform || $this->touchTrigger === null) {
            return [];
        }
        $name = $platform->quoteIdentifier($this->touchTrigger);
        return [
            'CREATE OR REPLACE FUNCTION ' . $name . '() RETURNS trigger LANGUAGE plpgsql AS $$ ' . $this->touchBody($platform, $column) . ' $$',
            'CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON ' . $platform->quoteIdentifier($table) . ' FOR EACH ROW EXECUTE FUNCTION ' . $name . '()',
        ];
    }
}
