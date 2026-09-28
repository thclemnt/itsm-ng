<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Exercise actual ORM INSERT/UPDATE/DELETE for every mapped table, not just metadata. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/orm-writes.php /path/to/test-config\n");
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
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$created = [];
$stamp = 'ORM all tables ' . bin2hex(random_bytes(4));
$DB->beginTransaction();
try {
    $create = static function (string $table) use (&$create, &$created, $DB, $stamp): int {
        if (isset($created[$table])) {
            return $created[$table];
        }
        $em = Orm::create($DB);
        $metadata = $em->getClassMetadata(EntityRegistry::TABLES[$table]);
        $values = [];
        foreach (ForeignKeys::RELATIONS[$table] ?? [] as $column => $parent) {
            $nullable = false;
            foreach ($metadata->associationMappings as $mapping) {
                if ($mapping->joinColumns[0]->name === $column) {
                    $nullable = $mapping->joinColumns[0]->nullable;
                }
            }
            $values[$column] = $nullable ? null : ($parent === 'glpi_entities' ? 0 : $create($parent));
        }
        if ($metadata->hasField('name')) {
            $values['name'] = $stamp;
        }
        if (!$metadata->usesIdGenerator()) {
            foreach ($metadata->identifier as $field) {
                $max = $em->createQueryBuilder()->select('MAX(r.' . $field . ')')->from($metadata->name, 'r')->getQuery()->getSingleScalarResult();
                $values[$metadata->getColumnName($field)] = (int)$max + 1;
            }
        }
        $created[$table] = (new RecordWriter($em))->insert($table, $values);
        if ($created[$table] <= 0) {
            throw new RuntimeException('Missing generated/assigned id for ' . $table);
        }
        $em->clear();
        return $created[$table];
    };
    foreach (EntityRegistry::TABLES as $table => $class) {
        $create($table);
    }
    $updates = 0;
    foreach ($created as $table => $id) {
        $em = Orm::create($DB);
        $record = (new RecordRepository($em))->find($table, 'id', $id);
        if ($record === null) {
            throw new RuntimeException('ORM inserted row is unreadable: ' . $table);
        }
        $metadata = $em->getClassMetadata(EntityRegistry::TABLES[$table]);
        // Update a stored non-identifier field on each table, using real type conversion.
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($field, $metadata->identifier, true) || $field === 'id' || ($mapping->notUpdatable ?? false)) {
                continue;
            }
            $value = match ($mapping->type) {
                'boolean' => !(bool)$record[$mapping->columnName],
                'smallint', 'integer' => 7,
                'bigint', 'decimal' => '7', 'float' => 7.5,
                'date' => '2025-02-03', 'datetime', 'datetimetz' => '2025-02-03 12:34:56',
                'itsm_clock_time' => '24:00:00', 'json' => ['orm' => true],
                default => substr('ORM update', 0, $mapping->length ?? 10),
            };
            (new RecordWriter($em))->update($table, $id, [$mapping->columnName => $value]);
            $em->clear();
            $updated = (new RecordRepository($em))->find($table, 'id', $id);
            if ($updated[$mapping->columnName] == $record[$mapping->columnName]) {
                throw new RuntimeException('ORM update did not change ' . $table . '.' . $mapping->columnName);
            }
            $updates++;
            break;
        }
        $em->clear();
    }
    $em = Orm::create($DB);
    $writer = new RecordWriter($em);
    $locationClass = EntityRegistry::TABLES['glpi_locations'];
    $max = $em->createQueryBuilder()->select('MAX(l.id)')->from($locationClass, 'l')->getQuery()->getSingleScalarResult();
    $assigned = (int)$max + 100;
    $literal = "NULL O'Reilly C:\\new\\file %_ 日本語";
    if ($writer->insert('glpi_locations', ['id' => $assigned, 'name' => $literal]) !== $assigned) {
        throw new RuntimeException('Explicit import identifier was not preserved');
    }
    $generated = $writer->insert('glpi_locations', ['name' => 'Generated after import']);
    if ($generated <= 0 || $generated === $assigned) {
        throw new RuntimeException('Explicit import changed subsequent ID generation');
    }
    $em->clear();
    if ((new RecordRepository($em))->find('glpi_locations', 'id', $assigned)['name'] !== $literal) {
        throw new RuntimeException('Raw repository values must not be unescaped');
    }
    $writer->delete('glpi_locations', $assigned);
    $writer->delete('glpi_locations', $generated);
    $em->clear();

    // Reverse dependency order: every referenced parent was inserted before its child.
    foreach (array_reverse($created, true) as $table => $id) {
        $em = Orm::create($DB);
        (new RecordWriter($em))->delete($table, $id);
        $em->clear();
        if ((new RecordRepository($em))->find($table, 'id', $id) !== null) {
            throw new RuntimeException('ORM deletion failed for ' . $table);
        }
    }
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ': ORM insert/read/delete for ' . count($created) . " tables; $updates table updates passed.\n";
