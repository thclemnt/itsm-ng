<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\OperatingSystemAssignmentRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/operating-system-subjects.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_items_operatingsystems', 'id', $id);
$subjects = EntityRegistry::discriminatedReferences('glpi_items_operatingsystems')['items_id']['selections'];
verify(array_keys($subjects) === $CFG_GLPI['operatingsystem_types'], 'All six configured subjects are declared on their owning properties');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$projection = (new ReflectionProperty(Record\ItemOperatingSystem::class, 'items_id'))->getAttributes(\Doctrine\ORM\Mapping\Column::class)[0]->newInstance();
verify(!$projection->insertable && !$projection->updatable && $projection->generated === 'ALWAYS', 'Legacy subject identity is a generated read-only projection');
$reject = static function (callable $operation, string $message) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (Throwable $error) {
            $cause = $error;
            while (!$cause instanceof DriverException && $cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            if (!$cause instanceof DriverException) {
                throw $error;
            }
            $failed = in_array($cause->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true);
        }
        verify($failed, $message);
    } finally {
        $connection->rollBack();
    }
};
$DB->beginTransaction();
try {
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $sameId = 4294970631;
    $os = (new OperatingSystem())->import(['name' => "Imported OS O'Reilly 東京"]);
    verify($os > 0 && (new OperatingSystem())->import(['name' => "Imported OS O'Reilly 東京"]) === $os, 'Actual core dropdown inventory import reuses its persisted OS');
    $architecture = $fixtures->create('glpi_operatingsystemarchitectures', ['name' => 'Ownership architecture']);
    $links = [];
    foreach ($subjects as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId, 'name' => 'OS subject ' . $kind]);
        $fixtures->create($selection['target'], ['id' => $sameId + 1, 'name' => 'Retarget ' . $kind]);
        $owner = getItemForItemtype($kind);
        verify($owner->getFromDB($sameId), 'Load real persisted owner: ' . $kind);
        $input = ['itemtype' => $kind, 'items_id' => $sameId, 'operatingsystems_id' => $os, 'operatingsystemarchitectures_id' => $architecture, 'is_dynamic' => true, 'licenseid' => 'Inventory product', 'license_number' => 'Retained license'];
        $link = new Item_OperatingSystem();
        verify($link->can(-1, CREATE, $input), 'Existing public asset authorization: ' . $kind);
        $id = $link->add($input);
        verify($id > 0 && $link->fields[$selection['column']] === $sameId && $link->fields['items_id'] === $sameId, 'Dynamic public assignment owns its asset and generated compatibility ID: ' . $kind);
        $links[$kind] = $id;
        verify(!(new Item_OperatingSystem())->add($input) && !$link->add($input), 'Fresh and reused public models reject a duplicate assignment: ' . $kind);
        verify($link->getFromDB($id) && $link->update(['id' => $id, 'license_number' => 'Updated license']), 'Component-absent update retains its assignment: ' . $kind);
        $rows = Item_OperatingSystem::getFromItem($owner);
        verify(count($rows) === 1 && $rows[0]['assocID'] === $id && $rows[0]['name'] === "Imported OS O'Reilly 東京", 'Owning repository distinguishes same IDs across six kinds: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_items_operatingsystems', ['itemtype' => $kind, $selection['column'] => $sameId, 'operatingsystems_id' => $os, 'operatingsystemarchitectures_id' => $architecture]), 'Native duplicate rejected: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_items_operatingsystems', ['itemtype' => $kind, $selection['column'] => $sameId + 99]), 'Missing subject rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Direct subject deletion restricted: ' . $kind);
        $em = Orm::create($DB);
        $native = new Record\ItemOperatingSystem();
        $native->itemtype = $kind;
        $association = Record\ItemOperatingSystem::referenceAssociation($kind);
        $class = $em->getClassMetadata(Record\ItemOperatingSystem::class)->getAssociationTargetClass($association);
        $native->{$association} = $em->getReference($class, $sameId + 1);
        $native->entities = $em->getReference(Record\Entity::class, 0);
        $em->persist($native);
        $em->flush();
        verify($native->items_id === $sameId + 1, 'Native owning graph generates a wide compatibility ID: ' . $kind);
        $native->{$association} = $em->getReference($class, $sameId);
        $em->flush();
        verify($native->items_id === $sameId && count(Item_OperatingSystem::getFromItem($owner)) === 2, 'Native update regenerates the projection and retains distinct NULL component assignment: ' . $kind);
        $em->remove($native);
        $em->flush();
        $clone = $link->clone([$selection['column'] => $sameId + 1]);
        verify($clone > 0 && $read($clone)[$selection['column']] === $sameId + 1 && $read($clone)['license_number'] === 'Updated license', 'Explicit canonical clone replaces copied subject and preserves license: ' . $kind);
        verify((new Item_OperatingSystem())->delete(['id' => $clone], true), 'Public relation purge: ' . $kind);
    }
    foreach ($subjects as $kind => $selection) {
        foreach ([['OperatingSystem', 'operatingsystems_id', 'operatingsystemarchitectures_id', $architecture], ['OperatingSystemArchitecture', 'operatingsystemarchitectures_id', 'operatingsystems_id', $os]] as [$componentKind, $column, $other, $otherId]) {
            $component = new $componentKind();
            $source = $component->add(['name' => 'Collision source ' . $kind . $componentKind]);
            $replacement = (new $componentKind())->add(['name' => 'Safe replacement ' . $kind . $componentKind]);
            $first = new Item_OperatingSystem();
            $second = new Item_OperatingSystem();
            $firstId = $first->add(['itemtype' => $kind, 'items_id' => $sameId + 1, $column => $source, $other => $otherId, 'license_number' => 'Source license']);
            $secondId = $second->add(['itemtype' => $kind, 'items_id' => $sameId + 1, $column => null, $other => $otherId, 'is_deleted' => true, 'license_number' => 'Locked license']);
            verify($source > 0 && $replacement > 0 && $firstId > 0 && $secondId > 0, 'Create distinct source and deleted empty assignments: ' . $kind . '/' . $componentKind);
            $before = [$read($firstId), $read($secondId)];
            $history = $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id');
            verify(!$component->delete(['id' => $source], true), 'Public component purge refuses assignment merge: ' . $kind . '/' . $componentKind);
            verify($component->getFromDB($source) && [$read($firstId), $read($secondId)] === $before && $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id') === $history, 'Refused purge retains source, both licenses, deleted inventory and audit history');
            verify(str_contains(implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []), 'Choose a different replacement'), 'Collision refusal explains recovery');
            verify($component->delete(['id' => $source, '_replace_by' => $replacement], true), 'Distinct replacement preserves licensed assignments');
            verify($read($firstId)[$column] === $replacement && $read($secondId)[$column] === null && $read($firstId)['license_number'] === 'Source license', 'Replacement preserves individual assignment roles');
            $newSource = (new $componentKind())->add(['name' => 'Occupied replacement source ' . $kind . $componentKind]);
            verify($second->update(['id' => $secondId, $column => $newSource]), 'Populate the formerly empty assignment with another component');
            $before = [$read($firstId), $read($secondId)];
            $history = $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id');
            verify(!(new $componentKind())->delete(['id' => $newSource, '_replace_by' => $replacement], true) && [$read($firstId), $read($secondId)] === $before && $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id') === $history, 'Occupied replacement refuses merge without losing either licensed assignment');
            verify((new Item_OperatingSystem())->delete(['id' => $firstId], true) && (new Item_OperatingSystem())->delete(['id' => $secondId], true), 'Clean component collision assignments');
        }
    }
    foreach ([['itemtype' => 'Unknown', 'items_id' => $sameId], ['itemtype' => null, 'items_id' => $sameId], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => $sameId + 99], ['itemtype' => 'Computer', 'items_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        verify(!(new Item_OperatingSystem())->add($invalid), 'Invalid public subjects fail before persistence');
    }
    foreach ([[], ['itemtype' => null], ['itemtype' => 'PluginAsset'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert('glpi_items_operatingsystems', $invalid + ['entities_id' => 0]), 'Native missing, zero, wrong, multiple and unknown subjects fail');
    }
    $reject(static fn () => $connection->update('glpi_items_operatingsystems', ['itemtype' => 'Monitor'], ['id' => $links['Computer']]), 'Discriminator-only update fails');
    $computer = new Computer();
    verify($computer->getFromDB($sameId), 'Load computer');
    $empty = new Item_OperatingSystem();
    $emptyId = $empty->add(['itemtype' => 'Computer', 'items_id' => $sameId, 'operatingsystems_id' => 0, 'operatingsystemarchitectures_id' => 0, 'is_deleted' => true]);
    verify($emptyId > 0 && $empty->fields['operatingsystems_id'] === null && $empty->fields['operatingsystemarchitectures_id'] === null, 'Zero selections become nullable owning component relations');
    verify(!(new Item_OperatingSystem())->add(['itemtype' => 'Computer', 'items_id' => $sameId]), 'Deleted empty assignment still blocks duplicate inventory assignment');
    $link = new Item_OperatingSystem();
    verify($link->getFromDB($links['Computer']), 'Load assignment for nullable update');
    verify(!$link->update(['id' => $link->getID(), 'operatingsystems_id' => null, 'operatingsystemarchitectures_id' => null]), 'Explicit NULL update refuses composite-key collision');
    verify($read($link->getID())['operatingsystems_id'] === $os, 'Refused duplicate update preserves component and license');
    verify($link->update(['id' => $link->getID(), 'operatingsystems_id' => null]) && $link->fields['operatingsystems_id'] === null && $link->fields['operatingsystemarchitectures_id'] === $architecture, 'Explicit NULL differs from an absent component update');
    verify($link->update(['id' => $link->getID(), 'operatingsystems_id' => $os]), 'Restore OS before parent clone');
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile']['computer'] = READ;
    $authorizationInput = ['itemtype' => 'Computer', 'items_id' => $sameId];
    verify(!(new Item_OperatingSystem())->can(-1, CREATE, $authorizationInput), 'Read-only parent cannot authorize an assignment');
    verify(!$link->can($link->getID(), UPDATE), 'Read-only parent cannot authorize assignment edits');
    verify(!$link->update(['id' => $link->getID(), 'computers_id' => $sameId + 1]) && $read($link->getID())['computers_id'] === $sameId, 'Canonical retarget reaches existing parent authorization and preserves denied assignment');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    verify(!(new Item_OperatingSystem())->can(-1, CREATE, $authorizationInput), 'Empty entity scope grants no assignment creation');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $scope = $fixtures->create('glpi_entities', ['entities_id' => 0, 'name' => 'OS subject scope', 'completename' => 'OS subject scope']);
    $scoped = $fixtures->create('glpi_computers', ['entities_id' => $scope, 'is_recursive' => true, 'name' => 'Scoped OS owner']);
    $scopedLink = new Item_OperatingSystem();
    $scopedId = $scopedLink->add(['itemtype' => 'Computer', 'items_id' => $scoped, 'entities_id' => 0, 'is_recursive' => false]);
    verify($scopedId > 0 && $scopedLink->fields['entities_id'] === $scope && $scopedLink->fields['is_recursive'], 'Subject scope and recursion are derived from the actual persisted parent');
    verify($scopedLink->update(['id' => $scopedId, 'entities_id' => 0, 'is_recursive' => false]) && $scopedLink->fields['entities_id'] === $scope && $scopedLink->fields['is_recursive'], 'Component-only update cannot detach the cached entity scope from its owner');
    $largeLink = $fixtures->create('glpi_items_operatingsystems', ['id' => $sameId + 20, 'itemtype' => 'Computer', 'items_id' => $sameId + 1, 'license_number' => 'Wide binding license']);
    $wideOwner = new Computer();
    verify($wideOwner->getFromDB($sameId + 1) && array_column(Item_OperatingSystem::getFromItem($wideOwner), 'assocID') === [$largeLink], 'Actual inventory view retains a binding ID above unsigned 32-bit');
    $large = new Item_OperatingSystem();
    verify($large->getFromDB($largeLink) && $large->update(['id' => $largeLink, 'licenseid' => 'Wide binding product']) && $read($largeLink)['licenseid'] === 'Wide binding product', 'Public update retains a wide assignment identity and license');
    verify($large->delete(['id' => $largeLink], true), 'Public purge uses the wide assignment identity');
    $deprecations = 0;
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        if ($level === E_USER_DEPRECATED && $message === 'Use clone') {
            ++$deprecations;
            return true;
        }
        return false;
    });
    try {
        Item_OperatingSystem::cloneItem('Computer', $sameId, $sameId + 1, 'Monitor');
    } finally {
        restore_error_handler();
    }
    $monitorClone = new Monitor();
    verify($deprecations === 1 && $monitorClone->getFromDB($sameId + 1) && count(Item_OperatingSystem::getFromItem($monitorClone)) === 2, 'Legacy cross-kind clone preserves deprecation and both active/deleted assignments');
    foreach (Item_OperatingSystem::getFromItem($monitorClone) as $row) {
        verify($read($row['assocID'])['monitors_id'] === $sameId + 1 && $read($row['assocID'])['computers_id'] === null, 'Legacy cross-kind clone replaces every copied subject association');
    }
    verify($monitorClone->delete(['id' => $sameId + 1], true), 'Purge cross-kind clone owner through actual lifecycle');
    $clone = $computer->clone(['name' => 'Parent clone OS']);
    $cloned = new Computer();
    verify($clone > 0 && $cloned->getFromDB($clone), 'Actual parent clone');
    $cloneRows = Item_OperatingSystem::getFromItem($cloned);
    verify(count($cloneRows) === 2, 'Parent clone retains active assignment and deleted history');
    foreach ($cloneRows as $row) {
        verify($read($row['assocID'])['computers_id'] === $clone, 'Parent clone selects the newly persisted owner');
    }
    verify($cloned->delete(['id' => $clone], true) && Item_OperatingSystem::getFromItem($cloned) === [], 'Parent purge removes all cloned OS relations through lifecycle hooks');
    foreach ($subjects as $kind => $selection) {
        $owner = getItemForItemtype($kind);
        verify($owner->getFromDB($sameId) && $owner->delete(['id' => $sameId], true), 'Public asset purge: ' . $kind);
        verify($read($links[$kind]) === null && Item_OperatingSystem::getFromItem($owner) === [], 'All six asset purges remove owned assignments: ' . $kind);
    }
    verify((new ForeignKeys())->audit($connection) === [], 'No orphan subjects or component references');
    verify((new SchemaCheck())->differences($connection) === [], 'Schema remains converged');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
}
echo $DB->getProvider() . ": six owning OS subjects, overlapping wide identities, core dropdown import, dynamic public/native graphs, invalid and duplicate rejection, nullable components, licensed purge/replace refusal, rights/scopes, wide bindings, cross-kind/parent cloning and purge passed.\n";
