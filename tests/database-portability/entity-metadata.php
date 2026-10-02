<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\Computer;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\UserReferenceAction;
use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\Orm;
use itsmng\Database\ReferenceValues;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/entity-metadata.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$tables = array_keys(EntityRegistry::tables());
$schemaTables = array_map(static fn ($table) => $table->getName(), (new BaselineSchema())->build($DB->getDoctrineConnection()->getDatabasePlatform())->getTables());
sort($schemaTables);
verify($tables === $schemaTables && count($tables) === 357, 'All core tables, including aggregate and guest memberships, are discovered from attributes');
verify(EntityRegistry::tables()['glpi_computers'] === Computer::class && !isset(EntityRegistry::tables()['glpi_not_a_core_table']), 'Class lookup uses the declared table, not naming guesses');
foreach ((new BaselineSchema())->build($DB->getDoctrineConnection()->getDatabasePlatform())->getTables() as $table) {
    $mappedColumns = EntityRegistry::columnNames($table->getName());
    $schemaColumns = array_map(static fn ($column): string => $column->getName(), $table->getColumns());
    sort($mappedColumns);
    sort($schemaColumns);
    verify($mappedColumns === $schemaColumns, 'Core object fields include owning and generated columns from metadata: ' . $table->getName());
}
verify(EntityRegistry::columnNames('glpi_not_a_core_table') === [], 'Unmapped columns are not inferred');
verify(array_sum(array_map(count(...), EntityRegistry::booleanColumns())) === 398, 'All audited boolean declarations are retained');
verify(EntityRegistry::isBoolean('glpi_users', 'is_active') && EntityRegistry::isBoolean('glpi_oidc_users', 'update'), 'Ordinary and reserved-name boolean columns come from mappings');
foreach (['glpi_savedsearches' => 'do_count', 'glpi_calendarsegments' => 'day', 'glpi_itilfollowups' => 'timeline_position', 'glpi_profilerights' => 'rights'] as $table => $column) {
    verify(!EntityRegistry::isBoolean($table, $column), 'Enums and bitmasks are not inferred as booleans: ' . $table . '.' . $column);
}
verify(array_sum(array_map(count(...), ForeignKeys::relations())) === 1038, 'Audited associations include normalized memberships, typed ITIL subjects, reservable assets, consumable recipients, physical placements, planning recalls, calendar objects, alerts, object lock subjects, ticket/change/problem assets and thirty-five project subjects');
$subjectChecks = (new BaselineSchema())->toSql($DB->getDoctrineConnection()->getDatabasePlatform());
foreach (['glpi_itilfollowups', 'glpi_itilsolutions', 'glpi_itils_projects'] as $table) {
    verify(count(array_filter($subjectChecks, static fn ($sql) => str_contains($sql, $table . '_subject_kind'))) === 1
        && !array_filter($subjectChecks, static fn ($sql) => str_contains($sql, $table . '_typed_item_kind')), 'Owning metadata retains one stable subject constraint name: ' . $table);
}
verify(EntityRegistry::hasPolicy('glpi_entities', 'entities_id', ReferenceKind::RootParent)
    && ForeignKeys::relations()['glpi_entities']['entities_id'] === 'glpi_entities', 'The entity parent is a local self association with a real root target');
verify((ForeignKeys::relations()['glpi_oidc_users']['user_id'] ?? null) === 'glpi_users', 'Nonconventional reference column comes from its association');
verify(!isset(ForeignKeys::relations()['glpi_users']['auths_id']) && !isset(ForeignKeys::relations()['glpi_events']['items_id']), 'Unmapped polymorphic scalars do not acquire guessed foreign keys');
verify(EntityRegistry::discriminatedReferences('glpi_users')['auths_id']['selections'][Auth::LDAP]['target'] === 'glpi_authldaps'
    && EntityRegistry::discriminatedReferences('glpi_users')['auths_id']['selections'][Auth::MAIL]['empty_value'] === 0, 'Typed authentication branches and no-server selection come from their owning properties');

