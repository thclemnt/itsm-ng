<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/transfer-bindings.php /path/to/test-config\n");
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
$savedSession = $_SESSION;
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$DB->disableTableCaching();
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$DB->beginTransaction();
try {
    $sourceEntity = $fixtures->create('glpi_entities', ['name' => 'Transfer binding source', 'entities_id' => 0]);
    $destinationEntity = $fixtures->create('glpi_entities', ['name' => 'Transfer binding destination', 'entities_id' => 0]);
    $_SESSION['glpiactive_entity'] = $sourceEntity;
    $_SESSION['glpiactiveentities'] = [0, $sourceEntity, $destinationEntity];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    foreach (['Contract', 'Document'] as $kindIndex => $kind) {
        $table = $kind::getTable();
        $linkType = $kind . '_Item';
        $linkTable = $linkType::getTable();
        $parentColumn = $kind === 'Contract' ? 'contracts_id' : 'documents_id';
        $option = strtolower($kind);
        $method = 'transfer' . $kind . 's';
        $computer = $fixtures->create('glpi_computers', ['id' => 4294967300 + 100 * $kindIndex, 'entities_id' => $sourceEntity]);
        $monitor = $fixtures->create('glpi_monitors', ['id' => $computer, 'entities_id' => $sourceEntity]);
        $outside = $fixtures->create('glpi_computers', ['entities_id' => $sourceEntity]);
        $copy = $fixtures->create('glpi_computers', ['entities_id' => $destinationEntity]);
        $bind = static fn (int $parent, string $type, int $item, array $extra = []): int => $fixtures->create($linkTable, [$parentColumn => $parent, 'itemtype' => $type, 'items_id' => $item] + $extra);
        $parent = static fn (string $name, int $entity, array $extra = []): int => $fixtures->create($table, ['name' => $name, 'entities_id' => $entity] + $extra);
        $transfer = new class () extends Transfer {
            public array $calls = [];

            // Exercise public copy/update hooks while isolating other transfer stages.
            public function transferItem($itemtype, $ID, $newID)
            {
                $this->calls[] = [$itemtype, $ID, $newID];
                verify((new $itemtype())->update(['id' => $newID, 'entities_id' => $this->to]), 'Parent transfer update');
                $this->addToAlreadyTransfer($itemtype, $ID, $newID);
            }
        };
        $transfer->to = $destinationEntity;
        $transfer->options = ['keep_' . $option => 1, 'clean_' . $option => 2];
        $transfer->needtobe_transfer = ['Computer' => [$computer], 'Monitor' => []];
        $excluded = $parent('Excluded', $destinationEntity);
        $excludedLink = $bind($excluded, 'Computer', $computer);
        $transfer->noneedtobe_transfer = [$kind => [$excluded]];
        $name = "Reuse ' slash\\ café";
        $source = $parent($name, $sourceEntity, ['id' => 4294967800 + $kindIndex]);
        $destination = $parent($name, $destinationEntity, ['is_deleted' => true] + ($kind === 'Contract' ? ['is_template' => true] : []));
        $parent($name, $destinationEntity);
        $extra = $kind === 'Document' ? ['entities_id' => $sourceEntity, 'timeline_position' => 2, 'is_recursive' => true] : [];
        $link = $bind($source, 'Computer', $computer, $extra);
        $outsideLink = $bind($source, 'Computer', $outside);
        $monitorLink = $bind($source, 'Monitor', $monitor);
        $second = $parent('NULL', $sourceEntity);
        $secondDestination = $parent('NULL', $destinationEntity);
        $secondLink = $bind($second, 'Computer', $computer);
        $bind($second, 'Computer', $outside);
        $DB->clearSchemaCache();
        $SQL_TOTAL_REQUEST = 0;
        $transfer->$method('Computer', $computer, $computer);
        verify($SQL_TOTAL_REQUEST === 0, 'Cold-cache reuse bypasses adapter SQL: ' . $kind);
        verify($read($linkTable, $link)[$parentColumn] === $destination && $read($linkTable, $secondLink)[$parentColumn] === $secondDestination, 'Destination reuse consumes every source link once: ' . $kind);
        verify($transfer->calls === [] && $transfer->already_transfer[$kind][$source] === $destination, 'Literal-name reuse is recorded without copying the parent: ' . $kind);
        verify($read($linkTable, $outsideLink)[$parentColumn] === $source && $read($linkTable, $monitorLink)[$parentColumn] === $source && $read($linkTable, $excludedLink)[$parentColumn] === $excluded, 'Other items, overlapping kinds and excluded parents stay intact: ' . $kind);
        verify($read($table, $source)['is_deleted'] === 0, 'Cleanup retains a shared parent still used by other items: ' . $kind);
        if ($kind === 'Document') {
            verify($read($linkTable, $link)['timeline_position'] === 2 && $read($linkTable, $link)['entities_id'] === $sourceEntity, 'Retarget retains document link metadata');
        }

        // A copied asset gets a new link when its parent was copied/reused.
        $copySource = $parent('Copy source', $sourceEntity);
        $copyDestination = $parent('Copy destination', $destinationEntity);
        $copyLink = $bind($copySource, 'Computer', $computer, $extra);
        $transfer->already_transfer = [$kind => [$copySource => $copyDestination, $destination => $destination, $secondDestination => $secondDestination]];
        $SQL_TOTAL_REQUEST = 0;
        $transfer->$method('Computer', $computer, $copy);
        verify($SQL_TOTAL_REQUEST === 0 && $read($linkTable, $copyLink)[$parentColumn] === $copySource, 'Copy link preserves source and bypasses adapter SQL: ' . $kind);
        $copiedLinks = (new RecordRepository(Orm::create($DB)))->matching($linkTable, [$parentColumn => $copyDestination, 'itemtype' => 'Computer', 'items_id' => $copy]);
        verify(count($copiedLinks) === 1, 'Copied asset links to its destination parent: ' . $kind);
        if ($kind === 'Document') {
            verify($copiedLinks[0]['entities_id'] === 0 && $copiedLinks[0]['timeline_position'] === 0, 'Minimal copied document link retains the historical root/default policy');
        }

        // All linked items move together, so the parent and its existing link move.
        $moveComputer = $fixtures->create('glpi_computers');
        $moveParent = $parent('Move in place', $sourceEntity);
        $moveLink = $bind($moveParent, 'Computer', $moveComputer, $extra);
        $transfer->already_transfer = [];
        $transfer->needtobe_transfer = ['Computer' => [$moveComputer]];
        // Public uniqueness validation must also bypass adapter SQL on a tree-cache miss.
        foreach ([$sourceEntity, $destinationEntity, 0] as $entity) {
            foreach (['ancestors_cache_', 'sons_cache_'] as $prefix) {
                $GLPI_CACHE->delete($prefix . 'glpi_entities_' . $entity);
                $GLPI_CACHE->delete($prefix . 'glpi_entities_' . md5((string)$entity));
            }
        }
        $SQL_TOTAL_REQUEST = 0;
        $transfer->$method('Computer', $moveComputer, $copy);
        verify($SQL_TOTAL_REQUEST === 0, 'Public parent move bypasses adapter SQL: ' . $kind);
        verify($read($table, $moveParent)['entities_id'] === $destinationEntity, 'Public parent move reaches the destination entity: ' . $kind);
        verify($read($linkTable, $moveLink)['items_id'] === $copy && $read($linkTable, $moveLink)[$parentColumn] === $moveParent, 'Same-parent asset copy retargets the existing link: ' . $kind);
        verify(in_array([$kind, $moveParent, $moveParent], $transfer->calls, true), 'In-place parent uses the transfer callback: ' . $kind);

        // No destination exists: real public add must copy literal fields.
        $newComputer = $fixtures->create('glpi_computers');
        $newName = "New ' \\ Unicode é " . $kind;
        $newParent = $parent($newName, $sourceEntity, ['comment' => "Copied ' comment\\"]);
        $newLink = $bind($newParent, 'Computer', $newComputer);
        $bind($newParent, 'Computer', $outside);
        $transfer->already_transfer = [];
        $transfer->needtobe_transfer = ['Computer' => [$newComputer]];
        $DB->clearSchemaCache();
        $SQL_TOTAL_REQUEST = 0;
        $transfer->$method('Computer', $newComputer, $newComputer);
        verify($SQL_TOTAL_REQUEST === 0, 'Public parent copy bypasses adapter SQL: ' . $kind);
        $newDestination = $transfer->already_transfer[$kind][$newParent];
        verify($newDestination !== $newParent && $read($table, $newDestination)['name'] === $newName && $read($table, $newDestination)['comment'] === "Copied ' comment\\", 'Real public copy preserves literal fields: ' . $kind);
        verify($read($table, $newDestination)['entities_id'] === $destinationEntity && $read($linkTable, $newLink)[$parentColumn] === $newDestination, 'Public copy and owning link land in destination: ' . $kind);

        foreach ([1, 2] as $cleanup) {
            $cleanComputer = $fixtures->create('glpi_computers');
            $cleanParent = $parent('Unused after transfer ' . $cleanup, $sourceEntity);
            $cleanLink = $bind($cleanParent, 'Computer', $cleanComputer);
            $transfer->already_transfer = [$kind => [$cleanParent => $destination]];
            $transfer->options['clean_' . $option] = $cleanup;
            $SQL_TOTAL_REQUEST = 0;
            $transfer->$method('Computer', $cleanComputer, $cleanComputer);
            verify($SQL_TOTAL_REQUEST === 0 && $read($linkTable, $cleanLink)[$parentColumn] === $destination, 'Owning cleanup bypasses adapter SQL: ' . $kind);
            $cleanRow = $read($table, $cleanParent);
            verify($cleanup === 1 ? $cleanRow['is_deleted'] === 1 : $cleanRow === null, 'Cleanup invokes public trash or purge: ' . $kind);
        }

        $transfer->options['keep_' . $option] = 0;
        $SQL_TOTAL_REQUEST = 0;
        $transfer->$method('Computer', $computer, $computer);
        verify($SQL_TOTAL_REQUEST === 0 && $read($linkTable, $monitorLink) !== null, 'Unlink is scoped to the source item kind: ' . $kind);
        verify((new RecordRepository(Orm::create($DB)))->countMatching($linkTable, ['itemtype' => 'Computer', 'items_id' => $computer]) === 0, 'Discard removes every selected binding: ' . $kind);
    }

    $firstDoc = $fixtures->create('glpi_documents');
    $secondDoc = $fixtures->create('glpi_documents');
    $fixtures->create('glpi_documents_items', ['documents_id' => $secondDoc, 'itemtype' => 'Document', 'items_id' => $firstDoc]);
    $fixtures->create('glpi_documents_items', ['documents_id' => $firstDoc, 'itemtype' => 'Computer', 'items_id' => $copy]);
    $SQL_TOTAL_REQUEST = 0;
    $types = Document_Item::getDistinctTypes($firstDoc);
    verify(count($types) === 2 && $types->next()['itemtype'] === 'Computer' && $types->next()['itemtype'] === 'Document', 'Distinct document kinds include reverse document links in sorted order');
    verify(iterator_to_array(Document_Item::getDistinctTypes($firstDoc, ['itemtype' => 'Document']), false) === [['itemtype' => 'Document']], 'Distinct kinds preserve subclass criteria and iterator behavior');
    verify($SQL_TOTAL_REQUEST === 0, 'Distinct kind discovery bypasses adapter SQL');
    verify((new ForeignKeys())->audit($connection) === [], 'Transfer graph retains all owning foreign keys');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}
echo $DB->getProvider() . ": contract/document transfer reuse, public copy, owning link move/copy/unlink, cleanup, literal names and distinct kinds passed.\n";
