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
        $enforced = \itsmng\Database\ForeignKeys::RELATIONS[$table->getName()][$name] ?? null;
        $relationships[$key] = [
            'status' => $enforced ? 'enforced' : (isset($polymorphic[$key]) ? 'polymorphic' : (count($targets) > 1 ? 'ambiguous' : 'pending')),
            'declared_targets' => $targets,
            'enforced_target' => $enforced,
            'nullable' => !$column->getNotnull(),
            'default' => $column->getDefault(),
        ];
    }
}
ksort($relationships);
$legacyCalls = [];
foreach (['inc', 'src', 'front', 'ajax', 'install'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(GLPI_ROOT . '/' . $directory));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $index => $line) {
            if (preg_match('/\$DB->(?:request|query|insert|update|delete)\s*\(|\b(?:new\s+mysqli|mysqli_\w+\s*\(|pg_\w+\s*\()/', $line)) {
                $legacyCalls[] = substr($file->getPathname(), strlen(GLPI_ROOT) + 1) . ':' . ($index + 1);
            }
        }
    }
}
$tables = array_map(fn ($table) => $table->getName(), $schema->getTables());
$output = [
    'summary' => [
        'tables' => count($tables),
        'mapped_tables' => count(\itsmng\Database\EntityRegistry::TABLES),
        'orm_lifecycle_write_tables' => count(\itsmng\Database\MappedStorage::TABLES),
        'relationship_candidates' => count($relationships),
        'relationship_statuses' => array_count_values(array_column($relationships, 'status')),
        'legacy_call_sites' => count($legacyCalls),
    ],
    'unmapped_tables' => array_values(array_diff($tables, array_keys(\itsmng\Database\EntityRegistry::TABLES))),
    'pending_lifecycle_write_tables' => array_values(array_diff($tables, array_keys(\itsmng\Database\MappedStorage::TABLES))),
    'relationships' => $relationships,
    'polymorphic' => $polymorphic,
    'invalid_legacy_declarations' => $invalid,
    'legacy_call_sites' => $legacyCalls,
    'limits' => 'Static candidate inventory, not proof of complete relationship discovery. Serialized references, custom discriminators, alternate connection variables and dynamically constructed SQL require semantic review.',
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
