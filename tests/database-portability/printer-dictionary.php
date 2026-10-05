<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\PrinterDictionaryRepository;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/printer-dictionary.php /path/to/test-config\n");
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
    $repo = fn (): PrinterDictionaryRepository => new PrinterDictionaryRepository(Orm::create($DB));
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $prefix = 'Printer dictionary ' . bin2hex(random_bytes(5));
    $entity = $fixtures->create('glpi_entities', ['name' => $prefix]);
    $_SESSION['glpiactiveentities'][] = $entity;
    $maker = $fixtures->create('glpi_manufacturers', ['name' => $prefix . " O'Reilly \\ 日本語"]);
    $name = $prefix . " O'Reilly \\ 日本語";
    $base = ['name' => $name, 'manufacturers_id' => $maker, 'comment' => 'same'];
    $source = $fixtures->create('glpi_printers', $base);
    $duplicate = $fixtures->create('glpi_printers', $base);
    $child = $fixtures->create('glpi_printers', $base + ['entities_id' => $entity]);
    $otherComment = $fixtures->create('glpi_printers', array_replace($base, ['comment' => 'other']));
    $deleted = $fixtures->create('glpi_printers', $base + ['is_deleted' => true]);
    $template = $fixtures->create('glpi_printers', $base + ['is_template' => true]);
    $noMaker = $fixtures->create('glpi_printers', ['name' => $name, 'comment' => null]);
    $groups = iterator_to_array($repo()->replayGroups(0), false);
    $selected = array_values(array_filter($groups, static fn (array $row): bool => $row['name'] === $name));
    verify(count($selected) === 3 && $repo()->groupCount() === count($groups), 'Distinct complete dictionary inputs retain comments and NULL manufacturer, excluding trash/templates');
    verify($selected[0]['manufacturer'] === $name && $selected[2]['manufacturers_id'] === null, 'Scalar manufacturer join retains literal names and NULL');
    verify(iterator_to_array($repo()->replayGroups(1), false) === array_slice($groups, 1), 'Deterministic group offset');
    verify($repo()->matchingPrinters($name, $maker) === [$source, $duplicate, $child, $otherComment, $deleted, $template], 'Explicit replay matches all owners/comments/trash, with raw bound names');
    verify($repo()->matchingPrinters($name, null) === [$noMaker] && $repo()->replayPrinters([]) === [], 'NULL manufacturer and empty explicit selection');
    $rows = $repo()->replayPrinters([$template, $child, $deleted, $child]);
    verify(array_map('intval', array_column($rows, 'id')) === [$child, $deleted] && (int)$rows[0]['entities_id'] === $entity, 'Explicit rows exclude templates, deduplicate IDs and preserve ownership');

    $destinationName = $prefix . " destination O'Reilly \\ 日本語";
    $rootDestination = $fixtures->create('glpi_printers', ['name' => $destinationName, 'is_deleted' => true, 'manufacturers_id' => $maker]);
    $childDestination = $fixtures->create('glpi_printers', ['name' => $destinationName, 'entities_id' => $entity, 'is_deleted' => true, 'manufacturers_id' => $maker]);
    $computer = $fixtures->create('glpi_computers');
    $secondComputer = $fixtures->create('glpi_computers');
    $childComputer = $fixtures->create('glpi_computers', ['entities_id' => $entity]);
    $link = $fixtures->create('glpi_computers_items', ['computers_id' => $computer, 'itemtype' => 'Printer', 'items_id' => $source, 'is_dynamic' => true]);
    $collision = $fixtures->create('glpi_computers_items', ['computers_id' => $secondComputer, 'itemtype' => 'Printer', 'items_id' => $source, 'is_dynamic' => true]);
    $existing = $fixtures->create('glpi_computers_items', ['computers_id' => $secondComputer, 'itemtype' => 'Printer', 'items_id' => $rootDestination, 'is_deleted' => true]);
    $monitor = $fixtures->create('glpi_monitors', ['id' => $source]);
    $differentType = $fixtures->create('glpi_computers_items', ['computers_id' => $computer, 'itemtype' => 'Monitor', 'items_id' => $monitor]);
    $childLink = $fixtures->create('glpi_computers_items', ['computers_id' => $childComputer, 'itemtype' => 'Printer', 'items_id' => $child]);
    $relation = new Computer_Item();
    try {
        $repo()->moveConnections(
            $source,
            $rootDestination,
            static function (int $id, int $target) use ($relation, $read): bool {
                verify($relation->update(['id' => $id, 'items_id' => $target]), 'Move succeeds before forced rejection');
                verify((int)$read('glpi_computers_items', $id)['items_id'] === $target, 'Earlier move is persisted before rollback');
                return true;
            },
            static fn (array $row): bool => false
        );
        throw new LogicException('Failed connection lifecycle accepted');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Unable to move printer direct connection.', 'Expected lifecycle rejection');
    }
    verify((int)$read('glpi_computers_items', $link)['items_id'] === $source && $read('glpi_computers_items', $collision) !== null && $DB->inTransaction(), 'Rollback restores earlier moved connection and caller transaction');
    try {
        $repo()->moveConnections($source, 2147483647, static fn (): bool => true, static fn (): bool => true);
        throw new LogicException('Invalid destination accepted');
    } catch (InvalidArgumentException) {
    }
    $repo()->moveConnections($source, $source, static fn (): bool => false, static fn (): bool => false);
    $collection = new RuleDictionnaryPrinterCollection();
    verify(!$collection::somethingHasChanged(['name' => addslashes($name)], ['name' => $name]), 'Escaped dictionary output compares as one logical name');
    $collection->replayDictionnaryOnPrintersByID([$source, $child, $template], ['name' => addslashes($destinationName)]);
    verify($read('glpi_printers', $source)['is_deleted'] === 1 && $read('glpi_printers', $child)['is_deleted'] === 1 && $read('glpi_printers', $template)['is_deleted'] === 0, 'Successful public replay trashes merged originals and skips templates');
    verify($read('glpi_printers', $rootDestination)['is_deleted'] === 0 && $read('glpi_printers', $childDestination)['is_deleted'] === 0, 'Each owner restores its own destination');
    verify((int)$read('glpi_computers_items', $link)['items_id'] === $rootDestination && (int)$read('glpi_computers_items', $childLink)['items_id'] === $childDestination, 'Root and child connections stay in their respective owners');
    verify($read('glpi_computers_items', $collision) === null && $read('glpi_computers_items', $existing)['is_deleted'] === 1 && $read('glpi_computers_items', $existing)['is_dynamic'] === 0, 'Duplicate collapse preserves destination metadata');
    verify((int)$read('glpi_computers_items', $differentType)['items_id'] === $monitor, 'Colliding numeric ID of another type remains untouched');
    $collection->replayDictionnaryOnPrintersByID([$noMaker], ['is_global' => 1, 'manufacturer' => $maker]);
    verify($read('glpi_printers', $noMaker)['is_global'] === 1 && (int)$read('glpi_printers', $noMaker)['manufacturers_id'] === $maker, 'Non-rename actions update typed printer fields');

    $rollbackSource = $fixtures->create('glpi_printers', ['name' => $prefix . ' rollback source']);
    $rollbackTarget = $fixtures->create('glpi_printers', ['name' => $prefix . ' rollback target', 'is_deleted' => true]);
    $rollbackLink = $fixtures->create('glpi_computers_items', ['computers_id' => $computer, 'itemtype' => 'Printer', 'items_id' => $rollbackSource]);
    $rejected = new class () extends RuleDictionnaryPrinterCollection {
        public function putOldPrintersInTrash($IDS = [])
        {
            parent::putOldPrintersInTrash($IDS);
            throw new RuntimeException('Reject completed printer merge');
        }
    };
    try {
        $rejected->replayDictionnaryOnPrintersByID([$rollbackSource], ['name' => $prefix . ' rollback target']);
        throw new LogicException('Rejected printer merge committed');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Reject completed printer merge', 'Expected rejection after source trash and target restore');
    }
    verify($read('glpi_printers', $rollbackSource)['is_deleted'] === 0 && $read('glpi_printers', $rollbackTarget)['is_deleted'] === 1
        && (int)$read('glpi_computers_items', $rollbackLink)['items_id'] === $rollbackSource && $DB->inTransaction(), 'Whole replay rolls back restore, connection moves and original trash together');
    $createName = $prefix . " newly created O'Reilly \\ 日本語";
    $collection->replayDictionnaryOnPrintersByID([$duplicate], ['name' => addslashes($createName)]);
    $created = $repo()->matchingPrinters($createName, $maker);
    verify(count($created) === 1 && (int)$read('glpi_printers', $created[0])['entities_id'] === 0, 'Creation/import keeps literal name, manufacturer and real root');

    $ruleName = $prefix . ' rule source';
    $rulePrinter = $fixtures->create('glpi_printers', ['name' => $ruleName, 'entities_id' => $entity]);
    $ruleTarget = $fixtures->create('glpi_printers', ['name' => $prefix . ' rule target', 'entities_id' => $entity]);
    $ruleLink = $fixtures->create('glpi_computers_items', ['computers_id' => $childComputer, 'itemtype' => 'Printer', 'items_id' => $rulePrinter]);
    $rule = (new Rule())->add(['name' => $prefix, 'is_active' => 1, 'sub_type' => 'RuleDictionnaryPrinter', 'match' => Rule::AND_MATCHING]);
    verify((int)$rule > 0 && (int)(new RuleCriteria())->add(['rules_id' => $rule, 'criteria' => 'name', 'condition' => Rule::PATTERN_IS, 'pattern' => $ruleName]) > 0, 'Create real printer name rule');
    verify((int)(new RuleAction())->add(['rules_id' => $rule, 'action_type' => 'assign', 'field' => 'name', 'value' => $prefix . ' rule target']) > 0, 'Create real printer rename action');
    ob_start();
    try {
        verify($collection->replayRulesOnExistingDB() === -1 && $collection->replayRulesOnExistingDB(1000000) === -1, 'Real rule replay and past-end offset complete');
    } finally {
        ob_end_clean();
    }
    verify($read('glpi_printers', $rulePrinter)['is_deleted'] === 1 && (int)$read('glpi_computers_items', $ruleLink)['items_id'] === $ruleTarget, 'Rule pipeline processes NULL manufacturer and keeps the owning entity');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repo()->groupCount();
    iterator_to_array($repo()->replayGroups(1), false);
    $repo()->matchingPrinters($name, $maker);
    $repo()->replayPrinters([$source]);
    $repo()->moveConnections($source, $source, static fn (): bool => false, static fn (): bool => false);
    verify($SQL_TOTAL_REQUEST === 0, 'Typed replay selections bypass adapter SQL execution');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No foreign key orphans');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": printer dictionary groups, literal names, entity ownership, connection collisions and lifecycle rollback passed.\n";
