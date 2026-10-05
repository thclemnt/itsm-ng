<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\V220\IdentifierColumns;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\WideIdentifiers;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/legacy-to-orm.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$manager = $connection->createSchemaManager();
$migration = new References();
verify($migration->plan($connection)['complete'], 'Installer records master completion');
$migration->apply($connection);
verify($migration->plan($connection)['complete'], 'Completed rerun is a no-op');
$metadata = Orm::create($DB)->getMetadataFactory()->getAllMetadata();
foreach ($metadata as $mapping) {
    foreach ($mapping->fieldMappings as $field) {
        if (in_array($field->columnName, IdentifierColumns::history()['identifiers'][$mapping->getTableName()] ?? [], true)) {
            verify($field->type === 'bigint', 'ORM identity type: ' . $mapping->getTableName() . '.' . $field->columnName);
        }
    }
}

// Required orphan rejection must happen before widening or creating a journal.
$originalState = $connection->fetchOne('SELECT state FROM ' . \itsmng\Database\Migration\Ledger::TABLE . ' WHERE version = ?', [References::PHASE]);
$key = $manager->introspectTable('glpi_useremails')->getForeignKey('fk_useremails_users_id');
$connection->executeStatement($platform->getDropForeignKeySQL($key->getQuotedName($platform), 'glpi_useremails'));
$orphan = null;
try {
    $connection->delete(\itsmng\Database\Migration\Ledger::TABLE, ['version' => References::PHASE]);
    $connection->insert('glpi_useremails', ['users_id' => 4294967302, 'email' => 'master-orphan@example.invalid']);
    $orphan = $connection->fetchOne("SELECT id FROM glpi_useremails WHERE email = 'master-orphan@example.invalid'");
    try {
        $migration->apply($connection);
        throw new RuntimeException('Master accepted a required orphan');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Orphaned required reference'), 'Master rejects required orphan during preflight');
    }
    verify(!$connection->fetchOne('SELECT COUNT(*) FROM ' . \itsmng\Database\Migration\Ledger::TABLE . ' WHERE version = ?', [References::PHASE]), 'Failed preflight records no migration state');
} finally {
    if ($orphan !== null) {
        $connection->delete('glpi_useremails', ['id' => $orphan]);
    }
    $connection->delete(\itsmng\Database\Migration\Ledger::TABLE, ['version' => References::PHASE]);
    $connection->insert(\itsmng\Database\Migration\Ledger::TABLE, ['version' => References::PHASE, 'state' => $originalState]);
    $connection->executeStatement($platform->getCreateForeignKeySQL($key, 'glpi_useremails'));
}

