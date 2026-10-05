<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\WideIdentifiers;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\TransactionOwnership;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/identifier-foreign-key-ownership.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_') && !$DB->isSlave(), 'Dedicated primary fixture required');
$connection = $DB->getDoctrineConnection();
TransactionOwnership::assertManaged($connection);
verify($connection->getTransactionNestingLevel() === 0, 'Idle actual configured writer required');
$postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
$platform = $connection->getDatabasePlatform();
$manager = $connection->createSchemaManager();
$namespace = (string)$connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
$external = $postgres ? 'port_identifier_external_' . substr(hash('sha256', (string)$connection->getDatabase()), 0, 12)
    : (getenv('PORT_HISTORY_DB') ?: 'itsm_port_history');
verify($postgres || (str_starts_with($external, 'itsm_port_') && str_ends_with($external, '_history')
    && $external !== (string)$connection->getDatabase()), 'Only the explicitly configured existing history auxiliary may supply MySQL external ownership');
$quote = $platform->quoteSingleIdentifier(...);
$qualified = static fn (string $schema, string $table): string => $quote($schema) . '.' . $quote($table);
$names = ['port_identifier_fk_parent', 'port_identifier_fk_child', 'port_identifier_fk_external_link', 'port_identifier_fk_generated_link'];
foreach ($names as $name) {
    verify(!$manager->tablesExist([$name]), 'Do not adopt preexisting fixture tables');
}
$exists = (bool)$connection->fetchOne($postgres ? 'SELECT 1 FROM pg_namespace WHERE nspname=?' : 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$external]);
verify($postgres ? !$exists : $exists, 'PostgreSQL fixture schema must be absent; configured MySQL history auxiliary must already exist');
$auxiliaryFacts = static function () use ($connection, $postgres, $external, $qualified): array {
    if ($postgres) {
        return [];
    }
    $facts = [];
    foreach ($connection->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME", [$external]) as $table) {
        $name = $qualified($external, $table);
        $rows = array_map(serialize(...), $connection->fetchAllAssociative('SELECT * FROM ' . $name));
        sort($rows, SORT_STRING); // Keep duplicate native rows and exact PHP cell types.
        $facts[$table] = ['rows' => hash('sha256', serialize($rows)), 'definition' => $connection->fetchAssociative('SHOW CREATE TABLE ' . $name)];
    }
    return $facts;
};
$auxiliaryBefore = $auxiliaryFacts();
$ledger = $connection->fetchAllAssociative('SELECT version,state FROM ' . \itsmng\Database\Migration\Ledger::TABLE . ' ORDER BY version');
$created = [];
$namespaceCreated = false;
$primary = null;
$cleanup = [];
$create = static function (string $schema, string $name, callable $configure) use ($connection, $platform, $qualified, &$created): void {
    $table = new Table($qualified($schema, $name));
    if (!$platform instanceof PostgreSQLPlatform) {
        $table->addOption('engine', 'InnoDB');
    }
    $configure($table);
    $owned = false;
    foreach ($platform->getCreateTableSQL($table) as $sql) {
        $connection->executeStatement($sql);
        if (!$owned) {
            // Own the CREATE before any later index/comment statement can fail.
            $created[] = [$schema, $name];
            $owned = true;
        }
    }
};
$facts = static function () use ($connection, $postgres, $qualified, &$created): array {
    $facts = [];
    foreach ($created as [$schema, $name]) {
        $table = $qualified($schema, $name);
        $facts[$table]['rows'] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        if ($postgres) {
            $facts[$table]['columns'] = $connection->fetchAllAssociative('SELECT a.attname,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attgenerated,pg_get_expr(d.adbin,d.adrelid) AS expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum', [$table]);
            $facts[$table]['constraints'] = $connection->fetchAllAssociative('SELECT conname,convalidated,condeferrable,condeferred,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname', [$table]);
            $facts[$table]['indexes'] = $connection->fetchAllAssociative('SELECT pg_get_indexdef(indexrelid) AS definition FROM pg_index WHERE indrelid=to_regclass(?) ORDER BY indexrelid', [$table]);
        } else {
            $facts[$table]['definition'] = $connection->fetchAssociative('SHOW CREATE TABLE ' . $table);
        }
    }
    $facts['ledger'] = $connection->fetchAllAssociative('SELECT version,state FROM ' . \itsmng\Database\Migration\Ledger::TABLE . ' ORDER BY version');
    return $facts;
};
$refuse = static function (array $scope, string $table, string $constraint) use ($connection, $external, $facts): void {
    $before = $facts();
    try {
        (new WideIdentifiers($scope))->plan($connection);
        throw new LogicException('Affected external FK was adopted');
    } catch (RuntimeException $error) {
        verify(str_starts_with($error->getMessage(), 'Identifier adoption cannot change a foreign key outside its configured namespace: ')
            && str_contains($error->getMessage(), $external) && str_contains($error->getMessage(), $table)
            && str_contains($error->getMessage(), $constraint), 'Refusal identifies the actual qualified affected constraint');
    }
    verify($facts() === $before, 'Refused plan preserves all owned/external rows, native declarations and complete raw ledger');
};
$drop = static function (string $schema, string $name) use ($connection, $qualified, &$created): void {
    $connection->executeStatement('DROP TABLE ' . $qualified($schema, $name));
    $created = array_values(array_filter($created, static fn (array $entry): bool => $entry !== [$schema, $name]));
};
try {
    if ($postgres) {
        $connection->executeStatement('CREATE SCHEMA ' . $quote($external));
        $namespaceCreated = true;
    }
    foreach ([$names[0], 'port_identifier_fk_incoming', 'port_identifier_fk_projection_incoming'] as $name) {
        verify(!(bool)$connection->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$external, $name]), 'Do not adopt any existing external fixture table');
    }
    $parent = $names[0];
    $child = $names[1];
    $externalLink = $names[2];
    $generatedLink = $names[3];
    $create($namespace, $parent, static function (Table $table): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('tenant_id', 'bigint');
        $table->addColumn('projection_id', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (id) STORED']);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['tenant_id', 'id'], 'port_identifier_fk_parent_composite');
        $table->addUniqueIndex(['projection_id'], 'port_identifier_fk_parent_projection');
    });
    $create($namespace, $child, static function (Table $table) use ($namespace, $parent, $qualified): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('tenant_id', 'bigint');
        $table->addColumn('parent_id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint($qualified($namespace, $parent), ['tenant_id', 'parent_id'], ['tenant_id', 'id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'RESTRICT'], 'port_identifier_fk_same_name');
    });
    if ($postgres) {
        $connection->executeStatement('ALTER TABLE ' . $qualified($namespace, $child) . ' DROP CONSTRAINT ' . $quote('port_identifier_fk_same_name'));
        $connection->executeStatement('ALTER TABLE ' . $qualified($namespace, $child) . ' ADD CONSTRAINT ' . $quote('port_identifier_fk_same_name')
            . ' FOREIGN KEY (tenant_id,parent_id) REFERENCES ' . $qualified($namespace, $parent)
            . ' (tenant_id,id) MATCH FULL ON DELETE CASCADE ON UPDATE RESTRICT DEFERRABLE INITIALLY IMMEDIATE NOT VALID');
    }
    // Same unqualified parent name AND identifier exist in another namespace.
    $create($external, $parent, static function (Table $table): void {
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
    });
    $create($namespace, $externalLink, static function (Table $table) use ($external, $parent, $qualified): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('parent_id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint($qualified($external, $parent), ['parent_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'port_identifier_fk_external_target');
    });
    $connection->insert($qualified($namespace, $parent), ['id' => 41, 'tenant_id' => 7]);
    $connection->insert($qualified($namespace, $child), ['id' => 51, 'tenant_id' => 7, 'parent_id' => 41]);
    $connection->insert($qualified($external, $parent), ['id' => 41]);
    $connection->insert($qualified($namespace, $externalLink), ['id' => 71, 'parent_id' => 41]);
    $refuse([$externalLink => ['parent_id']], $externalLink, 'port_identifier_fk_external_target');
    $externalChild = 'port_identifier_fk_incoming';
    $create($external, $externalChild, static function (Table $table) use ($namespace, $parent, $qualified): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('tenant_id', 'bigint');
        $table->addColumn('parent_id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint($qualified($namespace, $parent), ['tenant_id', 'parent_id'], ['tenant_id', 'id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'port_identifier_fk_same_name');
    });
    $connection->insert($qualified($external, $externalChild), ['id' => 81, 'tenant_id' => 7, 'parent_id' => 41]);
    $refuse([$parent => ['id']], $externalChild, 'port_identifier_fk_same_name');
    $drop($external, $externalChild);
    // A wide incoming key still prevents recreation of its actual generated target.
    $projectionChild = 'port_identifier_fk_projection_incoming';
    $create($external, $projectionChild, static function (Table $table) use ($namespace, $parent, $qualified): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('parent_id', 'bigint');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint($qualified($namespace, $parent), ['parent_id'], ['projection_id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'port_identifier_fk_external_projection');
    });
    $connection->insert($qualified($external, $projectionChild), ['id' => 91, 'parent_id' => 41]);
    $refuse([$parent => ['id']], $projectionChild, 'port_identifier_fk_external_projection');
    $drop($external, $projectionChild);
    $create($namespace, $generatedLink, static function (Table $table) use ($external, $parent, $qualified): void {
        $table->addColumn('id', 'integer');
        $table->addColumn('parent_id', 'integer');
        $table->addColumn('projection_id', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (id) STORED']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['parent_id', 'projection_id'], 'port_identifier_fk_generated_support');
        $table->addForeignKeyConstraint($qualified($external, $parent), ['parent_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'port_identifier_fk_support_external');
    });
    $connection->insert($qualified($namespace, $generatedLink), ['id' => 101, 'parent_id' => 41]);
    $refuse([$generatedLink => ['id']], $generatedLink, 'port_identifier_fk_support_external');
    $drop($namespace, $generatedLink);

    $before = $facts();
    $wide = new WideIdentifiers([$parent => ['id']]);
    $plan = $wide->plan($connection);
    verify($plan !== [] && $facts() === $before, 'Unrelated external reference permits a read-only local widening plan');
    verify(!array_filter($plan, static fn (array $operation): bool => str_contains($operation['sql'], $externalLink) || str_contains($operation['sql'], $external)), 'Native target qualification prevents a same-name external parent from entering local scope');
    // The original SQL-only operation shape and real already-applied retry remain intact.
    WideIdentifiers::execute($connection, $plan[0]);
    WideIdentifiers::execute($connection, $plan[0]);
    foreach ($plan as $operation) {
        WideIdentifiers::execute($connection, $operation);
    }
    verify($wide->plan($connection) === [], 'Owned composite graph converges with idempotent replay');
    verify(Type::lookupName($manager->introspectTable($parent)->getColumn('id')->getType()) === 'bigint'
        && Type::lookupName($manager->introspectTable($child)->getColumn('parent_id')->getType()) === 'bigint'
        && Type::lookupName($manager->introspectTable($child)->getColumn('id')->getType()) === 'integer', 'Second-ordinal identity widens only its actual paired child column');
    $after = $facts();
    foreach ([[$external, $parent], [$namespace, $externalLink]] as [$schema, $name]) {
        verify($after[$qualified($schema, $name)] === $before[$qualified($schema, $name)], 'Unrelated external target, row and exact constraint stay untouched');
    }
    verify($after['ledger'] === $ledger, 'Width planning/execution uses no competing migration receipt');
    if ($postgres) {
        verify($after[$qualified($namespace, $child)]['constraints'] === $before[$qualified($namespace, $child)]['constraints'], 'Actual MATCH FULL, NOT VALID and deferrability semantics survive owned widening exactly');
    }
    verify((int)$connection->fetchOne('SELECT projection_id FROM ' . $qualified($namespace, $parent) . ' WHERE id=41') === 41, 'Generated compatibility value survives widening');
    $frame = OwnedMutationFrame::begin($connection);
    $failure = null;
    try {
        try {
            $connection->insert($qualified($namespace, $child), ['id' => 201, 'tenant_id' => 7, 'parent_id' => 4294967301]);
            throw new LogicException('Restored local composite FK accepts an orphan');
        } catch (ForeignKeyConstraintViolationException $error) {
            verify(true, 'Restored actual FK rejects an orphan');
        }
    } catch (Throwable $error) {
        $failure = $error;
    }
    try {
        $frame->rollBack();
    } catch (Throwable $error) {
        if ($failure !== null) {
            throw new \itsmng\Database\MutationRollbackFailure($failure, $error);
        }
        throw $error;
    }
    if ($failure !== null) {
        throw $failure;
    }
    $connection->delete($qualified($namespace, $parent), ['id' => 41]);
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $qualified($namespace, $child)) === 0
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $qualified($namespace, $externalLink)) === 1, 'Local CASCADE and unrelated external RESTRICT ownership stay distinct');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    foreach (array_reverse($created) as [$schema, $name]) {
        try {
            $connection->executeStatement('DROP TABLE ' . $qualified($schema, $name));
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    if ($namespaceCreated && $cleanup === []) {
        try {
            $connection->executeStatement('DROP SCHEMA ' . $quote($external));
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        verify($connection->fetchAllAssociative('SELECT version,state FROM ' . \itsmng\Database\Migration\Ledger::TABLE . ' ORDER BY version') === $ledger, 'Cleanup leaves the entire original ledger untouched');
        verify($auxiliaryFacts() === $auxiliaryBefore, 'All preexisting MySQL history auxiliary table definitions and duplicate native rowbags remain exact');
        $DB->clearSchemaCache();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Additional cleanup error: ' . $error->getMessage() . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo $DB->getProvider() . ": qualified local composite widening, incoming/outgoing external refusal, real generated dependencies and unchanged SQL-only replay passed.\n";
