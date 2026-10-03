<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/transfer-atomicity.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
/** A real unmapped plugin parent exercises the nontransactional storage diagnostic. */
class PluginTransferAtomicityProbe extends CommonDBTM
{
    protected static $forward_entity_to = [PluginTransferAtomicityChild::class];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_transfer_atomicity_probe';
    }

    public static function getForeignKeyField($classname = null)
    {
        return 'parents_id';
    }
}

class PluginTransferAtomicityChild extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_transfer_atomicity_child';
    }
}

/** An actual model override returning legacy integer zero after lifecycle work. */
class ZeroUpdateTransferDomain extends Domain
{
    public static ?self $attempted = null;

    public static function getTable($classname = null)
    {
        return Domain::getTable();
    }

    public static function getType()
    {
        return Domain::getType();
    }

    public function update(array $input, $history = 1, $options = [])
    {
        self::$attempted = $this;
        verify((new QueuedNotification())->add(['itemtype' => 'Domain', 'items_id' => $this->getID(), 'name' => 'Zero update attempted', 'send_time' => '2030-01-01 00:00:00']) > 0, 'Public override queues actual attempted lifecycle work');
        $this->fields['entities_id'] = $input['entities_id'];
        return 0;
    }
}

/** Actual queue transport observes committed ownership without external delivery. */
class PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe
{
    public static array $deliveries = [];

    public static function canCron(): bool
    {
        return true;
    }

