<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Separate contract keeps the original three-family 300-second gate distinct.
// The same real reconstruction/native/refusal/retry assertions apply to the
// energy subjects, with Battery date payload and the joint21/22 preflight.
$componentSchemaFamilies = [
    [Item_DeviceBattery::class, \itsmng\Database\Migration\V220\BatterySubjects::class, ['manufacturing_date' => '2020-02-29']],
    [Item_DevicePowerSupply::class, \itsmng\Database\Migration\V220\PowerSupplySubjects::class, []],
];
$componentSchemaExtensionProbe = static function ($connection, string $table, string $deviceColumn, int $device, int $subject, object $migration, string $linkClass): void {
    global $CFG_GLPI;

    // An actual registered plugin class may use the same persisted asset table.
    // Its valid identity is still a distinct discriminator, not an orphan.
    if (!class_exists('PluginEnergyFixtureComputer', false)) {
        class PluginEnergyFixtureComputer extends Computer
        {
            public static function getTable($classname = null)
            {
                return Computer::getTable(Computer::class);
            }
        }
    }
    $configuration = $CFG_GLPI;
    $id = 4294996291;
    $primary = $cleanup = null;
    $created = false;
    try {
        verify(Plugin::registerClass(PluginEnergyFixtureComputer::class, [
            'itemdevices_types' => true, 'itemdevicebattery_types' => true, 'itemdevicepowersupply_types' => true,
        ]), 'The actual Plugin registration API accepts both previously extensible energy affinity keys');
        verify(in_array(PluginEnergyFixtureComputer::class, $CFG_GLPI['itemdevicebattery_types'], true)
            && in_array(PluginEnergyFixtureComputer::class, $CFG_GLPI['itemdevicepowersupply_types'], true),
            'Actual plugin registration retains its configured Battery and PowerSupply affinities');
        $asset = getItemForItemtype(PluginEnergyFixtureComputer::class);
        verify($asset instanceof PluginEnergyFixtureComputer && $asset->getFromDB($subject), 'Unsupported core kind nevertheless has a real persisted public plugin subject');
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE id=?', [$id]) === 0, 'Never adopt an existing plugin source binding');
        $connection->insert($table, ['id' => $id, $deviceColumn => $device, 'itemtype' => $asset->getType(), 'items_id' => $subject, 'serial' => 'Valid plugin original']);
        $created = true;
        $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        $receipts = $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version');
        $manager = $connection->createSchemaManager();
        $schema = $manager->introspectSchema();
        $configurationAfterRegistration = $CFG_GLPI;
        try {
            $migration->apply($connection);
            throw new LogicException('A valid unrepresented plugin subject was silently adopted by the core energy migration');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), $table) && str_contains($error->getMessage(), 'valid plugin subject')
                && str_contains($error->getMessage(), 'Unsupported kinds are not orphan proof'),
                'Core refusal identifies its actual family and the separate valid-plugin owning-mapping boundary');
        }
        verify($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows
            && $connection->fetchAllAssociative('SELECT * FROM itsmng_migrations ORDER BY version') === $receipts
            && $manager->createComparator()->compareSchemas($schema, $manager->introspectSchema())->isEmpty()
            && $CFG_GLPI === $configurationAfterRegistration && $asset->getFromDB($subject),
            'Plugin refusal preserves complete source rows, raw receipts, schema, registration and real subject identity');
        verify(!in_array(PluginEnergyFixtureComputer::class, $linkClass::itemAffinity(), true),
            'The current core owning properties do not claim this configured plugin extension has converged');
    } catch (Throwable $error) {
        $primary = $error;
    } finally {
        try {
            if ($created) {
                $connection->delete($table, ['id' => $id]);
            }
        } catch (Throwable $error) {
            $cleanup = $error;
        }
        $CFG_GLPI = $configuration;
    }
    if ($primary !== null) {
        throw $cleanup === null ? $primary : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup, false);
    }
    if ($cleanup !== null) {
        throw $cleanup;
    }
};
require __DIR__ . '/component-subjects-schema.php';
