<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\SoftwareDictionaryRepository;
use itsmng\Database\Repository\SoftwareRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-dictionary.php /path/to/test-config\n");
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
    $repository = fn (): SoftwareDictionaryRepository => new SoftwareDictionaryRepository(Orm::create($DB));
    $read = fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
    $prefix = 'Dictionary ' . bin2hex(random_bytes(5));
    $manufacturer = $fixtures->create('glpi_manufacturers', ['name' => $prefix]);
    $entity = $fixtures->create('glpi_entities', ['name' => $prefix]);
    $category = $fixtures->create('glpi_softwarecategories', ['name' => $prefix]);
    $base = ['name' => $prefix . " O'Reilly \\ 日本語", 'manufacturers_id' => $manufacturer];
    $source = $fixtures->create('glpi_softwares', $base);
    $duplicate = $fixtures->create('glpi_softwares', $base);
    $foreign = $fixtures->create('glpi_softwares', $base + ['entities_id' => $entity]);
    $classified = $fixtures->create('glpi_softwares', $base + ['softwarecategories_id' => $category]);
    $hidden = $fixtures->create('glpi_softwares', $base + ['is_helpdesk_visible' => false]);
    $deleted = $fixtures->create('glpi_softwares', $base + ['is_deleted' => true]);
    $template = $fixtures->create('glpi_softwares', $base + ['is_template' => true]);
    $groups = iterator_to_array($repository()->replayGroups($manufacturer, 0), false);
    verify(count($groups) === 4 && $repository()->groupCount($manufacturer) === 4, 'Replay groups collapse only identical complete inputs and exclude deleted/templates');
    verify($groups[0]['name'] === $base['name'] && (int)$groups[0]['entities_id'] === 0 && $groups[0]['softwarecategories_id'] === null, 'Replay retains raw Unicode/backslash names, real root and NULL category');
    verify(iterator_to_array($repository()->replayGroups($manufacturer, 2), false) === array_slice($groups, 2), 'Stable complete-group offset');
    verify(iterator_to_array($repository()->replayGroups($manufacturer, 100), false) === [], 'Past-end offset is empty');
    verify($repository()->matchingSoftware($base['name'], $manufacturer) === [$source, $duplicate, $foreign, $classified, $hidden, $deleted, $template], 'Matching software retains the former cross-entity/deleted lookup for explicit replay');
    verify($repository()->replaySoftware($template) === null && $repository()->replaySoftware($deleted) !== null, 'Explicit replay excludes templates but can restore a deleted software');
    $unowned = $fixtures->create('glpi_softwares', ['name' => $prefix . ' NULL']);
    verify($repository()->matchingSoftware($prefix . ' NULL', null) === [$unowned], 'No-manufacturer selection uses its nullable association');

    $target = $fixtures->create('glpi_softwares', ['name' => $prefix . ' destination']);
    $versionName = "O'Reilly \\ 日本語";
    $destination = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => $versionName]);
    $secondDestination = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => $versionName]);
    $incoming = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => 'old']);
    $unnamed = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => null]);
    $literal = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => 'NULL']);
    $empty = $fixtures->create('glpi_softwareversions', ['softwares_id' => $target, 'name' => '']);
    $collection = new RuleDictionnarySoftwareCollection();
    verify($collection->versionExists($target, addslashes($versionName)) === $destination, 'Legacy escaped name resolves one deterministic destination');
    verify($collection->versionExists($target, null) === $unnamed && $collection->versionExists($target, 'NULL') === $literal
        && $collection->versionExists($target, '') === $empty, 'NULL, empty and literal NULL names remain distinct');
    verify($collection->versionExists($target, 'missing') === -1, 'Missing version sentinel');
    $asset = $fixtures->create('glpi_computers', ['id' => 100000, 'name' => $prefix]);
    $monitor = $fixtures->create('glpi_monitors', ['id' => $asset, 'name' => $prefix]);
    $existing = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $destination, 'itemtype' => 'Computer', 'items_id' => $asset, 'date_install' => '2024-01-01', 'is_dynamic' => false]);
    $collision = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $incoming, 'itemtype' => 'Computer', 'items_id' => $asset, 'date_install' => '2025-01-01', 'is_dynamic' => true]);
    $differentType = $fixtures->create('glpi_items_softwareversions', ['softwareversions_id' => $incoming, 'itemtype' => 'Monitor', 'items_id' => $monitor, 'is_dynamic' => true]);
    $license = $fixtures->create('glpi_softwarelicenses', ['softwares_id' => $source, 'softwareversions_id_buy' => $incoming, 'softwareversions_id_use' => $incoming]);
    $rejected = false;
    try {
        (new SoftwareRepository(Orm::create($DB)))->moveDictionaryVersion($target, $incoming, $versionName, static function (int $id): bool {
            verify((new SoftwareVersion())->delete(['id' => $id]), 'Lifecycle deletes source before rollback');
            return false;
        });
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Unable to delete software version after dictionary merging.', 'Expected lifecycle rejection');
        $rejected = true;
    }
    verify($rejected && $DB->inTransaction() && $read('glpi_softwareversions', $incoming) !== null, 'Nested rollback restores source version and caller transaction');
    verify($read('glpi_items_softwareversions', $collision) !== null && (int)$read('glpi_items_softwareversions', $differentType)['softwareversions_id'] === $incoming
        && (int)$read('glpi_softwarelicenses', $license)['softwareversions_id_buy'] === $incoming, 'Rollback restores duplicate links, moved links and license references');
    $collection->moveVersions($source, $target, $incoming, 'old', addslashes($versionName), 0);
    verify($read('glpi_softwareversions', $incoming) === null && $read('glpi_items_softwareversions', $collision) === null, 'Successful dictionary merge removes source version and installation collision');
    verify($read('glpi_items_softwareversions', $existing)['date_install'] === '2024-01-01' && $read('glpi_items_softwareversions', $existing)['is_dynamic'] === 0, 'Destination installation metadata wins');
    verify((int)$read('glpi_items_softwareversions', $differentType)['softwareversions_id'] === $destination, 'Equal numeric IDs across asset types do not collide');
    verify((int)$read('glpi_softwarelicenses', $license)['softwareversions_id_buy'] === $destination
        && (int)$read('glpi_softwarelicenses', $license)['softwareversions_id_use'] === $destination, 'Both license associations move before restrictive source deletion');
    verify($read('glpi_softwareversions', $secondDestination) !== null, 'Additional destination version remains untouched');
    $unique = $fixtures->create('glpi_softwareversions', ['softwares_id' => $source, 'name' => 'unique', 'entities_id' => $entity]);
    $collection->moveVersions($source, $target, $unique, 'unique', addslashes('renamed \\ version'), 0);
    $row = $read('glpi_softwareversions', $unique);
    verify((int)$row['softwares_id'] === $target && $row['name'] === 'renamed \\ version' && (int)$row['entities_id'] === $entity, 'Rename moves software ownership while retaining the former version entity policy');
    $collection->moveVersions($target, $target, $unique, addslashes($row['name']), addslashes($row['name']), 0);
    verify($read('glpi_softwareversions', $unique) !== null, 'Same-version move is harmless');
    verify(!$collection->moveLicenses(2147483647, $target) && !$collection->moveLicenses($source, 2147483647), 'Missing license source/target fails without partial writes');
    verify($collection->moveLicenses($source, $target) && $collection->moveLicenses($target, $target), 'License move and self move succeed');
    verify((int)$read('glpi_softwarelicenses', $license)['softwares_id'] === $target, 'License ownership is moved independently of version policy');
    $collection->putOldSoftsInTrash([$source, $target, $deleted, $template]);
    verify($read('glpi_softwares', $source)['is_deleted'] === 1 && $read('glpi_softwares', $target)['is_deleted'] === 0
        && $read('glpi_softwares', $template)['is_deleted'] === 1, 'Only requested unversioned software is trashed through its public lifecycle');

    $replayManufacturer = $fixtures->create('glpi_manufacturers', ['name' => $prefix . ' replay']);
    $replay = $fixtures->create('glpi_softwares', ['name' => $prefix . ' replay old', 'manufacturers_id' => $replayManufacturer]);
    $replayVersion = $fixtures->create('glpi_softwareversions', ['softwares_id' => $replay, 'name' => '1 \\ old']);
    $stub = new class () extends RuleDictionnarySoftwareCollection {
        public array $inputs = [];
        public function processAllRules($input = [], $output = [], $params = [], $options = [])
        {
            $this->inputs[] = $input;
            return ['name' => addslashes($input['name'] . ' renamed')];
        }
    };
    ob_start();
    try {
        verify($stub->replayRulesOnExistingDB(0, 0, [], ['manufacturer' => $replayManufacturer]) === -1, 'Full manufacturer replay completes');
        verify($stub->replayRulesOnExistingDB(100, 0, [], ['manufacturer' => $replayManufacturer]) === -1, 'Past-end replay terminates');
    } finally {
        ob_end_clean();
    }
    verify(count($stub->inputs) === 1 && $stub->inputs[0]['name'] === $prefix . ' replay old', 'Replay consumes its original grouped input exactly once');
    $moved = $read('glpi_softwareversions', $replayVersion);
    verify((int)$moved['softwares_id'] !== $replay && $moved['name'] === '1 \\ old'
        && $read('glpi_softwares', $replay)['is_deleted'] === 1, 'Public rename replay preserves raw version name and trashes the emptied source');

    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    $repository()->groupCount($manufacturer);
    iterator_to_array($repository()->replayGroups($manufacturer, 0), false);
    $repository()->matchingSoftware($base['name'], $manufacturer);
    $repository()->replaySoftware($target);
    $repository()->unusedSoftware([$source, $target]);
    $collection->versionExists($target, 'NULL');
    $collection->moveLicenses($target, $target);
    $collection->moveVersions($target, $target, $unique, '', 'debug renamed', 0);
    verify($SQL_TOTAL_REQUEST === 0, 'Dictionary read/write operations execute no legacy adapter SQL after mapping warm-up');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'All restrictive relationships remain valid');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped software dictionary replay, grouping, literal names, typed installation collisions, license moves and atomic rollback passed.\n";
