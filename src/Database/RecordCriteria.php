<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use BackedEnum;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Mapping\ReferenceKind;
use RuntimeException;

/** Compile structured model criteria into typed DQL. No SQL text is rewritten. */
final class RecordCriteria
{
    private int $parameter = 0;
    /** Only diagnostics issued by this compiler can decline matching admission. */
    private ?UnsupportedCriteria $rejection = null;
    /** Query-local aliases for explicitly mapped joins, never schema declarations. */
    private array $joinedMetadata = [];

    public function __construct(private QueryBuilder $query, private ClassMetadata $metadata, private bool $legacyValues = true)
    {
    }

    /** Apply the existing compiler once, without treating a language rejection as a failed query. */
    public function applyMatching(array $criteria, array|string $order): ?UnsupportedCriteria
    {
        $this->rejection = null;
        try {
            $this->query->where($this->where($criteria));
            $this->order($order);
            return null;
        } catch (UnsupportedCriteria $error) {
            if ($error !== $this->rejection) {
                throw $error;
            }
            return $error;
        } finally {
            $this->rejection = null;
        }
    }

    private function reject(string $message): never
    {
        $this->rejection = new UnsupportedCriteria($message);
        throw $this->rejection;
    }

    public function withJoinedMetadata(ClassMetadata $metadata, string $alias, ?string $qualifier = null): self
    {
        $qualifier ??= $metadata->getTableName();
        if (!in_array($alias, $this->query->getAllAliases(), true)
            || $metadata->getTableName() === $this->metadata->getTableName()
            || isset($this->joinedMetadata[$qualifier])) {
            $this->reject('Joined criteria require a unique mapped query alias.');
        }
        $this->joinedMetadata[$qualifier] = [$metadata, $alias];
        return $this;
    }

