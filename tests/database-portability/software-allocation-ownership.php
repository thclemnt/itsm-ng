<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-allocation-ownership.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
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
/** Real callbacks run after actual public persistence on the same domain table. */
class OwnershipCallbackAllocation extends Item_SoftwareLicense
{
    public static ?Closure $after = null;
    public static int $calls = 0;

    public static function getTable($classname = null)
    {
        return Item_SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareLicense::getType();
    }

    public function post_updateItem($history = 1)
    {
        parent::post_updateItem($history);
        ++self::$calls;
        if (self::$after !== null) {
            (self::$after)();
        }
    }
}

verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Actual administrator login');
$connection = $DB->getDoctrineConnection();
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before ownership controls');
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$fixtures = new FixtureRecords($DB);
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$writer = static fn (): RecordWriter => new RecordWriter(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$counts = static fn (): array => [$records()->countMatching('glpi_items_softwarelicenses', []),
    $records()->countMatching('glpi_logs', []), $records()->countMatching('glpi_queuednotifications', [])];
$add = static fn (int $subject, int $license, array $extra = []): mixed => (new Item_SoftwareLicense())->add(
    ['itemtype' => 'Monitor', 'items_id' => $subject, 'softwarelicenses_id' => $license] + $extra
);
$connection->beginTransaction();
try {
    $first = $fixtures->create('glpi_entities', ['name' => 'Allocation first sibling']);
    $second = $fixtures->create('glpi_entities', ['name' => 'Allocation second sibling']);
    $child = $fixtures->create('glpi_entities', ['name' => 'Allocation descendant', 'entities_id' => $first,
        'ancestors_cache' => '[]']);
    $_SESSION['glpiactiveentities'] = [0, $first, $second, $child];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    $_SESSION['glpiactive_entity'] = $first;
    $_SESSION['glpishowallentities'] = false;
    $aggregateSoftware = $fixtures->create('glpi_softwares', ['name' => 'Allocation sibling licence aggregate',
        'entities_id' => $first, 'is_recursive' => true, 'is_valid' => true]);
    $finite = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $aggregateSoftware,
        'entities_id' => $first, 'number' => 0, 'is_valid' => true]);
    $otherLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $aggregateSoftware,
        'entities_id' => $child, 'number' => -1, 'is_valid' => true]);
    $finiteSubject = $fixtures->create('glpi_monitors', ['entities_id' => $first]);
    $otherSubject = $fixtures->create('glpi_monitors', ['entities_id' => $child]);
    $otherAllocation = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Monitor',
        'items_id' => $otherSubject, 'softwarelicenses_id' => $otherLicense]);
    $otherAllocationOld = $read('glpi_items_softwarelicenses', $otherAllocation);
    $overallocated = $add($finiteSubject, $finite);
    verify(is_int($overallocated) && $overallocated > 0,
        'Actual over-allocation succeeds when nested owning Software has another licence in a different entity');
    verify($read('glpi_softwarelicenses', $finite)['is_valid'] === 0
        && $read('glpi_softwares', $aggregateSoftware)['is_valid'] === 0
        && $read('glpi_softwarelicenses', $otherLicense)['is_valid'] === 1
        && $read('glpi_items_softwarelicenses', $otherAllocation) === $otherAllocationOld,
        'Required public licence/Software validity crosses threshold while sibling allocation remains exact');
    verify((new Item_SoftwareLicense())->delete(['id' => $overallocated], true) === true
        && $read('glpi_softwarelicenses', $finite)['is_valid'] === 1
        && $read('glpi_softwares', $aggregateSoftware)['is_valid'] === 1,
        'Actual selected purge restores aggregate validity without acquiring late sibling hierarchy locks');

    $software = $fixtures->create('glpi_softwares', ['name' => 'Allocation owning scope ' . bin2hex(random_bytes(5)), 'entities_id' => $first]);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $software, 'entities_id' => $first, 'number' => -1]);
    $same = $fixtures->create('glpi_monitors', ['entities_id' => $first]);
    $sibling = $fixtures->create('glpi_monitors', ['entities_id' => $second]);
    $descendant = $fixtures->create('glpi_monitors', ['entities_id' => $child]);
    $before = $counts();
    verify($add($sibling, $license) === false, 'Stable nonrecursive sibling pair is refused even with both entities visible');
    verify($counts() === $before, 'Incompatible public add writes no allocation/history/notification');
    $valid = $add($same, $license, ['is_dynamic' => 1]);
    verify(is_int($valid) && $valid > 0, 'Same-entity actual public allocation succeeds');
    verify((new Item_SoftwareLicense())->update(['id' => $valid, 'is_dynamic' => 0]) === true, 'Coherent scalar-only update succeeds');
    verify((new Item_SoftwareLicense())->update(['id' => $valid, 'is_dynamic' => 0]) === true, 'Coherent unchanged update retains current final ownership');
    $invalid = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $sibling,
        'softwarelicenses_id' => $license, 'is_dynamic' => true]);
    $before = $counts();
    $old = $read('glpi_items_softwarelicenses', $invalid);
    verify((new Item_SoftwareLicense())->update(['id' => $invalid, 'is_dynamic' => 0]) === false, 'Stable invalid native pair refuses scalar-only update');
    verify($read('glpi_items_softwarelicenses', $invalid) === $old && $counts() === $before,
        'Invalid scalar update preserves actual row/history/notification');
    verify((new Item_SoftwareLicense())->update(['id' => $invalid, 'is_dynamic' => 1]) === false,
        'Stable invalid native pair also refuses unchanged public input');
    verify((new Item_SoftwareLicense())->delete(['id' => $invalid], true) === true,
        'Invalid historical pair remains removable through actual public purge');
    verify($read('glpi_items_softwarelicenses', $invalid) === null, 'Actual purge removes selected invalid link');
    $locked = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $sibling,
        'softwarelicenses_id' => $license, 'is_dynamic' => true, 'is_deleted' => true]);
    $before = $counts();
    $old = $read('glpi_items_softwarelicenses', $locked);
    verify((new Item_SoftwareLicense())->restore(['id' => $locked]) === false, 'Restore cannot reactivate an incompatible selected pair');
    verify($read('glpi_items_softwarelicenses', $locked) === $old && $counts() === $before,
        'Refused restore retains dynamic lock and no history/notification writes');
    verify((new Item_SoftwareLicense())->delete(['id' => $locked], true) === true, 'Incompatible dynamic lock remains purgeable');

    verify((new SoftwareLicense())->update(['id' => $license, 'is_recursive' => 1]) === true, 'Actual licence recursion enabled');
    verify($add($descendant, $license) === false, 'Licence flag alone cannot bypass nonrecursive owning Software');
    verify((new Software())->update(['id' => $software, 'is_recursive' => 1]) === true, 'Actual owning Software recursion enabled');
    $recursive = $add($descendant, $license);
    verify(is_int($recursive) && $recursive > 0, 'Current recursive licence ancestor succeeds using actual parent despite stale cache');
    verify((new Software())->update(['id' => $software, 'is_recursive' => 0]) === true, 'Actual Software recursion disabled again');
    verify((new Item_SoftwareLicense())->update(['id' => $recursive, 'is_dynamic' => 1]) === false,
        'Existing descendant allocation scalar update rechecks effective licence recursion');
    verify((new Item_SoftwareLicense())->delete(['id' => $recursive], true) === true,
        'Disabling recursion does not prevent removing the old descendant link');

    $childSoftware = $fixtures->create('glpi_softwares', ['name' => 'Allocation child owner', 'entities_id' => $child]);
    $childLicense = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $childSoftware, 'entities_id' => $child, 'number' => -1]);
    $recursiveSubject = $fixtures->create('glpi_monitors', ['entities_id' => $first, 'is_recursive' => true]);
    $reverse = $add($recursiveSubject, $childLicense);
    verify(is_int($reverse) && $reverse > 0, 'Recursive subject ancestor permits the reverse relation');
    verify((new Monitor())->update(['id' => $recursiveSubject, 'is_recursive' => 0]) === true,
        'Actual subject recursion can change through its own lifecycle');
    verify((new Item_SoftwareLicense())->update(['id' => $reverse, 'is_dynamic' => 1]) === false,
        'Current scalar command rechecks changed subject recursion');
    verify((new Item_SoftwareLicense())->delete(['id' => $reverse], true) === true, 'Reverse relation cleanup remains allowed');

    $cloneSubject = $fixtures->create('glpi_monitors', ['entities_id' => $first]);
    $model = new Item_SoftwareLicense();
    verify($model->getFromDB($valid), 'Load actual clone source allocation');
    $clone = $model->clone(Record\ItemSoftwareLicense::withReference([], 'Monitor', $cloneSubject));
    verify(is_int($clone) && $clone > 0 && $read('glpi_items_softwarelicenses', $clone)['items_id'] === $cloneSubject,
        'Actual allocation clone retains a coherent selected subject');
    $model->getFromDB($valid);
    verify($model->clone(Record\ItemSoftwareLicense::withReference([], 'Monitor', $sibling)) === false,
        'Actual allocation clone refuses incompatible final subject');

    $transferSubject = $fixtures->create('glpi_monitors', ['entities_id' => $first]);
    $transferLink = $add($transferSubject, $license);
    verify(is_int($transferLink) && $transferLink > 0, 'Prepare actual source assignment for transfer');
    verify((new Monitor())->update(['id' => $transferSubject, 'entities_id' => $second]) === true,
        'Owning transfer moves subject first, leaving old pair temporarily incompatible');
    $transfer = new Transfer();
    $transfer->to = $second;
    $transfer->options['keep_software'] = 1;
    verify($transfer->transferItemSoftwares('Monitor', $transferSubject) === true,
        'Actual transfer retargets temporarily invalid old pair to copied destination licence');
    $targetLink = $read('glpi_items_softwarelicenses', $transferLink);
    $targetLicense = $read('glpi_softwarelicenses', $targetLink['softwarelicenses_id']);
    verify($targetLicense['entities_id'] === $second && $targetLink['items_id'] === $transferSubject,
        'Actual transfer final pair shares destination entity');

    $callbackSubject = $fixtures->create('glpi_monitors', ['entities_id' => $first]);
    $callbackLink = $add($callbackSubject, $license);
    $before = $counts();
    $old = $read('glpi_items_softwarelicenses', $callbackLink);
    OwnershipCallbackAllocation::$calls = 0;
    OwnershipCallbackAllocation::$after = static fn () => $writer()->update('glpi_monitors', $callbackSubject, ['entities_id' => $second]);
    try {
        verify((new OwnershipCallbackAllocation())->update(['id' => $callbackLink, 'is_dynamic' => 1]) === false
            && OwnershipCallbackAllocation::$calls === 1, 'Actual late row callback cannot make accepted selected pair incompatible');
    } finally {
        OwnershipCallbackAllocation::$after = null;
    }
    verify($read('glpi_items_softwarelicenses', $callbackLink) === $old
        && $read('glpi_monitors', $callbackSubject)['entities_id'] === $first && $counts() === $before,
        'Late scope refusal rolls back actual link/subject/history/notification changes');

    $otherLink = $fixtures->create('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $callbackSubject,
        'softwarelicenses_id' => $license]);
    $otherOld = $read('glpi_items_softwarelicenses', $otherLink);
    $before = $counts();
    $selectedModel = new OwnershipCallbackAllocation();
    OwnershipCallbackAllocation::$calls = 0;
    OwnershipCallbackAllocation::$after = static function () use ($selectedModel, $otherLink): void {
        // Another real row has the same owner tuple: matching fields cannot
        // authorize changing which selected allocation receives final validation.
        $selectedModel->fields['id'] = $otherLink;
    };
    try {
        verify($selectedModel->update(['id' => $callbackLink, 'is_dynamic' => 1]) === false
            && OwnershipCallbackAllocation::$calls === 1, 'Actual late callback cannot substitute another real allocation identity');
    } finally {
        OwnershipCallbackAllocation::$after = null;
    }
    verify($read('glpi_items_softwarelicenses', $callbackLink) === $old
        && $read('glpi_items_softwarelicenses', $otherLink) === $otherOld
        && (int)$selectedModel->getID() === $callbackLink && $counts() === $before,
        'Identity substitution restores only the actual rolled-back selected model and leaves both rows/history unchanged');

    $otherSoftware = $fixtures->create('glpi_softwares', ['name' => 'Allocation late unselected aggregate', 'entities_id' => $first]);
    $licenseOld = $read('glpi_softwarelicenses', $license);
    $before = $counts();
    OwnershipCallbackAllocation::$calls = 0;
    OwnershipCallbackAllocation::$after = static fn () => $writer()->update('glpi_softwarelicenses', $license, ['softwares_id' => $otherSoftware]);
    try {
        verify((new OwnershipCallbackAllocation())->update(['id' => $callbackLink, 'is_dynamic' => 1]) === false
            && OwnershipCallbackAllocation::$calls === 1, 'Late licence ownership cannot silently acquire a new Software aggregate in the same entity');
    } finally {
        OwnershipCallbackAllocation::$after = null;
    }
    verify($read('glpi_items_softwarelicenses', $callbackLink) === $old
        && $read('glpi_softwarelicenses', $license) === $licenseOld && $counts() === $before,
        'Late aggregate substitution rolls back selected link/licence/history/notification writes');

    verify((new Software())->update(['id' => $software, 'is_recursive' => 1]) === true,
        'Enable current Software recursion for actual hierarchy callback');
    $hierarchyLink = $add($descendant, $license);
    $before = $counts();
    $old = $read('glpi_items_softwarelicenses', $hierarchyLink);
    OwnershipCallbackAllocation::$calls = 0;
    OwnershipCallbackAllocation::$after = static fn () => $writer()->update('glpi_entities', $child, ['entities_id' => $second]);
    try {
        verify((new OwnershipCallbackAllocation())->update(['id' => $hierarchyLink, 'is_dynamic' => 1]) === false
            && OwnershipCallbackAllocation::$calls === 1, 'Actual callback cannot invalidate reserved ancestor edges and still accept allocation');
    } finally {
        OwnershipCallbackAllocation::$after = null;
    }
    verify($read('glpi_items_softwarelicenses', $hierarchyLink) === $old
        && $read('glpi_entities', $child)['entities_id'] === $first && $counts() === $before,
        'Native parent-edge refusal rolls back actual hierarchy/link/history/notification changes');

    $cyclic = $fixtures->create('glpi_entities', ['name' => 'Allocation cyclic native parent']);
    $writer()->update('glpi_entities', $cyclic, ['entities_id' => $cyclic]);
    $cyclicSubject = $fixtures->create('glpi_monitors', ['entities_id' => $cyclic]);
    $before = $counts();
    verify($add($cyclicSubject, $license) === false, 'Corrupt native ancestor cycle is diagnosed before allocation persistence');
    verify($counts() === $before, 'Cyclic ancestor refusal writes no allocation/history/notification');
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
}
// These DBAL-managed native frame replacement controls perform no application DML. They
// prove continuity refusal without pretending a prior COMMIT is reversible.
foreach (['commit', 'rollback'] as $replacement) {
    $connection->beginTransaction();
    try {
        $refused = false;
        try {
            \itsmng\Domain\SoftwareHierarchyUnit::run($DB, [0], static function () use ($connection, $replacement): void {
                if ($replacement === 'commit') {
                    $connection->commit();
                } else {
                    $connection->rollBack();
                }
                $connection->beginTransaction();
            });
        } catch (\itsmng\Database\TransactionOwnershipMismatch $error) {
            $refused = $error->getMessage() === 'The captured managed frame has ended or been replaced.';
        }
        verify($refused && $connection->getTransactionNestingLevel() === 1,
            'Actual native ' . $replacement . ' plus same-depth BEGIN invalidates the authoritative managed scope and refuses');
    } finally {
        while ($connection->getTransactionNestingLevel() > 0) {
            $connection->rollBack();
        }
    }
    verify((int)$connection->fetchOne('SELECT 1') === 1, 'Independent replacement frame retires without pretending original restoration');
    $connection->beginTransaction();
    try {
        verify(\itsmng\Domain\SoftwareHierarchyUnit::run($DB, [0], static fn (): bool => true) === true,
            'Retired failed hierarchy scope does not poison a new actual writer frame');
    } finally {
        $connection->rollBack();
    }
}