    public static function send(array $rows): void
    {
        global $DB;
        foreach ($rows as $row) {
            $domain = new Domain();
            verify($domain->getFromDB($row['items_id']), 'Ownership probe resolves its actual committed Domain');
            self::$deliveries[] = [$DB->getDoctrineConnection()->getTransactionNestingLevel(), (int)$domain->fields['entities_id']];
            verify((new QueuedNotification())->update(['id' => $row['id'], 'is_deleted' => 1]), 'Ownership probe marks actual delivered queue row');
        }
    }
}

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
verify((new ReflectionMethod(Domain::class, 'validateEntityTransfer'))->getDeclaringClass()->getName() === Domain::class, 'Commercial Domain coherence extension must be integrated');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Contract starts outside a caller transaction');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $plugins->getValue();
$plugins->setValue(null, [...$savedPlugins, 'transfer_atomicity_fixture']);
$fixtures = new FixtureRecords($DB);
$created = [];
$prefix = 'Atomic transfer ' . bin2hex(random_bytes(5));
$record = static function (string $table, array $values = []) use ($fixtures, &$created): int {
    $id = $fixtures->create($table, $values);
    $created[] = [$table, $id];
    return $id;
};
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$rows = static fn (string $table, array $criteria): array => (new RecordRepository(Orm::create($DB)))->matching($table, $criteria, 'id ASC');
$checkpoint = static fn (Transfer $transfer): array => [
    $transfer->already_transfer, $transfer->needtobe_transfer, $transfer->noneedtobe_transfer,
    $transfer->options, $transfer->to, $transfer->inittype,
    \itsmng\Database\LifecycleModelJournal::state($transfer),
];
$graph = static function (int $commercial, int $financial, int $owner, ?int $nativeIdentifier = null) use ($record, $prefix): array {
    $identity = $nativeIdentifier === null ? [] : ['id' => $nativeIdentifier];
    $domain = $record('glpi_domains', ['name' => $prefix, 'entities_id' => $owner, 'suppliers_id' => $commercial] + $identity);
    $computer = $record('glpi_computers', ['name' => $prefix, 'entities_id' => $owner]);
    $infocom = $record('glpi_infocoms', ['itemtype' => 'Domain', 'items_id' => $domain, 'suppliers_id' => $financial]);
    $contract = $record('glpi_contracts', ['name' => $prefix, 'entities_id' => $owner]);
    $document = $record('glpi_documents', ['name' => $prefix, 'entities_id' => $owner]);
    $contractLink = $record('glpi_contracts_items', ['contracts_id' => $contract, 'itemtype' => 'Domain', 'items_id' => $domain] + ($nativeIdentifier === null ? [] : ['id' => $nativeIdentifier + 1]));
    $documentLink = $record('glpi_documents_items', ['documents_id' => $document, 'entities_id' => $owner, 'itemtype' => 'Domain', 'items_id' => $domain] + ($nativeIdentifier === null ? [] : ['id' => $nativeIdentifier + 2]));
    verify((new Domain())->update(['id' => $domain, 'comment' => 'Audited source']), 'Prepare actual source audit history');
    return compact('domain', 'computer', 'infocom', 'contract', 'document', 'contractLink', 'documentLink');
};
$snapshot = static function (array $graph) use ($read, $rows): array {
    return [
        $read('glpi_domains', $graph['domain']), $read('glpi_computers', $graph['computer']),
        $read('glpi_infocoms', $graph['infocom']), $read('glpi_contracts', $graph['contract']),
        $read('glpi_documents', $graph['document']), $read('glpi_contracts_items', $graph['contractLink']),
        $read('glpi_documents_items', $graph['documentLink']),
        $rows('glpi_logs', ['OR' => [['itemtype' => 'Domain', 'items_id' => $graph['domain']], ['itemtype' => 'Computer', 'items_id' => $graph['computer']]]]),
        $rows('glpi_queuednotifications', ['itemtype' => 'Domain', 'items_id' => $graph['domain']]),
    ];
};
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
try {
    $source = $record('glpi_entities', ['name' => $prefix . ' source', 'entities_id' => 0]);
    $destination = $record('glpi_entities', ['name' => $prefix . ' destination', 'entities_id' => 0]);
    $_SESSION['glpiactive_entity'] = $source;
    $_SESSION['glpiactiveentities'] = [0, $source, $destination];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    $local = $record('glpi_suppliers', ['name' => $prefix . ' local commercial', 'entities_id' => $source]);
    $recursive = $record('glpi_suppliers', ['name' => $prefix . ' recursive financial', 'entities_id' => 0, 'is_recursive' => true]);
    $recursiveCommercial = $record('glpi_suppliers', ['name' => $prefix . ' recursive commercial', 'entities_id' => 0, 'is_recursive' => true]);
    $CFG_GLPI['use_notifications'] = '1';
    $CFG_GLPI['notifications_ajax'] = true;
    $CFG_GLPI['notifications_mailing'] = false;
    $CFG_GLPI['notifications_chat'] = 0;
    Notification_NotificationTemplate::registerMode('ownershipprobe', 'Ownership probe', 'transfer_atomicity_fixture');
    $CFG_GLPI['notifications_ownershipprobe'] = true;
    Notification_NotificationTemplate::getModes();
    $flags = $CFG_GLPI;
    $incompatible = $graph($local, $recursive, $source);
    $before = $snapshot($incompatible);
    $transfer = new Transfer();
    $transfer->already_transfer = ['Domain' => [123 => 456]];
    $transfer->needtobe_transfer = ['Computer' => [321]];
    $transfer->noneedtobe_transfer = ['Supplier' => [456]];
    $transfer->options = ['keep_history' => 1];
    $transfer->to = $source;
    $transfer->inittype = 'Old state';
    $state = $checkpoint($transfer);
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Previous feedback']];
    $_SESSION['glpitransfer_list'] = ['Domain' => [$incompatible['domain']]];
    verify($transfer->moveItems(['Domain' => [$incompatible['domain']]], $destination, []) === false, 'Commercial supplier refuses an incompatible Domain transfer before auxiliary writes');
    verify($snapshot($incompatible) === $before, 'Refused Domain preserves owner, financial supplier, binding IDs, documents, contracts, audit and queue');
    verify($checkpoint($transfer) === $state && $connection->getTransactionNestingLevel() === 0, 'Refusal restores previous Transfer bookkeeping and releases its own transaction');
    verify($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] === ['Previous feedback'] && $_SESSION['glpitransfer_list'] === ['Domain' => [$incompatible['domain']]], 'Failed operation preserves previous feedback and selected list');
    verify($CFG_GLPI === $flags, 'Failed transfer restores prior enable-flag types and ancillary configuration');

    $locationRootName = $prefix . ' location root';
    $locationLeafName = $prefix . ' location leaf';
    $sourceLocation = (new Location())->import(['completename' => $locationRootName . ' > ' . $locationLeafName, 'entities_id' => $source]);
    verify((int)$sourceLocation > 0, 'Actual source Location import prepares a two-level tree');
    $sourceLocations = $rows('glpi_locations', ['entities_id' => $source, 'name' => [$locationRootName, $locationLeafName]]);
    verify(count($sourceLocations) === 2, 'Source Location tree has both actual nodes');
    foreach ($sourceLocations as $location) {
        $created[] = ['glpi_locations', (int)$location['id']];
    }
    $locationGraph = $graph($recursiveCommercial, $recursive, $source);
    verify((new Computer())->update(['id' => $locationGraph['computer'], 'locations_id' => $sourceLocation, 'comment' => 'Location transfer audit']), 'Public Computer assignment prepares source Location and audit');
    $locationBefore = $snapshot($locationGraph);
    foreach ([false, true] as $callerTransaction) {
        $attemptedNodes = [];
        $PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture'][Location::class] = static function (Location $item) use ($destination, $locationRootName, &$attemptedNodes): void {
            if ((int)($item->input['entities_id'] ?? -1) === $destination) {
                $attemptedNodes[] = $item->input['name'];
                if ($item->input['name'] === $locationRootName) {
                    $item->input = false;
                }
            }
        };
        if ($callerTransaction) {
            $connection->beginTransaction();
        }
        try {
            $marker = $callerTransaction ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' location caller marker', 'entities_id' => 0]) : null;
            $level = $connection->getTransactionNestingLevel();
            verify((new Transfer())->moveItems(['Computer' => [$locationGraph['computer']]], $destination, ['keep_history' => 0]) === false, 'Refused intermediate Location import cancels the actual transfer');
            verify($attemptedNodes === [$locationRootName], 'Refused Location ancestor stops before a leaf can be created at the root');
            verify($snapshot($locationGraph) === $locationBefore && $rows('glpi_locations', ['entities_id' => $source, 'name' => [$locationRootName, $locationLeafName]]) === $sourceLocations, 'Location refusal restores source owner, location, history and source tree');
            verify($rows('glpi_locations', ['entities_id' => $destination, 'name' => [$locationRootName, $locationLeafName]]) === [], 'Location refusal leaves no target tree nodes');
            verify($connection->getTransactionNestingLevel() === $level && (!$callerTransaction || $read('glpi_suppliers', $marker) !== null), 'Location refusal preserves caller transaction and prior marker');
        } finally {
            if ($callerTransaction) {
                $connection->rollBack();
            }
            unset($PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture']);
        }
    }
    verify((new Transfer())->moveItems(['Computer' => [$locationGraph['computer']]], $destination, ['keep_history' => 1]) === true, 'Accepted full Location tree permits actual transfer');
    $targetLocations = $rows('glpi_locations', ['entities_id' => $destination, 'name' => [$locationRootName, $locationLeafName]]);
    verify(count($targetLocations) === 2, 'Accepted Location transfer creates both target levels');
    foreach ($targetLocations as $location) {
        $created[] = ['glpi_locations', (int)$location['id']];
    }
    $targetLeaf = $read('glpi_locations', (int)$read('glpi_computers', $locationGraph['computer'])['locations_id']);
    $targetRoot = $read('glpi_locations', (int)$targetLeaf['locations_id']);
    verify(
        $targetLeaf['name'] === $locationLeafName && $targetRoot['name'] === $locationRootName
        && (int)$targetRoot['entities_id'] === $destination && $targetLeaf['completename'] === $locationRootName . ' > ' . $locationLeafName,
        'Accepted transfer preserves target ancestor identity and complete path'
    );

    // The import boundary propagates failure; standalone import retains its
    // established per-node transaction semantics. Its caller owns atomicity.
    foreach ([Location::class, TaskCategory::class] as $treeType) {
        $tree = new $treeType();
        $rootName = $prefix . ' ' . $treeType . ' direct root';
        $leafName = $prefix . ' ' . $treeType . ' direct leaf';
        $treeInput = ['completename' => $rootName . ' > ' . $leafName, 'entities_id' => $destination];
        $treeCriteria = ['name' => [$rootName, $leafName]];
        $PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture'][$treeType] = static function (CommonTreeDropdown $item) use ($leafName): void {
            if ($item->input['name'] === $leafName) {
                $item->input = false;
            }
        };
        $connection->beginTransaction();
        try {
            $level = $connection->getTransactionNestingLevel();
            verify($tree->import($treeInput) === false, 'Direct ' . $treeType . ' import propagates refused leaf');
            $acceptedAncestors = $rows($tree->getTable(), $treeCriteria);
            verify(count($acceptedAncestors) === 1 && $acceptedAncestors[0]['name'] === $rootName
                && $connection->getTransactionNestingLevel() === $level, 'Direct import leaves accepted ancestor and frame under caller control');
        } finally {
            $connection->rollBack();
        }
        verify($rows($tree->getTable(), $treeCriteria) === [], 'Caller rollback removes accepted direct-import ancestor');
        verify($tree->import($treeInput) === false, 'Standalone ' . $treeType . ' import reports its refused leaf');
        $acceptedAncestors = $rows($tree->getTable(), $treeCriteria);
        verify(count($acceptedAncestors) === 1 && $acceptedAncestors[0]['name'] === $rootName, 'Standalone import keeps its earlier accepted ancestor without claiming implicit atomicity');
        $created[] = [$tree->getTable(), (int)$acceptedAncestors[0]['id']];
        unset($PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture']);
        $leafId = $tree->import($treeInput);
        verify((int)$leafId > 0 && $tree->import($treeInput) == $leafId && count($rows($tree->getTable(), $treeCriteria)) === 2, 'Accepted direct full-tree import reuses its valid ancestor and duplicate leaf');
        $leaf = $read($tree->getTable(), (int)$leafId);
        verify((int)$leaf[$tree->getForeignKeyField()] === (int)$acceptedAncestors[0]['id'], 'Accepted direct leaf keeps its actual parent identifier');
        $created[] = [$tree->getTable(), (int)$leafId];
    }

    $_SESSION['glpiactiveprofile']['managed_domainrecordtypes'] = [-1];
    foreach (['standalone', 'owned transfer', 'caller transfer'] as $forwardContext) {
        $forwardGraph = $graph($recursiveCommercial, $recursive, $source);
        $domainRecord = $record('glpi_domainrecords', ['name' => $prefix . ' forwarded record', 'domains_id' => $forwardGraph['domain'], 'entities_id' => $source]);
        $forwardBefore = $snapshot($forwardGraph);
        $recordBefore = $read('glpi_domainrecords', $domainRecord);
        $heldForwarded = null;
        $forwardCalls = 0;
        PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries = [];
        $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item) use ($forwardGraph, &$created): void {
            if ((int)$item->getID() === $forwardGraph['domain']) {
                $id = (int)(new QueuedNotification())->add(['itemtype' => 'Domain', 'items_id' => $item->getID(), 'mode' => 'ownershipprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Ownership unit probe']);
                verify($id > 0, 'Actual parent completion queues work before required child forwarding');
                $created[] = ['glpi_queuednotifications', $id];
                QueuedNotification::forceSendFor('Domain', $item->getID());
                QueuedNotification::forceSendFor('Domain', $item->getID());
                verify(PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [], 'Repeated force-send stays deferred inside required ownership frame');
            }
        };
        $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][DomainRecord::class] = static function (DomainRecord $item) use (&$heldForwarded, &$forwardCalls, $domainRecord): void {
            if ((int)$item->getID() === $domainRecord && isset($item->input['_transfer'])) {
                ++$forwardCalls;
                $heldForwarded = $item;
                Session::addMessageAfterRedirect('Required forwarded child refused', false, WARNING);
                $item->input = false;
            }
        };
        if ($forwardContext === 'caller transfer') {
            $connection->beginTransaction();
        }
        try {
            $level = $connection->getTransactionNestingLevel();
            $marker = $level ? $fixtures->create('glpi_suppliers', ['name' => $prefix . ' forwarded caller marker', 'entities_id' => 0]) : null;
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Forwarding previous feedback']];
            $model = new Domain();
            $result = $forwardContext === 'standalone'
                ? $model->update(['id' => $forwardGraph['domain'], 'entities_id' => $destination, 'update' => 'Attempt ownership change'])
                : (new Transfer())->moveItems(['Computer' => [$forwardGraph['computer']], 'Domain' => [$forwardGraph['domain']]], $destination, ['keep_history' => 1]);
            verify($result === false && $forwardCalls === 1, 'Required DomainRecord refusal propagates in ' . $forwardContext);
            verify(PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [], 'Forwarded refusal delivers no rolled-back notification');
            verify($snapshot($forwardGraph) === $forwardBefore && $read('glpi_domainrecords', $domainRecord) === $recordBefore, 'Forwarded refusal restores actual parent, earlier sibling, child, history and queued rows');
            verify($heldForwarded instanceof DomainRecord && \itsmng\Database\LifecycleModelJournal::state($heldForwarded) === ['fields' => $recordBefore], 'Actual refused forwarded child restores its loaded model state');
            verify($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] === ['Forwarding previous feedback']
                && in_array('Required forwarded child refused', $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [], true), 'Forwarding rollback discards success feedback and retains useful diagnostics');
            verify($connection->getTransactionNestingLevel() === $level && ($marker === null || $read('glpi_suppliers', $marker) !== null), 'Forwarded refusal preserves caller frame and prior marker');
            if ($forwardContext === 'standalone') {
                verify((int)$model->fields['entities_id'] === $source && (int)$model->input['entities_id'] === $destination
                    && $model->updates === [] && $model->oldvalues === [], 'Standalone owning parent keeps stored fields and attempted input without pending writes');
            }
        } finally {
            if ($forwardContext === 'caller transfer') {
                $connection->rollBack();
            }
            unset($PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture']);
        }
        $connection->beginTransaction();
        try {
            $level = $connection->getTransactionNestingLevel();
            verify((new Domain())->update(['id' => $forwardGraph['domain'], 'entities_id' => $destination]) === true, 'Accepted caller-owned parent update releases only its ownership savepoint');
            $pending = $rows('glpi_queuednotifications', ['itemtype' => 'Domain', 'items_id' => $forwardGraph['domain']]);
            verify(count($pending) === 1 && !(bool)$pending[0]['is_deleted']
                && PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === []
                && $connection->getTransactionNestingLevel() === $level, 'Caller savepoint leaves actual notification unsent and retains caller ownership');
        } finally {
            $connection->rollBack();
        }
        verify($snapshot($forwardGraph) === $forwardBefore && $read('glpi_domainrecords', $domainRecord) === $recordBefore
            && PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [], 'Later caller rollback removes accepted ownership writes and pending queue without delivery');
        verify((new Domain())->update(['id' => $forwardGraph['domain'], 'entities_id' => $destination]) === true
            && (int)$read('glpi_domainrecords', $domainRecord)['entities_id'] === $destination, 'Accepted standalone Domain ownership forwards its required child');
        verify(PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [[0, $destination]], 'Deduplicated notification delivery observes physical ownership commit');
        unset($PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture']);
    }

    foreach ([false, true] as $acceptNestedOwnership) {
        $nestedGraph = $graph($recursiveCommercial, $recursive, $source);
        $nestedRecord = $record('glpi_domainrecords', ['domains_id' => $nestedGraph['domain'], 'entities_id' => $source, 'name' => $prefix . ' nested record']);
        $nestedGroup = $record('glpi_groups', ['name' => $prefix . ' nested deletion', 'entities_id' => $source]);
        $group = new Group();
        verify($group->getFromDB($nestedGroup), 'Nested deletion loads its actual source model');
        $groupState = \itsmng\Database\LifecycleModelJournal::state($group);
        PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries = [];
        $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item) use ($nestedGraph, $group, $nestedGroup): void {
            if ((int)$item->getID() === $nestedGraph['domain']) {
                verify($group->delete(['id' => $nestedGroup, '_no_history' => true], true), 'Actual parent completion performs nested public deletion');
            }
        };
        $PLUGIN_HOOKS['pre_item_purge']['transfer_atomicity_fixture'][Group::class] = static function (Group $item) use ($nestedGroup, $nestedGraph, &$created): void {
            if ((int)$item->getID() === $nestedGroup) {
                $id = (int)(new QueuedNotification())->add(['itemtype' => 'Domain', 'items_id' => $nestedGraph['domain'], 'mode' => 'ownershipprobe', 'send_time' => '2026-01-01 00:00:00', 'name' => 'Nested lifecycle probe']);
                verify($id > 0, 'Nested deletion creates its actual notification');
                $created[] = ['glpi_queuednotifications', $id];
                QueuedNotification::forceSendFor('Domain', $nestedGraph['domain']);
                verify(PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [], 'Nested deletion scope defers notification before ownership outcome');
            }
        };
        if (!$acceptNestedOwnership) {
            $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][DomainRecord::class] = static function (DomainRecord $item) use ($nestedRecord): void {
                if ((int)$item->getID() === $nestedRecord) {
                    $item->input = false;
                }
            };
        }
        verify((new Domain())->update(['id' => $nestedGraph['domain'], 'entities_id' => $destination]) === $acceptNestedOwnership, 'Nested deletion and owning update propagate the actual final outcome');
        if ($acceptNestedOwnership) {
            verify($read('glpi_groups', $nestedGroup) === null
                && PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [[0, $destination]], 'Nested deletion notification merges into ownership scope and delivers after physical commit');
        } else {
            verify($read('glpi_groups', $nestedGroup) !== null
                && \itsmng\Database\LifecycleModelJournal::state($group) === $groupState
                && $rows('glpi_queuednotifications', ['itemtype' => 'Domain', 'items_id' => $nestedGraph['domain']]) === []
                && PluginTransfer_atomicity_fixtureNotificationEventOwnershipprobe::$deliveries === [], 'Outer owning refusal restores nested deletion/model and discards its queued delivery');
        }
        unset($PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['pre_item_purge']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture']);
    }

    $forwardGraph = $graph($recursiveCommercial, $recursive, $source);
    $laterGraph = $graph($recursiveCommercial, $recursive, $source);
    $domainRecord = $record('glpi_domainrecords', ['name' => $prefix . ' retained accepted record', 'domains_id' => $forwardGraph['domain'], 'entities_id' => $source]);
    $recordBefore = $read('glpi_domainrecords', $domainRecord);
    $forwardBefore = $snapshot($forwardGraph);
    $laterBefore = $snapshot($laterGraph);
    $heldForwarded = null;
    $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'][DomainRecord::class] = static function (DomainRecord $item) use (&$heldForwarded, $domainRecord): void {
        if ((int)$item->getID() === $domainRecord) {
            $heldForwarded = $item;
        }
    };
    $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item) use ($laterGraph): void {
        if ((int)$item->getID() === $laterGraph['domain'] && isset($item->input['_transfer'])) {
            $item->input = false;
        }
    };
    verify((new Transfer())->moveItems(['Domain' => [$forwardGraph['domain'], $laterGraph['domain']]], $destination, ['keep_history' => 1]) === false, 'Later parent refusal rolls back an earlier accepted ownership forwarding unit');
    verify($snapshot($forwardGraph) === $forwardBefore && $snapshot($laterGraph) === $laterBefore
        && $read('glpi_domainrecords', $domainRecord) === $recordBefore, 'Later sibling refusal restores earlier forwarded child and both actual parent graphs');
    verify($heldForwarded instanceof DomainRecord && \itsmng\Database\LifecycleModelJournal::state($heldForwarded) === ['fields' => $recordBefore], 'Transfer observer restores successfully forwarded model retained by a real completion hook');
    unset($PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture']);

    // A coherent candidate permits all early auxiliary work, then its real public
    // update/hook refuses. Already-transferred siblings must roll back as well.
    foreach (['update refusal', 'late throw'] as $case) {
        $valid = $graph($recursiveCommercial, $recursive, $source);
        $before = $snapshot($valid);
        $visited = [];
        $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item) use (&$visited, $case): void {
            if (isset($item->input['_transfer'])) {
                $visited[] = $item->getID();
                Session::addMessageAfterRedirect('Rolled back success', false, INFO);
                if ($case === 'update refusal') {
                    Session::addMessageAfterRedirect('Required transfer was refused', false, WARNING);
                    $item->input = false;
                }
            }
        };
        $heldModel = null;
        $heldSibling = null;
        $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'][Computer::class] = static function (Computer $item) use (&$heldSibling): void {
            if (isset($item->input['_transfer'])) {
                $heldSibling = $item;
            }
        };
        $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item) use (&$heldModel): void {
            if (isset($item->input['_transfer'])) {
                $heldModel = $item;
                verify((new QueuedNotification())->add(['itemtype' => 'Domain', 'items_id' => $item->getID(), 'name' => 'Transfer late queue', 'send_time' => '2030-01-01 00:00:00']) > 0, 'Real update hook queues a notification inside the operation');
            }
        };
        $PLUGIN_HOOKS['item_transfer']['transfer_atomicity_fixture'] = static function (array $event) use ($case): void {
            if ($case === 'late throw' && $event['type'] === 'Domain') {
                throw new RuntimeException('Actual late item_transfer hook refusal');
            }
        };
        $transfer = new Transfer();
        $state = $checkpoint($transfer);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Previous feedback']];
        $connection->beginTransaction();
        try {
            $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' caller marker', 'entities_id' => 0]);
            $callerLevel = $connection->getTransactionNestingLevel();
            verify($transfer->moveItems(['Computer' => [$valid['computer']], 'Domain' => [$valid['domain']]], $destination, []) === false, 'Actual ' . $case . ' stops the entire selected batch');
            verify($visited === [$valid['domain']], 'Transfer executes public prepare/hook once without replay');
            verify($snapshot($valid) === $before && $checkpoint($transfer) === $state, 'Late refusal rolls back earlier items, dependencies, audit, queued rows and bookkeeping');
            verify(
                $heldSibling instanceof Computer && \itsmng\Database\LifecycleModelJournal::state($heldSibling) === ['fields' => $before[1]],
                'Earlier successfully updated model retained by a real hook is restored after later sibling refusal'
            );
            verify($connection->getTransactionNestingLevel() === $callerLevel && $read('glpi_suppliers', $marker) !== null, 'Caller transaction and its prior marker remain intact');
            verify($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] === ['Previous feedback'], 'Rolled-back success feedback is discarded');
            if ($case === 'update refusal') {
                verify(in_array('Required transfer was refused', $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [], true), 'Useful actual refusal diagnostic is retained');
            } else {
                verify($heldModel instanceof Domain && (int)$heldModel->fields['entities_id'] === $source, 'Captured public source model is restored after a late hook exception');
            }
            verify($DB->getDoctrineConnection() === $connection && $CFG_GLPI === $flags, 'Late refusal retains supplied writer and exact notification settings');
        } finally {
            $connection->rollBack();
        }
        unset($PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['item_update']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['item_transfer']['transfer_atomicity_fixture']);

        // The same public transferItem entry point has its own frame without a batch.
        $transfer->to = $destination;
        $transfer->options = ['keep_networklink' => 0, 'keep_device' => 0, 'keep_reservation' => 0, 'keep_history' => 0, 'keep_ticket' => 0, 'keep_infocom' => 0, 'keep_contract' => 0, 'keep_document' => 0];
        $transfer->noneedtobe_transfer = [];
        $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item): void {
            if (isset($item->input['_transfer'])) {
                $item->input = false;
            }
        };
        verify($transfer->transferItem('Domain', $valid['domain'], $valid['domain']) === false && $connection->getTransactionNestingLevel() === 0, 'Direct transferItem refusal owns and closes its transaction');
        verify($snapshot($valid) === $before, 'Direct refusal restores early dependency deletes');
        unset($PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture']);
    }

    $diskGraph = $graph($recursiveCommercial, $recursive, $source);
    $disk = $record('glpi_items_disks', ['itemtype' => 'Computer', 'items_id' => $diskGraph['computer'], 'name' => $prefix]);
    $diskBefore = $read('glpi_items_disks', $disk);
    $before = $snapshot($diskGraph);
    $PLUGIN_HOOKS['pre_item_purge']['transfer_atomicity_fixture'][Item_Disk::class] = static function (Item_Disk $item): void {
        $item->input = false;
    };
    verify((new Transfer())->moveItems(['Computer' => [$diskGraph['computer']]], $destination, []) === false, 'Selected public disk cleanup refusal cancels parent transfer');
    verify($snapshot($diskGraph) === $before && $read('glpi_items_disks', $disk) === $diskBefore, 'Disk refusal restores parent owner/history and preserves the disk');
    unset($PLUGIN_HOOKS['pre_item_purge']['transfer_atomicity_fixture']);

    $copyFinancial = $record('glpi_suppliers', ['name' => $prefix . ' copied financial', 'entities_id' => $source]);
    $copyGraph = $graph($recursiveCommercial, $copyFinancial, $source);
    $outside = $record('glpi_domains', ['name' => $prefix . ' outside', 'entities_id' => $source]);
    $outsideInfocom = $record('glpi_infocoms', ['itemtype' => 'Domain', 'items_id' => $outside, 'suppliers_id' => $copyFinancial]);
    $before = $snapshot($copyGraph);
    $outsideBefore = $read('glpi_infocoms', $outsideInfocom);
    $suppliersBefore = $rows('glpi_suppliers', ['entities_id' => $destination]);
    $creates = 0;
    $heldCopyAttempt = null;
    $PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture'][Supplier::class] = static function (Supplier $item) use (&$creates, &$heldCopyAttempt): void {
        ++$creates;
        $heldCopyAttempt = $item;
        $item->input = false;
    };
    verify((new Transfer())->moveItems(['Domain' => [$copyGraph['domain']]], $destination, ['keep_infocom' => 1, 'keep_supplier' => 1]) === false, 'Required financial Supplier copy refusal cancels transfer');
    verify($creates === 1, 'Required child add is attempted once through its real lifecycle');
    verify(
        $snapshot($copyGraph) === $before && $read('glpi_infocoms', $outsideInfocom) === $outsideBefore
        && $rows('glpi_suppliers', ['entities_id' => $destination]) === $suppliersBefore,
        'Refused child creation restores its parent and does not retarget outside financial links'
    );
    verify(
        $heldCopyAttempt instanceof Supplier && \itsmng\Database\LifecycleModelJournal::state($heldCopyAttempt) === ['fields' => $read('glpi_suppliers', $copyFinancial)],
        'Refused copied-add model restores its loaded source before legacy field clearing'
    );
    unset($PLUGIN_HOOKS['pre_item_add']['transfer_atomicity_fixture']);

    $heldAddedCopy = null;
    $heldLoadedCopy = null;
    $newCopy = null;
    $sourceCopyFields = $read('glpi_suppliers', $copyFinancial);
    $PLUGIN_HOOKS['item_add']['transfer_atomicity_fixture'][Supplier::class] = static function (Supplier $item) use (&$heldAddedCopy, &$newCopy): void {
        $heldAddedCopy = $item;
        $newCopy = (int)$item->getID();
    };
    $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][Supplier::class] = static function (Supplier $item) use (&$heldLoadedCopy, &$newCopy): void {
        if (isset($item->input['_transfer']) && (int)$item->getID() === $newCopy) {
            $heldLoadedCopy = $item;
        }
    };
    $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture'][Domain::class] = static function (Domain $item): void {
        if (isset($item->input['_transfer'])) {
            $item->input = false;
        }
    };
    verify(
        (new Transfer())->moveItems(['Domain' => [$copyGraph['domain']]], $destination, ['keep_infocom' => 1, 'keep_supplier' => 1]) === false,
        'Successful financial Supplier copy is rolled back after a later actual Domain refusal'
    );
    verify(
        is_int($newCopy) && $newCopy > 0 && $read('glpi_suppliers', $newCopy) === null
        && $snapshot($copyGraph) === $before && $read('glpi_infocoms', $outsideInfocom) === $outsideBefore,
        'Late refusal removes the created copy and restores both financial relationships'
    );
    verify(
        $heldAddedCopy instanceof Supplier && \itsmng\Database\LifecycleModelJournal::state($heldAddedCopy) === ['fields' => $sourceCopyFields],
        'Successful copied-add hook instance restores original loaded source fields and pending state'
    );
    verify(
        $heldLoadedCopy instanceof Supplier && \itsmng\Database\LifecycleModelJournal::state($heldLoadedCopy) === ['fields' => []],
        'New recursive copy model restores its original unloaded state instead of retaining a phantom row identifier'
    );
    unset($PLUGIN_HOOKS['item_add']['transfer_atomicity_fixture'], $PLUGIN_HOOKS['pre_item_update']['transfer_atomicity_fixture']);

    $zeroGraph = $graph($recursiveCommercial, $recursive, $source);
    $before = $snapshot($zeroGraph);
    verify((new Transfer())->moveItems([ZeroUpdateTransferDomain::class => [$zeroGraph['domain']]], $destination, []) === false, 'Real public model override integer-zero refusal cancels transfer');
    verify($snapshot($zeroGraph) === $before && $connection->getTransactionNestingLevel() === 0, 'Integer-zero refusal rolls back actual queued work');
    verify(
        ZeroUpdateTransferDomain::$attempted instanceof ZeroUpdateTransferDomain
        && (int)ZeroUpdateTransferDomain::$attempted->fields['entities_id'] === $source,
        'Integer-zero refusal restores the actual attempted public model'
    );

    foreach (['false', 'throw', 'void'] as $outcome) {
        $recursiveGraph = $graph($recursiveCommercial, $recursive, $source);
        $before = $snapshot($recursiveGraph);
        $recursiveTransfer = new class ($outcome) extends Transfer {
            public int $recursiveCalls = 0;

            public function __construct(private string $outcome)
            {
            }

            public function transferItem($itemtype, $ID, $newID)
            {
                if ($itemtype === 'Contract') {
                    ++$this->recursiveCalls;
                    if ($this->outcome === 'false') {
                        return false;
                    }
                    if ($this->outcome === 'throw') {
                        throw new RuntimeException('Recursive public transfer override refused');
                    }
                    parent::transferItem($itemtype, $ID, $newID);
                    return; // Real work through the existing void override contract.
                }
                return parent::transferItem($itemtype, $ID, $newID);
            }
        };
        $success = $recursiveTransfer->moveItems(['Computer' => [$recursiveGraph['computer']], 'Domain' => [$recursiveGraph['domain']]], $destination, ['keep_contract' => 1]);
        verify($recursiveTransfer->recursiveCalls === 1, 'Recursive public transfer override is invoked exactly once');
        if ($outcome === 'void') {
            verify(
                $success === true && (int)$read('glpi_contracts', $recursiveGraph['contract'])['entities_id'] === $destination,
                'Existing void override compatibility retains its real recursive parent mutation'
            );
        } else {
            verify(
                $success === false && $snapshot($recursiveGraph) === $before,
                'Recursive explicit ' . $outcome . ' refusal rolls back earlier parent and sibling work'
            );
        }
        verify($connection->getTransactionNestingLevel() === 0 && $CFG_GLPI === $flags, 'Recursive outcome restores operation ownership and flags');
    }

    $callerGraph = $graph($recursiveCommercial, $recursive, $source);
    $before = $snapshot($callerGraph);
    $connection->beginTransaction();
    try {
        $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' accepted caller marker', 'entities_id' => 0]);
        $callerLevel = $connection->getTransactionNestingLevel();
        verify(
            (new Transfer())->moveItems(
                ['Domain' => [$callerGraph['domain']]],
                $destination,
                ['keep_infocom' => 1, 'keep_supplier' => 1, 'keep_contract' => 1, 'keep_document' => 1, 'keep_history' => 1]
            ) === true,
            'Successful transfer releases its savepoint inside the caller transaction'
        );
        verify(
            $connection->getTransactionNestingLevel() === $callerLevel && $read('glpi_suppliers', $marker) !== null
            && (int)$read('glpi_domains', $callerGraph['domain'])['entities_id'] === $destination,
            'Successful transfer retains caller ownership and exposes its still-uncommitted writes'
        );
    } finally {
        $connection->rollBack();
    }
    verify(
        $snapshot($callerGraph) === $before && $read('glpi_suppliers', $marker) === null,
        'Later caller rollback restores the successful transfer and its own marker without a physical commit'
    );

    // This parent keeps a distinct recursive financial supplier; no new copy or
    // clearing semantics are invented for its optional commercial relationship.
    $success = $graph($recursiveCommercial, $recursive, $source, 42949680009);
    $transfer = new Transfer();
    $options = ['keep_infocom' => 1, 'keep_supplier' => 1, 'keep_contract' => 1, 'keep_document' => 1, 'keep_history' => 1];
    verify($transfer->moveItems(['Domain' => [$success['domain']]], $destination, $options) === true, 'Compatible recursive Supplier permits transfer');
    verify((int)$read('glpi_domains', $success['domain'])['entities_id'] === $destination
        && (int)$read('glpi_domains', $success['domain'])['suppliers_id'] === $recursiveCommercial
        && (int)$read('glpi_infocoms', $success['infocom'])['suppliers_id'] === $recursive, 'Successful transfer preserves separate commercial and financial roles');
    verify($read('glpi_documents_items', $success['documentLink']) !== null && $read('glpi_contracts_items', $success['contractLink']) !== null, 'Successful in-place transfer preserves individual link identifiers');
    verify(
        $transfer->noneedtobe_transfer['Contract'] === []
        && (int)$read('glpi_contracts', $success['contract'])['entities_id'] === $destination
        && (int)$read('glpi_documents', $success['document'])['entities_id'] === $destination,
        'Empty exclusions retain every sole local binding and move its original Contract/Document parent'
    );
    verify(
        (int)$read('glpi_contracts_items', $success['contractLink'])['items_id'] === 42949680009
        && (int)$read('glpi_documents_items', $success['documentLink'])['items_id'] === 42949680009,
        'Native 64-bit subject and original binding identifiers retain canonical projections'
    );
    verify($CFG_GLPI === $flags && $connection->getTransactionNestingLevel() === 0, 'Successful transfer restores temporary settings and commits its own frame');
    $before = $snapshot($success);
    verify($transfer->moveItems(['Domain' => [$success['domain']]], $destination, $options) === true, 'No-op transfer to the same owner remains successful');
    verify($snapshot($success) === $before, 'No-op transfer preserves data and adds no synthetic update history');
    verify($transfer->moveItems([], $destination, []) === true, 'Empty selected batch is a successful no-op');
    verify($transfer->moveItems(['Domain' => [PHP_INT_MAX]], $destination, []) === false, 'Missing selected item is a refusal');
    verify($transfer->moveItems(['Domain' => [$success['domain']]], -1, []) === false && $CFG_GLPI === $flags, 'Invalid destination refuses and restores temporary flags');
    $primary = $DB;
    $DB = clone $primary;
    $DB->slave = true;
    try {
        verify((new Transfer())->moveItems(['Domain' => [$success['domain']]], $source, []) === false, 'Read-only supplied adapter cannot select an auxiliary writer');
        verify($snapshot($success) === $before && $connection->getTransactionNestingLevel() === 0, 'Read-only refusal writes nothing and does not alter caller transaction');
    } finally {
        $DB = $primary;
    }
    $owningLink = $record('glpi_links', ['name' => $prefix . ' physical owner', 'entities_id' => $source]);
    $inheritedLink = $record('glpi_links_itemtypes', ['links_id' => $owningLink, 'itemtype' => 'Computer']);
    $inherited = new Link_Itemtype();
    verify(
        $inherited->getFromDB($inheritedLink) && $inherited->isEntityAssign()
        && !$inherited->isField('entities_id') && $inherited->getEntityID() === $source,
        'Actual child derives entity scope from its owning Link without a physical owner column'
    );
    verify(
        !array_key_exists('MassiveAction:add_transfer_list', $inherited->getSpecificMassiveActions()),
        'Inherited-only entity scope does not expose a direct UI transfer action'
    );
    $linkBefore = $read('glpi_links', $owningLink);
    $childBefore = $read('glpi_links_itemtypes', $inheritedLink);
    $linkHistory = $rows('glpi_logs', ['itemtype' => 'Link', 'items_id' => $owningLink]);
    $_SESSION['glpitransfer_list'] = ['Link_Itemtype' => [$inheritedLink]];
    verify(
        (new Transfer())->moveItems(['Link_Itemtype' => [$inheritedLink]], $destination, []) === false,
        'Inherited-only child directly supplied to the batch is refused before simulation writes'
    );
    $unsupported = new Transfer();
    $unsupported->to = $destination;
    verify(
        $unsupported->transferItem('Link_Itemtype', $inheritedLink, $inheritedLink) === false,
        'Direct transferItem also refuses unsupported inherited-only ownership'
    );
    verify(
        $read('glpi_links', $owningLink) === $linkBefore && $read('glpi_links_itemtypes', $inheritedLink) === $childBefore
        && $rows('glpi_logs', ['itemtype' => 'Link', 'items_id' => $owningLink]) === $linkHistory
        && $_SESSION['glpitransfer_list'] === ['Link_Itemtype' => [$inheritedLink]],
        'Unsupported transfer leaves actual owner, link identity, history and selected list unchanged'
    );
    verify(
        (new Transfer())->moveItems(['Link' => [$owningLink]], $destination, ['keep_history' => 1]) === true,
        'Existing supported owning-Link transfer remains available'
    );
    $inherited = new Link_Itemtype();
    verify(
        $inherited->getFromDB($inheritedLink) && $inherited->getEntityID() === $destination
        && $read('glpi_links_itemtypes', $inheritedLink) === $childBefore,
        'Child effective scope follows its transferred parent without inventing a child owner column'
    );
    if ($DB->getProvider() === 'mysql') {
        $schema = $connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table(PluginTransferAtomicityProbe::getTable());
        $table->addColumn('id', 'bigint');
        $table->addColumn('entities_id', 'bigint');
        $table->addColumn('name', 'string', ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $table->addOption('engine', 'MyISAM');
        $childTable = new \Doctrine\DBAL\Schema\Table(PluginTransferAtomicityChild::getTable());
        $childTable->addColumn('id', 'bigint');
        $childTable->addColumn('parents_id', 'bigint');
        $childTable->addColumn('entities_id', 'bigint');
        $childTable->setPrimaryKey(['id']);
        $childTable->addOption('engine', 'InnoDB');
        verify(!$DB->tableExists($table->getName(), false), 'Disposable MyISAM probe table does not already exist');
        verify(!$DB->tableExists($childTable->getName(), false), 'Disposable actual forwarding child table does not already exist');
        $schema->createTable($table);
        $schema->createTable($childTable);
        $DB->clearSchemaCache();
        try {
            $connection->insert($table->getName(), ['id' => 1, 'entities_id' => $source, 'name' => $prefix]);
            $connection->insert($childTable->getName(), ['id' => 1, 'parents_id' => 1, 'entities_id' => $source]);
            $probeBefore = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table->getName()) . ' WHERE id = 1');
            verify((new Transfer())->moveItems([PluginTransferAtomicityProbe::class => [1]], $destination, []) === false, 'Selected MyISAM plugin parent refuses before any mutation');
            verify($connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table->getName()) . ' WHERE id = 1') === $probeBefore
                && $connection->getTransactionNestingLevel() === 0, 'Nontransactional diagnostic preserves its parent row without a rollback claim');
            foreach ([$table->getName(), $childTable->getName()] as $nontransactionalTable) {
                if ($nontransactionalTable === $childTable->getName()) {
                    $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($table->getName()) . ' ENGINE=InnoDB');
                    $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($childTable->getName()) . ' ENGINE=MyISAM');
                    $DB->clearSchemaCache();
                }
                $probe = new PluginTransferAtomicityProbe();
                try {
                    $probe->update(['id' => 1, 'entities_id' => $destination]);
                    throw new LogicException('Expected native ownership storage diagnostic');
                } catch (RuntimeException $error) {
                    verify($error->getMessage() === 'Ownership update requires InnoDB storage for ' . $nontransactionalTable, 'Standalone actual ownership unit preflights its nontransactional parent or registered child');
                }
                verify($connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table->getName()) . ' WHERE id = 1') === $probeBefore
                    && (int)$connection->fetchOne('SELECT entities_id FROM ' . $connection->quoteIdentifier($childTable->getName()) . ' WHERE id = 1') === $source
                    && (int)$probe->fields['entities_id'] === $source && $connection->getTransactionNestingLevel() === 0, 'Storage refusal preserves native rows, stored parent model and frame before persistence');
            }
            $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($childTable->getName()) . ' ENGINE=InnoDB');
            $DB->clearSchemaCache();
            verify((new PluginTransferAtomicityProbe())->update(['id' => 1, 'entities_id' => $destination]) === true
                && (int)$connection->fetchOne('SELECT entities_id FROM ' . $connection->quoteIdentifier($childTable->getName()) . ' WHERE id = 1') === $destination, 'The same actual registered forwarding models succeed with transactional storage');
        } finally {
            $schema->dropTable($childTable->getName());
            $schema->dropTable($table->getName());
            $DB->clearSchemaCache();
        }
    }
    verify((new ForeignKeys())->audit($connection) === [], 'Transfer lifecycle leaves all enforced associations valid');
} finally {
    $PLUGIN_HOOKS = $savedHooks;
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
    $CFG_GLPI['use_notifications'] = false;
    foreach (array_reverse($created) as [$table, $id]) {
        $class = getItemTypeForTable($table);
        $model = new $class();
        if ($model->getFromDB($id)) {
            verify((bool)$model->delete(['id' => $id, '_no_history' => true, '_disablenotif' => true], true), 'Fixture lifecycle cleanup: ' . $table);
        }
    }
    restore_error_handler();
    $CFG_GLPI = $savedConfig;
    $_SESSION = $savedSession;
    $plugins->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": $assertions atomic transfer, supplier coherence, public veto, direct entry and caller savepoint assertions passed.\n";
