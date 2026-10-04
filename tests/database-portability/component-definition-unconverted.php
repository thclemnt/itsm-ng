<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\EntityRegistry;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\ComponentDefinitionRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/component-definition-unconverted.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** A real public model with an explicitly owned, deliberately unmapped plugin layout. */
class PluginDefinitionFixtureAsset extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_definitionfixture_assets';
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable component database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$connection = $DB->getDoctrineConnection();
$DB->assertManagedTransaction();
verify(!$connection->isTransactionActive(), 'Fixture DDL requires the idle configured owner');
$manager = $connection->createSchemaManager();
$name = PluginDefinitionFixtureAsset::getTable();
verify(!isset(EntityRegistry::tables()[$name]) && !$manager->tablesExist([$name]), 'Never adopt an existing plugin table or claim it is mapped');
$schemaNames = $manager->listTableNames();
sort($schemaNames);
$table = new Table($name);
if ($DB->getProvider() === 'mysql') {
    $table->addOption('charset', 'utf8mb4');
    $table->addOption('collation', 'utf8mb4_unicode_ci');
}
$table->addColumn('id', Types::BIGINT);
$table->addColumn('name', Types::STRING, ['length' => 255]);
$table->addColumn('entities_id', Types::BIGINT);
$table->addColumn('is_recursive', Types::BOOLEAN, ['default' => false]);
$table->setPrimaryKey(['id']);
$table->addForeignKeyConstraint('glpi_entities', ['entities_id'], ['id'], ['onDelete' => 'RESTRICT']);
$savedSession = $_SESSION;
$savedHooks = $PLUGIN_HOOKS;
$savedConfig = $CFG_GLPI;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$tables = ['glpi_devicegenerics', 'glpi_devicepcis', 'glpi_items_devicegenerics', 'glpi_items_devicepcis',
    'glpi_logs', 'glpi_queuednotifications', 'itsmng_migrations'];
$snapshot = static function () use ($connection, $tables): array {
    $rows = [];
    foreach ($tables as $tableName) {
        $rows[$tableName] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($tableName)
            . ' ORDER BY ' . $connection->quoteIdentifier($tableName === 'itsmng_migrations' ? 'version' : 'id'));
    }
    return $rows;
};
$baseline = $snapshot();
$created = false;
$frame = null;
$primary = null;
$cleanup = [];
try {
    $manager->createTable($table);
    $created = true;
    $frame = OwnedMutationFrame::begin($connection);
    $connection->insert(
        $name,
        ['id' => 42041, 'name' => 'Unmapped plugin subject', 'entities_id' => 0, 'is_recursive' => false],
        ['id' => Types::BIGINT, 'entities_id' => Types::BIGINT, 'is_recursive' => Types::BOOLEAN]
    );
    $asset = getItemForItemtype(PluginDefinitionFixtureAsset::class);
    verify($asset instanceof PluginDefinitionFixtureAsset && $asset->getFromDB(42041), 'Actual plugin subject uses its unchanged public unmapped read');
    $pluginRows = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($name));
    $fixtures = new FixtureRecords($DB);
    $plugins->setValue(null, [...$savedPlugins, 'definition_fixture']);
    $CFG_GLPI['itemdevices_types'][] = PluginDefinitionFixtureAsset::class;
    foreach ([Item_DeviceGeneric::class, Item_DevicePci::class] as $linkClass) {
        $link = new $linkClass();
        $deviceClass = $linkClass::getDeviceType();
        $deviceTable = $deviceClass::getTable();
        $column = $linkClass::getDeviceForeignKey();
        verify(in_array('*', $linkClass::itemAffinity(), true)
            && !ComponentDefinitionRepository::supportsFamily($link, $deviceTable), 'An open subject family retains its existing lifecycle dispatch: ' . $linkClass);
        $source = $fixtures->create($deviceTable);
        $target = $fixtures->create($deviceTable);
        $id = $fixtures->create($link->getTable(), [$column => $source, 'itemtype' => PluginDefinitionFixtureAsset::class,
            'items_id' => 42041, 'serial' => 'Exact plugin binding']);
        $neighbor = $fixtures->create($link->getTable(), [$column => $target, 'itemtype' => PluginDefinitionFixtureAsset::class, 'items_id' => 42041]);
        $read = static fn (int $id): array => $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($link->getTable()) . ' WHERE id = ?', [$id]);
        $before = $read($id);
        $neighborBefore = $read($neighbor);
        $callbacks = 0;
        $PLUGIN_HOOKS['item_update']['definition_fixture'][$linkClass] = static function (Item_Devices $model) use ($id, $target, $column, &$callbacks): void {
            if ((int)$model->getID() !== $id) {
                return;
            }
            $subject = getItemForItemtype($model->fields['itemtype']);
            verify($subject instanceof PluginDefinitionFixtureAsset && $subject->getFromDB($model->fields['items_id'])
                && (int)$model->fields[$column] === $target, 'Unconverted replacement retains real public plugin reads and completion hooks');
            ++$callbacks;
        };
        $owner = new $deviceClass();
        verify($owner->getFromDB($source) && $owner->delete(['id' => $source, '_replace_by' => $target], true), 'Actual public definition replacement accepts the existing unmapped subject: ' . $linkClass);
        $expected = $before;
        $expected[$column] = $target;
        verify(
            $callbacks === 1 && $read($id) === $expected && $read($neighbor) === $neighborBefore,
            'Exactly one ordinary update changes only the definition FK and preserves all subject/cache/specificity cells'
        );
        verify(
            $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($name)) === $pluginRows,
            'Definition substitution preserves every unmapped plugin row'
        );
        $PLUGIN_HOOKS = $savedHooks;
    }
    verify(
        ComponentDefinitionRepository::supportsFamily(new Item_DeviceProcessor(), DeviceProcessor::getTable())
        && !ComponentDefinitionRepository::supportsFamily(new Item_DeviceProcessor(), DeviceGeneric::getTable()),
        'Closed Processor delegation requires its actual owning definition target, independently of row values'
    );
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($frame !== null) {
        try {
            $frame->rollBack();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    $_SESSION = $savedSession;
    $PLUGIN_HOOKS = $savedHooks;
    $CFG_GLPI = $savedConfig;
    $plugins->setValue(null, $savedPlugins);
    if ($created && !$connection->isTransactionActive()) {
        try {
            $manager->dropTable($name);
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
}
if ($primary !== null) {
    throw $primary;
}
if ($cleanup) {
    throw $cleanup[0];
}
$afterNames = $manager->listTableNames();
sort($afterNames);
verify($snapshot() === $baseline && $afterNames === $schemaNames, 'Owned fixture restores original core rows/ledger and table inventory');
echo $DB->getProvider() . ": metadata-owned closed component dispatch and unconverted plugin lifecycle passed.\n";