// Model a populated partially converted schema, including generated-column dependencies.
$parent = 'port_master_parent';
$child = 'port_master_child';
$plugin = 'port_master_plugin';
$partial = 'port_master_partial';
$tables = [$parent, $child, $plugin, $partial];
foreach ($tables as $table) {
    verify(!$manager->tablesExist([$table]), 'Fixture must not exist: ' . $table);
}
try {
    $table = new Table($parent);
    $table->addColumn('id', 'integer', ['autoincrement' => true]);
    $table->addColumn('name', 'string', ['length' => 100]);
    $table->setPrimaryKey(['id']);
    $manager->createTable($table);
    $table = new Table($child);
    $table->addColumn('id', 'integer', ['autoincrement' => true]);
    $table->addColumn('parent_id', 'integer', ['notnull' => false]);
    $table->addColumn('parent_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED']);
    $comment = "Legacy projection O'Reilly 日本語";
    $declaration = 'INTEGER GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED';
    if ($platform instanceof PostgreSQLPlatform) {
        $declaration .= ' NOT NULL';
    } else {
        $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
    }
    $table->addColumn('legacy_id', 'integer', ['notnull' => $platform instanceof PostgreSQLPlatform, 'columnDefinition' => $declaration, 'comment' => $comment]);
    $table->setPrimaryKey(['id']);
    $table->addUniqueIndex(['parent_key'], 'port_master_unique');
    $table->addIndex(['legacy_id'], 'port_master_legacy');
    $table->addForeignKeyConstraint($parent, ['parent_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'RESTRICT'], 'port_master_parent_fk');
    $manager->createTable($table);
    $connection->executeStatement('ALTER TABLE ' . $child . ' ADD CONSTRAINT port_master_check CHECK (parent_key >= 0)');
    if ($platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
        // MariaDB exposes JSON validity as an inline column CHECK, which cannot
        // be dropped as a table constraint while generated identities are rebuilt.
        $connection->executeStatement('ALTER TABLE ' . $child . ' ADD payload JSON');
    }
    $table = new Table($plugin);
    $table->addColumn('id', 'integer');
    $table->addColumn('parent_id', 'integer');
    $table->setPrimaryKey(['id']);
    $table->addForeignKeyConstraint($parent, ['parent_id'], ['id'], ['onDelete' => 'RESTRICT'], 'port_master_plugin_fk');
    $manager->createTable($table);
    $table = new Table($partial);
    $table->addColumn('id', 'integer');
    $table->addColumn('users_id', 'bigint');
    $table->addColumn('user_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(users_id, 0)) STORED']);
    $table->setPrimaryKey(['id']);
    $table->addIndex(['users_id', 'user_key'], 'port_master_support');
    $table->addForeignKeyConstraint('glpi_users', ['users_id'], ['id'], ['onDelete' => 'RESTRICT'], 'port_master_support_fk');
    $manager->createTable($table);
    $connection->insert($partial, ['id' => 71, 'users_id' => 2]);
    $connection->insert($parent, ['id' => 41, 'name' => 'keep populated identity']);
    $connection->insert($child, ['id' => 51, 'parent_id' => 41]);
    $connection->insert($plugin, ['id' => 61, 'parent_id' => 41]);
    $wide = new WideIdentifiers([$parent => ['id'], $child => ['id', 'parent_id', 'legacy_id'], $partial => ['id']]);
    $plan = $wide->plan($connection);
    verify($plan !== [], 'Populated 32-bit schema requires widening');
    verify(Type::lookupName($manager->introspectTable($parent)->getColumn('id')->getType()) === 'integer', 'Planning leaves schema untouched');
    // Simulate process death after a DDL commit but before saving its checkpoint.
    WideIdentifiers::execute($connection, $plan[0]);
    WideIdentifiers::execute($connection, $plan[0]);
    $interrupted = 0;
    foreach ($plan as $offset => $operation) {
        WideIdentifiers::execute($connection, $operation);
        if ($operation['kind'] === 'add_column' && $operation['name'] === 'legacy_id') {
            $interrupted = $offset;
            break; // A PostgreSQL COMMENT has not run and CREATE has not been checkpointed.
        }
    }
    verify($interrupted > 0, 'Fixture interrupts after generated identity recreation');
    $connection->update(\itsmng\Database\Migration\Ledger::TABLE, ['state' => json_encode(['complete' => false, 'identifiers' => $plan, 'next' => $interrupted], JSON_THROW_ON_ERROR)], ['version' => References::PHASE]);
    $migration->apply($connection);
    verify($migration->plan($connection)['complete'], 'Master resumes interrupted journal and records completion');
    verify($manager->introspectTable($partial)->hasForeignKey('port_master_support_fk') && $manager->introspectTable($partial)->hasIndex('port_master_support'), 'FK supporting index preserved when only the child ID is widened');
    verify($wide->plan($connection) === [], 'Widening converges and reruns without DDL');
    verify($connection->fetchOne('SELECT name FROM ' . $parent . ' WHERE id = 41') === 'keep populated identity', 'Parent data preserved');
    verify((int)$connection->fetchOne('SELECT legacy_id FROM ' . $child . ' WHERE id = 51') === 41, 'Generated identity and indexes preserved');
    $restored = $manager->introspectTable($child)->getColumn('legacy_id');
    verify($restored->getComment() === $comment, 'Generated identity comment survives interrupted widening and replay');
    verify($restored->getNotnull() === ($platform instanceof PostgreSQLPlatform), 'Generated identity nullability survives interrupted widening');
    verify($manager->introspectTable($child)->getForeignKey('port_master_parent_fk')->onDelete() === 'CASCADE', 'Custom FK action preserved');
    if ($platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
        verify($connection->fetchOne('SELECT level FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?', [$child, 'payload']) === 'Column', 'Inline JSON validity CHECK survives generated-column widening');
        try {
            $connection->update($child, ['payload' => 'invalid JSON'], ['id' => 51]);
            throw new LogicException('Widening lost JSON validation');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
        }
    }
    verify(Type::lookupName($manager->introspectTable($plugin)->getColumn('parent_id')->getType()) === 'bigint', 'Incoming plugin FK reference widened');
    verify(Type::lookupName($manager->introspectTable($plugin)->getColumn('id')->getType()) === 'integer', 'Unrelated plugin identity untouched');
    $large = 4294967301;
    if ($platform instanceof PostgreSQLPlatform) {
        $sequence = $connection->fetchOne("SELECT pg_get_serial_sequence(?, 'id')", [$parent]);
        $connection->fetchOne('SELECT setval(?, ?, false)', [$sequence, $large]);
    } else {
        $connection->executeStatement('ALTER TABLE ' . $parent . ' AUTO_INCREMENT = ' . $large);
    }
    $connection->insert($parent, ['name' => 'above unsigned 32-bit limit']);
    $id = (int)$connection->fetchOne('SELECT id FROM ' . $parent . ' WHERE name = ?', ['above unsigned 32-bit limit']);
    verify($id === $large, 'Auto-increment / sequence generates a 64-bit ID');
    $connection->insert($child, ['parent_id' => $id]);
    verify((int)$connection->fetchOne('SELECT legacy_id FROM ' . $child . ' WHERE parent_id = ?', [$id]) === $id, 'Generated reference carries 64-bit ID');
    $connection->beginTransaction();
    try {
        try {
            $connection->insert($child, ['parent_id' => $id + 1]);
            throw new RuntimeException('FK allowed an orphan after widening');
        } catch (ForeignKeyConstraintViolationException $error) {
            verify(true, 'Restored FK rejects orphan');
        }
    } finally {
        $connection->rollBack();
    }
    $connection->beginTransaction();
    try {
        $storage = new MappedStorage($DB);
        $storage->insert('glpi_logs', ['id' => $large, 'itemtype' => 'User', 'items_id' => $large, 'old_value' => '64-bit audit record']);
        $row = (new RecordRepository(Orm::create($DB)))->find('glpi_logs', 'id', $large);
        verify($row['id'] === $large && $row['items_id'] === $large, 'ORM audit ID and polymorphic reference hydrate as native 64-bit integers');
    } finally {
        $connection->rollBack();
    }
    echo $DB->getProvider() . ": master completion/rerun, populated widening, DDL replay, generated keys, custom/plugin FKs, sequences and 64-bit ORM audit records passed.\n";
} finally {
    foreach (array_reverse($tables) as $table) {
        if ($manager->tablesExist([$table])) {
            $manager->dropTable($table);
        }
    }
}
