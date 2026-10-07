<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use CommonDBTM;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Plugin;

use function getItemForItemtype;
use function getItemTypeForTable;
use function isPluginItemType;

/** Identifier reads for an installed plugin's own model and physical schema. */
final class PluginRecordSelection
{
    private Table $schema;
    public readonly CommonDBTM $model;

    public function __construct(private Connection $connection, private string $table)
    {
        $this->model = self::model($table);
        $this->schema = $connection->createSchemaManager()->introspectTable($table);
    }

    public static function model(string $table): CommonDBTM
    {
        if (isset(EntityRegistry::tables()[$table]) || !preg_match('/^glpi_plugin_[a-z0-9_]+$/D', $table)) {
            throw new InvalidArgumentException('Plugin selection requires an unmapped plugin table.');
        }
        $model = getItemForItemtype(getItemTypeForTable($table));
        $plugin = $model instanceof CommonDBTM ? isPluginItemType($model->getType()) : false;
        if (!$plugin || $model->getTable() !== $table
            || !str_starts_with($table, 'glpi_plugin_' . strtolower($plugin['plugin']) . '_')
            || !in_array(strtolower($plugin['plugin']), array_map('strtolower', Plugin::getPlugins()), true)) {
            throw new InvalidArgumentException('Plugin selection requires its actual active plugin model and table.');
        }
        return $model;
    }

    /** A declared relationship contains integer references and an optional text discriminator. */
    public function referenceIdentifiers(string $index, array $criteria, ?int $limit = null): array
    {
        foreach ($criteria as $column => $value) {
            $type = $this->column($column, $column !== 'itemtype');
            if ($column === 'itemtype' && !in_array($type, [Types::STRING, Types::ASCII_STRING, Types::TEXT], true)) {
                throw new InvalidArgumentException('Plugin relationship discriminators require text columns.');
            }
        }
        return $this->identifiers($index, $criteria, $limit);
    }

    /** Only literal equality/entity scopes are accepted, never expressions or joins. */
    public function identifiers(string $index, array $criteria, ?int $limit = null): array
    {
        $this->column($index, true);
        $query = $this->connection->createQueryBuilder()
            ->select($this->connection->quoteIdentifier($index))
            ->from($this->connection->quoteIdentifier($this->table));
        $parameter = 0;
        $where = function (array $criteria, string $operator = 'AND') use (&$where, &$parameter, $query): string {
            $parts = [];
            foreach ($criteria as $field => $value) {
                if (is_int($field)) {
                    if (!is_array($value)) {
                        throw new InvalidArgumentException('Plugin scopes require structured literal criteria.');
                    }
                    $parts[] = $where($value);
                    continue;
                }
                if (in_array($field, ['AND', 'OR', 'NOT'], true)) {
                    if (!is_array($value)) {
                        throw new InvalidArgumentException('Plugin scopes require structured literal criteria.');
                    }
                    $parts[] = ($field === 'NOT' ? 'NOT ' : '') . $where($value, $field === 'OR' ? 'OR' : 'AND');
                    continue;
                }
                $prefix = $this->table . '.';
                $field = str_starts_with($field, $prefix) ? substr($field, strlen($prefix)) : $field;
                $type = $this->column($field);
                $column = $this->connection->quoteIdentifier($field);
                if ($value === null) {
                    $parts[] = $column . ' IS NULL';
                    continue;
                }
                $name = 'plugin_value_' . $parameter++;
                if (is_array($value)) {
                    if (!in_array($type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                        throw new InvalidArgumentException('Plugin entity scopes require integer lists.');
                    }
                    foreach ($value as $entry) {
                        if (filter_var($entry, FILTER_VALIDATE_INT) === false) {
                            throw new InvalidArgumentException('Plugin entity scopes require integer lists.');
                        }
                    }
                    $parts[] = $value ? $column . ' IN (:' . $name . ')' : '1 = 0';
                    if ($value) {
                        $query
                            ->setParameter($name, array_values(array_map('intval', $value)), ArrayParameterType::INTEGER);
                    }
                } else {
                    if (!is_scalar($value)) {
                        throw new InvalidArgumentException('Plugin selections require literal scalar values.');
                    }
                    $parts[] = $column . ' = :' . $name;
                    $query
                        ->setParameter($name, $type === Types::BOOLEAN ? (bool)$value : $value, $type);
                }
            }
            return '(' . ($parts ? implode(' ' . $operator . ' ', $parts) : ($operator === 'OR' ? '1 = 0' : '1 = 1')) . ')';
        };
        return $query
            ->where($where($criteria))
            ->orderBy($this->connection->quoteIdentifier($index))
            ->setMaxResults($limit)->executeQuery()->fetchFirstColumn();
    }

    private function column(string $name, bool $identifier = false): string
    {
        $integers = [Types::SMALLINT, Types::INTEGER, Types::BIGINT];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || !$this->schema->hasColumn($name)) {
            throw new InvalidArgumentException('Plugin selection requires existing physical columns.');
        }
        $type = Type::lookupName($this->schema->getColumn($name)->getType());
        if (!in_array($type, $identifier ? $integers : [...$integers, Types::BOOLEAN, Types::STRING, Types::ASCII_STRING, Types::TEXT], true)) {
            throw new InvalidArgumentException('Plugin selection requires scalar columns and integer identifiers.');
        }
        return $type;
    }
}
