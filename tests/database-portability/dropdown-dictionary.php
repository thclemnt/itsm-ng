<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DropdownDictionaryRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/dropdown-dictionary.php /path/to/test-config\n");
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
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $repo = fn (): DropdownDictionaryRepository => new DropdownDictionaryRepository(Orm::create($DB));
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $prefix = 'Dropdown dictionary ' . bin2hex(random_bytes(5));
    $source = $fixtures->create('glpi_printermodels', ['name' => $prefix . ' old', 'comment' => "O'Reilly \\ 日本語"]);
    $targetA = $fixtures->create('glpi_printermodels', ['name' => $prefix . ' A']);
    $targetB = $fixtures->create('glpi_printermodels', ['name' => $prefix . ' B']);
    $makerA = $fixtures->create('glpi_manufacturers', ['name' => $prefix . ' manufacturer A']);
    $makerB = $fixtures->create('glpi_manufacturers', ['name' => $prefix . ' manufacturer B']);
    $a = $fixtures->create('glpi_printers', ['printermodels_id' => $source, 'manufacturers_id' => $makerA]);
    $a2 = $fixtures->create('glpi_printers', ['printermodels_id' => $source, 'manufacturers_id' => $makerA]);
    $b = $fixtures->create('glpi_printers', ['printermodels_id' => $source, 'manufacturers_id' => $makerB]);
    $none = $fixtures->create('glpi_printers', ['printermodels_id' => $source, 'manufacturers_id' => null]);
    $rows = iterator_to_array($repo()->modelRows('glpi_printermodels', 'glpi_printers', 0), false);
    verify(count($rows) === $repo()->modelCount('glpi_printermodels', 'glpi_printers'), 'Model count matches its distinct scalar stream');
    $sourceRows = array_values(array_filter($rows, static fn ($row) => (int)$row['id'] === $source));
    verify(count($sourceRows) === 3 && array_map(static fn ($row) => $row['idmanu'] === null ? null : (int)$row['idmanu'], $sourceRows) === [null, $makerA, $makerB], 'Models replay once per manufacturer, including the nullable association');
    verify($sourceRows[0]['comment'] === "O'Reilly \\ 日本語", 'Raw comments survive scalar projection');
    verify(iterator_to_array($repo()->modelRows('glpi_printermodels', 'glpi_printers', 1), false) === array_slice($rows, 1), 'Stable model/manufacturer offset');
    try {
        $repo()->modelCount('glpi_printermodels', 'glpi_computers');
        throw new LogicException('Guessed model association accepted');
    } catch (InvalidArgumentException) {
    }
    try {
        $repo()->count('glpi_printermodels; DROP TABLE glpi_printers');
        throw new LogicException('Unmapped table accepted');
    } catch (InvalidArgumentException) {
    }
    $cartridge = $fixtures->create('glpi_cartridgeitems', ['name' => $prefix]);
    $compatibility = new CartridgeItem();
    verify($compatibility->addCompatibleType($cartridge, $source) && $compatibility->addCompatibleType($cartridge, $targetA), 'Mapped cartridge compatibility inserts');
    verify($compatibility->addCompatibleType($cartridge, $targetA), 'Already-present compatibility is idempotent');
    verify(!$compatibility->addCompatibleType(0, $targetA) && !$compatibility->addCompatibleType($cartridge, 2147483647), 'Invalid compatibility target rejected without orphan');
    $compatibilities = static fn (): array => array_map('intval', array_column((new RecordRepository(Orm::create($DB)))->matching('glpi_cartridgeitems_printermodels', ['cartridgeitems_id' => $cartridge], ['printermodels_id']), 'printermodels_id'));
    $rejected = false;
    try {
        $repo()->replaceModel('glpi_printermodels', 'glpi_printers', $source, [$makerA => $targetB], static function (int $cartridge, int $model): bool {
            verify((new CartridgeItem())->addCompatibleType($cartridge, $model), 'New compatibility exists before forced rollback');
            return false;
        });
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Unable to move printer model compatibility.', 'Expected compatibility rejection');
        $rejected = true;
    }
    verify($rejected && $DB->inTransaction() && (int)$read('glpi_printers', $a)['printermodels_id'] === $source
        && !in_array($targetB, $compatibilities(), true), 'Rollback restores manufacturer partitions and added compatibility');
    $repo()->replaceModel('glpi_printermodels', 'glpi_printers', $source, [$makerA => $targetA]);
    verify((int)$read('glpi_printers', $a)['printermodels_id'] === $targetA && (int)$read('glpi_printers', $a2)['printermodels_id'] === $targetA
        && (int)$read('glpi_printers', $b)['printermodels_id'] === $source && (int)$read('glpi_printers', $none)['printermodels_id'] === $source, 'Replacement is scoped to one manufacturer');
    verify($read('glpi_printermodels', $source) !== null && in_array($source, $compatibilities(), true), 'Still-used model and its compatibility remain');
    try {
        $repo()->replaceModel('glpi_printermodels', 'glpi_printers', $source, [$makerB => 2147483647]);
        throw new LogicException('Orphaned model assignment accepted');
    } catch (DriverException) {
    }
    verify((int)$read('glpi_printers', $b)['printermodels_id'] === $source, 'Restrictive FK rejection restores owner assignment');
    $repo()->replaceModel('glpi_printermodels', 'glpi_printers', $source, [$makerB => $targetB, 0 => $targetA]);
    verify($read('glpi_printermodels', $source) === null && (int)$read('glpi_printers', $b)['printermodels_id'] === $targetB
        && (int)$read('glpi_printers', $none)['printermodels_id'] === $targetA, 'Final manufacturer/NULL partitions move before deleting the unused model');
    verify($compatibilities() === [$targetA, $targetB], 'Compatibility links move before source deletion and duplicate targets collapse');

    // Exercise the public model replay with real rule/import processing.
    $old = $fixtures->create('glpi_printermodels', ['name' => $prefix . ' replay']);
    $printer = $fixtures->create('glpi_printers', ['printermodels_id' => $old, 'manufacturers_id' => $makerA]);
    verify($compatibility->addCompatibleType($cartridge, $old), 'Replay source has a restrictive child');
    $rule = (new Rule())->add(['name' => $prefix, 'is_active' => 1, 'sub_type' => 'RuleDictionnaryPrinterModel', 'match' => Rule::AND_MATCHING]);
    verify((int)$rule > 0, 'Create real model dictionary rule');
    verify((int)(new RuleCriteria())->add(['rules_id' => $rule, 'criteria' => 'name', 'condition' => Rule::PATTERN_IS, 'pattern' => $prefix . ' replay']) > 0, 'Create model name criterion');
    verify((int)(new RuleAction())->add(['rules_id' => $rule, 'action_type' => 'assign', 'field' => 'name', 'value' => $prefix . ' A']) > 0, 'Create model name action');
    ob_start();
    try {
        verify((new RuleDictionnaryPrinterModelCollection())->replayRulesOnExistingDBForModel() === -1, 'Public model replay completes');
    } finally {
        ob_end_clean();
    }
    verify((int)$read('glpi_printers', $printer)['printermodels_id'] === $targetA && $read('glpi_printermodels', $old) === null
        && !in_array($old, $compatibilities(), true), 'Public replay reassigns mapped owner and compatibility before restrictive deletion');
    $osCollection = new RuleDictionnaryOperatingSystemCollection();
    $oldOS = $fixtures->create('glpi_operatingsystems', ['name' => $prefix . ' old OS']);
    $targetOS = $fixtures->create('glpi_operatingsystems', ['name' => $prefix . ' OS']);
    $computer = $fixtures->create('glpi_computers');
    $installation = $fixtures->create('glpi_items_operatingsystems', ['itemtype' => 'Computer', 'items_id' => $computer, 'operatingsystems_id' => $oldOS]);
    $osRule = (new Rule())->add(['name' => $prefix . ' OS rule', 'is_active' => 1, 'sub_type' => 'RuleDictionnaryOperatingSystem', 'match' => Rule::AND_MATCHING]);
    verify((int)$osRule > 0, 'Create real plain dropdown rule');
    verify((int)(new RuleCriteria())->add(['rules_id' => $osRule, 'criteria' => 'name', 'condition' => Rule::PATTERN_IS, 'pattern' => $prefix . ' old OS']) > 0, 'Create OS criterion');
    verify((int)(new RuleAction())->add(['rules_id' => $osRule, 'action_type' => 'assign', 'field' => 'name', 'value' => $prefix . ' OS']) > 0, 'Create OS action');
    ob_start();
    try {
        verify($osCollection->replayRulesOnExistingDB() === -1, 'Public plain dropdown replay completes');
    } finally {
        ob_end_clean();
    }
    verify($read('glpi_operatingsystems', $oldOS) === null && (int)$read('glpi_items_operatingsystems', $installation)['operatingsystems_id'] === $targetOS, 'Plain dropdown replay retains public replacement cleanup');
    $osRows = iterator_to_array($repo()->rows($osCollection->item_table, 0), false);
    verify(count($osRows) === $repo()->count($osCollection->item_table), 'Plain dropdown count and stream use the mapped class');
    ob_start();
    try {
        verify($osCollection->replayRulesOnExistingDB(count($osRows) + 100) === -1, 'Past-end plain dropdown replay terminates');
        verify($osCollection->replayRulesOnExistingDBForModel() === false, 'Non-model path retains its explicit rejection');
    } finally {
        ob_end_clean();
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo()->count('glpi_printermodels');
    iterator_to_array($repo()->rows('glpi_printermodels', 0), false);
    $repo()->modelCount('glpi_printermodels', 'glpi_printers');
    iterator_to_array($repo()->modelRows('glpi_printermodels', 'glpi_printers', 0), false);
    $repo()->replaceModel('glpi_printermodels', 'glpi_printers', $targetA, [$makerA => $targetA]);
    $compatibility->addCompatibleType($cartridge, $targetA);
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped dropdown operations and compatibility inserts bypass legacy adapter execution');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned relationships after model replay');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped dropdown dictionary streams, manufacturer partitions, restrictive model cleanup, cartridge compatibility and rollback passed.\n";
