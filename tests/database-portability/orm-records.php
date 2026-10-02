<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Compare full ORM records with native adapter rows on an isolated installation. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/orm-records.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Dedicated test database required');
}
$em = \itsmng\Database\Orm::create($DB);
$records = new \itsmng\Database\Repository\RecordRepository($em);
$tables = 0;
$rows = 0;
$fields = 0;
foreach (\itsmng\Database\EntityRegistry::tables() as $table => $class) {
    $metadata = $em->getClassMetadata($class);
    $tables++;
    foreach ($DB->request(['FROM' => $table, 'ORDER' => 'id', 'LIMIT' => 25]) as $native) {
        $mapped = $records->find($table, 'id', (int)$native['id']);
        $rows++;
        if ($mapped === null || count($mapped) !== count($native)) {
            throw new RuntimeException('Missing mapped row/columns for ' . $table);
        }
        foreach ($native as $column => $value) {
            $fields++;
            $field = $metadata->getFieldName($column);
            $type = $metadata->hasField($field) ? $metadata->getTypeOfField($field) : 'integer';
            $actual = $mapped[$column];
            // JSON's textual formatting is not part of its semantic value.
            if ($type === 'json' && $value !== null) {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                $actual = json_decode($actual, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($value !== $actual) {
                throw new RuntimeException("ORM row differs at $table.$column (" . get_debug_type($value) . ' vs ' . get_debug_type($actual) . ')');
            }
        }
    }
    $em->clear();
}
echo $DB->getProvider() . ": ORM/native parity for $tables tables, $rows seeded rows, $fields field values.\n";
