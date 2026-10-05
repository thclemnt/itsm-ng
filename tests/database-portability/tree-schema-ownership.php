<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\BusinessCriticity;
use itsmng\Database\Entity\DocumentCategory;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\KnowbaseItemCategory;
use itsmng\Database\Entity\Location;
use itsmng\Database\Entity\State;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tree-schema-ownership.php /path/to/disposable-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable test database required');
$connection = $DB->getDoctrineConnection();
verify(!$connection->isTransactionActive(), 'Index drift probe requires an idle supplied connection');
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$quote = $platform->quoteIdentifier(...);
$em = Orm::create($DB);
$expected = (new BaselineSchema())->build($platform);
$checker = new SchemaCheck();
verify($checker->differences($connection) === [], 'Installed schema starts without drift');

// Independent physical expectations, rather than rediscovering an implementation list.
$cases = [
    [BusinessCriticity::class, 'businesscriticities_id', 'glpi_businesscriticities_unicity', false],
    [DocumentCategory::class, 'documentcategories_id', 'glpi_documentcategories_unicity', false],
    [KnowbaseItemCategory::class, 'knowbaseitemcategories_id', 'glpi_knowbaseitemcategories_unicity', true],
    [Location::class, 'locations_id', 'glpi_locations_unicity', true],
    [State::class, 'states_id', 'glpi_states_unicity', false],
];
$metadata = array_map(static fn (array $case) => $em->getClassMetadata($case[0]), $cases);
$mapped = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
$rows = static function () use ($connection, $metadata, $quote): array {
    $result = [];
    foreach ($metadata as $entity) {
        $result[$entity->getTableName()] = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($entity->getTableName()) . ' ORDER BY id');
    }
    return $result;
};
$before = $rows();
foreach ($cases as [$class, $parent, $postgresqlIndex, $scoped]) {
    $entity = $em->getClassMetadata($class);
    $name = $entity->getTableName();
    $indexName = $postgres ? $postgresqlIndex : 'unicity';
    $columns = $scoped ? ['entities_id', 'parent_key', 'name'] : ['parent_key', 'name'];
    $actual = $connection->createSchemaManager()->introspectTable($name);
    $actualKey = $actual->getColumn('parent_key');
    verify(Type::lookupName($actualKey->getType()) === 'bigint' && !$actualKey->getNotnull()
        && $actualKey->getDefault() === null, 'Installed key retains nullable BIGINT storage: ' . $name);
    foreach ([$actual, $expected->getTable($name), $mapped->getTable($name)] as $table) {
        verify($table->hasIndex($indexName), 'Exact physical unique name: ' . $name);
        $index = $table->getIndex($indexName);
        verify($index->isUnique() && $index->getColumns() === $columns, 'Ordered unique columns: ' . $name);
        if ($scoped) {
            $support = $name . '_tree_entities';
            verify($table->hasIndex($support) && !$table->getIndex($support)->isUnique()
                && $table->getIndex($support)->getColumns() === ['entities_id'], 'Exact supporting ownership index: ' . $name);
        }
    }
    $field = $entity->getFieldMapping('parent_key');
    verify($field->notInsertable && $field->notUpdatable && $field->generated === ClassMetadata::GENERATED_ALWAYS
        && $field->nullable && $field->type === 'bigint', 'Generated identity is nullable and read-only: ' . $name);
    verify(in_array('parent_key', EntityRegistry::readOnlyColumns($name), true), 'Clone projection remains read-only: ' . $name);
    $definition = $expected->getTable($name)->getColumn('parent_key');
    verify(!$definition->getNotnull() && $definition->getDefault() === null
        && str_contains($definition->getColumnDefinition(), 'COALESCE(' . $quote($parent) . ', 0)'), 'Current generated expression uses the explicit parent: ' . $name);
    $native = $connection->fetchAssociative($postgres
        ? "SELECT a.attgenerated AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = to_regclass(?) AND a.attname = ?"
        : 'SELECT EXTRA AS generated, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$name, 'parent_key']);
    $expression = str_replace(['`', '"', ' '], '', strtolower($native['expression'] ?? ''));
    verify($native !== false && ($postgres ? $native['generated'] === 's' : str_contains(strtoupper($native['generated']), 'STORED'))
        && preg_match('/^coalesce\(' . preg_quote($parent, '/') . ',\(*0\)*(?:::bigint)?\)$/D', $expression) === 1, 'Native stored expression retains NULL-root normalization: ' . $name);
    $parentKey = ForeignKeys::name($name, $parent);
    verify($actual->hasForeignKey($parentKey) && $actual->getForeignKey($parentKey)->getForeignTableName() === $name
        && $actual->getForeignKey($parentKey)->getLocalColumns() === [$parent], 'Actual nullable parent still has its owning self FK: ' . $name);
    verify($connection->fetchOne('SELECT id FROM ' . $quote($name) . ' WHERE id = 0') === false, 'Zero is an empty parent, not a real parent row: ' . $name);
}

$connection->beginTransaction();
try {
    foreach ($cases as [$class, $parent]) {
        $entity = $em->getClassMetadata($class);
        $name = $entity->getTableName();
        $parentAssociations = array_filter($entity->associationMappings, static fn ($association): bool =>
            $association->isToOneOwningSide() && $association->targetEntity === $class
            && count($association->joinColumns) === 1 && $association->joinColumns[0]->name === $parent);
        verify(count($parentAssociations) === 1, 'The native parent column has one mapped owning self association: ' . $name);
        $parentProperty = array_key_first($parentAssociations);
        $hasOwner = $entity->hasAssociation('entities');
        $label = 'Owned tree key ' . bin2hex(random_bytes(8));
        $root = new $class();
        $root->name = $label;
        if ($hasOwner) {
            $root->entities = $em->getReference(Entity::class, 0);
        }
        $root->parent_key = 999999;
        $em->persist($root);
        $em->flush();
        $read = static fn (int $id) => $connection->fetchAssociative('SELECT ' . $quote($parent) . ' AS parent, parent_key, name'
            . ($hasOwner ? ', entities_id' : '') . ' FROM ' . $quote($name) . ' WHERE id = ?', [$id]);
        $rootRow = $read($root->id);
        verify($rootRow['parent'] === null && (int)$rootRow['parent_key'] === 0, 'Nullable root generates the compatibility identity zero: ' . $name);
        if ($hasOwner) {
            verify((int)$rootRow['entities_id'] === 0, 'Real owner zero is retained separately from the nullable parent: ' . $name);
        }
        $root->parent_key = 888888;
        $root->name .= ' updated';
        $em->flush();
        verify((int)$read($root->id)['parent_key'] === 0 && (int)$root->parent_key === 0, 'ORM ignores generated writes and refreshes the native projection: ' . $name);
        $child = new $class();
        $child->name = $root->name;
        if ($hasOwner) {
            $child->entities = $em->getReference(Entity::class, 0);
        }
        $child->$parentProperty = $em->getReference($class, $root->id);
        $em->persist($child);
        $em->flush();
        verify((int)$read($child->id)['parent_key'] === $root->id, 'Equal names under a different parent remain valid: ' . $name);
        $childBefore = $read($child->id);
        foreach ([null, 0] as $invalidParent) {
            $connection->beginTransaction();
            try {
                $rejected = false;
                try {
                    $values = [$parent => $invalidParent];
                    if ($invalidParent === 0) {
                        // Isolate the nonexistent-parent FK from root-name uniqueness.
                        $values['name'] = 'Nonexistent parent ' . bin2hex(random_bytes(8));
                    }
                    $connection->update($name, $values, ['id' => $child->id]);
                } catch (UniqueConstraintViolationException $error) {
                    $rejected = $invalidParent === null;
                } catch (ForeignKeyConstraintViolationException $error) {
                    $rejected = $invalidParent === 0;
                }
                verify($rejected, 'Native root duplicate or nonexistent zero parent is refused: ' . $name);
            } finally {
                $connection->rollBack();
            }
            verify($read($child->id) === $childBefore, 'Rejected reassignment preserves the native child: ' . $name);
        }
    }
} finally {
    $connection->rollBack();
    $em->clear();
}
verify($rows() === $before, 'ORM/savepoint probes preserve full existing tree rows');

// Change a real installed owned index; the checker must diagnose and leave it untouched.
$probeName = $em->getClassMetadata(BusinessCriticity::class)->getTableName();
$probeIndex = $postgres ? 'glpi_businesscriticities_unicity' : 'unicity';
$savedIndex = $connection->createSchemaManager()->introspectTable($probeName)->getIndex($probeIndex);
try {
    $connection->executeStatement($platform->getDropIndexSQL($quote($probeIndex), $quote($probeName)));
    $connection->executeStatement($platform->getCreateIndexSQL(new \Doctrine\DBAL\Schema\Index($probeIndex, ['parent_key', 'name']), $quote($probeName)));
    $differences = $checker->differences($connection);
    verify(count($differences) === 1 && in_array($differences[0], [
        'Changed index: ' . $probeName . '.' . $probeIndex,
        'Missing index: ' . $probeName . '.' . $probeIndex,
    ], true), 'Actual uniqueness drift is diagnosed without unrelated differences');
    verify(!$connection->createSchemaManager()->introspectTable($probeName)->getIndex($probeIndex)->isUnique(), 'Schema checking leaves native index drift untouched');
} finally {
    $manager = $connection->createSchemaManager();
    if ($manager->introspectTable($probeName)->hasIndex($probeIndex)) {
        $connection->executeStatement($platform->getDropIndexSQL($quote($probeIndex), $quote($probeName)));
    }
    $connection->executeStatement($platform->getCreateIndexSQL($savedIndex, $quote($probeName)));
}
verify($checker->differences($connection) === [] && $rows() === $before, 'Exact original index and all existing rows are restored');
echo $DB->getProvider() . ": five entity-owned native tree keys/indexes, read-only ORM projection, NULL/root ownership, duplicate/FK refusal and unrepaired index drift passed.\n";
