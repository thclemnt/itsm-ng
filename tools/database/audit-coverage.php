<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Read-only inventory; candidates are NOT authorization to infer/install constraints. */
define('GLPI_ROOT', dirname(__DIR__, 2));
require GLPI_ROOT . '/vendor/autoload.php';
require GLPI_ROOT . '/inc/relation.constant.php';
$schema = (new \itsmng\Database\BaselineSchema())->build(new \Doctrine\DBAL\Platforms\MySQLPlatform());
$declared = [];
$polymorphic = [];
$invalid = [];
foreach ($RELATION as $target => $children) {
    foreach ($children as $child => $columns) {
        $child = ltrim($child, '_');
        $columns = (array)$columns;
        if (in_array('itemtype', $columns, true)) {
            $polymorphic[$child . '.items_id']['discriminator'] = 'itemtype';
            $polymorphic[$child . '.items_id']['declared_targets'][] = $target;
            continue;
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !$schema->hasTable($child) || !$schema->hasTable($target) || !$schema->getTable($child)->hasColumn($column)) {
                $invalid[] = [$child, $column, $target];
                continue;
            }
            $declared[$child . '.' . $column][] = $target;
        }
    }
}
$relationships = [];
foreach ($schema->getTables() as $table) {
    foreach ($table->getColumns() as $column) {
        $name = $column->getName();
        $key = $table->getName() . '.' . $name;
        if (!isset($declared[$key]) && !preg_match('/(?:_id(?:_[a-z0-9_]+)?|Id)$/D', $name)) {
            continue;
        }
        $targets = array_values(array_unique($declared[$key] ?? []));
        $discriminator = preg_replace('/items_id/', 'itemtype', $name);
        if ($discriminator !== $name && $table->hasColumn($discriminator)) {
            $polymorphic[$key]['discriminator'] = $discriminator;
        }
        $enforced = \itsmng\Database\ForeignKeys::relations()[$table->getName()][$name] ?? null;
        $typed = \itsmng\Database\EntityRegistry::discriminatedReferences($table->getName())[$name] ?? null;
        foreach ($typed['selections'] ?? [] as $selection) {
            if ((\itsmng\Database\ForeignKeys::relations()[$table->getName()][$selection['column']] ?? null) !== $selection['target']) {
                throw new LogicException('Discriminated recipient is missing its owning association');
            }
        }
        $relationships[$key] = [
            'status' => $enforced ? 'enforced' : ($typed ? 'discriminated' : (isset($polymorphic[$key]) ? 'polymorphic' : (count($targets) > 1 ? 'ambiguous' : 'pending'))),
            'discriminated_references' => $typed,
            'declared_targets' => $targets,
            'enforced_target' => $enforced,
            'nullable' => !$column->getNotnull(),
            'default' => $column->getDefault(),
        ];
    }
}
ksort($relationships);
require __DIR__ . '/SqlCallInventory.php';
$sqlCalls = SqlCallInventory::discover(GLPI_ROOT);
$callCategories = array_count_values(array_column($sqlCalls, 'category'));
ksort($callCategories);
$legacyCalls = array_values(array_filter($sqlCalls, static fn (array $call): bool =>
    in_array($call['category'], ['legacy_adapter', 'legacy_dynamic', 'direct_driver'], true)));
$tables = array_map(fn ($table) => $table->getName(), $schema->getTables());
$output = [
    'summary' => [
        'tables' => count($tables),
        'mapped_tables' => count(\itsmng\Database\EntityRegistry::tables()),
        'orm_lifecycle_write_tables' => count(\itsmng\Database\EntityRegistry::tables()),
        'relationship_candidates' => count($relationships),
        'relationship_statuses' => array_count_values(array_column($relationships, 'status')),
        'legacy_call_sites' => count($legacyCalls),
        'sql_call_categories' => $callCategories,
    ],
    'unmapped_tables' => array_values(array_diff($tables, array_keys(\itsmng\Database\EntityRegistry::tables()))),
    'pending_lifecycle_write_tables' => array_values(array_diff($tables, array_keys(\itsmng\Database\EntityRegistry::tables()))),
    'relationships' => $relationships,
    'polymorphic' => $polymorphic,
    'invalid_legacy_declarations' => $invalid,
    'legacy_call_sites' => array_map(static fn (array $call): string => $call['path'] . ':' . $call['line'], $legacyCalls),
    'sql_calls' => $sqlCalls,
    'limits' => 'Static inventory, not completion proof. Owned driver boundaries retain exact native call locations and are established only by the actual same-source Doctrine Driver Connection declaration, PDO-typed property and concrete query/prepare method. This does not prove that driver behavior or application persistence is correct. Method candidates include alternate DB receivers, Doctrine and model CRUD; inspect them semantically. Aliased class/function imports, callbacks, generated code, dynamic receivers and plugin code outside the scanned roots require further discovery. Serialized references and custom relationship discriminators require semantic review. Call counts now use PHP tokens, not historical regex-matched lines.',
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
