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

    $transferSource = $fixtures->create('glpi_softwares', ['name' => 'Transfer source', 'entities_id' => $otherEntity]);
    $transferOther = $fixtures->create('glpi_softwares', ['name' => 'Transfer other']);
    $transferVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $transferSource, 'name' => 'Transfer version']);
    $transferTemplate = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $transferSource, 'is_template' => true]);
    $transferDeleted = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $transferSource, 'is_deleted' => true]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $transferOther]);
    $fixtures->create('glpi_softwareversions', ['softwares_id' => $transferOther]);
    $transfer = new class () extends Transfer {
        public array $calls = [];
        public ?Closure $duringTransfer = null;

        public function transferItem($itemtype, $ID, $newID)
        {
            $this->calls[] = [$itemtype, $ID, $newID];
            if ($this->duringTransfer !== null) {
                ($this->duringTransfer)($ID);
            }
        }
    };
    $SQL_TOTAL_REQUEST = 0;
    $transfer->transferSoftwareLicensesAndVersions($transferSource);
    verify($transfer->calls === [['SoftwareLicense', $transferTemplate, $transferTemplate], ['SoftwareLicense', $transferDeleted, $transferDeleted]], 'Transfer discovers all owned licenses including templates, trash and foreign entity rows');
    verify($transfer->already_transfer['SoftwareVersion'] === [$transferVersion => $transferVersion], 'Transfer records only the source software versions');
    verify($SQL_TOTAL_REQUEST === 0, 'Public transfer discovery bypasses legacy adapter execution');
    $transfer->calls = [];
    $transfer->already_transfer = [];
    $transfer->duringTransfer = static function (int $id) use ($DB, $transferTemplate, $transferDeleted, $transferVersion, $transferOther): void {
        if ($id === $transferTemplate) {
            verify((new SoftwareLicense())->delete(['id' => $transferDeleted], true), 'License callback mutates transfer candidates');
            $em = Orm::create($DB);
            $version = $em->find(\itsmng\Database\Entity\SoftwareVersion::class, $transferVersion);
            $version->softwares = $em->getReference(\itsmng\Database\Entity\Software::class, $transferOther);
            $em->flush();
        }
    };
    $transfer->transferSoftwareLicensesAndVersions($transferSource);
    verify(count($transfer->calls) === 2, 'Transfer snapshots license IDs before callbacks mutate the selected rows');
    verify(empty($transfer->already_transfer['SoftwareVersion']), 'Transfer discovers versions after the license callbacks finish');

    $cleanupSoftware = $fixtures->create('glpi_softwares', ['name' => 'Cleanup versions']);
    $unusedVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $boughtVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $usedVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $installedVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $transferOther, 'softwareversions_id_buy' => $boughtVersion, 'is_template' => true]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $transferOther, 'softwareversions_id_use' => $usedVersion, 'is_deleted' => true]);
    $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $installedVersion, 'itemtype' => 'Computer', 'items_id' => $asset, 'is_deleted' => true]);
    $SQL_TOTAL_REQUEST = 0;
    verify(!$repo()->isVersionReferenced($unusedVersion) && $repo()->isVersionReferenced($boughtVersion)
        && $repo()->isVersionReferenced($usedVersion) && $repo()->isVersionReferenced($installedVersion), 'Cleanup retains buy/use and deleted installation references globally');
    verify($repo()->hasInventory($cleanupSoftware) && $repo()->hasInventory($transferSource), 'Cleanup sees both version-only and license-only software');
    verify($SQL_TOTAL_REQUEST === 0, 'Cleanup relationship queries bypass legacy adapter execution');
    $cleanup = new Transfer();
    $cleanup->already_transfer['SoftwareVersion'] = array_combine([$unusedVersion, $boughtVersion, $usedVersion, $installedVersion], [$unusedVersion, $boughtVersion, $usedVersion, $installedVersion]);
    $cleanup->cleanSoftwareVersions();
    verify($read('glpi_softwareversions', $unusedVersion) === null && $read('glpi_softwareversions', $boughtVersion) !== null
        && $read('glpi_softwareversions', $usedVersion) !== null && $read('glpi_softwareversions', $installedVersion) !== null, 'Public version cleanup removes only the unreferenced version');
    $emptySoftware = $fixtures->create('glpi_softwares', ['name' => 'Cleanup empty']);
    $cleanup->already_transfer['Software'] = [$emptySoftware => $emptySoftware, $cleanupSoftware => $cleanupSoftware, $transferSource => $transferSource];
    $cleanup->options = ['clean_software' => 0];
    $cleanup->cleanSoftwares();
    verify($read('glpi_softwares', $emptySoftware)['is_deleted'] === 0, 'Keep option preserves empty software');
    $cleanup->options['clean_software'] = 1;
    $cleanup->cleanSoftwares();
    verify($read('glpi_softwares', $emptySoftware)['is_deleted'] === 1 && $read('glpi_softwares', $cleanupSoftware)['is_deleted'] === 0
        && $read('glpi_softwares', $transferSource)['is_deleted'] === 0, 'Trash option applies only to software without inventory');
    $cleanup->options['clean_software'] = 2;
    $cleanup->cleanSoftwares();
    verify($read('glpi_softwares', $emptySoftware) === null && $read('glpi_softwares', $cleanupSoftware) !== null
        && $read('glpi_softwares', $transferSource) !== null, 'Purge option uses the application lifecycle and preserves referenced software');

    $transferInstallations = new class () extends Transfer {
        public array $versions = [];
        public array $licenses = [];

        public function copySingleVersion($ID)
        {
            return $this->versions[$ID] ?? $ID;
        }

        public function transferAffectedLicense($ID)
        {
            $this->licenses[] = $ID;
        }
    };
    $installationSource = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $installationTarget = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $installationExcluded = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $installationRejected = $fixtures->create('glpi_softwareversions', ['softwares_id' => $cleanupSoftware]);
    $overlappingMonitor = $fixtures->create('glpi_monitors', ['id' => $otherAsset, 'name' => 'Transfer type overlap']);
    $moveInstallation = $fixtures->create('glpi_items_softwareversions', ['itemtype' => 'Computer', 'items_id' => $otherAsset,
        'softwareversions_id' => $installationSource, 'date_install' => '2023-03-21', 'is_deleted' => true, 'is_dynamic' => true, 'is_template_item' => true]);
    $excludeInstallation = $fixtures->create('glpi_items_softwareversions', ['itemtype' => 'Computer', 'items_id' => $otherAsset, 'softwareversions_id' => $installationExcluded]);
    $rejectInstallation = $fixtures->create('glpi_items_softwareversions', ['itemtype' => 'Computer', 'items_id' => $otherAsset, 'softwareversions_id' => $installationRejected]);
    $monitorInstallation = $fixtures->create('glpi_items_softwareversions', ['itemtype' => 'Monitor', 'items_id' => $overlappingMonitor, 'softwareversions_id' => $installationSource]);
    $computerLicense = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Computer', 'items_id' => $otherAsset, 'softwarelicenses_id' => $transferTemplate]);
    $monitorLicense = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $overlappingMonitor, 'softwarelicenses_id' => $transferTemplate]);
    $transferInstallations->options = ['keep_software' => 1];
    $transferInstallations->noneedtobe_transfer['SoftwareVersion'] = [900000000 => $installationExcluded];
    $transferInstallations->versions = [$installationSource => $installationTarget, $installationRejected => -1];
    $transferLifecycleRows = static fn (string $table): array => (new RecordRepository(Orm::create($DB)))->matching($table, [], 'id ASC');
    $transferScope = static function () use ($read, $transferLifecycleRows, $moveInstallation, $excludeInstallation, $rejectInstallation, $monitorInstallation, $computerLicense, $monitorLicense, $installationSource, $installationTarget, $installationExcluded, $installationRejected, $transferTemplate): array {
        return [
            $read('glpi_items_softwareversions', $moveInstallation), $read('glpi_items_softwareversions', $excludeInstallation),
            $read('glpi_items_softwareversions', $rejectInstallation), $read('glpi_items_softwareversions', $monitorInstallation),
            $read('glpi_items_softwarelicenses', $computerLicense), $read('glpi_items_softwarelicenses', $monitorLicense),
            $read('glpi_softwareversions', $installationSource), $read('glpi_softwareversions', $installationTarget),
            $read('glpi_softwareversions', $installationExcluded), $read('glpi_softwareversions', $installationRejected),
            $read('glpi_softwarelicenses', $transferTemplate), $transferLifecycleRows('glpi_logs'), $transferLifecycleRows('glpi_queuednotifications'),
        ];
    };
    $softwareTransferHooks = $PLUGIN_HOOKS;
    $softwareTransferPluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
    $softwareTransferPlugins = $softwareTransferPluginProperty->getValue();
    $softwareTransferPluginProperty->setValue(null, [...$softwareTransferPlugins, 'software_transfer_lifecycle_fixture']);
    $lifecycleCalls = [];
    foreach (['pre_item_update', 'item_update', 'pre_item_purge', 'item_purge'] as $event) {
        foreach ([Item_SoftwareVersion::class, Item_SoftwareLicense::class] as $kind) {
            $PLUGIN_HOOKS[$event]['software_transfer_lifecycle_fixture'][$kind] = static function (CommonDBTM $item) use ($event, $kind, $moveInstallation, &$lifecycleCalls): void {
                $lifecycleCalls[$event][$kind][] = (int)$item->getID();
                if ($event === 'pre_item_update' && $kind === Item_SoftwareVersion::class && (int)$item->getID() === $moveInstallation) {
                    // The raw fixture deliberately has a cached template flag
                    // differing from the live subject. Explicit same-owner flag
                    // synchronization is supported; version-only retarget must
                    // preserve this supplied cache and the installation's lock.
                    $item->input['is_template_item'] = $item->fields['is_template_item'];
                    $item->input['is_deleted_item'] = $item->fields['is_deleted_item'];
                }
            };
        }
    }
    try {
        $beforeRejectedCopy = $transferScope();
        $level = $DB->getDoctrineConnection()->getTransactionNestingLevel();
        verify($transferInstallations->transferItemSoftwares('Computer', $otherAsset) === false, 'A required negative copy identity refuses the actual direct installation transfer');
        verify($transferScope() === $beforeRejectedCopy && $transferInstallations->licenses === [], 'Rejected copy restores earlier installation retarget, complete selected/control scope, history and queue');
        verify(($lifecycleCalls['pre_item_update'][Item_SoftwareVersion::class] ?? []) === [$moveInstallation]
            && ($lifecycleCalls['item_update'][Item_SoftwareVersion::class] ?? []) === [$moveInstallation], 'Rejected later copy follows an actual earlier public installation lifecycle');
        verify($DB->getDoctrineConnection()->getTransactionNestingLevel() === $level, 'Copy refusal retains the caller transaction');

        $transferInstallations->versions[$installationRejected] = $installationRejected;
        $lifecycleCalls = [];
        $beforeKeepHistory = $transferLifecycleRows('glpi_logs');
        verify($transferInstallations->transferItemSoftwares('Computer', $otherAsset) !== false, 'All required positive copy identities allow the actual direct transfer');
        verify(($lifecycleCalls['pre_item_update'][Item_SoftwareVersion::class] ?? []) === [$moveInstallation]
            && ($lifecycleCalls['item_update'][Item_SoftwareVersion::class] ?? []) === [$moveInstallation], 'Retarget invokes the actual public installation pre/post update hooks once');
        verify($transferLifecycleRows('glpi_logs') !== $beforeKeepHistory, 'Accepted public installation retarget records relation history');
        verify($transferInstallations->licenses === [$computerLicense], 'Transfer visits only the selected asset type license assignments');
        $movedInstallation = $read('glpi_items_softwareversions', $moveInstallation);
        verify((int)$movedInstallation['softwareversions_id'] === $installationTarget && $movedInstallation['date_install'] === '2023-03-21'
            && $movedInstallation['is_deleted'] === 1 && $movedInstallation['is_dynamic'] === 1 && $movedInstallation['is_template_item'] === 1, 'Retarget retains installation date, lock, dynamic state and explicit same-owner cached flags');
        verify((int)$read('glpi_items_softwareversions', $excludeInstallation)['softwareversions_id'] === $installationExcluded
            && (int)$read('glpi_items_softwareversions', $rejectInstallation)['softwareversions_id'] === $installationRejected, 'Excluded and accepted same-version copies retain their original installations');
        verify((int)$read('glpi_items_softwareversions', $monitorInstallation)['softwareversions_id'] === $installationSource, 'Transfer preserves another type with the same numeric asset ID');
        $transferInstallations->options['keep_software'] = 0;
        $lifecycleCalls = [];
        $beforeDiscardHistory = $transferLifecycleRows('glpi_logs');
        verify($transferInstallations->transferItemSoftwares('Computer', $otherAsset) !== false, 'Actual public discard succeeds');
        foreach (['pre_item_purge', 'item_purge'] as $event) {
            $purged = $lifecycleCalls[$event][Item_SoftwareVersion::class] ?? [];
            sort($purged);
            $expectedPurged = [$moveInstallation, $rejectInstallation];
            sort($expectedPurged);
            verify($purged === $expectedPurged && ($lifecycleCalls[$event][Item_SoftwareLicense::class] ?? []) === [$computerLicense], 'Discard invokes actual public installation/licence ' . $event . ' hooks only for selected links');
        }
        verify($transferLifecycleRows('glpi_logs') !== $beforeDiscardHistory, 'Actual public discard records relation removal history');
        verify($read('glpi_items_softwareversions', $moveInstallation) === null && $read('glpi_items_softwareversions', $rejectInstallation) === null
            && $read('glpi_items_softwareversions', $excludeInstallation) !== null, 'Discard respects the excluded version set');
        verify($read('glpi_items_softwarelicenses', $computerLicense) === null && $read('glpi_items_softwarelicenses', $monitorLicense) !== null
            && $read('glpi_items_softwareversions', $monitorInstallation) !== null, 'Discard removes only the chosen asset type relationships');
        verify($read('glpi_softwareversions', $installationTarget) !== null && $read('glpi_softwarelicenses', $transferTemplate) !== null, 'Discard preserves version and license targets');
    } finally {
        $PLUGIN_HOOKS = $softwareTransferHooks;
        $softwareTransferPluginProperty->setValue(null, $softwareTransferPlugins);
    }

    // Exercise the real copy callbacks, including literal names and owning targets.
    $copyName = "Transfer O'Reilly \\path 日本語 NULL";
    $copyManufacturer = $fixtures->create('glpi_manufacturers', ['name' => 'Transfer manufacturer']);
    $wrongManufacturer = $fixtures->create('glpi_manufacturers', ['name' => 'Other manufacturer']);
    $copySource = $fixtures->create('glpi_softwares', ['name' => $copyName, 'entities_id' => $otherEntity, 'manufacturers_id' => $copyManufacturer]);
    $fixtures->create('glpi_softwares', ['name' => $copyName, 'entities_id' => $otherEntity, 'manufacturers_id' => $copyManufacturer]);
    $unclassifiedDestination = $fixtures->create('glpi_softwares', ['name' => $copyName, 'manufacturers_id' => $wrongManufacturer]);
    $copyDestination = $fixtures->create('glpi_softwares', ['name' => $copyName, 'manufacturers_id' => $copyManufacturer, 'is_template' => true, 'is_deleted' => true]);
    $fixtures->create('glpi_softwares', ['name' => $copyName, 'manufacturers_id' => $copyManufacturer]);
    $copy = new Transfer();
    $copy->to = 0;
    $DB->clearSchemaCache();
    $SQL_TOTAL_REQUEST = 0;
    verify($copy->copySingleSoftware($copySource) === $copyDestination, 'Software reuse matches literal name, destination entity and selected manufacturer including template/trash rows');
    verify($copy->copySingleSoftware($copySource) === $copyDestination, 'Repeated copy uses recorded destination');
    verify($SQL_TOTAL_REQUEST === 0, 'Public software reuse bypasses legacy adapter SQL');
    $unclassifiedSource = $fixtures->create('glpi_softwares', ['name' => $copyName, 'entities_id' => $otherEntity]);
    $unclassified = new Transfer();
    $unclassified->to = 0;
    verify($unclassified->copySingleSoftware($unclassifiedSource) === $unclassifiedDestination, 'Unselected manufacturer preserves unrestricted destination reuse');
    $createSource = $fixtures->create('glpi_softwares', ['name' => $copyName . ' new', 'entities_id' => $otherEntity, 'comment' => "Copied O'Reilly \\path"]);
    $createdSoftware = $copy->copySingleSoftware($createSource);
    verify($createdSoftware > 0 && $createdSoftware !== $createSource && $read('glpi_softwares', $createdSoftware)['name'] === $copyName . ' new'
        && $read('glpi_softwares', $createdSoftware)['comment'] === "Copied O'Reilly \\path" && (int)$read('glpi_softwares', $createdSoftware)['entities_id'] === 0, 'Software copy creates a destination through the public lifecycle with literal data');
    $sourceCopyVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $copySource, 'name' => $copyName]);
    $fixtures->create('glpi_softwareversions', ['softwares_id' => $transferOther, 'name' => $copyName]);
    $targetCopyVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $copyDestination, 'name' => $copyName]);
    $SQL_TOTAL_REQUEST = 0;
    verify($copy->copySingleVersion($sourceCopyVersion) === $targetCopyVersion, 'Version reuse matches literal name and owning destination software');
    verify($SQL_TOTAL_REQUEST === 0, 'Public version reuse bypasses legacy adapter SQL');
    $newSourceVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $createSource, 'name' => $copyName . ' new version']);
    $newTargetVersion = $copy->copySingleVersion($newSourceVersion);
    verify($newTargetVersion > 0 && $newTargetVersion !== $newSourceVersion && (int)$read('glpi_softwareversions', $newTargetVersion)['softwares_id'] === $createdSoftware
        && $read('glpi_softwareversions', $newTargetVersion)['name'] === $copyName . ' new version', 'Version copy creates a destination owned by the copied software');
    $copy->already_transfer['Software'][$transferOther] = $transferOther;
    $unchangedVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $transferOther]);
    verify($copy->copySingleVersion($unchangedVersion) === $unchangedVersion && $copy->copySingleSoftware(2147483647) === -1
        && $copy->copySingleVersion(2147483647) === -1, 'Retained software preserves its version and missing copy targets fail without a mutation');
    $copySerial = "Serial ' \\ 日本語";
    $sourceCopyLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $copySource, 'name' => $copyName, 'serial' => $copySerial, 'number' => 2]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $copyDestination, 'name' => $copyName, 'serial' => 'Different serial', 'number' => 9]);
    $targetCopyLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $copyDestination, 'name' => $copyName, 'serial' => $copySerial, 'number' => 5]);
    $copyAssignment = $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $sourceCopyLicense, 'itemtype' => 'Computer', 'items_id' => $asset]);
    $SQL_TOTAL_REQUEST = 0;
    $copy->transferAffectedLicense($copyAssignment);
    verify((int)$read('glpi_softwarelicenses', $sourceCopyLicense)['number'] === 1 && (int)$read('glpi_softwarelicenses', $targetCopyLicense)['number'] === 6
        && (int)$read('glpi_items_softwarelicenses', $copyAssignment)['softwarelicenses_id'] === $targetCopyLicense, 'Real affected-license transfer decrements source, increments matching name/serial destination and retargets only the assignment');
    verify($SQL_TOTAL_REQUEST === 0, 'Public license reuse and lifecycle updates bypass legacy adapter SQL');
    $lastSourceLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $copySource, 'name' => $copyName, 'serial' => $copySerial, 'number' => 1]);
    $lastAssignment = $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $lastSourceLicense, 'itemtype' => 'Computer', 'items_id' => $otherAsset]);
    $copy->transferAffectedLicense($lastAssignment);
    verify($read('glpi_softwarelicenses', $lastSourceLicense)['is_deleted'] === 1 && (int)$read('glpi_softwarelicenses', $targetCopyLicense)['number'] === 7
        && (int)$read('glpi_items_softwarelicenses', $lastAssignment)['softwarelicenses_id'] === $targetCopyLicense, 'Moving the last license preserves the source trash lifecycle and retargets its assignment');
    $newSourceLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $createSource, 'name' => $copyName . ' new license', 'serial' => $copySerial,
        'number' => 2, 'softwareversions_id_buy' => $newSourceVersion, 'softwareversions_id_use' => $newSourceVersion]);
    $newCopyAssignment = $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $newSourceLicense, 'itemtype' => 'Computer', 'items_id' => $otherAsset]);
    $DB->clearSchemaCache();
    $SQL_TOTAL_REQUEST = 0;
    $copy->transferAffectedLicense($newCopyAssignment);
    $createdLicense = $read('glpi_softwarelicenses', (int)$read('glpi_items_softwarelicenses', $newCopyAssignment)['softwarelicenses_id']);
    verify($createdLicense['id'] !== $newSourceLicense && (int)$createdLicense['softwares_id'] === $createdSoftware && (int)$createdLicense['number'] === 1
        && (int)$createdLicense['softwareversions_id_buy'] === $newTargetVersion && (int)$createdLicense['softwareversions_id_use'] === $newTargetVersion
        && $createdLicense['serial'] === $copySerial, 'Real affected-license copy preserves serial and owns the copied software and buy/use versions');
    verify($SQL_TOTAL_REQUEST === 0, 'Real license copy and owning version transfer bypass legacy adapter SQL with a cold schema cache');
    $invalidCopyLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $createdSoftware, 'is_valid' => false, 'is_template' => true, 'is_deleted' => true, 'entities_id' => $otherEntity]);
    $SQL_TOTAL_REQUEST = 0;
    Software::updateValidityIndicator($createdSoftware);
    verify($read('glpi_softwares', $createdSoftware)['is_valid'] === 0 && !$repo()->hasInvalidLicense($copySource), 'Validity follows only owning licenses and includes invalid templates, trash and foreign entities');
    $em = Orm::create($DB);
    $nativeLicense = $em->find(\itsmng\Database\Entity\SoftwareLicense::class, $invalidCopyLicense);
    $nativeLicense->is_valid = true;
    $em->flush();
    Software::updateValidityIndicator($createdSoftware);
    verify($read('glpi_softwares', $createdSoftware)['is_valid'] === 1 && $SQL_TOTAL_REQUEST === 0, 'Validity restoration uses the latest owning license state and bypasses adapter SQL');

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
echo $DB->getProvider() . ": software associations, quantities, atomic merge, transfer discovery, installation transfer/discard, software/version/license copy, cleanup and purge passed.\n";
