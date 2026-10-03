<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\Table;
use itsmng\Appliance\AppliancePluginImport;
use itsmng\Appliance\PluginApplianceSource;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use Symfony\Component\Console\Tester\CommandTester;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/appliance-plugin-import.php /path/to/test-config\n");
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
function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException $error) {
        verify(str_contains($error->getMessage(), $message), 'Diagnostic: ' . $message . '; actual: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Rejected input was accepted: ' . $message);
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$pluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $pluginProperty->getValue();
$connection = $DB->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$sourceTables = [];
$transaction = false;
$ledgerBackup = null;
$savedStorageEngine = null;
try {
    if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        // Exercise the server setting that previously made import receipts
        // survive rollback. Preserve the disposable database's original ledger.
        $backupName = 'itsm_port_appliance_saved_ledger';
        verify(!$manager->tablesExist([$backupName]), 'Fixture exclusively owns ledger backup');
        $savedStorageEngine = $connection->fetchOne('SELECT @@SESSION.default_storage_engine');
        $ledgerStates = $connection->fetchAllAssociative('SELECT version, state FROM itsmng_migrations');
        $manager->renameTable('itsmng_migrations', $backupName);
        $ledgerBackup = $backupName;
        $connection->executeStatement('SET SESSION default_storage_engine = ?', ['MyISAM']);
        $connection->beginTransaction();
        try {
            rejected(fn () => Ledger::save($connection, 'missing-ledger-in-transaction', ['complete' => true]), 'outside an application transaction');
            verify($connection->isTransactionActive() && !$manager->tablesExist(['itsmng_migrations']), 'Missing ledger refuses implicit-commit DDL inside application work');
        } finally {
            $connection->rollBack();
        }
        foreach ($ledgerStates as $state) {
            Ledger::save($connection, $state['version'], json_decode($state['state'], true, flags: JSON_THROW_ON_ERROR));
        }
        verify($connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['itsmng_migrations']) === 'InnoDB', 'Ledger creation is transactional even when the server defaults to MyISAM');
        $connection->executeStatement('ALTER TABLE itsmng_migrations ENGINE = MyISAM');
        rejected(fn () => Ledger::state($connection, \itsmng\Database\Migration\Baseline20261001::VERSION), 'must use InnoDB');
        rejected(fn () => Ledger::save($connection, 'invalid-engine-write', ['complete' => true]), 'must use InnoDB');
        rejected(fn () => (new \itsmng\Database\Migration\History())->upgrade($connection), 'must use InnoDB');
        rejected(fn () => (new \itsmng\Database\Migration\LegacyToOrm())->apply($connection), 'must use InnoDB');
        rejected(fn () => (new AppliancePluginImport($DB))->import(), 'must use InnoDB');
        verify($connection->fetchAllAssociative('SELECT version, state FROM itsmng_migrations ORDER BY version') === $connection->fetchAllAssociative('SELECT version, state FROM ' . $ledgerBackup . ' ORDER BY version'), 'Existing nontransactional receipts are never trusted, written or silently repaired');
        // This disposable ledger was copied from the preserved, validated fixture.
        // Production repair cannot infer that its historical receipts are valid.
        $connection->executeStatement('ALTER TABLE itsmng_migrations ENGINE = InnoDB');
    }
    // Historical plugin input is a separate schema, never substituted for core entities.
    $type = new Table('glpi_plugin_appliances_appliancetypes');
    $type->addColumn('id', 'bigint');
    $type->addColumn('entities_id', 'bigint', ['default' => 0]);
    $type->addColumn('is_recursive', 'integer', ['default' => 0]);
    $type->addColumn('name', 'string', ['length' => 255]);
    $type->addColumn('comment', 'text', ['notnull' => false]);
    $type->addColumn('externalid', 'string', ['notnull' => false, 'length' => 255]);
    $type->setPrimaryKey(['id']);
    $environment = new Table('glpi_plugin_appliances_environments');
    $environment->addColumn('id', 'bigint');
    $environment->addColumn('name', 'string', ['length' => 255]);
    $environment->addColumn('comment', 'text', ['notnull' => false]);
    $environment->setPrimaryKey(['id']);
    $appliance = new Table('glpi_plugin_appliances_appliances');
    $appliance->addColumn('id', 'bigint');
    foreach (['entities_id', 'plugin_appliances_appliancetypes_id', 'locations_id', 'plugin_appliances_environments_id', 'users_id', 'users_id_tech', 'groups_id', 'groups_id_tech', 'states_id'] as $column) {
        $appliance->addColumn($column, 'bigint', ['default' => 0]);
    }
    foreach (['is_recursive', 'is_deleted', 'is_helpdesk_visible', 'relationtype'] as $column) {
        $appliance->addColumn($column, 'integer', ['default' => 0]);
    }
    $appliance->addColumn('name', 'string', ['length' => 255]);
    foreach (['comment', 'date_mod', 'externalid', 'serial', 'otherserial'] as $column) {
        $appliance->addColumn($column, 'text', ['notnull' => false]);
    }
    $appliance->setPrimaryKey(['id']);
    $item = new Table('glpi_plugin_appliances_appliances_items');
    foreach (['id', 'plugin_appliances_appliances_id', 'items_id'] as $column) {
        $item->addColumn($column, 'bigint');
    }
    $item->addColumn('itemtype', 'string', ['length' => 255]);
    $item->setPrimaryKey(['id']);
    $relation = new Table('glpi_plugin_appliances_relations');
    foreach (['id', 'plugin_appliances_appliances_items_id', 'relations_id'] as $column) {
        $relation->addColumn($column, 'bigint');
    }
    $relation->setPrimaryKey(['id']);
    foreach ([$type, $environment, $appliance, $item, $relation] as $table) {
        verify(!$manager->tablesExist([$table->getName()]), 'Fixture owns plugin source table: ' . $table->getName());
        $manager->createTable($table);
        $sourceTables[] = $table->getName();
    }
    $importer = new AppliancePluginImport($DB);
    $missing = clone $appliance;
    $missing->dropColumn('serial');
    foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($appliance, $missing)) as $sql) {
        $connection->executeStatement($sql);
    }
    rejected(fn () => $importer->plan(), 'Missing appliance plugin source columns');
    foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($missing, $appliance)) as $sql) {
        $connection->executeStatement($sql);
    }
    // Older compatible source shapes explicitly default only these two optional fields.
    $optionalType = clone $type;
    $optionalType->dropColumn('externalid');
    foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($type, $optionalType)) as $sql) {
        $connection->executeStatement($sql);
    }
    verify($importer->plan()->counts['types'] === 0, 'Missing historical optional externalid remains supported');
    foreach ($connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($optionalType, $type)) as $sql) {
        $connection->executeStatement($sql);
    }
    $DB->beginTransaction();
    $transaction = true;
    $fixtures = new FixtureRecords($DB);
    $entity = $fixtures->create('glpi_entities', ['name' => 'Appliance plugin scope']);
    $technician = $fixtures->create('glpi_users', ['name' => 'Imported appliance technician ' . bin2hex(random_bytes(4))]);
    $group = $fixtures->create('glpi_groups', ['entities_id' => $entity]);
    $location = $fixtures->create('glpi_locations', ['entities_id' => $entity, 'name' => 'Import location']);
    $network = $fixtures->create('glpi_networks', ['name' => 'Import network']);
    $domain = $fixtures->create('glpi_domains', ['entities_id' => $entity, 'name' => 'Import domain']);
    $base = 5000000000;
    $typeId = $base;
    $environmentId = $base + 50;
    $applianceIds = [$base + 100, $base + 101, $base + 102];
    $connection->insert($type->getName(), ['id' => $typeId, 'entities_id' => $entity, 'is_recursive' => 1, 'name' => "Imported type 日本語 O'Reilly", 'comment' => 'Type C:\\new', 'externalid' => 'source-type']);
    $connection->insert($environment->getName(), ['id' => $environmentId, 'name' => 'Imported environment', 'comment' => 'null']);
    foreach ($applianceIds as $offset => $id) {
        $connection->insert($appliance->getName(), ['id' => $id, 'entities_id' => $entity, 'is_recursive' => 1,
            'name' => $offset === 2 ? 'NULL' : "Imported appliance 日本語 O'Reilly C:\\new " . $offset,
            'plugin_appliances_appliancetypes_id' => $typeId, 'plugin_appliances_environments_id' => $environmentId,
            'locations_id' => $location, 'users_id_tech' => $technician, 'groups_id' => $group, 'groups_id_tech' => $group,
            'relationtype' => $offset + 1, 'is_helpdesk_visible' => $offset === 0 ? 0 : 1,
            'date_mod' => $offset === 2 ? '0000-00-00 00:00:00' : '2030-07-14 22:45:06',
            'externalid' => $offset === 2 ? '' : 'source-appliance-' . $offset, 'serial' => 'Serial-' . $offset, 'otherserial' => null]);
    }
    $subjects = EntityRegistry::discriminatedReferences('glpi_appliances_items')['items_id']['selections'];
    $itemIds = [];
    $offset = 0;
    foreach ($subjects as $kind => $selection) {
        $subject = $fixtures->create($selection['target'], ['entities_id' => $entity]);
        $id = $base + 300 + $offset;
        $itemIds[] = $id;
        $connection->insert($item->getName(), ['id' => $id, 'plugin_appliances_appliances_id' => $applianceIds[$offset % 3], 'items_id' => $subject, 'itemtype' => $kind]);
        ++$offset;
    }
    foreach ([$location, $network, $domain] as $offset => $subject) {
        $connection->insert($relation->getName(), ['id' => $base + 400 + $offset, 'plugin_appliances_appliances_items_id' => $itemIds[$offset], 'relations_id' => $subject]);
    }
    $unrelated = $fixtures->create('glpi_appliances', ['id' => 987654321, 'name' => 'Unrelated core appliance', 'entities_id' => $entity, 'externalidentifier' => 'unrelated-core']);
    $infocom = $fixtures->create('glpi_infocoms', ['itemtype' => PluginApplianceSource::ITEMTYPE, 'items_id' => $applianceIds[0], 'entities_id' => $entity, 'comment' => "Invoice O'Reilly C:\\new 日本語"]);
    $knowledge = $fixtures->create('glpi_knowbaseitems_items', ['itemtype' => PluginApplianceSource::ITEMTYPE, 'items_id' => $applianceIds[1]]);
    $notepad = $fixtures->create('glpi_notepads', ['itemtype' => PluginApplianceSource::ITEMTYPE, 'items_id' => $applianceIds[2], 'content' => 'Original private note']);
    $history = $fixtures->create('glpi_logs', ['itemtype' => PluginApplianceSource::ITEMTYPE, 'items_id' => $applianceIds[0], 'itemtype_link' => PluginApplianceSource::ITEMTYPE,
        'old_value' => 'Original audit C:\\new 日本語', 'new_value' => "O'Reilly", 'date_mod' => '2030-07-14 10:20:30']);
    $retiredHistory = $fixtures->create('glpi_logs', ['itemtype' => PluginApplianceSource::ITEMTYPE, 'items_id' => 987654321, 'new_value' => 'Retired historical identity']);
    $linkedHistory = $fixtures->create('glpi_logs', ['itemtype' => 'Computer', 'items_id' => (int)$connection->fetchOne('SELECT items_id FROM ' . $item->getName() . ' WHERE id = ?', [$itemIds[0]]),
        'itemtype_link' => PluginApplianceSource::ITEMTYPE, 'old_value' => 'Original linked-object name (987654321)', 'new_value' => 'Original linked-object name (' . $applianceIds[0] . ')']);
    $profileTypes = [0 => 'Computer', 1 => PluginApplianceSource::ITEMTYPE, '日本語' => 'C:\\new 日本語',
        PluginApplianceSource::ITEMTYPE => 'Unrelated', 'embedded' => 'Prefix' . PluginApplianceSource::ITEMTYPE . 'Suffix'];
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Import active profile', 'helpdesk_item_type' => json_encode($profileTypes, JSON_THROW_ON_ERROR)]);
    $_SESSION['glpiactiveprofile']['id'] = $profile;
    $_SESSION['glpiactiveprofile']['helpdesk_item_type'] = $profileTypes;
    $CFG_GLPI['auto_create_infocoms'] = true;
    $CFG_GLPI['use_notifications'] = false;
    $CFG_GLPI['notifications_mailing'] = false;
    $coreTables = ['glpi_appliancetypes', 'glpi_applianceenvironments', 'glpi_appliances', 'glpi_appliances_items', 'glpi_appliances_items_relations', 'glpi_infocoms', 'glpi_logs'];
    $counts = static function () use ($connection, $coreTables): array {
        $result = [];
        foreach ($coreTables as $table) {
            $result[$table] = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        }
        return $result;
    };
    $before = $counts();
    $plan = $importer->plan();
    verify($plan->counts === ['types' => 1, 'environments' => 1, 'appliances' => 3, 'items' => 8, 'relations' => 3], 'Whole owned graph is planned');
    verify(count($plan->bindings) === 3 && count($plan->audits) === 2 && $plan->profiles === [$profile], 'Plan covers live scalar bindings, both history roles and exact profile values');
    verify($counts() === $before && Ledger::state($connection, AppliancePluginImport::RECEIPT) === null, 'Planning is read-only');
    $application = new \Symfony\Component\Console\Application();
    $application->add(new \Glpi\Console\Migration\AppliancesPluginToCoreCommand());
    $command = new CommandTester($application->find('itsmng:migration:appliances_plugin_to_core'));
    verify($command->execute(['--dry-run' => true, '--no-interaction' => true]) === 0 && $counts() === $before, 'Actual CLI dry-run is read-only');
    verify($command->execute(['--skip-errors' => true, '--no-interaction' => true]) === 1 && $counts() === $before, 'Partial graph imports are explicitly rejected');
    $fixtures->create('glpi_applianceenvironments', ['id' => $environmentId, 'name' => 'Imported environment', 'comment' => 'null']);
    rejected(fn () => $importer->import(), 'ID collision');
    $connection->delete('glpi_applianceenvironments', ['id' => $environmentId]);
    verify($counts() === $before, 'Even an identical unreceipted core record is an ownership collision');
    foreach ([['id' => $base + 901], ['clone' => false], ['_oldID' => $unrelated]] as $invalid) {
        rejected(fn () => (new Appliance())->addWithAssignedIdentifier($base + 900, $invalid + ['name' => 'Invalid assigned-ID input']), 'positive matching ID and no clone parameters');
    }
    rejected(fn () => (new Appliance())->addWithAssignedIdentifier(0, ['name' => 'Zero assigned ID']), 'positive matching ID');
    rejected(fn () => (new Appliance())->addWithAssignedIdentifier($unrelated, ['name' => 'Occupied assigned ID']), 'Assigned-ID collision');

    $badInputs = [
        [$appliance->getName(), $applianceIds[0], ['users_id_tech' => 999999999], ['users_id_tech' => $technician], 'Invalid appliance import target'],
        [$appliance->getName(), $applianceIds[0], ['is_recursive' => 2], ['is_recursive' => 1], 'Invalid appliance import boolean'],
        [$appliance->getName(), $applianceIds[0], ['date_mod' => '2030-02-30 12:00:00'], ['date_mod' => '2030-07-14 22:45:06'], 'Invalid appliance import calendar date'],
        [$appliance->getName(), $applianceIds[0], ['relationtype' => 99], ['relationtype' => 1], 'Unknown appliance plugin relation kind'],
        [$item->getName(), $itemIds[0], ['itemtype' => 'UnsupportedPluginAsset'], ['itemtype' => array_key_first($subjects)], 'Unsupported Typed item reference'],
    ];
    foreach ($badInputs as [$table, $id, $changes, $restore, $diagnostic]) {
        $connection->update($table, $changes, ['id' => $id]);
        rejected(fn () => $importer->import(), $diagnostic);
        verify($counts() === $before && Ledger::state($connection, AppliancePluginImport::RECEIPT) === null, 'Invalid source refuses before core writes: ' . $diagnostic);
        $connection->update($table, $restore, ['id' => $id]);
    }
    $connection->update($appliance->getName(), ['externalid' => 'unrelated-core'], ['id' => $applianceIds[0]]);
    rejected(fn () => $importer->import(), 'unique collision');
    $connection->update($appliance->getName(), ['externalid' => 'source-appliance-0'], ['id' => $applianceIds[0]]);
    $duplicate = $connection->fetchAssociative('SELECT * FROM ' . $item->getName() . ' WHERE id = ?', [$itemIds[0]]);
    $duplicate['id'] = $base + 399;
    $connection->insert($item->getName(), $duplicate);
    rejected(fn () => $importer->import(), 'unique collision');
    $connection->delete($item->getName(), ['id' => $duplicate['id']]);
    $connection->update('glpi_infocoms', ['items_id' => 999999999], ['id' => $infocom]);
    rejected(fn () => $importer->import(), 'Invalid appliance plugin binding');
    $connection->update('glpi_infocoms', ['items_id' => $applianceIds[0]], ['id' => $infocom]);

    $pluginProperty->setValue(null, [...$savedPlugins, 'orm_import_fixture']);
    $created = [];
    $profileUpdates = 0;
    $applianceInputHook = static function ($item) use ($applianceIds): void {
        verify(
            is_int($item->input['id']) && $item->input['is_recursive'] === true && $item->input['is_deleted'] === false,
            'Importer pre-add hook receives the normalized wide ID and true/false flags without text coercion'
        );
        verify($item->input['is_helpdesk_visible'] === ($item->input['id'] !== $applianceIds[0]), 'Importer pre-add hook retains both visibility states');
    };
    $PLUGIN_HOOKS['pre_item_add']['orm_import_fixture'][Appliance::class] = $applianceInputHook;
    foreach ([ApplianceType::class, ApplianceEnvironment::class, Appliance::class, Appliance_Item::class, Appliance_Item_Relation::class] as $model) {
        $PLUGIN_HOOKS['item_add']['orm_import_fixture'][$model] = static function ($item) use (&$created): void {
            verify(!array_key_exists('clone', $item->input), 'Imported creation is never disguised as a clone');
            verify($GLOBALS['CFG_GLPI']['auto_create_infocoms'] === false, 'Source financial ownership is retained without automatic duplicate creation');
            if ($item instanceof Appliance) {
                verify($item->input['is_deleted'] === 0 && $item->input['is_recursive'] === 1, 'Actual add hook retains the lifecycle zero/one flag representation');
            }
            $created[] = [$item->getType(), $item->getID()];
        };
    }
    $PLUGIN_HOOKS['item_update']['orm_import_fixture'][Profile::class] = static function () use (&$profileUpdates): void {
        ++$profileUpdates;
    };
    foreach (['source', 'Sourcé', 'Source '] as $equivalent) {
        $connection->update($appliance->getName(), ['externalid' => 'Source'], ['id' => $applianceIds[0]]);
        $connection->update($appliance->getName(), ['externalid' => $equivalent], ['id' => $applianceIds[1]]);
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            rejected(fn () => $importer->import(), 'unique collision');
            verify($created === [] && $counts() === $before, 'Destination collation rejects equivalent incoming external IDs before creation hooks');
        } else {
            verify($importer->plan()->counts['appliances'] === 3, 'PostgreSQL distinct external IDs follow the destination collation');
        }
    }
    foreach ([0, 1] as $offset) {
        $connection->update($appliance->getName(), ['externalid' => 'source-appliance-' . $offset], ['id' => $applianceIds[$offset]]);
    }
    $sessionBeforeImport = $_SESSION;
    rejected(fn () => $importer->import(static function (string $event, string $table, int $id): void {
        if ($table === 'glpi_appliances') {
            throw new RuntimeException('Injected interrupted appliance import');
        }
    }), 'Injected interrupted');
    verify($counts() === $before && Ledger::state($connection, AppliancePluginImport::RECEIPT) === null, 'Mid-import failure rolls back owners, hooks audit rows and receipt');
    verify($_SESSION === $sessionBeforeImport && $CFG_GLPI['auto_create_infocoms'] === true, 'Mid-import failure restores session and configuration');
    $created = [];
    $profileUpdates = 0;
    rejected(fn () => $importer->import(static function (string $event): void {
        if ($event === 'complete') {
            throw new RuntimeException('Injected failure after import receipt');
        }
    }), 'Injected failure after');
    verify($counts() === $before && Ledger::state($connection, AppliancePluginImport::RECEIPT) === null, 'Failure after receipt save rolls back the full adoption');
    verify($_SESSION === $sessionBeforeImport && $CFG_GLPI['auto_create_infocoms'] === true, 'Completed-stage failure restores active-profile session effects');
    $created = [];
    $profileUpdates = 0;
    $failedCreation = null;
    $PLUGIN_HOOKS['pre_item_add']['orm_import_fixture'][Appliance::class] = static function ($item) use (&$failedCreation): void {
        $failedCreation = $item;
        ++$item->input['id'];
    };
    rejected(fn () => $importer->import(), 'changed the assigned identity');
    verify($counts() === $before, 'Hook identity rewrite refuses before persisting its appliance and rolls back earlier owners');
    verify((new ReflectionProperty(CommonDBTM::class, 'assignedIdentifier'))->getValue($failedCreation) === null, 'Failed assigned-ID creation restores its scoped clone bypass');
    $PLUGIN_HOOKS['pre_item_add']['orm_import_fixture'][Appliance::class] = $applianceInputHook;
    $created = [];
    $profileUpdates = 0;
    rejected(fn () => $importer->import(static function (string $event, string $table) use ($fixtures, $typeId): void {
        if ($event === 'created' && $table === 'glpi_appliancetypes') {
            $fixtures->create('glpi_applianceenvironments', ['id' => $typeId + 50, 'name' => 'Late ID collision']);
        }
    }), 'Assigned-ID collision');
    verify($counts() === $before, 'Late occupied ID is never interpreted as a clone and the transaction rolls back');

    $created = [];
    $profileUpdates = 0;
    verify($command->execute(['--no-interaction' => true]) === 0, 'Actual CLI completes the canonical import: ' . $command->getDisplay());
    verify(count($created) === 16 && $profileUpdates === 1, 'Every created aggregate record executes add hooks once; profile update hooks execute once; actual ' . count($created) . '/' . $profileUpdates);
    verify($CFG_GLPI['auto_create_infocoms'] === true, 'Successful import restores source Infocom configuration');
    $em = Orm::create($DB);
    $records = new RecordRepository($em);
    $imported = $records->find('glpi_appliances', 'id', $applianceIds[0]);
    verify($imported['id'] === $applianceIds[0] && $imported['users_id_tech'] === $technician && $imported['groups_id'] === $group && $imported['groups_id_tech'] === $group, 'Wide IDs and technician/group ownership survive import');
    verify(!$imported['is_helpdesk_visible'] && $imported['is_recursive'] && $imported['manufacturers_id'] === null && $imported['users_id'] === null, 'Real booleans and legacy empty selections retain canonical semantics');
    verify($imported['date_mod'] === '2030-07-14 22:45:06', 'Original modification timestamp is preserved');
    verify($imported['name'] === "Imported appliance 日本語 O'Reilly C:\\new 0", 'Quotes, literal backslashes and UTF-8 survive the model input boundary');
    $emptyExternal = $records->find('glpi_appliances', 'id', $applianceIds[2]);
    verify($emptyExternal['name'] === 'NULL' && $emptyExternal['externalidentifier'] === null && $emptyExternal['date_mod'] === null, 'Literal NULL name differs from empty external identity and historical zero date');
    verify($records->find('glpi_applianceenvironments', 'id', $environmentId)['comment'] === 'null', 'Literal null text remains text');
    verify($records->find('glpi_appliances', 'id', $unrelated)['name'] === 'Unrelated core appliance', 'Unrelated core records survive');
    foreach (['glpi_infocoms' => $infocom, 'glpi_knowbaseitems_items' => $knowledge, 'glpi_notepads' => $notepad] as $table => $id) {
        verify($records->find($table, 'id', $id)['itemtype'] === 'Appliance', 'Existing live binding IDs retain their subject through adoption: ' . $table);
    }
    $audit = $records->find('glpi_logs', 'id', $history);
    verify($audit['itemtype'] === 'Appliance' && $audit['itemtype_link'] === 'Appliance' && $audit['old_value'] === 'Original audit C:\\new 日本語'
        && $audit['new_value'] === "O'Reilly" && $audit['date_mod'] === '2030-07-14 10:20:30', 'Both audit roles change without rewriting original audit values or date');
    verify($records->find('glpi_logs', 'id', $retiredHistory)['itemtype'] === PluginApplianceSource::ITEMTYPE, 'Retired audit subjects retain their origin instead of aliasing unrelated core appliance IDs');
    verify($records->countMatching('glpi_logs', ['itemtype' => 'Appliance', 'items_id' => $unrelated]) === 0, 'Unrelated appliance timeline does not acquire retired plugin history');
    verify($records->find('glpi_logs', 'id', $linkedHistory)['itemtype_link'] === 'Appliance'
        && $records->find('glpi_logs', 'id', $linkedHistory)['old_value'] === 'Original linked-object name (987654321)', 'Standalone linked-role class labels convert without rewriting historical display values');
    $expectedTypes = $profileTypes;
    $expectedTypes[1] = 'Appliance';
    verify(importArrayFromDB($records->find('glpi_profiles', 'id', $profile)['helpdesk_item_type']) === $expectedTypes
        && $_SESSION['glpiactiveprofile']['helpdesk_item_type'] === $expectedTypes, 'Exact encoded profile entries and active-session refresh preserve keys, Unicode, backslashes and embedded-name substrings');
    $receipt = Ledger::state($connection, AppliancePluginImport::RECEIPT);
    verify($receipt['complete'] && $receipt['fingerprint'] === $plan->fingerprint, 'Import provenance reuses the existing ledger without changing canonical history');
    $em->clear();
    $after = $counts();
    $hookCount = count($created);
    $connection->update('glpi_appliances', ['name' => 'Later application edit'], ['id' => $applianceIds[0]]);
    verify($command->execute(['--no-interaction' => true]) === 0 && $counts() === $after && count($created) === $hookCount && $profileUpdates === 1, 'Exact retry is a no-op without duplicate hooks or audit rows');
    verify($connection->fetchOne('SELECT name FROM glpi_appliances WHERE id = ?', [$applianceIds[0]]) === 'Later application edit', 'Receipt preserves later user changes');
    $connection->update($appliance->getName(), ['name' => 'Changed source'], ['id' => $applianceIds[0]]);
    rejected(fn () => $importer->import(), 'differs from its completed import receipt');
    verify($counts() === $after, 'Changed source cannot silently overwrite owned application records');
    unset($PLUGIN_HOOKS['item_add']['orm_import_fixture']);
    $next = (new Appliance())->add(['name' => 'After assigned import', 'entities_id' => $entity]);
    verify($next > max($applianceIds), 'Actual application insert uses synchronized sequence allocation after wide assigned IDs');
    verify($records->countMatching('glpi_infocoms', ['itemtype' => 'Appliance', 'items_id' => $next]) === 1, 'Restored automatic financial creation applies to subsequent ordinary application inserts');
} finally {
    if ($transaction) {
        $DB->rollBack();
    }
    foreach (array_reverse($sourceTables) as $table) {
        $manager->dropTable($table);
    }
    if ($ledgerBackup !== null && $manager->tablesExist([$ledgerBackup])) {
        if ($manager->tablesExist(['itsmng_migrations'])) {
            $manager->dropTable('itsmng_migrations');
        }
        $manager->renameTable($ledgerBackup, 'itsmng_migrations');
    }
    if ($savedStorageEngine !== null) {
        $connection->executeStatement('SET SESSION default_storage_engine = ?', [$savedStorageEngine]);
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $pluginProperty->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": canonical appliance plugin plan, non-destructive import, owned graph, lifecycle hooks, encoded profiles, audit identity adoption, transactional ledger, atomic rollback, receipt idempotency and sequences passed.\n";
