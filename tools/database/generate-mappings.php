<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Development scaffold. Runtime persistence reads attributes, never the SQL dump. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('GLPI_ROOT', dirname(__DIR__, 2));
$schema = (new \itsmng\Database\BaselineSchema())->build(new \Doctrine\DBAL\Platforms\MySQLPlatform());
$inflector = \Doctrine\Inflector\InflectorFactory::create()->build();
$legacyNames = [];
foreach (glob(GLPI_ROOT . '/inc/*.class.php') as $file) {
    if (preg_match('/^\s*(?:(?:abstract|final)\s+)?class\s+(\w+)/m', file_get_contents($file), $match)) {
        $legacyNames[strtolower(str_replace('_', '', $match[1]))] = str_replace('_', '', $match[1]);
    }
}
$names = [];
foreach (glob(GLPI_ROOT . '/src/Database/Entity/*.php') as $file) {
    if (preg_match("/ORM\\\\Table\(name: '([^']+)'\)/", file_get_contents($file), $match)) {
        $names[$match[1]] = basename($file, '.php');
    }
}
foreach ($schema->getTables() as $table) {
    $name = $table->getName();
    if (!isset($names[$name])) {
        $candidate = implode('', array_map(fn ($part) => ucfirst($inflector->singularize($part)), explode('_', substr($name, 5))));
        $names[$name] = $legacyNames[strtolower($candidate)] ?? $candidate;
    }
}
if (count(array_unique($names)) !== count($names)) {
    throw new RuntimeException('Entity class name collision');
}
ksort($names);
$export = static function ($value) use (&$export): string {
    if (!is_array($value)) {
        return var_export($value, true);
    }
    $parts = [];
    foreach ($value as $key => $entry) {
        $parts[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . $export($entry);
    }
    return '[' . implode(', ', $parts) . ']';
};
$registry = "<?php\n\n// SPDX-License-Identifier: GPL-2.0-or-later\n\nnamespace itsmng\\Database;\n\n/** Explicit core table registry; plugins must register their own mapped records. */\nfinal class EntityRegistry\n{\n    public const TABLES = [\n";
foreach ($names as $tableName => $class) {
    $table = $schema->getTable($tableName);
    $keys = array_map(fn ($key) => trim($key, '`'), $table->getPrimaryKey()->getColumns());
    $registry .= "        '$tableName' => Entity\\$class::class,\n";
    $code = "<?php\n\n// SPDX-License-Identifier: GPL-2.0-or-later\n\nnamespace itsmng\\Database\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity]\n#[ORM\\Table(name: '$tableName')]\n";
    // ORM identity follows the database primary key, including the dashboard's composite key.
    foreach ($table->getIndexes() as $index) {
        if (!$index->isPrimary() && $index->isUnique()) {
            $columns = array_map(fn ($c) => trim($c, '`'), $index->getColumns());
            $code .= '#[ORM\\UniqueConstraint(name: ' . var_export(substr($tableName, 5) . '_' . $index->getName(), true) . ', columns: ' . $export($columns) . ")]\n";
        }
    }
    $code .= "class $class\n{\n";
    // Keep associations together, independently of DBAL's schema column ordering.
    $columns = $table->getColumns();
    $relations = \itsmng\Database\ForeignKeys::RELATIONS[$tableName] ?? [];
    uasort($columns, static fn ($left, $right) => isset($relations[$right->getName()]) <=> isset($relations[$left->getName()]));
    foreach ($columns as $column) {
        $name = $column->getName();
        $type = \Doctrine\DBAL\Types\Type::lookupName($column->getType());
        $nullable = !$column->getNotnull();
        $default = $column->getDefault();
        $target = \itsmng\Database\ForeignKeys::RELATIONS[$tableName][$name] ?? null;
        if ($target !== null) {
            $property = preg_replace('/_id$/', '', $name);
            $target = $names[$target];
            $code .= "    #[ORM\\ManyToOne(targetEntity: $target::class)]\n    #[ORM\\JoinColumn(name: '$name', referencedColumnName: 'id', nullable: " . ($nullable ? 'true' : 'false') . ", onDelete: 'RESTRICT')]\n    public ?$target \$$property = null;\n\n";
            continue;
        }
        if (\itsmng\Database\BooleanColumns::contains($tableName, $name)) {
            $type = 'boolean';
        }
        if ($type === 'time') {
            $type = 'itsm_clock_time';
        }
        $phpType = match ($type) {
            'integer', 'smallint' => 'int', 'bigint', 'decimal' => 'string', 'float' => 'float', 'boolean' => 'bool',
            'date', 'datetime', 'datetimetz', 'time' => '\\DateTimeInterface', 'json' => 'array', default => 'string',
        };
        if (in_array($name, $keys, true)) {
            $code .= "    #[ORM\\Id]\n";
            if ($column->getAutoincrement()) {
                $code .= "    #[ORM\\GeneratedValue(strategy: 'IDENTITY')]\n";
            }
        }
        $args = "name: '`$name`', type: '$type'";
        if ($column->getLength() !== null) {
            $args .= ', length: ' . $column->getLength();
        }
        if ($type === 'decimal') {
            $args .= ', precision: ' . $column->getPrecision() . ', scale: ' . $column->getScale();
        }
        $args .= ', nullable: ' . ($nullable ? 'true' : 'false');
        $options = [];
        if ($column->getUnsigned()) {
            $options['unsigned'] = true;
        }
        if ($column->getFixed()) {
            $options['fixed'] = true;
        }
        if ($default !== null) {
            $options['default'] = $type === 'boolean' ? (bool)(int)$default : $default;
        }
        if ($options) {
            $args .= ', options: ' . $export($options);
        }
        if (($nullable && $default === null) || $column->getAutoincrement() || str_starts_with($phpType, '\\')) {
            $value = 'null';
        } else {
            $value = var_export(match ($phpType) {
                'int' => (int)$default, 'float' => (float)$default, 'bool' => (bool)(int)$default,
                'array' => $default === null ? [] : json_decode($default, true, flags: JSON_THROW_ON_ERROR), default => (string)$default,
            }, true);
        }
        if ($nullable || $column->getAutoincrement() || str_starts_with($phpType, '\\')) {
            $phpType = '?' . $phpType;
        }
        if ($column->getAutoincrement() && !in_array($name, $keys, true)) {
            // Generated non-identifier values (dashboard id) are returned after INSERT.
            $args .= ", insertable: false, updatable: false, generated: 'INSERT'";
        }
        $code .= "    #[ORM\\Column($args)]\n    public $phpType \$$name = $value;\n\n";
    }
    $code = preg_replace('/options: \[\s*(.*?)\s*,?\s*\]/', 'options: [$1]', $code);
    file_put_contents(GLPI_ROOT . '/src/Database/Entity/' . $class . '.php', rtrim($code) . "\n}\n");
}
file_put_contents(GLPI_ROOT . '/src/Database/EntityRegistry.php', $registry . "    ];\n}\n");
echo count($names) . " explicit entity mappings generated. Review before enabling persistence.\n";
