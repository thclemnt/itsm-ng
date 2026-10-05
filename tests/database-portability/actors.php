<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Migration\ActorReferences;
use itsmng\Database\Migration\ActorUniqueness;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\TreeUniqueness;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/actors.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);

// Current entity ownership is checked against immutable adoption definitions.
// Keep the public-flow and historical reconstruction assertions below intact.
$expectedGeneratedKeys = [];
foreach (array_keys(ActorUniqueness::TABLES) as $table) {
    $expectedGeneratedKeys[] = $table . '.actor_key';
    $expectedGeneratedKeys[] = $table . '.actor_email_key';
}
foreach (array_keys(TreeUniqueness::TABLES) as $table) {
    $expectedGeneratedKeys[] = $table . '.parent_key';
}
sort($expectedGeneratedKeys, SORT_STRING);
foreach ([new \Doctrine\DBAL\Platforms\MySQLPlatform(), new \Doctrine\DBAL\Platforms\MariaDBPlatform(),
    new \Doctrine\DBAL\Platforms\PostgreSQLPlatform(), new \Doctrine\DBAL\Platforms\MySQLPlatform()] as $schemaPlatform) {
    $offlineConnection = \Doctrine\DBAL\DriverManager::getConnection($schemaPlatform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
        ? ['driver' => 'pdo_pgsql', 'serverVersion' => '16.0']
        : ['driver' => 'pdo_mysql', 'serverVersion' => $schemaPlatform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform ? '10.11.0-MariaDB' : '8.4.0']);
    try {
        $schemaEm = new \Doctrine\ORM\EntityManager($offlineConnection, Orm::configuration($schemaPlatform));
        $metadata = $schemaEm->getMetadataFactory()->getAllMetadata();
        $mapped = (new \Doctrine\ORM\Tools\SchemaTool($schemaEm))->getSchemaFromMetadata($metadata);
        $required = (new BaselineSchema())->build($schemaPlatform, false);
        $historical = (new Baseline20261001())->build($schemaPlatform);
        $ownedGeneratedKeys = [];
        foreach ($metadata as $entity) {
            $ownedColumns = [];
            foreach ((new ReflectionClass($entity->name))->getAttributes(SchemaIndex::class) as $attribute) {
                array_push($ownedColumns, ...$attribute->newInstance()->columns);
            }
            foreach ($entity->fieldMappings as $field) {
                $column = trim($field->columnName, '`"');
                if (in_array($column, $ownedColumns, true) && $field->notInsertable && $field->notUpdatable
                    && $field->generated === \Doctrine\ORM\Mapping\ClassMetadata::GENERATED_ALWAYS && $field->columnDefinition !== null) {
                    $ownedGeneratedKeys[] = $entity->getTableName() . '.' . $column;
                }
            }
        }
        sort($ownedGeneratedKeys, SORT_STRING);
        verify($ownedGeneratedKeys === $expectedGeneratedKeys, 'Only twelve actor identity properties and five existing tree keys own generated index columns');
        foreach (ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
            $entity = $schemaEm->getClassMetadata(EntityRegistry::tables()[$table]);
            $attributes = (new ReflectionClass($entity->name))->getAttributes(SchemaIndex::class);
            verify(count($attributes) === 2, 'Each actor entity owns its unique and supporting physical indexes: ' . $table);
            $oracle = clone $historical->getTable($table);
            ActorUniqueness::addToTable($oracle, $schemaPlatform);
            $unique = ActorUniqueness::indexName($table, $schemaPlatform);
            foreach ([$mapped->getTable($table), $required->getTable($table)] as $schemaTable) {
                foreach ([$unique, $table . '_actor_parent'] as $indexName) {
                    verify($schemaTable->hasIndex($indexName), 'ORM and current schema retain the exact adopted physical index name: ' . $table . '.' . $indexName);
                    $index = $schemaTable->getIndex($indexName);
                    $frozen = $oracle->getIndex($indexName);
                    verify($index->isUnique() === $frozen->isUnique() && $index->getColumns() === $frozen->getColumns()
                        && $index->getFlags() === $frozen->getFlags() && $index->getOptions() === $frozen->getOptions(), 'Actor index tuple, uniqueness and options remain frozen: ' . $table . '.' . $indexName);
                }
                foreach (['actor_key', 'actor_email_key'] as $key) {
                    $column = $schemaTable->getColumn($key);
                    $frozen = $oracle->getColumn($key);
                    verify(\Doctrine\DBAL\Types\Type::lookupName($column->getType()) === \Doctrine\DBAL\Types\Type::lookupName($frozen->getType())
                        && $column->getNotnull() === $frozen->getNotnull() && $column->getDefault() === $frozen->getDefault()
                        && $column->getLength() === $frozen->getLength() && $column->getComment() === $frozen->getComment()
                        && $column->getColumnDefinition() === $frozen->getColumnDefinition(), 'Generated property storage and expression equal the frozen adoption definition: ' . $table . '.' . $key);
                }
            }
            foreach (['actor_key', 'actor_email_key'] as $key) {
                $field = $entity->getFieldMapping($key);
                verify($field->notInsertable && $field->notUpdatable && $field->generated === \Doctrine\ORM\Mapping\ClassMetadata::GENERATED_ALWAYS
                    && $field->nullable && $field->columnDefinition === $oracle->getColumn($key)->getColumnDefinition(), 'Actor generated property remains nullable, always generated and excluded from writes: ' . $table . '.' . $key);
            }
        }
    } finally {
        $offlineConnection->close();
        unset($schemaEm, $metadata, $mapped, $required, $historical);
        gc_collect_cycles();
    }
}
$schemaPlatform = $connection->getDatabasePlatform();
$required = (new BaselineSchema())->build($schemaPlatform, false);
$postgres = $schemaPlatform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
foreach (ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
    $actual = $connection->createSchemaManager()->introspectTable($table);
    foreach ([ActorUniqueness::indexName($table, $schemaPlatform), $table . '_actor_parent'] as $indexName) {
        verify($actual->hasIndex($indexName), 'Installed actor index has its exact entity-owned physical name: ' . $table . '.' . $indexName);
        $index = $actual->getIndex($indexName);
        $expectedIndex = $required->getTable($table)->getIndex($indexName);
        verify($index->isUnique() === $expectedIndex->isUnique() && $index->getColumns() === $expectedIndex->getColumns(), 'Installed actor identity and supporting tuples are preserved: ' . $table . '.' . $indexName);
    }
    foreach (['actor_key', 'actor_email_key'] as $key) {
        $column = $actual->getColumn($key);
        $expectedColumn = $required->getTable($table)->getColumn($key);
        verify(\Doctrine\DBAL\Types\Type::lookupName($column->getType()) === \Doctrine\DBAL\Types\Type::lookupName($expectedColumn->getType())
            && !$column->getNotnull() && $column->getDefault() === null && $column->getLength() === $expectedColumn->getLength(), 'Native actor key retains its declared type, length, nullability and absent default: ' . $table . '.' . $key);
        $native = $connection->fetchAssociative($postgres
            ? 'SELECT a.attgenerated AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = to_regclass(?) AND a.attname = ?'
            : 'SELECT EXTRA AS generated, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $key]);
        verify($native !== false && ($postgres ? $native['generated'] === 's' : str_contains(strtoupper($native['generated']), 'STORED'))
            && is_string($native['expression']) && $native['expression'] !== '', 'Native actor identity remains a stored generated expression: ' . $table . '.' . $key);
        // Actual values for both branches remain checked by the original public
        // actor/ORM duplicate and generated-readback assertions below.
    }
}

$DB->beginTransaction();
try {
    $user = $fixtures->create('glpi_users', ['name' => 'Mapped actor']);
    $supplier = $fixtures->create('glpi_suppliers', ['name' => 'Mapped supplier']);
    foreach (\itsmng\Database\Migration\ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
        $model = getItemForItemtype(getItemTypeForTable($table));
        $parentTable = 'glpi_' . substr($parentKey, 0, -3);
        $parent = $fixtures->create($parentTable, ['name' => 'Actor parent ' . $table]);
        $actor = $actorKey === 'users_id' ? $user : $supplier;
        $input = [$parentKey => $parent, $actorKey => $actor, 'type' => CommonITILActor::ASSIGN, 'alternative_email' => '', '_disablenotif' => true];
        $id = $model->add($input);
        verify((int)$id > 0, 'Add named actor ' . $table);
        verify($model->add(array_replace($input, ['alternative_email' => 'different@example.invalid'])) === false, 'Named actor duplicate rejected regardless of email');
        $anonymousInput = array_replace($input, [$actorKey => 0, 'alternative_email' => 'anonymous@example.invalid']);
        $anonymous = $model->add($anonymousInput);
        verify((int)$anonymous > 0 && $read($table, $anonymous)[$actorKey] === null, 'Anonymous actor stores NULL association ' . $table);
        $anonymous2 = $model->add(array_replace($anonymousInput, [$actorKey => null, 'alternative_email' => 'second@example.invalid']));
        verify((int)$anonymous2 > 0, 'Distinct anonymous emails coexist ' . $table);
        verify($model->add($anonymousInput) === false, 'Duplicate anonymous email rejected');
        $criteria = [$parentKey => $parent, $actorKey => 0, 'type' => CommonITILActor::ASSIGN];
        verify(count($model->find($criteria)) === 2, 'Legacy zero actor criteria selects NULL actors');
        verify($model->getFromDB($anonymous) && $model->isAttach2Valid($model->fields), 'Loaded anonymous relationship remains valid');
        $actors = $model->getActors($parent);
        verify(count($actors[CommonITILActor::ASSIGN]) === 3, 'Mapped actor list includes named and anonymous actors');
        verify($model->isAlternateEmailForITILObject($parent, 'anonymous@example.invalid'), 'Alternate email membership uses mapped query');
        verify(!$model->isAlternateEmailForITILObject($parent, 'missing@example.invalid'), 'Missing alternate email excluded');
        foreach ([[$actorKey => $actor, 'alternative_email' => 'race@example.invalid'], [$actorKey => null, 'alternative_email' => 'anonymous@example.invalid']] as $duplicate) {
            $connection->beginTransaction();
            $rejected = false;
            try {
                (new RecordWriter(Orm::create($DB)))->insert($table, $duplicate + [$parentKey => $parent, 'type' => CommonITILActor::ASSIGN]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
                $rejected = true;
            } finally {
                $connection->rollBack();
            }
            verify($rejected, 'Database rejects actor duplicate even without application precheck');
        }
        $record = $read($table, $anonymous);
        verify((int)$record['actor_key'] === 0 && $record['actor_email_key'] === 'anonymous@example.invalid', 'Generated anonymous identity keys');
        $record = $read($table, $id);
        verify((int)$record['actor_key'] === $actor && $record['actor_email_key'] === '', 'Generated named identity ignores alternate address');
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $SQL_TOTAL_REQUEST = 0;
        $model->getActors($parent);
        $model->isAlternateEmailForITILObject($parent, 'anonymous@example.invalid');
        verify($SQL_TOTAL_REQUEST === 0, 'Actor queries use ORM');
        $parentModel = getItemForItemtype(getItemTypeForTable($parentTable));
        verify($parentModel->getFromDB($parent), 'Load actor parent');
        ob_start();
        if ($actorKey === 'users_id') {
            $model->showUserNotificationForm($anonymous);
        } else {
            $model->showSupplierNotificationForm($anonymous);
        }
        $html = ob_get_clean();
        verify(str_contains($html, 'anonymous@example.invalid'), 'Anonymous notification editor remains available');
        $additional = $actorKey === 'users_id'
            ? ['_additional_requesters' => CommonITILActor::REQUESTER, '_additional_observers' => CommonITILActor::OBSERVER, '_additional_assigns' => CommonITILActor::ASSIGN]
            : ['_additional_suppliers_assigns' => CommonITILActor::ASSIGN];
        foreach ($additional as $inputKey => $role) {
            $email = $inputKey . '@example.invalid';
            verify($parentModel->update(['id' => $parent, $inputKey => [[$actorKey => null, 'alternative_email' => $email, 'use_notification' => 0]], '_disablenotif' => true]), 'Parent accepts loaded anonymous additional actor');
            verify((new RecordRepository(Orm::create($DB)))->countMatching($table, [$parentKey => $parent, $actorKey => null, 'type' => $role, 'alternative_email' => $email]) === 1, 'Additional anonymous actor preserved for ' . $inputKey);
        }
        verify($parentModel->delete(['id' => $parent], true), 'Parent purge cleans all actor links');
        verify((new RecordRepository(Orm::create($DB)))->countMatching($table, [$parentKey => $parent]) === 0, 'Actor children purged');
    }
    // User/supplier purge removes their links, leaving anonymous actors intact.
    foreach ([['glpi_tickets_users', 'users_id', $user, new User()], ['glpi_suppliers_tickets', 'suppliers_id', $supplier, new Supplier()]] as [$table, $actorKey, $actor, $model]) {
        $ticket = $fixtures->create('glpi_tickets', ['name' => 'Actor endpoint purge']);
        $named = $fixtures->create($table, ['tickets_id' => $ticket, $actorKey => $actor, 'type' => CommonITILActor::OBSERVER]);
        $anonymous = $fixtures->create($table, ['tickets_id' => $ticket, $actorKey => null, 'type' => CommonITILActor::OBSERVER, 'alternative_email' => 'preserved@example.invalid']);
        verify($model->delete(['id' => $actor], true), 'Actor endpoint purge');
        verify($read($table, $named) === null && $read($table, $anonymous) !== null, 'Purge preserves anonymous endpoint');
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Actor graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$migration = new ActorReferences();
$parent = $fixtures->create('glpi_tickets', ['name' => 'Legacy actor host']);
$user = $fixtures->create('glpi_users', ['name' => 'Legacy actor identity']);
$legacy = [];
try {
    foreach (\itsmng\Database\Migration\ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
        $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $actorKey), $table));
        $index = \itsmng\Database\Migration\ActorUniqueness::indexName($table, $platform);
        $connection->executeStatement($platform->getDropIndexSQL($index, $table));
        $connection->executeStatement('UPDATE ' . $table . ' SET ' . $actorKey . ' = 0 WHERE ' . $actorKey . ' IS NULL');
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        $after->dropColumn('actor_key');
        $after->dropColumn('actor_email_key');
        $after->getColumn($actorKey)->setNotnull(true)->setDefault(0);
        $columns = [$parentKey, 'type', $actorKey];
        if ($actorKey === 'users_id') {
            $columns[] = 'alternative_email';
        }
        $after->addUniqueIndex($columns, $index);
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    if ($DB->getProvider() === 'mysql') {
        $connection->beginTransaction();
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'DDL must run outside an application transaction');
        } finally {
            $connection->rollBack();
        }
        verify($rejected, 'MySQL migration refuses an application transaction before implicit-commit DDL');
    }
    $next = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_tickets_users');
    $connection->insert('glpi_tickets_users', ['id' => $next, 'tickets_id' => $parent, 'users_id' => 2147483647, 'type' => CommonITILActor::REQUESTER, 'alternative_email' => 'first@example.invalid']);
    $legacy[] = $next;
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned ITIL actor');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_tickets_users')['users_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_tickets_users', ['users_id' => $user], ['id' => $next]);
    $connection->insert('glpi_tickets_users', ['id' => $next + 1, 'tickets_id' => $parent, 'users_id' => $user, 'type' => CommonITILActor::REQUESTER, 'alternative_email' => 'second@example.invalid']);
    $legacy[] = $next + 1;
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Duplicate ITIL actors');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_tickets_users')['users_id']->getNotnull(), 'Named duplicate with different email rejected before DDL');
    $connection->delete('glpi_tickets_users', ['id' => $next + 1]);
    array_pop($legacy);
    $connection->update('glpi_tickets_users', ['users_id' => 0], ['id' => $next]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_tickets_users WHERE id = ?', [$next]) === null, 'Legacy anonymous actor becomes NULL');
    verify($connection->fetchOne('SELECT actor_email_key FROM glpi_tickets_users WHERE id = ?', [$next]) === 'first@example.invalid', 'Migration preserves anonymous identity');
    verify($migration->plan($connection)['sql'] === [] && $migration->apply($connection) === [], 'Actor migration is idempotent');
} finally {
    foreach ($legacy as $id) {
        $connection->delete('glpi_tickets_users', ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    (new Ticket())->delete(['id' => $parent], true);
    (new User())->delete(['id' => $user], true);
}
echo $DB->getProvider() . ": mapped named/anonymous actors, uniqueness, notification forms, purges and migration passed.\n";
