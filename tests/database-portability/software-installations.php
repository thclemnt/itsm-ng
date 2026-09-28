<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/software-installations.php /path/to/test-config\n");
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
use itsmng\Database\Repository\SoftwareRepository;

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $otherEntity = (new Entity())->add(['name' => 'Installation hidden entity', 'entities_id' => 0]);
    verify((bool)$otherEntity, 'Create foreign entity');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $software = $fixtures->create('glpi_softwares', ['name' => "Candidate O'Reilly C:\\日本語"]);
    $version = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software]);
    $secondVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $software]);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software]);
    $secondLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software]);
    $active = [];
    foreach (['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'] as $type) {
        foreach (['active' => [], 'hidden' => ['entities_id' => $otherEntity], 'deleted' => ['is_deleted' => true], 'template' => ['is_template' => true], 'removed' => []] as $case => $flags) {
            $asset = $fixtures->create($type::getTable(), ['name' => $type . ' ' . $case] + $flags);
            $link = ['itemtype' => $type, 'items_id' => $asset, 'is_deleted' => $case === 'removed'];
            // Cached flags may be stale: counting must still inspect the real asset.
            $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $version, 'is_deleted_item' => $case === 'active', 'is_template_item' => $case === 'active'] + $link);
            $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $license] + $link);
            if ($case === 'active') {
                $active[$type] = $asset;
                $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $secondVersion] + $link);
                $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $secondLicense] + $link);
            }
        }
    }
    verify(Item_SoftwareVersion::countForVersion($version) === 6, 'Version counts real active assets across all six types');
    verify(Item_SoftwareVersion::countForVersion($version, [$otherEntity]) === 6, 'Explicit version entity restriction');
    verify(Item_SoftwareVersion::countForSoftware($software) === 12, 'Software count retains two installations per asset');
    verify(Item_SoftwareLicense::countForLicense($license) === 6, 'Scoped license assignments');
    verify(Item_SoftwareLicense::countForLicense($license, -1) === 12, 'Unrestricted license count still excludes deleted and template assets');
    verify(Item_SoftwareLicense::countForLicense($license, '', 'Computer') === 1, 'Explicit asset type restriction');
    verify(Item_SoftwareLicense::countForSoftware($software) === 12, 'Software count retains multiple license assignments');
    // A missing polymorphic target cannot increase a count.
    $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $version, 'itemtype' => 'Computer', 'items_id' => 2147483647]);
    $fixtures->create('glpi_items_softwarelicenses', ['softwarelicenses_id' => $license, 'itemtype' => 'Computer', 'items_id' => 2147483647]);
    verify(Item_SoftwareVersion::countForVersion($version) === 6 && Item_SoftwareLicense::countForLicense($license) === 6, 'Missing asset targets excluded by the concrete join');
    $_SESSION['glpiactiveentities'] = [$otherEntity];
    verify(Item_SoftwareLicense::countForSoftware($software) === 6, 'Software license count follows active entity changes');
    $installationRepo = new \itsmng\Database\Repository\SoftwareInstallationRepository(Orm::create($DB));
    $grouped = $installationRepo->countsByEntity(true, $license, 'Computer', 'glpi_computers', []);
    ksort($grouped);
    verify($grouped === [0 => 1, $otherEntity => 1], 'Grouped license counts use asset entities');
    $licenseModel = new SoftwareLicense();
    verify($licenseModel->getFromDB($license), 'Load root license for entity report');
    ob_start();
    Item_SoftwareLicense::showForLicenseByEntity($licenseModel);
    $html = ob_get_clean();
    verify(str_contains($html, 'Installation hidden entity') && substr_count($html, "class='numeric'>1</td>") === 6, 'License entity report includes child-entity assets using a root license');
    $_SESSION['glpiactiveentities'] = [0];
    ob_start();
    Item_SoftwareLicense::showForLicenseByEntity($licenseModel);
    $html = ob_get_clean();
    verify(!str_contains($html, 'Installation hidden entity') && substr_count($html, "class='numeric'>1</td>") === 6, 'License entity report excludes inaccessible asset entities');
    $_SESSION['glpishowallentities'] = true;
    verify(Item_SoftwareVersion::countForVersion($version) === 12, 'All-entity installation count');
    $_SESSION['glpishowallentities'] = false;

    $repo = new SoftwareRepository(Orm::create($DB));
    $eligible = array_column($repo->withLicenses(getEntitiesRestrictCriteria('glpi_softwarelicenses', '', 0, true)), 'id');
    verify(count(array_keys($eligible, $software)) === 1, 'License software selector deduplicates multiple licenses');
    $hidden = $fixtures->create('glpi_softwares', ['name' => 'License only in hidden entity']);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $hidden, 'entities_id' => $otherEntity]);
    $deleted = $fixtures->create('glpi_softwares', ['name' => 'Deleted licensed software', 'is_deleted' => true]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $deleted]);
    $template = $fixtures->create('glpi_softwares', ['name' => 'Template licensed software', 'is_template' => true]);
    $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $template]);
    $eligible = array_column($repo->withLicenses(getEntitiesRestrictCriteria('glpi_softwarelicenses', '', 0, true)), 'id');
    verify(!array_intersect([$hidden, $deleted, $template], $eligible), 'License software selector applies license scope and software flags');

    $name = "Candidate O'Reilly C:\\日本語";
    $candidate = $fixtures->create('glpi_softwares', ['name' => $name, 'entities_id' => $otherEntity]);
    $fixtures->create('glpi_softwares', ['name' => $name, 'is_deleted' => true]);
    $fixtures->create('glpi_softwares', ['name' => $name, 'is_template' => true]);
    verify(array_column($repo->mergeCandidates($software, $name, getEntitiesRestrictCriteria('glpi_softwares', '', [$otherEntity])), 'id') === [$candidate], 'Merge candidates bind literal names and scope with self/flag exclusion');
    $restorable = $fixtures->create('glpi_softwares', ['name' => $name . ' restore', 'is_deleted' => true]);
    verify((int)(new Software())->addOrRestoreFromTrash(addslashes($name . ' restore'), '', 0) === $restorable, 'Dictionary lookup decodes its pre-escaped name once and restores the existing record');
    $restored = new Software();
    verify($restored->getFromDB($restorable) && !$restored->fields['is_deleted'], 'Restored software is active');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Item_SoftwareVersion::countForVersion($version);
    Item_SoftwareVersion::countForSoftware($software);
    Item_SoftwareLicense::countForLicense($license);
    Item_SoftwareLicense::countForSoftware($software);
    $repo->withLicenses(getEntitiesRestrictCriteria('glpi_softwarelicenses', '', 0));
    $repo->mergeCandidates($software, $name, getEntitiesRestrictCriteria('glpi_softwares', '', 0));
    ob_start();
    Item_SoftwareLicense::showForLicenseByEntity($licenseModel);
    ob_end_clean();
    verify($SQL_TOTAL_REQUEST === 0, 'Core inventory counts, entity report and software selectors use ORM execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": six asset types, installation/license counts, flags, entity scope and software selectors passed.\n";
