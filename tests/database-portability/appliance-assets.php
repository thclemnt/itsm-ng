<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ApplianceAssetRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RelationshipLifecycleRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/appliance-assets.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$repository = static fn () => new ApplianceAssetRepository(Orm::create($DB));
$assets = EntityRegistry::discriminatedReferences('glpi_appliances_items')['items_id']['selections'];
$contexts = EntityRegistry::discriminatedReferences('glpi_appliances_items_relations')['items_id']['selections'];
verify(array_keys($assets) === Appliance::getTypes(true) && array_keys($contexts) === Appliance_Item_Relation::getTypes(true), 'Configured appliance subjects and nested context are declared on owning properties');
verify((new SchemaCheck())->differences($connection) === [], 'Complete canonical history installed the current schema');
$reject = static function (callable $operation, string $message) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (Throwable $error) {
            // Native pgsql DEALLOCATE warnings may chain the original constraint exception.
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
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$DB->beginTransaction();
try {
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $sameId = 4294969731;
    $owner = $fixtures->create('glpi_appliances', ['name' => "Appliance owner O'Reilly 東京", 'is_recursive' => true]);
    $other = $fixtures->create('glpi_appliances', ['name' => 'Other appliance']);
    foreach ($contexts as $kind => $selection) {
        $values = ['id' => $sameId, 'name' => 'Context ' . $kind];
        if (Orm::create($DB)->getClassMetadata(EntityRegistry::tables()[$selection['target']])->hasField('completename')) {
            $values['completename'] = 'Context ' . $kind;
        }
        $fixtures->create($selection['target'], $values);
    }
    $links = [];
    $nested = [];
    foreach ($assets as $kind => $selection) {
        $fixtures->create($selection['target'], ['id' => $sameId, 'name' => 'Subject ' . $kind]);
        $link = new Appliance_Item();
        $input = ['appliances_id' => $owner, 'itemtype' => $kind, 'items_id' => $sameId];
        verify($link->can(-1, CREATE, $input), 'Existing public relation authorization: ' . $kind);
        $id = $link->add($input);
        verify($id > 0 && $link->fields[$selection['column']] === $sameId && $link->fields['items_id'] === $sameId, 'Public owning subject and compatibility projection: ' . $kind);
        $links[$kind] = $id;
        verify(!(new Appliance_Item())->add($input), 'Public duplicate asset binding is rejected: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_appliances_items', ['appliances_id' => $owner, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Database duplicate asset binding is rejected: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_appliances_items', ['appliances_id' => $other, 'itemtype' => $kind, $selection['column'] => $sameId + 500]), 'Missing asset target is rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Direct target deletion is restricted: ' . $kind);
        $em = Orm::create($DB);
        $native = new Record\ApplianceItem();
        $native->appliances = $em->getReference(Record\Appliance::class, $other);
        $native->itemtype = $kind;
        $association = Record\ApplianceItem::referenceAssociation($kind);
        $target = $em->getClassMetadata(Record\ApplianceItem::class)->getAssociationTargetClass($association);
        $native->{$association} = $em->getReference($target, $sameId);
        $em->persist($native);
        $em->flush();
        verify($native->items_id === $sameId, 'Native graph generates legacy identity: ' . $kind);
        $em->remove($native);
        $em->flush();
        foreach ($contexts as $contextKind => $context) {
            $relation = new Appliance_Item_Relation();
            $input = ['appliances_items_id' => $id, 'itemtype' => $contextKind, 'items_id' => $sameId];
            verify($relation->can(-1, CREATE, $input), 'Nested public authorization: ' . $kind . '/' . $contextKind);
            $nestedId = $relation->add($input);
            verify($nestedId > 0 && $relation->fields[$context['column']] === $sameId && $relation->fields['items_id'] === $sameId, 'Public nested owning context: ' . $contextKind);
            $nested[$kind][$contextKind] = $nestedId;
            if ($kind === 'Computer') {
                $em = Orm::create($DB);
                $nativeContext = new Record\ApplianceItemRelation();
                $nativeContext->appliances_items = $em->getReference(Record\ApplianceItem::class, $id);
                $nativeContext->itemtype = $contextKind;
                $contextAssociation = Record\ApplianceItemRelation::referenceAssociation($contextKind);
                $contextClass = $em->getClassMetadata(Record\ApplianceItemRelation::class)->getAssociationTargetClass($contextAssociation);
                $nativeContext->{$contextAssociation} = $em->getReference($contextClass, $sameId);
                $em->persist($nativeContext);
                $em->flush();
                verify($nativeContext->items_id === $sameId, 'Native nested graph generates its 64-bit compatibility projection: ' . $contextKind);
                $em->remove($nativeContext);
                $em->flush();
            }
            $reject(static fn () => $connection->insert('glpi_appliances_items_relations', ['appliances_items_id' => $id, 'itemtype' => $contextKind, $context['column'] => $sameId + 500]), 'Missing nested target is rejected: ' . $contextKind);
            $reject(static fn () => $connection->delete($context['target'], ['id' => $sameId]), 'Direct nested target deletion is restricted: ' . $contextKind);
        }
    }
    $duplicate = (new Appliance_Item_Relation())->add(['appliances_items_id' => $links['Computer'], 'itemtype' => 'Location', 'items_id' => $sameId]);
    verify($duplicate > 0 && count(Appliance_Item_Relation::getForApplianceItem($links['Computer'])) === 4, 'Historically permitted nested duplicates retain individual binding identities: ' . json_encode(['duplicate' => $duplicate, 'bindings' => $repository()->relationBindings($links['Computer']), 'view' => Appliance_Item_Relation::getForApplianceItem($links['Computer'])]));
    $appliance = new Appliance();
    $computer = new Computer();
    $link = new Appliance_Item();
    verify($appliance->getFromDB($owner) && $computer->getFromDB($sameId) && $link->getFromDB($links['Computer']), 'Load public owner, asset and binding');
    verify(Appliance_Item::countForMainItem($appliance) === 8 && Appliance_Item::countForItem($computer) === 1, 'Public counts retain owner/subject direction');
    verify(Appliance_Item_Relation::countForMainItem($link) === 4, 'Nested count counts each binding including duplicates');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_logs', ['itemtype' => 'Appliance', 'items_id' => $owner, 'linked_action' => Log::HISTORY_ADD_RELATION]) === 8, 'Public asset associations retain owner audit history');
    foreach ([['itemtype' => 'Unknown', 'items_id' => $sameId], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        verify(!(new Appliance_Item())->add($invalid + ['appliances_id' => $other]), 'Invalid public asset input is rejected before persistence');
    }
    foreach ([['itemtype' => 'Computer', 'items_id' => $sameId], ['itemtype' => 'Location', 'items_id' => 0], ['itemtype' => 'Location', 'locations_id' => $sameId, 'domains_id' => $sameId]] as $invalid) {
        verify(!(new Appliance_Item_Relation())->add($invalid + ['appliances_items_id' => $links['Computer']]), 'Invalid public nested input is rejected before persistence');
    }
    $reject(static fn () => $connection->update('glpi_appliances_items', ['itemtype' => 'Monitor'], ['id' => $links['Computer']]), 'Discriminator-only asset update is rejected');
    $reject(static fn () => $connection->update('glpi_appliances_items_relations', ['itemtype' => 'Domain'], ['id' => $nested['Computer']['Location']]), 'Discriminator-only nested update is rejected');
    $reject(static fn () => $connection->insert('glpi_appliances_items', ['appliances_id' => 999999999, 'itemtype' => 'Computer', 'computers_id' => $sameId]), 'Missing appliance owner is rejected');
    $reject(static fn () => $connection->insert('glpi_appliances_items_relations', ['appliances_items_id' => 999999999, 'itemtype' => 'Location', 'locations_id' => $sameId]), 'Missing nested owner is rejected');
    $em = Orm::create($DB);
    $retained = new ApplianceAssetRepository($em);
    verify((new ReflectionProperty($retained, 'em'))->getValue($retained) === $em && $em->getConnection() === $connection, 'Repository retains the supplied application connection');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    foreach ($assets as $kind => $selection) {
        $rows = iterator_to_array(Appliance_Item::getTypeItems($owner, $kind), false);
        verify(count($rows) === 1 && $rows[0]['id'] === $sameId && (int)$rows[0]['linkid'] === $links[$kind], 'Subject query distinguishes overlapping kinds and binding IDs: ' . $kind);
    }
    Appliance_Item::getListForItem($computer);
    Appliance_Item::countForMainItem($appliance);
    Appliance_Item::countForItem($computer);
    Appliance_Item_Relation::countForMainItem($link);
    Appliance_Item_Relation::getForApplianceItem($links['Computer']);
    verify($SQL_TOTAL_REQUEST === 0, 'Public selectors/counts and nested model rendering bypass legacy SQL adapter requests');
    $bigBinding = $fixtures->create('glpi_appliances_items', ['id' => $sameId + 10, 'appliances_id' => $other, 'itemtype' => 'Computer', 'items_id' => $sameId]);
    $bigNested = $fixtures->create('glpi_appliances_items_relations', ['id' => $sameId + 20, 'appliances_items_id' => $bigBinding, 'itemtype' => 'Location', 'items_id' => $sameId]);
    verify(array_keys(Appliance_Item_Relation::getForApplianceItem($bigBinding)) === [$bigNested], 'Nested public view retains a 64-bit parent and nested binding identity');
    verify(Appliance_Item::getOppositeByTypeAndID('Computer', $sameId) === false, 'Opposite lookup rejects multiple appliance owners');
    $largeOwner = new Appliance();
    verify($largeOwner->getFromDB($other), 'Load appliance with large binding identifier');
    ob_start();
    Appliance_Item::showItems($largeOwner);
    $largeHtml = ob_get_clean();
    verify(str_contains($largeHtml, 'item[Appliance_Item][' . $bigBinding . ']') && str_contains($largeHtml, "data-relations-id='" . $bigNested . "'"), 'Actual owner rendering retains both 64-bit binding identifiers');
    verify((new Appliance_Item())->delete(['id' => $bigBinding], true) && $read('glpi_appliances_items_relations', $bigNested) === null, 'Public 64-bit binding purge invokes nested lifecycle');
    $oppositeId = 0;
    $opposite = Appliance_Item::getOppositeByTypeAndID('Computer', $sameId, $oppositeId);
    verify($opposite instanceof Appliance && $opposite->getID() === $owner && $oppositeId === $links['Computer'], 'Opposite lookup loads the public model and preserves its binding ID');
    ob_start();
    Appliance_Item::showItems($appliance);
    $html = ob_get_clean();
    foreach ($links as $kind => $id) {
        verify(str_contains($html, 'item[Appliance_Item][' . $id . ']'), 'Owner view retains asset binding choice: ' . $kind);
        foreach ($nested[$kind] as $nestedId) {
            verify(str_contains($html, "data-relations-id='" . $nestedId . "'"), 'Owner view renders nested bindings and their deletion IDs');
        }
    }
    verify(substr_count($html, 'id=\'add_relation_dialog\'') === 1 && str_contains($html, "'.delete_relation'") && !str_contains($html, 'Undefined array key'), 'Actual owner view renders its CSRF form, one nested add dialog and the existing deletion handler');
    ob_start();
    Appliance_Item::showForItem($computer);
    $reverseHtml = ob_get_clean();
    verify(str_contains($reverseHtml, 'item[Appliance_Item][' . $links['Computer'] . ']'), 'Reverse public view retains the original relation ID');
    verify(substr_count($reverseHtml, 'id=\'add_relation_dialog\'') === 1 && str_contains($reverseHtml, "'.delete_relation'"), 'Actual reverse view wires the nested relation controls once');
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile']['appliance'] = 0;
    ob_start();
    $result = Appliance_Item::showItems($appliance);
    $denied = ob_get_clean();
    verify($result === false && $denied === '' && (new Appliance_Item())->getTabNameForItem($appliance) === '', 'Owner READ denial hides the actual list and its tab');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveprofile']['appliance'] = READ;
    ob_start();
    Appliance_Item::showItems($appliance);
    $readOnlyHtml = ob_get_clean();
    verify(str_contains($readOnlyHtml, 'Subject Computer') && !str_contains($readOnlyHtml, 'add_relation_dialog') && !str_contains($readOnlyHtml, 'delete_relation pointer') && !str_contains($readOnlyHtml, 'pointer add_relation'), 'Read-only owner view retains subjects without edit dialogs or addition/deletion controls');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveprofile']['computer'] = 0;
    ob_start();
    Appliance_Item::showItems($appliance);
    $deniedType = ob_get_clean();
    verify(!str_contains($deniedType, 'item[Appliance_Item][' . $links['Computer'] . ']') && Appliance_Item::countForMainItem($appliance) === 7, 'Denied subject type is absent from the rendered owner list and count');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveprofile']['location'] = 0;
    verify(count(Appliance_Item_Relation::getForApplianceItem($links['Computer'])) === 2
        && Appliance_Item_Relation::countForMainItem($link) === 2, 'Nested type permissions exclude both duplicate Location bindings from rendering and count');
    $_SESSION['glpiactiveprofile'] = $rights;
    $newComputer = $fixtures->create('glpi_computers', ['name' => 'Retarget subject']);
    verify(!$link->update(['id' => $link->getID(), 'appliances_id' => null]), 'Explicit NULL cannot clear a required appliance owner');
    verify($link->update(['id' => $link->getID(), 'appliances_id' => $other]) && $link->fields['computers_id'] === $sameId && $link->fields['items_id'] === $sameId, 'Owner-only update preserves the absent subject selection');
    verify($link->update(['id' => $link->getID(), 'appliances_id' => $owner]), 'Restore owner without supplying a subject');
    verify(!$link->update(['id' => $link->getID(), 'items_id' => null]) && $read('glpi_appliances_items', $link->getID())['computers_id'] === $sameId, 'Explicit NULL update cannot clear required subject');
    verify($link->update(['id' => $link->getID(), 'items_id' => $newComputer]) && $link->fields['computers_id'] === $newComputer && $link->fields['items_id'] === $newComputer, 'Public asset retarget updates physical fields and projection together');
    verify($link->update(['id' => $link->getID(), 'items_id' => $sameId]), 'Restore public asset subject');
    $newLocation = $fixtures->create('glpi_locations', ['name' => 'Retarget context', 'completename' => 'Retarget context']);
    $relation = new Appliance_Item_Relation();
    verify($relation->getFromDB($nested['Computer']['Location']), 'Load public nested link');
    verify(!$relation->update(['id' => $relation->getID(), 'appliances_items_id' => null]), 'Explicit NULL cannot clear a required nested owner');
    verify($relation->update(['id' => $relation->getID(), 'appliances_items_id' => $links['Monitor']]) && $relation->fields['locations_id'] === $sameId, 'Nested owner-only update preserves the absent context selection');
    verify($relation->update(['id' => $relation->getID(), 'appliances_items_id' => $links['Computer']]), 'Restore nested owner without supplying a context');
    verify($relation->update(['id' => $relation->getID(), 'items_id' => $newLocation]), 'Public nested retarget succeeds');
    verify($relation->fields['locations_id'] === $newLocation && $relation->fields['items_id'] === $newLocation, 'Nested retarget synchronizes canonical/model/generated identities');
    verify($relation->update(['id' => $relation->getID(), 'items_id' => $sameId]), 'Restore nested context');
    $nestedClone = $relation->clone(['items_id' => $newLocation]);
    verify($nestedClone > 0 && $read('glpi_appliances_items_relations', $nestedClone)['locations_id'] === $newLocation, 'Explicit nested clone retarget removes stale copied subject columns');
    verify((new Appliance_Item_Relation())->delete(['id' => $nestedClone], true), 'Delete explicit nested clone');
    $cloneLink = $link->clone(['appliances_id' => $other, 'items_id' => $newComputer]);
    verify($cloneLink > 0 && $read('glpi_appliances_items', $cloneLink)['computers_id'] === $newComputer, 'Explicit asset clone retargets owning subject');
    verify(count($repository()->relationBindings($cloneLink)) === 4 && array_column($repository()->relationBindings($cloneLink), 'items_id') === array_fill(0, 4, $sameId), 'Asset clone preserves all nested contexts including duplicate bindings');
    verify((new Appliance_Item())->delete(['id' => $cloneLink], true) && $repository()->relationBindings($cloneLink) === [], 'Public binding purge runs nested child cleanup');
    $cloneOwner = $appliance->clone(['name' => 'Cloned appliance']);
    $cloned = new Appliance();
    verify($cloneOwner > 0 && $cloned->getFromDB($cloneOwner) && Appliance_Item::countForMainItem($cloned) === 8, 'Public appliance clone preserves all asset bindings');
    foreach ($assets as $kind => $selection) {
        $rows = iterator_to_array(Appliance_Item::getTypeItems($cloneOwner, $kind), false);
        verify(count($rows) === 1 && $rows[0]['id'] === $sameId && $rows[0]['linkid'] !== $links[$kind], 'Cloned appliance owns new links to the same original asset: ' . $kind);
        verify(count($repository()->relationBindings($rows[0]['linkid'])) === ($kind === 'Computer' ? 4 : 3), 'Parent clone invokes nested relation cloning: ' . $kind);
    }
    verify($cloned->delete(['id' => $cloneOwner], true), 'Cloned appliance purge removes its graph');
    $cloneComputer = $computer->clone(['name' => 'Asset clone without appliance links']);
    verify($cloneComputer > 0 && $repository()->ownerCount('Computer', $cloneComputer, []) === 0, 'Asset parent clone preserves appliance-link exclusion');
    $scope = $fixtures->create('glpi_entities', ['name' => 'Visible appliance entity', 'completename' => 'Z visible', 'entities_id' => 0, 'level' => 1]);
    $outside = $fixtures->create('glpi_entities', ['name' => 'Outside appliance entity', 'completename' => 'ZZ outside', 'entities_id' => 0, 'level' => 1]);
    $scoped = $fixtures->create('glpi_computers', ['name' => 'Scoped asset', 'entities_id' => $scope]);
    $foreign = $fixtures->create('glpi_computers', ['name' => 'Foreign asset', 'entities_id' => $outside]);
    $scopedLink = (new Appliance_Item())->add(['appliances_id' => $owner, 'itemtype' => 'Computer', 'computers_id' => $scoped]);
    $foreignLink = (new Appliance_Item())->add(['appliances_id' => $owner, 'itemtype' => 'Computer', 'items_id' => $foreign]);
    verify($scopedLink > 0 && $foreignLink > 0, 'Public canonical and legacy inputs create independent scoped bindings');
    verify(!$appliance->canUnrecurs(), 'Actual appliance recursion removal is blocked by its foreign owning asset');
    $lifecycle = new RelationshipLifecycleRepository(Orm::create($DB));
    $resolve = static fn (string $kind): ?string => EntityRegistry::tables()[getTableForItemType($kind)] ?? null;
    $collision = $fixtures->create('glpi_computers', ['entities_id' => 0]);
    $fixtures->create('glpi_monitors', ['id' => $collision, 'entities_id' => $outside]);
    foreach ([['Contract', 'glpi_contracts', 'glpi_contracts_items', 'contracts_id', Contract_Item::class], ['Project', 'glpi_projects', 'glpi_items_projects', 'projects_id', Item_Project::class]] as [$ownerKind, $ownerTable, $bindingTable, $ownerColumn, $bindingClass]) {
        $managedOwner = $fixtures->create($ownerTable, ['is_recursive' => true]);
        $fixtures->create($bindingTable, [$ownerColumn => $managedOwner, 'itemtype' => 'Computer', 'items_id' => $collision]);
        verify(!$lifecycle->hasDeclaredOutsideEntities($ownerTable, [$bindingTable => $ownerColumn], $managedOwner, $ownerKind, [0], $resolve), 'Managed peer read uses only the owning Computer, not a foreign Monitor with the same identity: ' . $ownerKind);
        $managedForeign = $fixtures->create($bindingTable, [$ownerColumn => $managedOwner, 'itemtype' => 'Computer', 'items_id' => $foreign]);
        verify($lifecycle->hasDeclaredOutsideEntities($ownerTable, [$bindingTable => $ownerColumn], $managedOwner, $ownerKind, [0], $resolve)
            && !$lifecycle->hasDeclaredOutsideEntities($ownerTable, [$bindingTable => $ownerColumn], $managedOwner, $ownerKind, [0, $outside], $resolve), 'Managed peer reads retain the actual foreign reference and complete allowed scope: ' . $ownerKind);
        verify(iterator_to_array($lifecycle->replacements($ownerTable, $managedOwner, $managedOwner, $ownerKind, static fn (): string => 'id'), false) === [], 'Read-only peer inspection does not change managed replacement ownership: ' . $ownerKind);
        verify((new $bindingClass())->delete(['id' => $managedForeign], true), 'Public managed link deletion still owns its lifecycle: ' . $ownerKind);
        verify(!$lifecycle->hasDeclaredOutsideEntities($ownerTable, [$bindingTable => $ownerColumn], $managedOwner, $ownerKind, [0], $resolve), 'Removing the actual foreign binding restores managed graph scope: ' . $ownerKind);
    }
    $storage->update('glpi_computers', $sameId, ['is_recursive' => true]);
    $storage->update('glpi_locations', $sameId, ['is_recursive' => true]);
    $foreignContext = $fixtures->create('glpi_locations', ['name' => 'Foreign nested context', 'completename' => 'Foreign nested context', 'entities_id' => $outside]);
    $foreignContextLink = (new Appliance_Item_Relation())->add(['appliances_items_id' => $links['Computer'], 'itemtype' => 'Location', 'items_id' => $foreignContext]);
    verify($foreignContextLink > 0, 'Public nested binding retains independent context scope');
    $_SESSION['glpiactiveentities'] = [$scope];
    $_SESSION['glpiactiveentities_string'] = (string)$scope;
    $_SESSION['glpiactive_entity'] = $scope;
    verify(array_column(iterator_to_array(Appliance_Item::getTypeItems($owner, 'Computer')), 'id') === [$sameId, $scoped], 'Recursive ancestor and active entity are visible; foreign entity is excluded');
    $visibleContexts = Appliance_Item_Relation::getForApplianceItem($links['Computer']);
    verify(isset($visibleContexts[$nested['Computer']['Location']], $visibleContexts[$duplicate], $visibleContexts[$nested['Computer']['Network']])
        && !isset($visibleContexts[$foreignContextLink], $visibleContexts[$nested['Computer']['Domain']]), 'Nested context scope includes recursive ancestors and global networks while excluding foreign or nonrecursive ancestor contexts');
    ob_start();
    Appliance_Item::showItems($appliance);
    $scopedHtml = ob_get_clean();
    verify(str_contains($scopedHtml, 'item[Appliance_Item][' . $scopedLink . ']') && !str_contains($scopedHtml, 'item[Appliance_Item][' . $foreignLink . ']'), 'Actual recursive owner view follows subject entity scope');
    $storage->update('glpi_computers', $scoped, ['is_template' => true]);
    verify(count(Appliance_Item::getTypeItems($owner, 'Computer')) === 1, 'Template subjects remain excluded');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    verify(count(Appliance_Item::getTypeItems($owner, 'Computer')) === 0 && count(Appliance_Item::getListForItem($computer)) === 0, 'Empty entity scope cannot expose assets or appliance owners');
    verify(array_keys(Appliance_Item_Relation::getForApplianceItem($links['Computer'])) === [$nested['Computer']['Network']], 'Empty entity scope exposes only the genuinely global Network context');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $emptyName = $fixtures->create('glpi_appliances', ['name' => '']);
    $tie1 = $fixtures->create('glpi_appliances', ['name' => 'A tied appliance']);
    $tie2 = $fixtures->create('glpi_appliances', ['name' => 'A tied appliance']);
    foreach ([$emptyName, $tie1, $tie2] as $id) {
        verify((new Appliance_Item())->add(['appliances_id' => $id, 'itemtype' => 'Computer', 'items_id' => $newComputer]) > 0, 'Owner ordering fixture');
    }
    verify(array_column($repository()->owners('Computer', $newComputer, ['entities_id' => 0]), 'id') === [$emptyName, $tie1, $tie2], 'Empty names and tied owner labels order identically on both providers');
    $nullSubject = $fixtures->create('glpi_computers', ['name' => null]);
    $tieSubject1 = $fixtures->create('glpi_computers', ['name' => 'A tied subject']);
    $tieSubject2 = $fixtures->create('glpi_computers', ['name' => 'A tied subject']);
    foreach ([$nullSubject, $tieSubject1, $tieSubject2] as $id) {
        verify((new Appliance_Item())->add(['appliances_id' => $other, 'itemtype' => 'Computer', 'items_id' => $id]) > 0, 'Nullable subject ordering fixture');
    }
    verify(array_column($repository()->assets($other, 'Computer', ['entities_id' => 0], 'name'), 'id') === [$nullSubject, $tieSubject1, $tieSubject2], 'NULL subject names and tied labels order identically on both providers');
    foreach ($contexts as $kind => $selection) {
        verify((new $kind())->delete(['id' => $sameId], true), 'Public nested target purge succeeds: ' . $kind);
        foreach ($nested as $assetKind => $contextsForAsset) {
            verify($read('glpi_appliances_items_relations', $contextsForAsset[$kind]) === null && $read('glpi_appliances_items', $links[$assetKind]) !== null, 'Nested target purge removes its bindings and preserves appliance assets: ' . $kind . '/' . $assetKind);
        }
    }
    verify($read('glpi_appliances_items_relations', $duplicate) === null, 'Nested target purge also removes duplicate bindings');
    foreach ($assets as $kind => $selection) {
        $cascadeContext = $fixtures->create('glpi_locations', ['name' => 'Cascade ' . $kind]);
        $cascade = (new Appliance_Item_Relation())->add(['appliances_items_id' => $links[$kind], 'itemtype' => 'Location', 'items_id' => $cascadeContext]);
        verify($cascade > 0 && (new $kind())->delete(['id' => $sameId], true), 'Every public asset purge succeeds: ' . $kind);
        verify($read('glpi_appliances_items', $links[$kind]) === null && $read('glpi_appliances_items_relations', $cascade) === null
            && $read('glpi_locations', $cascadeContext) !== null && $read('glpi_appliances', $owner) !== null, 'Asset purge runs nested lifecycle while preserving independent owner/context: ' . $kind);
    }
    verify($appliance->delete(['id' => $owner], true) && $read('glpi_appliances_items', $foreignLink) === null && $read('glpi_computers', $foreign) !== null, 'Appliance owner purge removes remaining links and preserves assets');
    verify((new ForeignKeys())->audit($connection) === [], 'Public lifecycle operations leave no orphaned references');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
    $GLPI_CACHE = $savedCache;
    restore_error_handler();
}
echo $DB->getProvider() . ": eight appliance assets and three nested contexts, owning/public writes, scopes, rendered binding identities, duplicates, clone and nested purge lifecycles passed.\n";
