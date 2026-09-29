<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/asset-contract-reports.php /path/to/test-config\n");
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
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Asset report scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactiveentities_string'] = (string)$entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $contractType = $fixtures->create('glpi_contracttypes', ['name' => 'Report service']);
    $contract = $fixtures->create('glpi_contracts', ['name' => 'Current contract', 'begin_date' => '2025-01-01', 'duration' => 12, 'contracttypes_id' => $contractType]);
    $laterContract = $fixtures->create('glpi_contracts', ['name' => 'Next contract', 'begin_date' => '2026-01-01']);
    $ids = [];
    foreach (['visible' => [], 'hidden' => ['entities_id' => 0], 'template' => ['is_template' => true], 'deleted' => ['is_deleted' => true], 'uncontracted' => []] as $name => $values) {
        $ids[$name] = $fixtures->create('glpi_computers', $values + ['name' => 'Report ' . $name, 'entities_id' => $entity]);
        if ($name !== 'uncontracted') {
            $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'Computer', 'items_id' => $ids[$name]]);
        }
    }
    $fixtures->create('glpi_contracts_items', ['contracts_id' => $laterContract, 'itemtype' => 'Computer', 'items_id' => $ids['visible']]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $ids['visible'], 'buy_date' => '2024-12-31', 'warranty_duration' => 24]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $ids['uncontracted'], 'buy_date' => '2025-12-31']);
    $repo = static fn () => new \itsmng\Database\Repository\AssetContractReportRepository(Orm::create($DB));
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $rows = $repo()->rows('Computer', [], [$entity], false);
    verify(count($rows) === 4 && $rows[0]['itemid'] === $ids['deleted'], 'Year report keeps deleted and uncontracted assets, excludes templates/other entities and sorts deleted first');
    verify(count($repo()->rows('Computer', [], [$entity], true)) === 3, 'Contract report requires a binding and retains one row per contract');
    verify($repo()->rows('Computer', [], [], true) === [], 'Empty entity scope grants no records');
    verify(count($repo()->rows('Computer', [], null, true)) === 4, 'Explicit unrestricted scope includes other entities');
    $rows = $repo()->rows('Computer', [2025], [$entity], false);
    verify(count($rows) === 3, 'Either purchase or contract date matches a half-open year interval');
    verify($rows[0]['begin_date'] === '2025-01-01' && $rows[0]['itemdeleted'] === 1, 'Dates and booleans retain the report row format');
    verify(count($repo()->rows('Computer', [2024], [$entity], true)) === 2, 'Purchase date selects every matching contract row');
    verify(count($repo()->rows('Computer', [2024, 2025, 2025], [$entity], true)) === 3, 'Multiple years combine without duplicating rows');
    verify($repo()->rows('Computer', [2023], [$entity], false) === [], 'Absent year matches no rows');
    verify($SQL_TOTAL_REQUEST === 0, 'Core report queries bypass adapter SQL');
    $project = $fixtures->create('glpi_projects', ['name' => 'Project report', 'entities_id' => $entity, 'is_template' => true]);
    $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'Project', 'items_id' => $project]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Project', 'items_id' => $project, 'buy_date' => '2024-06-01']);
    verify(count($repo()->rows('Project', [2024], [$entity], false)) === 1, 'Project year report uses optional financial data and retains its template policy');
    verify($repo()->rows('Project', [2024], [$entity], true) === [], 'Project contract report filters by contract date only');
    verify($repo()->rows('Project', [2025], [$entity], true)[0]['buy_date'] === null, 'Project contract report has no financial projection');
    $software = $fixtures->create('glpi_softwares', ['name' => 'Deleted software', 'entities_id' => $entity, 'is_deleted' => true]);
    $license = $fixtures->create('glpi_softwarelicenses', ['name' => 'License report', 'softwares_id' => $software, 'entities_id' => $entity]);
    $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'SoftwareLicense', 'items_id' => $license]);
    verify($repo()->rows('SoftwareLicense', [2025], [$entity], false)[0]['itemdeleted'] === 1, 'License year report uses the software deletion flag');
    verify($repo()->rows('SoftwareLicense', [2025], [$entity], true)[0]['itemdeleted'] === 0, 'License contract report preserves its historical deletion projection');
    (new \itsmng\Database\Repository\RecordWriter(Orm::create($DB)))->update('glpi_softwares', $software, ['is_template' => true]);
    verify($repo()->rows('SoftwareLicense', [], [$entity], false) === [], 'Software templates excluded from license year report');
    verify(count($repo()->rows('SoftwareLicense', [], [$entity], true)) === 1, 'License contract report keeps its existing template policy');
    foreach ($CFG_GLPI['contract_types'] as $type) {
        $id = $fixtures->create($type::getTable(), ['entities_id' => $entity]);
        $fixtures->create('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => $type, 'items_id' => $id]);
        verify(in_array($id, array_column($repo()->rows($type, [2025], [$entity], true), 'itemid'), true), 'Mapped contract report supports ' . $type);
        if (in_array($type, $CFG_GLPI['report_types'], true)) {
            verify(in_array($id, array_column($repo()->rows($type, [2025], [$entity], false), 'itemid'), true), 'Mapped year report supports ' . $type);
        }
    }
    verify(\itsmng\Reporting\Criteria::itemtypes(['Computer', 'bogus', ['Computer'], 'Computer'], ['Computer']) === ['Computer'], 'Type selection rejects unknown/non-string entries and deduplicates');
    verify(\itsmng\Reporting\Criteria::itemtypes('Computer', ['Computer']) === [], 'Malformed selection cannot expose all assets');
    verify(\itsmng\Reporting\Criteria::itemtypes(['0'], ['Computer']) === ['Computer'], 'All-types sentinel remains supported');
    verify(\itsmng\Reporting\Criteria::years(['0']) === [] && \itsmng\Reporting\Criteria::years(['2025', '2025']) === ['2025'], 'Year selection handles the all-years sentinel and duplicate values');
    foreach ([['2025 OR 1=1'], ['9999'], [['2025']], '2025'] as $invalid) {
        try {
            \itsmng\Reporting\Criteria::years($invalid);
            throw new RuntimeException('Invalid years accepted');
        } catch (InvalidArgumentException) {
        }
    }
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}
echo $DB->getProvider() . ": Asset year/contract report types, entity scope, dates, flags and projections passed.\n";