    public function where(array $criteria, string $junction = 'AND'): string
    {
        $parts = [];
        foreach ($criteria as $column => $value) {
            if (is_int($column) || in_array($column, ['AND', 'OR', 'NOT'], true)) {
                if (is_bool($value)) {
                    $expression = $value ? '1 = 1' : '1 = 0';
                    $parts[] = ($column === 'NOT' ? 'NOT ' : '') . '(' . $expression . ')';
                    continue;
                }
                if (!is_array($value)) {
                    $this->reject('Raw predicates require a mapped query.');
                }
                $expression = $this->where($value, $column === 'OR' ? 'OR' : 'AND');
                $parts[] = ($column === 'NOT' ? 'NOT ' : '') . '(' . $expression . ')';
                continue;
            }
            [$expression, $type, $optional, $scope] = $this->field($column);
            $isEmpty = $scope ? ReferenceValues::isUnrestricted(...) : ReferenceValues::isEmptySelection(...);
            if ($type === Types::JSON && $value !== null) {
                $this->reject('JSON comparisons require a mapped platform-aware query.');
            }
            if ($value === null || ($this->legacyValues && is_string($value) && strtolower($value) === 'null')) {
                $parts[] = $expression . ' IS NULL';
                continue;
            }
            $operator = '=';
            if (is_array($value)) {
                if (count($value) === 2 && is_string($value[0] ?? null) && in_array($value[0], ['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', '&', '|', 'REGEXP', 'NOT REGEX'], true)) {
                    [$operator, $value] = $value;
                } else {
                    $operator = 'IN';
                }
            }
            if ($operator === 'REGEXP' || $operator === 'NOT REGEX') {
                $this->reject('Regular expressions require a mapped query.');
            }
            if ($optional && $this->legacyValues && in_array($operator, ['=', '!=', '<>'], true) && $isEmpty($value)) {
                $parts[] = $expression . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }
            if ($operator === 'IN') {
                if (!is_array($value)) {
                    $this->reject('Subqueries require a mapped query.');
                }
                if (!$value) {
                    throw new RuntimeException('Empty IN are not allowed');
                }
                $includeEmpty = $optional && $this->legacyValues && (bool)array_filter($value, $isEmpty);
                if ($includeEmpty) {
                    $value = array_filter($value, static fn ($entry) => !$isEmpty($entry));
                }
                $parameters = array_map(fn ($entry) => $this->value($entry, $type), array_values($value));
                $list = $parameters ? $expression . ' IN (' . implode(', ', $parameters) . ')' : '';
                $parts[] = $includeEmpty ? '(' . $expression . ' IS NULL' . ($list ? ' OR ' . $list : '') . ')' : $list;
            } elseif ($operator === '&' || $operator === '|') {
                $parts[] = ($operator === '&' ? 'BIT_AND' : 'BIT_OR') . '(' . $expression . ', ' . $this->value($value, Types::INTEGER) . ') <> 0';
            } elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                $parameter = $this->value($value, Types::STRING);
                $temporal = match ($type) {
                    Types::DATE_MUTABLE, Types::DATE_IMMUTABLE => 'date',
                    Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE => 'datetime',
                    default => null,
                };
                if ($temporal !== null) {
                    // A timestamp's native PostgreSQL text includes an offset;
                    // application filters use calendar text in the connection's
                    // timezone, matching MySQL's existing second precision.
                    $expression = 'TEMPORAL_TEXT(' . $expression . ", '" . $temporal . "')";
                }
                if (in_array($type, [Types::INTEGER, Types::SMALLINT, Types::BIGINT, Types::FLOAT, Types::DECIMAL], true)) {
                    // PostgreSQL does not implicitly turn IDs/numbers into text for LIKE.
                    // Preserve NULL rather than CONCAT's provider-dependent NULL handling.
                    $expression = 'LOWER(CASE WHEN ' . $expression . " IS NULL THEN NULL ELSE CONCAT('', " . $expression . ') END)';
                }
                if ($this->query->getEntityManager()->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $parts[] = 'LOWER(' . $expression . ') ' . $operator . ' LOWER(' . $parameter . ')';
                } else {
                    $parts[] = $expression . ' ' . $operator . ' ' . $parameter;
                }
            } else {
                $parts[] = $expression . ' ' . ($operator === '!=' ? '<>' : $operator) . ' ' . $this->value($value, $type);
            }
        }
        return $parts ? implode(' ' . $junction . ' ', $parts) : ($junction === 'OR' ? '1 = 0' : '1 = 1');
    }

    public function order(array|string $order): void
    {
        foreach ((array)$order as $clause) {
            if (!is_string($clause)) {
                $this->reject('Raw ordering requires a mapped query.');
            }
            foreach (explode(',', $clause) as $part) {
                if (!preg_match('/^\s*([a-zA-Z0-9_.`]+)(?:\s+(ASC|DESC))?\s*$/iD', $part, $match)) {
                    $this->reject('Invalid mapped ordering.');
                }
                [$field] = $this->field($match[1]);
                $this->query->addOrderBy($field, strtoupper($match[2] ?? 'ASC'));
            }
        }
    }

    public function column(string $column): string
    {
        return $this->field($column)[0];
    }

    /** @return array{string, string, bool, bool} Expression, type, nullable selection and unrestricted-scope policy. */
    private function field(string $column): array
    {
        $column = str_replace('`', '', $column);
        $metadata = $this->metadata;
        $alias = 'r';
        if (str_contains($column, '.')) {
            [$table, $column] = explode('.', $column, 2);
            if ($table !== $metadata->getTableName()) {
                if (!isset($this->joinedMetadata[$table])) {
                    $this->reject('Cross-table criteria require a mapped join.');
                }
                [$metadata, $alias] = $this->joinedMetadata[$table];
            }
        } elseif ($this->joinedMetadata && !in_array($column, EntityRegistry::columnNames($metadata->getTableName()), true)) {
            // Legacy joined queries also accept unqualified columns belonging
            // only to a joined table, such as a group's entity scope.
            $matches = array_filter($this->joinedMetadata, static fn (array $join): bool =>
                in_array($column, EntityRegistry::columnNames($join[0]->getTableName()), true));
            if (count($matches) > 1) {
                $this->reject('Ambiguous unqualified joined column: ' . $column);
            }
            if ($matches) {
                [$metadata, $alias] = reset($matches);
            }
        }
        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            if ($mapping->joinColumns[0]->name === $column) {
                if ($this->legacyValues && EntityRegistry::hasPolicy($metadata->getTableName(), $column, ReferenceKind::RootParent)) {
                    return ['COALESCE(IDENTITY(' . $alias . '.' . $field . '), -1)', Types::INTEGER, false, false];
                }
                if ($this->legacyValues && $metadata->getTableName() === 'glpi_entities' && isset(EntityConfigurationReferences::fields()[$column])) {
                    return [EntityConfigurationReferences::selection($column, $alias), Types::INTEGER, false, false];
                }
                $scope = EntityRegistry::hasPolicy($metadata->getTableName(), $column, ReferenceKind::Audience)
                    || EntityRegistry::hasPolicy($metadata->getTableName(), $column, ReferenceKind::GlobalScope);
                return ['IDENTITY(' . $alias . '.' . $field . ')', Types::INTEGER, $scope || EntityRegistry::hasPolicy($metadata->getTableName(), $column, ReferenceKind::EmptySelection), $scope];
            }
        }
        $field = $metadata->getFieldName($column);
        if (!$metadata->hasField($field)) {
            $this->reject('Unmapped column in record criteria: ' . $column);
        }
        return [$alias . '.' . $field, $metadata->getTypeOfField($field), false, false];
    }

    private function value(mixed $value, string $type): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof DateTimeInterface && in_array($type, [Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE], true)) {
            $parameter = 'p' . ++$this->parameter;
            $this->query->setParameter($parameter, DateTime::createFromInterface($value), $type);
            return ':' . $parameter;
        }
        if (is_object($value) || is_array($value)) {
            $this->reject('Expressions and subqueries require mapped queries.');
        }
        if ($this->legacyValues) {
            $value = LegacyValues::decode($value);
        }
        if ($value !== null) {
            $value = match ($type) {
                Types::BOOLEAN => (bool)(int)$value,
                Types::INTEGER, Types::SMALLINT => (int)$value,
                Types::FLOAT => (float)$value,
                Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE => new DateTime((string)$value),
                default => (string)$value,
            };
        }
        $parameter = 'p' . ++$this->parameter;
        $this->query->setParameter($parameter, $value, $type);
        return ':' . $parameter;
    }
}