// Failed ownership cleanup preserves the original exception and never retires
// a callback's replacement frame. This control performs no application DML.
$primary = new RuntimeException('Actual callback primary before replacement-frame cleanup');
try {
    $refused = false;
    try {
        \itsmng\Database\OwnedMutationFrame::run($connection, static function () use ($connection, $primary): void {
            $connection->commit();
            $connection->beginTransaction();
            throw $primary;
        });
    } catch (\itsmng\Database\MutationRollbackFailure $failure) {
        $refused = $failure->primary === $primary && $failure->getPrevious() === $primary
            && $failure->cleanup instanceof \itsmng\Database\TransactionOwnershipMismatch;
    }
    verify($refused && $connection->getTransactionNestingLevel() === 1,
        'Actual ended-frame rollback retains first Throwable and leaves the replacement frame untouched');
} finally {
    // This test itself created the replacement, so only the test retires it.
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
}
verify((int)$connection->fetchOne('SELECT 1') === 1, 'New owner remains usable after explicit test-owned replacement cleanup');

// Real notification ownership cleanup can fail independently of the callback.
// Reflection only retains the actual outer object for strictly ordered cleanup;
// neither scope state nor a token is changed or fabricated by this control.
$outerDelivery = null;
$nestedDelivery = null;
$primary = new RuntimeException('Actual callback primary before notification cleanup');
$model = new Monitor();
$checkpoint = \itsmng\Database\LifecycleModelJournal::state($model);
try {
    $preserved = false;
    try {
        \itsmng\Domain\SoftwareMutation::run($DB, $model, $checkpoint, static function () use (
            $connection, $primary, &$outerDelivery, &$nestedDelivery
        ): void {
            $property = new ReflectionProperty(\itsmng\Database\LifecycleNotifications::class, 'scopes');
            $scopes = $property->getValue()[$connection];
            $outerDelivery = end($scopes);
            $nestedDelivery = \itsmng\Database\LifecycleNotifications::begin($connection);
            throw $primary;
        });
    } catch (\itsmng\Database\MutationCleanupFailure $failure) {
        $preserved = $failure->primary === $primary && $failure->getPrevious() === $primary
            && $failure->cleanup instanceof LogicException
            && $failure->cleanup->getMessage() === 'Lifecycle notification scopes closed out of order'
            && !$failure->rollbackUnproven;
    }
    verify($preserved && $connection->getTransactionNestingLevel() === 0,
        'Actual out-of-order notification cleanup retains callback primary after proven owned rollback');
} finally {
    // Retire only the actual scopes created by this known invocation, through
    // their ordinary ownership-checked API. No private scope state is rewritten.
    $nestedDelivery?->finish(false);
    $outerDelivery?->finish(false);
}
verify(\itsmng\Database\LifecycleNotifications::begin($connection)->finish(false) === [],
    'Actual ordered cleanup leaves notification ownership usable for the next command');

verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after rolled-back ownership controls');
echo "Software allocation current ownership: $assertions assertions passed.\n";
