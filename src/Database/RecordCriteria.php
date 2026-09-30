<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

/** Compile structured model criteria into typed DQL. No SQL text is rewritten. */
final class RecordCriteria
{
    private int $parameter = 0;

    public function __construct(private QueryBuilder $query, private ClassMetadata $metadata, private bool $legacyValues = true)
    {
    }

    public function where(array $criteria, string $junction = 'AND'): string
    {
        $parts = [];
        foreach ($criteria as $column => $value) {
            if (is_int($column) || in_array($column, ['AND', 'OR', 'NOT'], true)) {
                if (!is_array($value)) {
                    throw new UnsupportedCriteria('Raw predicates require a mapped query.');
                }
                $expression = $this->where($value, $column === 'OR' ? 'OR' : 'AND');
                $parts[] = ($column === 'NOT' ? 'NOT ' : '') . '(' . $expression . ')';
                continue;
            }
            [$expression, $type, $optional, $scope] = $this->field($column);
            $isEmpty = $scope ? ContentAudienceScopes::isUnrestricted(...) : OptionalReferences::isEmptySelection(...);
            if ($type === Types::JSON && $value !== null) {
                throw new UnsupportedCriteria('JSON comparisons require a mapped platform-aware query.');
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
                throw new UnsupportedCriteria('Regular expressions require a mapped query.');
            }
            if ($optional && $this->legacyValues && in_array($operator, ['=', '!=', '<>'], true) && $isEmpty($value)) {
                $parts[] = $expression . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }
            if ($operator === 'IN') {
                if (!is_array($value)) {
                    throw new UnsupportedCriteria('Subqueries require a mapped query.');
                }
                if (!$value) {
                    throw new \RuntimeException('Empty IN are not allowed');
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
                throw new UnsupportedCriteria('Raw ordering requires a mapped query.');
            }
            foreach (explode(',', $clause) as $part) {
                if (!preg_match('/^\s*([a-zA-Z0-9_.`]+)(?:\s+(ASC|DESC))?\s*$/iD', $part, $match)) {
                    throw new UnsupportedCriteria('Invalid mapped ordering.');
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
        if (str_contains($column, '.')) {
            [$table, $column] = explode('.', $column, 2);
            if ($table !== $this->metadata->getTableName()) {
                throw new UnsupportedCriteria('Cross-table criteria require a mapped join.');
            }
        }
        foreach ($this->metadata->associationMappings as $field => $mapping) {
            if ($mapping->joinColumns[0]->name === $column) {
                $scope = isset(ContentAudienceScopes::RELATIONS[$this->metadata->getTableName()][$column]);
                return ['IDENTITY(r.' . $field . ')', Types::INTEGER, $scope || isset(OptionalReferences::RELATIONS[$this->metadata->getTableName()][$column]), $scope];
            }
        }
        $field = $this->metadata->getFieldName($column);
        if (!$this->metadata->hasField($field)) {
            throw new UnsupportedCriteria('Unmapped column in record criteria: ' . $column);
        }
        return ['r.' . $field, $this->metadata->getTypeOfField($field), false, false];
    }

    private function value(mixed $value, string $type): string
    {
        if ($value instanceof \DateTimeInterface && in_array($type, [Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE], true)) {
            $parameter = 'p' . ++$this->parameter;
            $this->query->setParameter($parameter, \DateTime::createFromInterface($value), $type);
            return ':' . $parameter;
        }
        if (is_object($value) || is_array($value)) {
            throw new UnsupportedCriteria('Expressions and subqueries require mapped queries.');
        }
        if ($this->legacyValues) {
            $value = LegacyValues::decode($value);
        }
        if ($value !== null) {
            $value = match ($type) {
                Types::BOOLEAN => (bool)(int)$value,
                Types::INTEGER, Types::SMALLINT => (int)$value,
                Types::FLOAT => (float)$value,
                Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE => new \DateTime((string)$value),
                default => (string)$value,
            };
        }
        $parameter = 'p' . ++$this->parameter;
        $this->query->setParameter($parameter, $value, $type);
        return ':' . $parameter;
    }
}