// Compare entity-local policies with frozen pre-refactor upgrade inputs, not
// another runtime catalogue generated from the same attributes.
foreach (['optional' => ReferenceKind::EmptySelection, 'ownership' => ReferenceKind::RootEntity,
    'audience' => ReferenceKind::Audience, 'global' => ReferenceKind::GlobalScope,
    'inherited' => ReferenceKind::Inherited] as $section => $kind) {
    $expected = ReferenceHistory::get($section);
    $actual = EntityRegistry::relationsByPolicy($kind);
    foreach ([$expected, $actual] as $index => $relations) {
        foreach ($relations as &$columns) {
            ksort($columns);
        }
        unset($columns);
        ksort($relations);
        $sorted[$index] = $relations;
    }
    verify($sorted[0] === $sorted[1], 'Every historical reference policy and target is retained: ' . $section);
}
foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
    $reference = EntityRegistry::references('glpi_entities')[$column];
    verify($reference->association === $definition['association']
        && $reference->policy->modeProperty === $definition['mode']
        && $reference->modeColumn === $definition['mode']
        && $reference->modeLength === 16
        && $reference->policy->emptyZero === $definition['empty_zero']
        && $reference->defaultMode->value === $definition['default'], 'Inherited reference policy stays beside its association: ' . $column);
}
$expectedReassignments = ReferenceHistory::get('optional', 'ITIL_USERS');
$actualReassignments = [];
foreach (EntityRegistry::tables() as $table => $class) {
    foreach (EntityRegistry::references($table) as $column => $reference) {
        if ($reference->policy->userPurge === UserReferenceAction::ReassignHistory) {
            $actualReassignments[$table][$column] = $reference->targetTable;
        }
    }
}
foreach ([$expectedReassignments, $actualReassignments] as $index => $relations) {
    foreach ($relations as &$columns) {
        ksort($columns);
    }
    unset($columns);
    ksort($relations);
    $sorted[$index] = $relations;
}
verify($sorted[0] === $sorted[1], 'Only declared ITIL history references are reassigned when purging a user');
verify(ReferenceValues::normalizeLegacy('glpi_computers', ['entities_id' => 0, 'users_id' => 0]) === ['entities_id' => 0, 'users_id' => null], 'Real root and empty selection zero remain distinct');
verify(ReferenceValues::normalizeLegacy('glpi_groups_knowbaseitems', ['entities_id' => -1]) === ['entities_id' => null], 'Unrestricted audiences normalize to NULL');
verify(ReferenceValues::normalizeLegacy('glpi_fieldunicities', ['entities_id' => 0]) === ['entities_id' => 0], 'Global scope retains the real root');

// The offline mapping service and CLI tools must not require a running database.
$offline = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'serverVersion' => '8.4.0']);
$offlineEm = new EntityManager($offline, Orm::configuration(new MySQLPlatform()));
verify(count($offlineEm->getMetadataFactory()->getAllMetadata()) === 357 && !$offline->isConnected(), 'Complete mapping inspection opens no database connection');
$offlineEm->clear();
$offline->close();

$first = Orm::create($DB);
$second = Orm::create($DB);
verify($first !== $second && $first->getConnection() === $second->getConnection(), 'Units of work are separate while transactions share the application connection');
verify($first->getConfiguration() !== $second->getConfiguration() && $first->getConfiguration()->getMetadataCache() === $second->getConfiguration()->getMetadataCache(), 'Configuration copies share their metadata cache');
$original = $first->getClassMetadata(Computer::class);
$length = $original->fieldMappings['name']->length;
$original->fieldMappings['name']->length = 17;
$reloaded = $second->getClassMetadata(Computer::class);
verify($original !== $reloaded && $original->fieldMappings['name'] !== $reloaded->fieldMappings['name'] && $reloaded->fieldMappings['name']->length === $length, 'Serialized metadata isolates nested mapping mutations between managers');
$original->fieldMappings['name']->length = $length;
$first->getConfiguration()->addCustomStringFunction('LOCAL_ONLY', \itsmng\Database\Query\Replace::class);
verify($second->getConfiguration()->getCustomStringFunction('LOCAL_ONLY') === null && Orm::create($DB)->getConfiguration()->getCustomStringFunction('LOCAL_ONLY') === null, 'Configuration changes remain local');

$DB->beginTransaction();
try {
    $id = (new FixtureRecords($DB))->create('glpi_computers', ['name' => 'Metadata first unit']);
    $managed = $first->find(Computer::class, $id);
    $DB->getDoctrineConnection()->update('glpi_computers', ['name' => 'Metadata second unit'], ['id' => $id]);
    verify($managed->name === 'Metadata first unit' && $second->find(Computer::class, $id)->name === 'Metadata second unit', 'Sharing mappings never shares managed records or masks external writes');
    $assigned = 900000100;
    while ($DB->getDoctrineConnection()->fetchOne('SELECT id FROM glpi_computers WHERE id = ?', [$assigned]) !== false) {
        ++$assigned;
    }
    (new RecordWriter($first))->insert('glpi_computers', ['id' => $assigned, 'entities_id' => 0]);
    verify(Orm::create($DB)->getClassMetadata(Computer::class)->usesIdGenerator(), 'Assigned-ID imports cannot change the cached generated-ID strategy');
    $discarded = [];
    for ($unit = 0; $unit < 96; ++$unit) {
        $manager = Orm::create($DB);
        $manager->find(Computer::class, $id);
        $discarded[] = WeakReference::create($manager);
        unset($manager);
    }
    verify(count(array_filter($discarded, static fn ($reference) => $reference->get() !== null)) <= 16, 'Discarded units of work are collected in bounded batches');
    verify($first->contains($managed) && $managed->name === 'Metadata first unit', 'Collection does not clear a live unit of work');
} finally {
    $DB->rollBack();
    $first->clear();
    $second->clear();
}
echo $DB->getProvider() . ": attribute-derived tables, booleans, FK targets and reference policies; offline discovery and isolated metadata/identity maps passed.\n";
