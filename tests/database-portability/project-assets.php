<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\Entity as Record;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedStorage;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectAssetRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/project-assets.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';
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
$savedSession = $_SESSION;
$savedConfiguration = $CFG_GLPI;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$storage = new MappedStorage($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
$repository = static fn () => new ProjectAssetRepository(Orm::create($DB));
$branches = EntityRegistry::discriminatedReferences('glpi_items_projects')['items_id']['selections'];
$actual = array_keys($branches);
$expected = $CFG_GLPI['contract_types'];
sort($actual);
sort($expected);
verify($actual === $expected && count($branches) === 35, 'Every configured project subject has an owning association');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical history installed the required schema');
$reject = static function (callable $operation, string $message, ?string $omittedRequiredColumn = null) use ($connection): void {
    $connection->beginTransaction();
    try {
        $failed = false;
        try {
            $operation();
        } catch (Throwable $error) {
            // DBAL's native PgSQL statement destructor can attach a DEALLOCATE
            // warning to an already-thrown constraint failure in the aborted
            // savepoint. Require the actual driver SQLSTATE in that chain.
            $cause = $error;
            while (!$cause instanceof DriverException && $cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            if (!$cause instanceof DriverException) {
                throw $error;
            }
            $failed = NativeConstraintRefusal::matches($cause, $omittedRequiredColumn);
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
    $pictureDirectory = GLPI_PICTURE_DIR . '/project-purge-' . bin2hex(random_bytes(4));
    mkdir($pictureDirectory);
    $picture = tempnam($pictureDirectory, 'picture-');
    $outsidePicture = tempnam(GLPI_TMP_DIR, 'outside-picture-');
    try {
        verify(!Toolbox::deletePicture(null) && !Toolbox::deletePicture('') && !Toolbox::deletePicture('.'), 'Absent blueprints and the picture directory cannot be unlinked');
        verify(!Toolbox::deletePicture(basename($pictureDirectory)) && is_dir($pictureDirectory), 'Nested picture directories remain intact');
        verify(!Toolbox::deletePicture('../_tmp/' . basename($outsidePicture)) && is_file($outsidePicture), 'A picture deletion cannot target another application directory');
        verify(Toolbox::deletePicture(basename($pictureDirectory) . '/' . basename($picture)) && !file_exists($picture), 'An existing contained picture file is deleted');
        verify(!Toolbox::deletePicture(basename($pictureDirectory) . '/' . basename($picture)), 'Missing pictures retain the false return contract');
    } finally {
        if (file_exists($picture)) {
            unlink($picture);
        }
        unlink($outsidePicture);
        rmdir($pictureDirectory);
    }
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $_SESSION['glpishowallentities'] = false;
    $sameId = 4294969111;
    $owner = $fixtures->create('glpi_projects', ['name' => "Project owner O'Reilly 東京", 'is_recursive' => true]);
    $other = $fixtures->create('glpi_projects', ['name' => 'Other project owner']);
    $links = [];
    foreach ($branches as $kind => $selection) {
        $values = ['id' => $sameId];
        $subject = new $kind();
        if ($subject instanceof Item_Devices) {
            $definitionColumn = $kind::$items_id_2;
            $definitionTable = ForeignKeys::relations()[$selection['target']][$definitionColumn];
            $values[$definitionColumn] = $fixtures->create($definitionTable, ['designation' => 'Definition ' . $kind]);
        } else {
            $values['name'] = 'Subject ' . $kind;
        }
        $fixtures->create($selection['target'], $values);
        $input = ['projects_id' => $owner, 'itemtype' => $kind, 'items_id' => $sameId];
        $link = new Item_Project();
        verify($link->can(-1, CREATE, $input), 'Authorized public link creation: ' . $kind);
        $id = $link->add($input);
        verify($id > 0 && $link->fields['items_id'] === $sameId && $link->fields[$selection['column']] === $sameId
            && $link->fields['projects_id'] === $owner, 'Public legacy input owns the selected subject and retains its owner: ' . $kind);
        $links[$kind] = $id;
        verify(!$link->add($input), 'Public duplicate is rejected: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_items_projects', ['projects_id' => $owner, 'itemtype' => $kind, $selection['column'] => $sameId]), 'Database duplicate is rejected: ' . $kind);
        $reject(static fn () => $connection->insert('glpi_items_projects', ['projects_id' => $other, 'itemtype' => $kind, $selection['column'] => $sameId + 999]), 'Invalid target is rejected: ' . $kind);
        $reject(static fn () => $connection->delete($selection['target'], ['id' => $sameId]), 'Direct target deletion is restricted: ' . $kind);
        $em = Orm::create($DB);
        $native = new Record\ItemProject();
        $native->projects = $em->getReference(Record\Project::class, $other);
        $native->itemtype = $kind;
        $association = Record\ItemProject::referenceAssociation($kind);
        $target = $em->getClassMetadata(Record\ItemProject::class)->getAssociationTargetClass($association);
        $native->{$association} = $em->getReference($target, $sameId);
        $em->persist($native);
        $em->flush();
        verify($native->items_id === $sameId, 'Native owning graph generates a 64-bit legacy projection: ' . $kind);
        $em->remove($native);
        $em->flush();
        $em->clear();
        $criteria = getEntitiesRestrictCriteria($selection['target'], '', '', 'auto');
        $rows = $repository()->subjects($owner, $kind, $criteria, $subject instanceof Item_Devices ? 'itemtype' : $subject::getNameField(), $subject instanceof Item_Devices ? $kind::$items_id_2 : null);
        verify(count($rows) === 1 && $rows[0]['id'] === $sameId && (int)$rows[0]['linkid'] === $id, 'Owning subject list isolates overlapping kind identities: ' . $kind);
        if ($subject instanceof Item_Devices) {
            verify($rows[0]['name'] === 'Definition ' . $kind, 'Installed subject display name comes from its owning definition: ' . $kind);
        }
    }
    $rootProject = new Project();
    verify($rootProject->getFromDB($owner), 'Load project owner');
    $countedComputer = new Computer();
    verify($countedComputer->getFromDB($sameId), 'Load counted subject');
    verify(Item_Project::countForMainItem($rootProject) === 35 && Item_Project::countForItem($countedComputer) === 1, 'Public counts select distinct owner and subject directions');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_logs', ['itemtype' => 'Project', 'items_id' => $owner, 'linked_action' => Log::HISTORY_ADD_RELATION]) === 35, 'Public association creation retains owner audit history');
    foreach ([[], ['itemtype' => null], ['itemtype' => 'UnknownPlugin'], ['itemtype' => 'Computer'], ['itemtype' => 'Computer', 'computers_id' => 0], ['itemtype' => 'Computer', 'monitors_id' => $sameId], ['itemtype' => 'Computer', 'computers_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        $reject(static fn () => $connection->insert('glpi_items_projects', $invalid + ['projects_id' => $other]), 'Missing, unknown, zero, mismatched or multiple discriminator selection is rejected', !array_key_exists('itemtype', $invalid) ? 'itemtype' : null);
    }
    $reject(static fn () => $connection->insert('glpi_items_projects', ['projects_id' => 999999999, 'itemtype' => 'Computer', 'computers_id' => $sameId]), 'Nonexistent project owner is rejected');
    foreach ([['itemtype' => 'UnknownPlugin', 'items_id' => $sameId], ['itemtype' => 'Computer', 'items_id' => 0], ['itemtype' => 'Computer', 'items_id' => $sameId, 'monitors_id' => $sameId]] as $invalid) {
        verify(!(new Item_Project())->add($invalid + ['projects_id' => $other]), 'Invalid public discriminator input is rejected before persistence');
    }
    $em = Orm::create($DB);
    $retained = new ProjectAssetRepository($em);
    verify((new ReflectionProperty($retained, 'em'))->getValue($retained) === $em && $em->getConnection() === $connection, 'Repository retains the supplied manager and application connection');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $bindings = $retained->bindings($owner);
    verify(array_column($bindings, 'id') === array_values($links) && count($retained->kinds($owner)) === 35, 'Notification bindings retain ordered individual relation IDs');
    verify($retained->bindings(0) === [] && $retained->subjects($owner, "Computer' OR 1=1", [], 'name') === [], 'Absent owners and unsupported kinds cannot widen the query');
    foreach ($branches as $kind => $selection) {
        Item_Project::getTypeItems($owner, $kind);
    }
    Item_Project::countForMainItem($rootProject);
    verify($SQL_TOTAL_REQUEST === 0, 'Subject selectors and public counts bypass the legacy SQL adapter');
    $notifications = new NotificationTargetProject(0, 'new', $rootProject);
    $notifications->addDataForTemplate('new', ['additionnaloption' => ['usertype' => NotificationTarget::ANONYMOUS_USER]]);
    verify($notifications->data['##project.numberofitems##'] === 35, 'Real notification template loads all subject models and their lifecycle hooks');
    verify(count(array_filter(array_column($notifications->data['items'], '##item.name##'), static fn ($name) => $name === 'Subject Project')) === 1, 'Notification owner and Project subject roles remain independent');
    ob_start();
    Item_Project::showForProject($rootProject);
    $html = ob_get_clean();
    foreach ($links as $id) {
        verify(str_contains($html, 'item[Item_Project][' . $id . ']'), 'Rendered massive-action choice retains its relation ID');
    }
    $rights = $_SESSION['glpiactiveprofile'];
    $_SESSION['glpiactiveprofile']['project'] = 0;
    ob_start();
    $result = Item_Project::showForProject($rootProject);
    $denied = ob_get_clean();
    verify($result === false && $denied === '' && Item_Project::countForMainItem($rootProject) === 0, 'Actual project view and tab count expose nothing without owner read permission');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveprofile']['project'] = Project::READMY;
    ob_start();
    $result = Item_Project::showForProject($rootProject);
    $denied = ob_get_clean();
    verify($result === false && $denied === '' && Item_Project::countForMainItem($rootProject) === 0, 'Read-my permission alone cannot expose an unrelated owner');
    $fixtures->create('glpi_projectteams', ['projects_id' => $owner, 'itemtype' => 'User', 'items_id' => Session::getLoginUserID()]);
    verify($rootProject->getFromDB($owner), 'Reload the public owner with its current team');
    ob_start();
    Item_Project::showForProject($rootProject);
    $teamHtml = ob_get_clean();
    verify(str_contains($teamHtml, 'item[Item_Project][' . $links['Computer'] . ']') && Item_Project::countForMainItem($rootProject) === 35, 'Read-my owner team membership authorizes the actual view and count through model hooks');
    $_SESSION['glpiactiveprofile'] = $rights;
    $_SESSION['glpiactiveprofile']['computer'] = 0;
    verify(count(Item_Project::getTypeItems($owner, 'Computer')) === 0 && Item_Project::countForMainItem($rootProject) === 34, 'Subject type rights apply to both actual lists and counts');
    $_SESSION['glpiactiveprofile'] = $rights;

    $newComputer = $fixtures->create('glpi_computers', ['name' => 'Retarget computer']);
    $canonical = new Item_Project();
    $canonicalId = $canonical->add(['projects_id' => $other, 'itemtype' => 'Computer', 'computers_id' => $sameId]);
    verify($canonicalId > 0 && $canonical->fields['items_id'] === $sameId, 'Public canonical input projects the selected subject without requiring legacy input');
    $reject(static fn () => $connection->update('glpi_items_projects', ['projects_id' => $owner], ['id' => $canonicalId]), 'Duplicate owner/subject update is rejected');
    $reject(static fn () => $connection->update('glpi_items_projects', ['itemtype' => 'Monitor'], ['id' => $canonicalId]), 'Discriminator-only update cannot change the owning subject kind');
    $reject(static fn () => $connection->update('glpi_items_projects', ['computers_id' => null], ['id' => $canonicalId]), 'A required owning subject cannot be cleared by update');
    $reject(static fn () => $connection->update('glpi_items_projects', ['computers_id' => $sameId + 999], ['id' => $canonicalId]), 'Update to a nonexistent owning subject is rejected');
    verify($canonical->delete(['id' => $canonicalId], true), 'Public canonical relation deletion runs its lifecycle');
    $link = new Item_Project();
    verify($link->getFromDB($links['Computer']), 'Load public relation before updating');
    foreach ([['items_id' => null], ['computers_id' => null], ['itemtype' => 'UnknownPlugin'], ['monitors_id' => $sameId]] as $invalid) {
        verify(!$link->update($invalid + ['id' => $link->getID()]), 'Invalid public reference update is rejected before persistence');
        verify($read('glpi_items_projects', $link->getID())['computers_id'] === $sameId, 'Rejected update retains the original owning association');
    }
    verify($link->update(['id' => $link->getID(), 'items_id' => $newComputer]), 'Public relation update retargets its subject');
    verify($link->fields['computers_id'] === $newComputer && $link->fields['items_id'] === $newComputer && $link->fields['projects_id'] === $owner, 'Retarget updates the owning association and projection without moving its owner');
    verify($link->update(['id' => $link->getID(), 'items_id' => $sameId]), 'Restore subject through the public writer');
    $clone = $link->clone(['projects_id' => $other, 'items_id' => $newComputer]);
    verify($clone > 0 && $read('glpi_items_projects', $clone)['computers_id'] === $newComputer, 'Explicit relation clone replaces stale copied canonical subject columns');
    verify((new Item_Project())->delete(['id' => $clone], true), 'Delete cloned relation through lifecycle');
    $computer = new Computer();
    verify($computer->getFromDB($sameId), 'Load parent clone source');
    $cloneComputer = $computer->clone(['name' => 'Computer clone without project links']);
    verify($cloneComputer > 0 && $repository()->ownerCount('Computer', $cloneComputer, []) === 0, 'Parent asset cloning preserves its existing project-link exclusion');
    $cloneProject = $rootProject->clone(['name' => 'Project clone without subject links']);
    verify($cloneProject > 0 && $repository()->bindings($cloneProject) === [], 'Parent project cloning preserves its existing subject-link exclusion');

    $scope = $fixtures->create('glpi_entities', ['name' => 'Project visible entity', 'completename' => 'Z visible entity', 'entities_id' => 0, 'level' => 1]);
    $outside = $fixtures->create('glpi_entities', ['name' => 'Project outside entity', 'completename' => 'ZZ outside entity', 'entities_id' => 0, 'level' => 1]);
    $transfer = new Transfer();
    ob_start();
    try {
        $transfer->moveItems(['Computer' => [$sameId]], $scope, []);
    } finally {
        ob_end_clean();
    }
    verify($read('glpi_computers', $sameId)['entities_id'] === $scope, 'Actual asset transfer moves its entity');
    verify($read('glpi_items_projects', $links['Computer'])['projects_id'] === $owner
        && $read('glpi_items_projects', $links['Computer'])['computers_id'] === $sameId, 'Asset transfer preserves the existing project owner and subject binding');
    $storage->update('glpi_computers', $sameId, ['entities_id' => 0]);
    $scopedComputer = $fixtures->create('glpi_computers', ['entities_id' => $scope, 'name' => 'Scoped computer']);
    $outsideComputer = $fixtures->create('glpi_computers', ['entities_id' => $outside, 'name' => 'Outside computer']);
    foreach ([$scopedComputer, $outsideComputer] as $id) {
        verify((new Item_Project())->add(['projects_id' => $owner, 'itemtype' => 'Computer', 'items_id' => $id]) > 0, 'Fixture links retain independent subject scope');
    }
    $storage->update('glpi_computers', $sameId, ['is_recursive' => true]);
    $_SESSION['glpiactiveentities'] = [$scope];
    $_SESSION['glpiactiveentities_string'] = (string)$scope;
    $_SESSION['glpiactive_entity'] = $scope;
    $rows = iterator_to_array(Item_Project::getTypeItems($owner, 'Computer'));
    verify(array_column($rows, 'id') === [$sameId, $scopedComputer], 'Recursive ancestor subject and active entity remain visible; foreign entity is excluded: ' . json_encode(array_column($rows, 'id')));
    $storage->update('glpi_computers', $scopedComputer, ['is_template' => true]);
    verify(count(Item_Project::getTypeItems($owner, 'Computer')) === 1, 'Template subjects remain excluded');
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpiactiveentities_string'] = '';
    verify(count(Item_Project::getTypeItems($owner, 'Computer')) === 0, 'An empty active entity scope never exposes subjects');
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    $_SESSION['glpiactive_entity'] = 0;
    $nullName = $fixtures->create('glpi_computers', ['name' => null]);
    $tie1 = $fixtures->create('glpi_computers', ['name' => 'A tied name']);
    $tie2 = $fixtures->create('glpi_computers', ['name' => 'A tied name']);
    foreach ([$nullName, $tie1, $tie2] as $id) {
        verify((new Item_Project())->add(['projects_id' => $other, 'itemtype' => 'Computer', 'items_id' => $id]) > 0, 'Ordering fixture link');
    }
    verify(array_column($repository()->subjects($other, 'Computer', ['entities_id' => 0], 'name'), 'id') === [$nullName, $tie1, $tie2], 'NULL names and tied labels have the same deterministic order on both providers');
    $peerOwner = $fixtures->create('glpi_projects', ['name' => 'Single peer owner']);
    $fixtures->create('glpi_projectteams', ['projects_id' => $peerOwner, 'itemtype' => 'User', 'items_id' => Session::getLoginUserID()]);
    $peerComputer = $fixtures->create('glpi_computers');
    $peerLink = (new Item_Project())->add(['projects_id' => $peerOwner, 'itemtype' => 'Computer', 'items_id' => $peerComputer]);
    $relation = 0;
    $opposite = Item_Project::getOppositeByTypeAndID('Computer', $peerComputer, $relation);
    verify($opposite instanceof Project && $opposite->getID() === $peerOwner && $relation === $peerLink, 'Opposite lookup retains public model loading and the individual relation ID');
    verify(count((new ReflectionProperty(Project::class, 'team'))->getValue($opposite)['User']) === 1, 'Opposite lookup executes the public Project post_getFromDB team hook');
    $self = $fixtures->create('glpi_projects', ['name' => 'Self-bound project']);
    $selfLink = (new Item_Project())->add(['projects_id' => $self, 'itemtype' => 'Project', 'items_id' => $self]);
    $roles = $repository()->relationshipsForItem('Project', $self);
    verify(count($roles) === 1 && $roles[0]['is_1'] === 1 && $roles[0]['is_2'] === 1 && Item_Project::getOppositeByTypeAndID('Project', $self) === false, 'A self-link occupies both distinct roles once and has no opposite');
    verify((new Project())->delete(['id' => $self], true) && $read('glpi_items_projects', $selfLink) === null, 'Public self-project purge removes its binding once');

    foreach ($branches as $kind => $selection) {
        $subject = new $kind();
        verify($subject->delete(['id' => $sameId], true), 'Public purge succeeds for every supported subject: ' . $kind);
        verify($read('glpi_items_projects', $links[$kind]) === null && $read('glpi_projects', $owner) !== null, 'Subject purge cleans its binding and preserves the independent owner: ' . $kind);
    }
    verify($rootProject->delete(['id' => $owner], true), 'Project owner purge removes its remaining subject links');
    verify($repository()->bindings($owner) === [] && $read('glpi_computers', $outsideComputer) !== null, 'Owner purge preserves the referenced subjects');
    verify((new ForeignKeys())->audit($connection) === [], 'No orphaned references after public lifecycle operations');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfiguration;
    $GLPI_CACHE = $savedCache;
    restore_error_handler();
}
echo $DB->getProvider() . ": thirty-five project subjects, native/public writes and history, scoped lists/counts, model hooks/notifications, ordering, clone/transfer boundaries, both Project roles and all public purges passed.\n";
