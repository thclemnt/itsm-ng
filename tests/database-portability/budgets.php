<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\BudgetRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/budgets.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $entity = (new Entity())->add(['name' => 'Budget report scope', 'entities_id' => 0]);
    $budget = $fixtures->create('glpi_budgets', ['name' => 'Mapped report budget', 'entities_id' => $entity, 'value' => '2000.0000']);
    $otherBudget = $fixtures->create('glpi_budgets', ['name' => 'Other budget']);
    $em = Orm::create($DB);
    $repository = new BudgetRepository($em);
    $costIds = [];
    foreach (['Contract', 'Ticket', 'Problem', 'Change', 'Project'] as $type) {
        $parentTable = getTableForItemType($type);
        $costTable = getTableForItemType($type . 'Cost');
        $parentColumn = getForeignKeyFieldForTable($parentTable);
        $costValues = in_array($type, ['Contract', 'Project'], true)
            ? ['cost' => '12.5000']
            : ['actiontime' => 900, 'cost_time' => '10.0000', 'cost_fixed' => '3.2500', 'cost_material' => '6.7500'];
        $costIds[$type] = $fixtures->create($parentTable, ['name' => 'Visible budget ' . $type, 'entities_id' => $entity]);
        foreach ([1, 2] as $entry) {
            $fixtures->create($costTable, [$parentColumn => $costIds[$type], 'budgets_id' => $budget, 'entities_id' => 0] + $costValues);
        }
        $fixtures->create($costTable, [$parentColumn => $costIds[$type], 'budgets_id' => $otherBudget] + $costValues);
        $hidden = $fixtures->create($parentTable, ['name' => 'Hidden budget ' . $type, 'entities_id' => 0]);
        $fixtures->create($costTable, [$parentColumn => $hidden, 'budgets_id' => $budget, 'entities_id' => $entity] + $costValues);
        $rows = $repository->items($type, $budget, [$entity]);
        verify(count($rows) === 1 && (int)$rows[0]['id'] === $costIds[$type] && abs((float)$rows[0]['value'] - 25) < 0.00001, 'Grouped mapped costs and fractional labour: ' . $type);
        $totals = $repository->totalsByEntity($type, $budget, [$entity]);
        verify(count($totals) === 1 && (int)$totals[0]['entities_id'] === $entity && abs((float)$totals[0]['sumvalue'] - 25) < 0.00001, 'Cost totals use parent entity: ' . $type);
        verify(count($repository->totalsByEntity($type, $budget, null)) === 2, 'Explicit unrestricted cost scope: ' . $type);
        verify($repository->items($type, $budget, []) === [] && $repository->totalsByEntity($type, $budget, []) === [], 'Empty cost scope: ' . $type);
    }
    $costRepository = new \itsmng\Database\Repository\CostRepository($em);
    foreach (['Contract', 'Project', 'Ticket', 'Problem', 'Change'] as $type) {
        $costType = $type . 'Cost';
        $parentColumn = getForeignKeyFieldForTable(getTableForItemType($type));
        $parent = $fixtures->create(getTableForItemType($type), ['name' => 'Cost chronology ' . $type, 'entities_id' => $entity]);
        $chronology = [];
        foreach ([[null, null], ['2026-01-01', '2026-01-31'], ['2026-01-01', '2026-01-31'], [null, null]] as [$begin, $end]) {
            $chronology[] = $fixtures->create(getTableForItemType($costType), [$parentColumn => $parent, 'begin_date' => $begin, 'end_date' => $end, 'name' => 'Mapped cost history']);
        }
        verify(array_column($costRepository->rows($costType, $parent), 'id') === [$chronology[0], $chronology[3], $chronology[1], $chronology[2]], 'Cost lists sort NULL dates first with stable IDs: ' . $type);
        verify((int)$costRepository->rows($costType, $parent, true)[0]['id'] === $chronology[2], 'Latest cost excludes undated rows and resolves equal dates by ID: ' . $type);
        verify($costRepository->rows($costType, []) === [], 'Empty cost parent list');
        $cost = new $costType();
        $method = match ($type) {
            'Contract' => 'getLastCostForContract', 'Project' => 'getLastCostForProject', default => 'getLastCostForItem'
        };
        verify((int)$cost->$method($parent)['id'] === $chronology[2], 'Application latest-cost path: ' . $type);
        verify(!$cost->$method(-1), 'Missing parent has no latest cost');
        if (in_array($type, ['Ticket', 'Problem', 'Change'], true)) {
            verify($cost->getTotalActionTimeForItem($costIds[$type]) === 2700 && $cost->getTotalActionTimeForItem(-1) === null, 'Mapped action-time sum and empty result: ' . $type);
            $summary = $costType::getCostsSummary($costType, $costIds[$type]);
            verify($summary['actiontime'] === Html::formatNumber(2700) && $summary['totalcost'] === Html::formatNumber(37.5), 'Cost summary retains formatted application values: ' . $type);
        }
    }
    $template = $fixtures->create('glpi_contracts', ['name' => 'Budget contract template', 'entities_id' => $entity, 'is_template' => 1]);
    $fixtures->create('glpi_contractcosts', ['contracts_id' => $template, 'budgets_id' => $budget, 'cost' => '7.0000']);
    verify(count($repository->items('Contract', $budget, [$entity])) === 1, 'Contract item list excludes templates');
    verify((float)$repository->totalsByEntity('Contract', $budget, [$entity])[0]['sumvalue'] === 32.0, 'Contract total retains existing template-spending behavior');
    $assets = [];
    foreach (['Computer', 'SoftwareLicense', 'Cartridge', 'Consumable', 'Item_DeviceProcessor'] as $type) {
        $table = getTableForItemType($type);
        $assetValues = ['entities_id' => $entity];
        if (!in_array($type, ['Cartridge', 'Consumable', 'Item_DeviceProcessor'], true)) {
            $assetValues += ['name' => 'Visible budget ' . $type];
        }
        if ($type === 'Item_DeviceProcessor') {
            $assetValues += ['itemtype' => 'Computer', 'items_id' => $assets['Computer'], 'serial' => 'Mapped device serial'];
        }
        $assets[$type] = $fixtures->create($table, $assetValues);
        $fixtures->create('glpi_infocoms', ['itemtype' => $type, 'items_id' => $assets[$type], 'entities_id' => $entity, 'budgets_id' => $budget, 'value' => '123.4567']);
        $hidden = $fixtures->create($table, ['entities_id' => 0]);
        $fixtures->create('glpi_infocoms', ['itemtype' => $type, 'items_id' => $hidden, 'entities_id' => $entity, 'budgets_id' => $budget, 'value' => '999.0000']);
        $rows = $repository->items($type, $budget, [$entity]);
        verify(count($rows) === 1 && (int)$rows[0]['id'] === $assets[$type] && (string)$rows[0]['value'] === '123.4567', 'Financial details preserve decimal values and entity scope: ' . $type);
        $totals = $repository->totalsByEntity($type, $budget, [$entity]);
        verify(count($totals) === 1 && (string)$totals[0]['sumvalue'] === '123.4567', 'Financial totals: ' . $type);
        verify($repository->items($type, $budget, []) === [] && $repository->totalsByEntity($type, $budget, []) === [], 'Empty financial scope: ' . $type);
    }
    $deleted = $fixtures->create('glpi_computers', ['name' => 'Deleted budget asset', 'entities_id' => $entity, 'is_deleted' => 1]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $deleted, 'entities_id' => $entity, 'budgets_id' => $budget, 'value' => '20.0000']);
    $template = $fixtures->create('glpi_computers', ['entities_id' => $entity, 'is_template' => 1]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $template, 'entities_id' => $entity, 'budgets_id' => $budget, 'value' => '900.0000']);
    $ids = array_column($repository->items('Computer', $budget, [$entity]), 'id');
    verify(in_array($deleted, $ids) && !in_array($template, $ids), 'Spent assets remain after deletion while templates are excluded');
    verify((string)$repository->totalsByEntity('Computer', $budget, [$entity])[0]['sumvalue'] === '143.4567', 'Asset totals retain historical spending');
    foreach (Infocom::getExcludedTypes() as $type) {
        $fixtures->create('glpi_infocoms', ['itemtype' => $type, 'items_id' => $fixtures->create(getTableForItemType($type)), 'entities_id' => $entity, 'budgets_id' => $budget]);
    }
    $phone = $fixtures->create('glpi_phones', ['entities_id' => $entity]);
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Phone', 'items_id' => $phone, 'entities_id' => 0, 'budgets_id' => $budget]);
    verify(in_array('Phone', $repository->itemTypes($budget), true) && !in_array('Phone', $repository->itemTypes($budget, [$entity]), true), 'Type discovery preserves its infocom scope');
    verify(array_intersect(Infocom::getExcludedTypes(), $repository->itemTypes($budget)) === [] && $repository->itemTypes($budget, []) === [], 'Excluded types and empty discovery scope');
    $em->clear();
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    $item = new Budget();
    verify($item->getFromDB($budget), 'Load report budget');
    ob_start();
    $item->showItems();
    $html = ob_get_clean();
    verify(str_contains($html, 'Visible budget Computer') && str_contains($html, 'Visible budget Ticket') && !str_contains($html, 'Hidden budget'), 'Budget item report renders visible rows');
    ob_start();
    $item->showValuesByEntity();
    $html = ob_get_clean();
    verify(str_contains($html, 'Budget report scope') && str_contains($html, 'Total spent on the budget'), 'Budget totals render mapped groups');
    $beforeDiscovery = $html;
    $fixtures->create('glpi_infocoms', ['itemtype' => 'Contract', 'items_id' => $costIds['Contract'], 'entities_id' => $entity, 'budgets_id' => $budget, 'value' => '999.0000']);
    ob_start();
    $item->showValuesByEntity();
    $html = ob_get_clean();
    $spent = static function (string $markup): string {
        preg_match("~Total spent on the budget</td><td class='numeric b'>([^<]+)</td>~", $markup, $matches);
        return $matches[1] ?? throw new RuntimeException('Missing rendered spending total');
    };
    verify($spent($html) === $spent($beforeDiscovery) && $spent($html) === Html::formatNumber(769.2835), 'A cost type discovered through infocoms is counted only once');
    foreach (['Contract', 'Project', 'Ticket', 'Problem', 'Change'] as $type) {
        $parent = new $type();
        verify($parent->getFromDB($costIds[$type]), 'Load cost view parent');
        $costType = $type . 'Cost';
        ob_start();
        match ($type) {
            'Contract' => ContractCost::showForContract($parent),
            'Project' => ProjectCost::showForProject($parent),
            default => $costType::showForObject($parent),
        };
        $html = ob_get_clean();
        verify(str_contains($html, 'Mapped report budget'), 'Cost view renders mapped budget association: ' . $type);
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach (['Computer', 'Contract', 'Ticket', 'Problem', 'Change', 'Project', 'Cartridge', 'Consumable', 'Item_DeviceProcessor'] as $type) {
        $repository->items($type, $budget, [$entity]);
        $repository->totalsByEntity($type, $budget, [$entity]);
    }
    $repository->itemTypes($budget, [$entity]);
    (new TicketCost())->getLastCostForItem($costIds['Ticket']);
    (new TicketCost())->getTotalActionTimeForItem($costIds['Ticket']);
    TicketCost::getCostsSummary('TicketCost', $costIds['Ticket']);
    verify($SQL_TOTAL_REQUEST === 0, 'Budget projections bypass legacy SQL execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped budget details, totals, spending history, entity scope and rendering passed.\n";
