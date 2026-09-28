<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/software.php /path/to/test-config\n");
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
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SoftwareRepository;

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $repo = fn (): SoftwareRepository => new SoftwareRepository(Orm::create($DB));
    $otherEntity = (new Entity())->add(['name' => 'Software hidden entity', 'entities_id' => 0]);
    verify((bool)$otherEntity, 'Create foreign entity');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $target = $fixtures->create('glpi_softwares', ['name' => 'Merge destination']);
    $source = $fixtures->create('glpi_softwares', ['name' => 'Merge source', 'entities_id' => $otherEntity]);
    $emptySource = $fixtures->create('glpi_softwares', ['name' => 'Empty source']);
    $state = $fixtures->create('glpi_states', ['name' => 'Published']);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => "O'Reilly 日本語", 'states_id' => $state]);
    $duplicate = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => "O'Reilly 日本語"]);
    $incoming = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => "O'Reilly 日本語"]);
    $unique = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => 'Unique', 'entities_id' => $otherEntity]);
    $rows = $repo()->versions($target);
    verify(array_column($rows, 'id') === [$version, $duplicate] && $rows[0]['sname'] === 'Published', 'Version labels and deterministic tie order');
    verify(array_column($repo()->versions($target, [$version]), 'id') === [$duplicate], 'Used versions excluded');
    verify($repo()->versions($emptySource) === [], 'Empty software has no versions');

    $nullTarget = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => null]);
    $nullSource = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => null]);
    $literalTarget = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => 'NULL']);
    $literalSource = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => 'NULL']);
    $literalLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'softwareversions_id_buy' => $literalSource]);
    $nullLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'softwareversions_id_buy' => $nullSource]);
    $dropdown = SoftwareVersion::dropdownForOneSoftware(['softwares_id' => $target, 'used' => [$duplicate], 'display' => false]);
    verify(str_contains($dropdown, 'Published') && str_contains($dropdown, '日本語'), 'Public version dropdown includes mapped status and Unicode name');

    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'number' => 3, 'softwareversions_id_buy' => $incoming, 'softwareversions_id_use' => $incoming]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'number' => 2]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'number' => 99, 'is_template' => true]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'number' => -1, 'entities_id' => $otherEntity]);
    verify(SoftwareLicense::countForSoftware($source) === 5, 'Finite total excludes templates and inaccessible unlimited licenses');
    verify(SoftwareLicense::countForVersion($incoming, 0) === 1, 'Version license count');
    verify($repo()->licenseQuantity($emptySource, []) === 0, 'Empty license sum is zero');
    verify($repo()->licenseQuantity($source, []) === -1, 'Accessible unlimited license takes precedence');
    $asset = $fixtures->create('glpi_computers', ['name' => 'Installed asset']);
    $otherAsset = $fixtures->create('glpi_computers', ['name' => 'Other installed asset']);
    $existingLink = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $version, 'itemtype' => 'Computer', 'items_id' => $asset, 'date_install' => '2024-01-01', 'is_dynamic' => false]);
    $collidingLink = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $incoming, 'itemtype' => 'Computer', 'items_id' => $asset, 'date_install' => '2025-02-02', 'is_dynamic' => true]);
    $movedLink = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $incoming, 'itemtype' => 'Computer', 'items_id' => $otherAsset, 'date_install' => '2025-03-03', 'is_dynamic' => true]);
    $assignedLicense = $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $license, 'itemtype' => 'Computer', 'items_id' => $asset]);
    $installed = new Item_SoftwareVersion();
    verify($installed->updateDatasForItem('Computer', $asset), 'Application flag synchronization');
    $repo()->updateAssetFlags('Monitor', $asset, true, true);
    verify($read('glpi_items_softwareversions', $existingLink)['is_deleted_item'] === 0, 'Flag synchronization scopes polymorphic type');
    $repo()->updateAssetFlags('Computer', $asset, true, true);
    verify($read('glpi_items_softwareversions', $existingLink)['is_template_item'] === 1, 'Boolean template flag');
    verify($read('glpi_items_softwareversions', $existingLink)['is_deleted_item'] === 1, 'Boolean deletion flag');
    $installed->updateDatasForItem('Computer', $asset);

    // Fail after all relationship changes and one lifecycle write, then verify rollback.
    $rejected = false;
    try {
        $repo()->merge($target, 0, [$source, $emptySource], static function (int $id) use ($source): bool {
            if ($id === $source) {
                return (new Software())->putInTrash($id, 'Rollback candidate');
            }
            return false;
        });
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Unable to trash software after merging.', 'Expected lifecycle rejection');
        $rejected = true;
    }
    verify($rejected && $DB->inTransaction(), 'Failed merge retains caller transaction');
    verify($read('glpi_softwareversions', $incoming) !== null && $read('glpi_items_softwareversions', $collidingLink) !== null, 'Rollback restores deleted version and duplicate installation');
    verify((int)$read('glpi_items_softwareversions', $movedLink)['softwareversions_id'] === $incoming, 'Rollback restores moved installation');
    verify((int)$read('glpi_softwarelicenses', $license)['softwares_id'] === $source, 'Rollback restores license ownership');
    verify($read('glpi_softwares', $source)['is_deleted'] === 0, 'Rollback restores source lifecycle write');

    $software = new Software();
    verify($software->getFromDB($target), 'Load merge destination');
    verify($software->merge([$source => 1, $emptySource => 1, $target => 1], false), 'Application merge with duplicate installations and self selection');
    verify($read('glpi_softwareversions', $incoming) === null, 'Matching source version removed');
    verify($read('glpi_items_softwareversions', $collidingLink) === null, 'Collision removed before reassignment');
    $existing = $read('glpi_items_softwareversions', $existingLink);
    verify($existing['date_install'] === '2024-01-01' && $existing['is_dynamic'] === 0, 'Destination installation metadata retained');
    $moved = $read('glpi_items_softwareversions', $movedLink);
    verify((int)$moved['softwareversions_id'] === $version && $moved['date_install'] === '2025-03-03' && $moved['is_dynamic'] === 1, 'Nonduplicate installation moved with metadata');
    $movedVersion = $read('glpi_softwareversions', $unique);
    verify((int)$movedVersion['softwares_id'] === $target && (int)$movedVersion['entities_id'] === 0, 'Unique version adopts destination and entity');
    $movedLicense = $read('glpi_softwarelicenses', $license);
    verify((int)$movedLicense['softwares_id'] === $target && (int)$movedLicense['softwareversions_id_buy'] === $version && (int)$movedLicense['softwareversions_id_use'] === $version, 'License ownership and buy/use versions updated');
    verify($read('glpi_softwareversions', $nullSource) === null && $read('glpi_softwareversions', $literalSource) === null, 'Empty and literal NULL version names merge separately');
    verify((int)$read('glpi_softwarelicenses', $literalLicense)['softwareversions_id_buy'] === $literalTarget, 'Literal NULL name is not interpreted as a NULL predicate');
    verify((int)$read('glpi_softwarelicenses', $nullLicense)['softwareversions_id_buy'] === $nullTarget, 'NULL name matches an unnamed destination version');
    ob_start();
    SoftwareVersion::showForSoftware($software);
    $versionHtml = ob_get_clean();
    verify(str_contains($versionHtml, 'Unique') && str_contains($versionHtml, 'Published'), 'Public version tab renders mapped rows');
    verify($read('glpi_softwares', $source)['is_deleted'] === 1 && $read('glpi_softwares', $emptySource)['is_deleted'] === 1, 'Sources trashed after merge');
    verify($software->merge([], false), 'Empty merge is harmless');
    verify($software->merge([$target => 1], false), 'Self-only merge is harmless');
    verify($read('glpi_softwares', $target)['is_deleted'] === 0, 'Self selection cannot trash destination');
    verify($read('glpi_softwareversions', $duplicate) !== null, 'Only one deterministic matching destination chosen');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo()->versions($target);
    SoftwareLicense::countForSoftware($target);
    SoftwareLicense::countForVersion($version);
    $installed->updateDatasForItem('Computer', $asset);
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped software queries bypass legacy execution');

    // Purging a software must purge its licenses, including previously trashed ones.
    $trashed = new SoftwareLicense();
    verify($trashed->delete(['id' => $license]), 'Trash license before parent purge');
    verify($software->delete(['id' => $target], true), 'Purge software under restrictive FKs');
    verify($read('glpi_softwarelicenses', $license) === null && $read('glpi_items_softwarelicenses', $assignedLicense) === null, 'License and its assignment purged');
    verify($read('glpi_softwareversions', $version) === null && $read('glpi_items_softwareversions', $existingLink) === null, 'Version and its installation purged');
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned relationships');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": software associations, quantities, version lists, booleans, atomic merge and purge passed.\n";
