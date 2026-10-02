<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity\Computer as ComputerRecord;
use itsmng\Database\Orm;
use itsmng\Database\RecordCriteria;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/boolean-predicates.php /path/to/test-config\n");
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
$savedSession = $_SESSION;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $first = $fixtures->create('glpi_computers', ['name' => 'Boolean predicates active', 'is_deleted' => false]);
    $second = $fixtures->create('glpi_computers', ['name' => 'Boolean predicates deleted', 'is_deleted' => true]);
    $ids = [$first, $second];
    $cases = [
        [[true], $ids], [[false], []],
        [['AND' => [true, false]], []], [['OR' => [false, true]], $ids],
        [['AND' => [true, true]], $ids], [['OR' => [false, false]], []],
        [['NOT' => [false]], $ids], [['NOT' => [true]], []],
        [['NOT' => ['OR' => [false, ['NOT' => [true]]]]], $ids],
        [['AND' => [true, ['OR' => [false, ['id' => $first]]]]], [$first]],
        [['OR' => [false, ['AND' => [true, ['is_deleted' => true]]]]], [$second]],
        [['NOT' => ['AND' => [true, ['is_deleted' => true]]]], [$first]],
        [['AND' => true], $ids], [['OR' => false], []], [['NOT' => false], $ids],
        [['is_deleted' => false], [$first]], [['is_deleted' => true], [$second]],
    ];
    $em = Orm::create($DB);
    $repository = new RecordRepository($em);
    foreach ($cases as $number => [$criteria, $expected]) {
        $scope = ['AND' => [['id' => $ids], $criteria]];
        $native = iterator_to_array($DB->request(['FROM' => 'glpi_computers', 'WHERE' => $scope, 'ORDER' => 'id']));
        verify(array_map('intval', array_column($native, 'id')) === $expected, 'Native boolean predicate truth table: ' . $number);
        verify(array_column($repository->matching('glpi_computers', $scope, 'id'), 'id') === $expected, 'Mapped boolean predicate truth table: ' . $number);
        verify($repository->countMatching('glpi_computers', $scope) === count($expected), 'Mapped boolean predicate count: ' . $number);
        verify(array_keys((new Computer())->find($scope, 'id')) === $expected, 'Public model boolean predicate selection: ' . $number);
    }
    $iterator = new DBmysqlIterator($DB);
    verify($iterator->analyseCrit(true) === '1 = 1' && $iterator->analyseCrit(false) === '1 = 0', 'Literal SQL booleans remain predicates');
    verify($iterator->analyseCrit([]) === '', 'Existing empty legacy criteria remain empty');
    $query = $em->createQueryBuilder()->select('r.id')->from(ComputerRecord::class, 'r');
    $compiler = new RecordCriteria($query, $em->getClassMetadata(ComputerRecord::class));
    verify($compiler->where([]) === '1 = 1' && $compiler->where([], 'OR') === '1 = 0', 'Existing empty mapped conjunction identities remain unchanged');
    foreach ([[1], [0], ['1 = 1'], [new QueryExpression('1 = 1')]] as $raw) {
        try {
            $compiler->where($raw);
            throw new RuntimeException('Raw predicate was silently accepted');
        } catch (UnsupportedCriteria $expected) {
        }
    }

    // This exact public update is used by Entity::testChangeEntityParent.
    verify($DB->update('glpi_entities', ['ancestors_cache' => null, 'sons_cache' => null], [true]) === true, 'Public Entity cache reset accepts an unrestricted boolean predicate');
    verify($DB->update('glpi_computers', ['comment' => 'Boolean update'], ['AND' => [['id' => $ids], [true]]]) === true, 'Public update accepts true in a nested conjunction');
    verify($DB->update('glpi_computers', ['comment' => 'Must not change'], ['AND' => [['id' => $ids], [false]]]) === true, 'Public false update is a valid no-op');
    verify($repository->countMatching('glpi_computers', ['id' => $ids, 'comment' => 'Boolean update']) === 2, 'False update cannot alter selected records');

    // Search still consumes this shared visibility declaration. An administrator
    // with all entities gets [true] inside the profile-sharing branch.
    $viewer = $fixtures->create('glpi_users', ['name' => 'Boolean predicate viewer ' . bin2hex(random_bytes(4))]);
    $profile = $fixtures->create('glpi_profiles');
    $entity = $fixtures->create('glpi_entities', ['name' => 'Boolean visibility scope']);
    $foreign = $fixtures->create('glpi_entities', ['name' => 'Boolean visibility foreign']);
    $owned = $fixtures->create('glpi_rssfeeds', ['users_id' => $viewer]);
    $shared = $fixtures->create('glpi_rssfeeds');
    $global = $fixtures->create('glpi_rssfeeds');
    $hidden = $fixtures->create('glpi_rssfeeds');
    $fixtures->create('glpi_profiles_rssfeeds', ['rssfeeds_id' => $shared, 'profiles_id' => $profile, 'entities_id' => $foreign]);
    $fixtures->create('glpi_profiles_rssfeeds', ['rssfeeds_id' => $global, 'profiles_id' => $profile, 'entities_id' => null]);
    $_SESSION['glpiID'] = $viewer;
    $_SESSION['glpiactiveprofile'] = ['id' => $profile, 'rssfeed_public' => READ];
    $_SESSION['glpigroups'] = [];
    $_SESSION['glpiactiveentities'] = [];
    $_SESSION['glpishowallentities'] = 1;
    $visible = static function () use ($DB, $owned, $shared, $global, $hidden): array {
        $criteria = RSSFeed::getVisibilityCriteria();
        $criteria['FROM'] = 'glpi_rssfeeds';
        $criteria['SELECT'] = 'glpi_rssfeeds.id';
        $criteria['WHERE'] = ['AND' => [['glpi_rssfeeds.id' => [$owned, $shared, $global, $hidden]], $criteria['WHERE']]];
        $criteria['ORDER'] = 'glpi_rssfeeds.id';
        return array_map('intval', array_column(iterator_to_array($DB->request($criteria)), 'id'));
    };
    verify($visible() === [$owned, $shared, $global], 'Administrator RSS profile sharing keeps literal true and excludes unrelated feeds');
    $_SESSION['glpishowallentities'] = 0;
    $_SESSION['glpiactiveentities'] = [$entity];
    verify($visible() === [$owned, $global], 'Scoped RSS profile sharing excludes the foreign entity while allowing global sharing');
    $_SESSION['glpiactiveprofile']['rssfeed_public'] = 0;
    verify($visible() === [$owned], 'Without public RSS rights only the owner branch is visible');
} finally {
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
    $DB->rollBack();
}
echo $DB->getProvider() . ": boolean predicate truth tables, typed values, public updates and RSS visibility passed.\n";
