<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\Repository\InventoryLockRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SoftwareInstallationRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-subjects.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
require __DIR__ . '/fixtures/SoftwareNativeAdmission.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$repo = static fn (): SoftwareInstallationRepository => new SoftwareInstallationRepository(Orm::create($DB));
$reject = static function (callable $operation, string $message, ?string $selectedCheckTable = null) use ($connection, $DB): void {
    $connection->beginTransaction();
    try {
        $rejected = false;
        try {
            $operation();
        } catch (DriverException $error) {
            $rejected = $selectedCheckTable === null || SoftwareNativeAdmission::selectedCheck(
                $error,
                $selectedCheckTable,
                $DB->dbdefault
            );
        }
        verify($rejected, $message);
    } finally {
        $connection->rollBack();
    }
};
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$DB->beginTransaction();
try {
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $software = $fixtures->create('glpi_softwares', ['name' => "Ownership software O'Reilly 東京", 'is_recursive' => true]);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'name' => 'Installed version']);
    $otherVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software, 'name' => 'Other version']);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'softwareversions_id_use' => $version,
        'softwareversions_id_buy' => $otherVersion, 'number' => -1, 'serial' => 'Independent duplicates']);
    $beforeKinds = $CFG_GLPI['software_types'];
    verify(Plugin::registerClass('PluginSoftwareAssignmentsAsset', ['software_types' => true]), 'Actual plugin registration remains available');
    verify(in_array('PluginSoftwareAssignmentsAsset', $CFG_GLPI['software_types'], true), 'Plugin registration genuinely extends configured software types');
    foreach ([Item_SoftwareVersion::class => ['softwareversions_id' => $version], Item_SoftwareLicense::class => ['softwarelicenses_id' => $license]] as $model => $parent) {
        $unsupported = ['itemtype' => 'PluginSoftwareAssignmentsAsset', 'items_id' => 1] + $parent;
        // can() normalizes its input by reference, including rejection to false.
        // Each independent permission request needs its own proposed payload.
        $createInput = $unsupported;
        $updateInput = $unsupported;
        verify(!(new $model())->can(-1, CREATE, $createInput), 'An extension requires an owning mapping before public assignment CREATE ' . $model);
        verify(!(new $model())->can(-1, UPDATE, $updateInput), 'An extension requires an owning mapping before public assignment UPDATE ' . $model);
    }
    $CFG_GLPI['software_types'] = $beforeKinds;
    $sameId = 4294970801;
    $links = [];
    foreach (EntityRegistry::discriminatedReferences('glpi_items_softwareversions')['items_id']['selections'] as $kind => $selection) {
        verify(isset(EntityRegistry::discriminatedReferences('glpi_items_softwarelicenses')['items_id']['selections'][$kind]), 'Both families declare ' . $kind);
        $fixtures->create($selection['target'], ['id' => $sameId, 'name' => 'Software owner ' . $kind]);
        $fixtures->create($selection['target'], ['id' => $sameId + 1, 'name' => 'Other software owner ' . $kind]);
        $owner = new $kind();
        verify($owner->getFromDB($sameId), 'Actual persisted owner ' . $kind);
        verify($owner->isEntityAssign() && $owner->maybeRecursive() && !$owner->isRecursive(), 'Actual ownership capabilities ' . $kind);
        $installation = new Item_SoftwareVersion();
        $input = ['itemtype' => $kind, 'items_id' => $sameId, 'softwareversions_id' => $version,
            'is_dynamic' => true, 'date_install' => '2026-10-02'];
        verify($installation->can(-1, CREATE, $input), 'Actual installation relation authorization ' . $kind);
        $install = $installation->add($input);
        verify(
            $install > 0 && $installation->fields[$selection['column']] === $sameId && $installation->fields['items_id'] === $sameId,
            'Public installation owns its selected asset ' . $kind
        );
        $assignment = new Item_SoftwareLicense();
        $licenseInput = ['itemtype' => $kind, 'items_id' => $sameId, 'softwarelicenses_id' => $license, 'is_dynamic' => true];
        verify($assignment->can(-1, CREATE, $licenseInput), 'Actual licence relation authorization ' . $kind);
        $first = $assignment->add($licenseInput);
        $second = (new Item_SoftwareLicense())->add($licenseInput);
        verify($first > 0 && $second > 0 && $first !== $second, 'Licence duplicates retain independent identities ' . $kind);
        $links[$kind] = [$install, $first, $second];
        verify(array_column(Item_SoftwareVersion::getFromItem($owner), 'id') === [$install], 'Same numeric IDs remain isolated by owning kind ' . $kind);
        verify(
            array_keys(Item_SoftwareLicense::getLicenseForInstallation($kind, $sameId, $version)) === [$license]
            && array_keys(Item_SoftwareLicense::getLicenseForInstallation($kind, $sameId, $otherVersion)) === [$license],
            'Use/buy version matching preserves licence-ID keyed presentation ' . $kind
        );
        verify(count($repo()->licenseAssignmentsForTransfer($kind, $sameId)) === 2, 'Transfer preserves every licence assignment ' . $kind);
        $reject(static fn () => $connection->insert('glpi_items_softwareversions', ['itemtype' => $kind, $selection['column'] => $sameId, 'softwareversions_id' => $version]), 'Installation duplicate rejected ' . $kind);
        foreach (['glpi_items_softwareversions' => ['softwareversions_id' => $version], 'glpi_items_softwarelicenses' => ['softwarelicenses_id' => $license]] as $table => $parent) {
            $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => $sameId + 99] + $parent), 'Actual subject FK rejects a missing target ' . $table . ' ' . $kind);
            foreach ([0, null] as $invalidId) {
                $reject(static fn () => $connection->insert($table, ['itemtype' => $kind, $selection['column'] => $invalidId] + $parent), 'Zero/NULL selected subject is rejected ' . $table . ' ' . $kind);
            }
            foreach ([strtolower($kind), $kind . ' '] as $invalidKind) {
                // This pair has never been installed: UNIQUE cannot mask a folded CHECK.
                $unused = ['itemtype' => $kind, $selection['column'] => $sameId + 1] + $parent;
                $allRows = static fn (): array => $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
                $beforeRows = $allRows();
                verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table) . ' WHERE itemtype = ? AND ' . $connection->quoteIdentifier($selection['column']) . ' = ? AND ' . $connection->quoteIdentifier(array_key_first($parent)) . ' = ?', [$kind, $sameId + 1, reset($parent)]) === 0, 'Malformed discriminator lanes start with an unused valid pair ' . $table . ' ' . $kind);
                $connection->beginTransaction();
                try {
                    verify($connection->insert($table, $unused) === 1, 'Canonical unused software pair accepts the identical native insert ' . $table . ' ' . $kind);
                    $canonical = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE itemtype = ? AND ' . $connection->quoteIdentifier($selection['column']) . ' = ? AND ' . $connection->quoteIdentifier(array_key_first($parent)) . ' = ?', [$kind, $sameId + 1, reset($parent)]);
                    verify($canonical !== false && (int)$canonical['items_id'] === $sameId + 1, 'Canonical native control reads back its generated identity ' . $table . ' ' . $kind);
                    $reject(static fn () => $connection->update($table, ['itemtype' => $invalidKind], ['id' => $canonical['id']]), 'Raw discriminator update requires its own selected CHECK ' . $table . ' ' . $kind, $table);
                    verify($connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$canonical['id']]) === $canonical, 'Refused discriminator update retains the complete native row ' . $table . ' ' . $kind);
                } finally {
                    $connection->rollBack();
                }
                verify($allRows() === $beforeRows, 'Canonical unused control rollback retains all native software rows ' . $table . ' ' . $kind);
                $reject(static fn () => $connection->insert($table, ['itemtype' => $invalidKind, $selection['column'] => $sameId + 1] + $parent), 'Discriminator spelling and spacing are exact ' . $table . ' ' . $kind, $table);
                verify($allRows() === $beforeRows, 'Refused noncanonical insert retains every native software row ' . $table . ' ' . $kind);
            }
            $beforeGeneratedRows = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
            $connection->beginTransaction();
            try {
                $canonicalInput = ['itemtype' => $kind, $selection['column'] => $sameId + 1] + $parent;
                verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table) . ' WHERE itemtype = ? AND ' . $connection->quoteIdentifier($selection['column']) . ' = ? AND ' . $connection->quoteIdentifier(array_key_first($parent)) . ' = ?', [$kind, $sameId + 1, reset($parent)]) === 0, 'Generated INSERT lane starts with an unused canonical pair ' . $table . ' ' . $kind);
                verify($connection->insert($table, $canonicalInput) === 1, 'Canonical generated-write control accepts native INSERT ' . $table . ' ' . $kind);
                $canonical = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE itemtype = ? AND ' . $connection->quoteIdentifier($selection['column']) . ' = ? AND ' . $connection->quoteIdentifier(array_key_first($parent)) . ' = ?', [$kind, $sameId + 1, reset($parent)]);
                verify($canonical !== false && (int)$canonical['items_id'] === $sameId + 1, 'Physical owning field produces the canonical projection before direct writes ' . $table . ' ' . $kind);
                SoftwareNativeAdmission::rejectGenerated(
                    $connection,
                    static fn () => $connection->update($table, ['items_id' => $sameId], ['id' => $canonical['id']]),
                    $table,
                    'UPDATE'
                );
                verify($connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$canonical['id']]) === $canonical, 'Refused direct generated UPDATE retains the complete canonical native row ' . $table . ' ' . $kind);
            } finally {
                $connection->rollBack();
            }
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id') === $beforeGeneratedRows, 'Generated control rollback preserves every native row ' . $table . ' ' . $kind);
            SoftwareNativeAdmission::rejectGenerated(
                $connection,
                static fn () => $connection->insert($table, ['items_id' => $sameId] + $canonicalInput),
                $table,
                'INSERT'
            );
            verify($connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id') === $beforeGeneratedRows, 'Refused direct generated INSERT preserves every native row ' . $table . ' ' . $kind);
            $reject(static fn () => $connection->insert($table, ['itemtype' => 'Software', $selection['column'] => $sameId] + $parent), 'Discriminator mismatch rejected ' . $table . ' ' . $kind);
            $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Direct target deletion is restricted ' . $table . ' ' . $kind);
        }
        $native = new Record\ItemSoftwareLicense();
        $native->itemtype = $kind;
        $em = Orm::create($DB);
        $subject = Record\ItemSoftwareLicense::referenceAssociation($kind);
        $target = $em->getClassMetadata(Record\ItemSoftwareLicense::class)->getAssociationMapping($subject)->targetEntity;
        $native->$subject = $em->getReference($target, $sameId + 1);
        $native->softwarelicenses = $em->getReference(Record\SoftwareLicense::class, $license);
        $em->persist($native);
        $em->flush();
        verify($native->items_id === $sameId + 1, 'Native ORM populates its generated compatibility identity ' . $kind);
        $native->$subject = $em->getReference($target, $sameId);
        $em->flush();
        verify($native->items_id === $sameId, 'Native owning association update regenerates compatibility identity ' . $kind);
        $em->clear();
        verify((new Item_SoftwareLicense())->delete(['id' => $native->id], true), 'Native assignment public purge ' . $kind);
        verify((new Item_SoftwareLicense())->delete(['id' => $first]), 'Dynamic licence soft delete creates a lock ' . $kind);
        $locks = new InventoryLockRepository(Orm::create($DB));
        verify(array_column($locks->forItem('SoftwareLicense', $kind, $sameId), 'id') === [$first], 'Actual lock query uses owning subject ' . $kind);
        verify((new Item_SoftwareVersion())->delete(['id' => $install]), 'Dynamic installation soft delete creates a lock ' . $kind);
        verify(array_column($locks->forItem('SoftwareVersion', $kind, $sameId), 'id') === [$install], 'Installation lock query uses the owning subject ' . $kind);
        verify((new Item_SoftwareVersion())->restore(['id' => $install]), 'Public installation restore ' . $kind);
        verify($locks->forItem('SoftwareVersion', $kind, $sameId) === [], 'Restored installation is no longer locked ' . $kind);
        verify((new Item_SoftwareLicense())->restore(['id' => $first]), 'Public licence restore ' . $kind);
        verify($locks->forItem('SoftwareLicense', $kind, $sameId) === [], 'Restored assignment is no longer locked ' . $kind);
    }
    $child = $fixtures->create('glpi_entities', ['name' => 'Assignment child', 'entities_id' => 0]);
    $sibling = $fixtures->create('glpi_entities', ['name' => 'Assignment sibling', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [0, $child, $sibling];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    $childAsset = $fixtures->create('glpi_monitors', ['entities_id' => $child]);
    $siblingLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'entities_id' => $sibling]);
    $input = ['itemtype' => 'Monitor', 'items_id' => $childAsset, 'softwarelicenses_id' => $siblingLicense];
    $before = $records()->countMatching('glpi_items_softwarelicenses', []);
    verify(!(new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Actual relation guard refuses sibling ownership before public add');
    verify($records()->countMatching('glpi_items_softwarelicenses', []) === $before, 'Refused guard writes no assignment');
    $recursiveLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'is_recursive' => true]);
    $input['softwarelicenses_id'] = $recursiveLicense;
    verify((new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Actual recursive licence ancestor is accepted');
    verify((new Software())->update(['id' => $software, 'is_recursive' => false]), 'Disable actual Software recursion');
    verify(!(new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Licence flag cannot bypass Software recursion capability');
    verify((new Software())->update(['id' => $software, 'is_recursive' => true]), 'Restore actual Software recursion');
    $recursiveAsset = $fixtures->create('glpi_monitors', ['is_recursive' => true]);
    $recursiveSubjectInput = ['itemtype' => 'Monitor', 'items_id' => $recursiveAsset, 'softwarelicenses_id' => $siblingLicense];
    verify(
        (new Item_SoftwareLicense())->can(-1, CREATE, $recursiveSubjectInput),
        'Actual recursive subject ancestor is accepted in reverse'
    );
    $_SESSION['glpiactiveprofile']['monitor'] = READ;
    $_SESSION['glpiactiveprofile']['software'] = READ | UPDATE;
    verify((new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Writable licence and visible owner suffice');
    $_SESSION['glpiactiveprofile']['software'] = READ;
    verify(!(new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Two read-only ends cannot create an assignment');
    $_SESSION['glpiactiveprofile']['monitor'] = READ | UPDATE;
    verify((new Item_SoftwareLicense())->can(-1, CREATE, $input), 'Writable owner and visible licence preserve inherited public policy');
    $_SESSION = $savedSession;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $computer = new Computer();
    verify($computer->getFromDB($sameId), 'Load Computer for actual clone');
    $clone = $computer->clone(['name' => 'Software owner cloned Computer']);
    verify($clone > 0 && count($repo()->assignmentsForClone(false, 'Computer', $clone)) === 1
        && count($repo()->assignmentsForClone(true, 'Computer', $clone)) === 2, 'Actual Computer clone retains installation and independent licence rows');
    verify((new Computer())->delete(['id' => $clone], true), 'Clone purge runs actual lifecycle');
    foreach ($links as $kind => $ids) {
        $owner = new $kind();
        verify($owner->getFromDB($sameId), 'Load owner before forced purge ' . $kind);
        if ($kind !== 'Computer') {
            verify(!in_array(Item_SoftwareLicense::class, $owner->getCloneRelations(), true)
                && !in_array(Item_SoftwareVersion::class, $owner->getCloneRelations(), true), 'Other asset clone policy unchanged ' . $kind);
        }
        verify((new Item_SoftwareLicense())->delete(['id' => $ids[1]]), 'Leave a dynamic locked licence before owner purge ' . $kind);
        verify($owner->delete(['id' => $sameId], true), 'Public asset purge removes active and locked assignment children ' . $kind);
        verify(
            $repo()->assignmentsForClone(false, $kind, $sameId) === [] && $repo()->assignmentsForClone(true, $kind, $sameId) === [],
            'Metadata-derived purge cleans both assignment families ' . $kind
        );
    }
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after fixtures');
echo $DB->getProvider() . ": twelve owning software subjects, licence multiplicity, scope, locks, native associations and public clone/purge passed.\n";
