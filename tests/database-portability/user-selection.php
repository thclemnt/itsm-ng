<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\RecordCriteria;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\UserSelectionRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/user-selection.php /path/to/test-config\n");
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
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedCache = $GLPI_CACHE;
// This isolated cache holds the small selector fixture. The adapter's implicit
// limit counts the entire PHP process, including the Doctrine mapping graph.
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(['memory_limit' => 0]), GLPI_CACHE_DIR, false);
$fixtures = new FixtureRecords($DB);
$writer = static fn () => new RecordWriter(Orm::create($DB));
$DB->beginTransaction();
try {
    $prefix = 'Selector ' . bin2hex(random_bytes(5));
    $entity = $fixtures->create('glpi_entities', ['name' => $prefix]);
    $child = $fixtures->create('glpi_entities', ['name' => $prefix . ' child', 'entities_id' => $entity]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $prefix . ' foreign']);
    $central = $fixtures->create('glpi_profiles', ['name' => $prefix . ' central', 'interface' => 'central']);
    $helpdesk = $fixtures->create('glpi_profiles', ['name' => $prefix . ' helpdesk', 'interface' => 'helpdesk']);
    $emptyProfile = $fixtures->create('glpi_profiles', ['name' => $prefix . ' empty', 'interface' => 'central']);
    foreach ([$central, $helpdesk] as $profile) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'ticket', 'rights' => Ticket::OWN]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'ticketvalidation', 'rights' => TicketValidation::VALIDATEREQUEST | TicketValidation::CREATEINCIDENT]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'changevalidation', 'rights' => ChangeValidation::VALIDATE | CREATE]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'project', 'rights' => Project::READMY]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'knowbase', 'rights' => KnowbaseItem::READFAQ]);
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'reservation', 'rights' => READ]);
    }
    $accounts = [];
    $newUser = static function (string $label, array $values = []) use (&$accounts, $fixtures, $prefix): int {
        $accounts[$label] = $fixtures->create('glpi_users', $values + ['name' => $prefix . ' ' . $label, 'realname' => $label, 'firstname' => 'Test']);
        return $accounts[$label];
    };
    $grant = static fn (int $user, int $profile, int $scope, bool $recursive = false) => $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $scope, 'is_recursive' => $recursive]);
    $direct = $newUser('direct');
    $grant($direct, $central, $child);
    $grant($direct, $central, $entity, true);
    $recursive = $newUser('recursive');
    $grant($recursive, $central, $entity, true);
    $nonRecursive = $newUser('nonrecursive');
    $grant($nonRecursive, $central, $entity);
    $foreignUser = $newUser('foreign');
    $grant($foreignUser, $central, $foreign);
    $helpdeskUser = $newUser('helpdesk');
    $grant($helpdeskUser, $helpdesk, $child);
    $emptyRights = $newUser('empty rights');
    $grant($emptyRights, $emptyProfile, $child);
    $noRights = $newUser('no grants');
    $now = new DateTimeImmutable($DB->getDoctrineConnection()->fetchOne('SELECT CURRENT_TIMESTAMP'));
    foreach (['inactive' => ['is_active' => false], 'deleted' => ['is_deleted' => true], 'future' => ['begin_date' => $now->modify('+1 day')], 'expired' => ['end_date' => $now->modify('-1 day')]] as $label => $values) {
        $grant($newUser($label, $values), $central, $child);
        $address = $prefix . '-' . $label . '@example.invalid';
        $fixtures->create('glpi_useremails', ['users_id' => $accounts[$label], 'email' => $address]);
        $repository = new UserSelectionRepository(Orm::create($DB));
        verify($repository->byEmail($address, []) === [$accounts[$label]] && $repository->byEmail($address, [], activeOnly: true) === [], 'Reset eligibility uses database-clock lifecycle restrictions: ' . $label);
    }
    $writer()->update('glpi_users', $direct, ['access_custom_shortcuts' => ['quoted' => true]]);
    $email = $prefix . " O'Reilly\\team@example.invalid";
    foreach ([$email, $prefix . '-extra@example.invalid'] as $address) {
        $fixtures->create('glpi_useremails', ['users_id' => $direct, 'email' => $address, 'is_dynamic' => true]);
    }
    verify((new UserSelectionRepository(Orm::create($DB)))->byEmail(addslashes($email), [], activeOnly: true) === [$direct], 'Reset email resolves one currently active account');
    $_SESSION['glpinames_format'] = User::FIRSTNAME_BEFORE;
    $selected = static fn ($right = 'all', $scope = null, array $used = [], string $search = '', int $start = 0, int $limit = -1, bool $inactive = false, bool $without = false) => User::getSqlSearchResult(false, $right, $scope ?? $child, 0, $used, $search, $start, $limit, $inactive, $without);
    $ids = static fn ($result): array => array_map('intval', array_column(iterator_to_array($result), 'id'));
    $ours = static fn ($result): array => array_values(array_intersect($ids($result), $accounts));
    $same = static function (array $actual, array $expected, string $message): void {
        sort($actual);
        sort($expected);
        verify($actual === $expected, $message);
    };
    $same($ours($selected()), [$direct, $recursive, $helpdeskUser, $emptyRights], 'Active users with direct or recursive scoped grants');
    $same($ours($selected('interface')), [$direct, $recursive, $emptyRights], 'Central interface permission');
    $same($ours($selected('own_ticket')), [$direct, $recursive], 'Ticket ownership requires a scoped central grant');
    $same($ours($selected('reservation')), [$direct, $recursive, $helpdeskUser], 'Generic helpdesk rights use the read/create/update/delete/purge mask');
    foreach (['validate_request', 'create_ticket_validate'] as $right) {
        $same($ours($selected($right)), [$direct, $recursive, $helpdeskUser], 'Validation rights allow scoped helpdesk profiles: ' . $right);
    }
    $same($ours($selected('validate_incident')), [], 'A different validation bit grants no permission');
    foreach (['validate', 'create_validate', 'see_project', 'faq'] as $right) {
        $same($ours($selected($right)), [$direct, $recursive], 'Scoped named permission: ' . $right);
    }
    $same($ours($selected(['own_ticket', 'validate_request'])), [$direct, $recursive, $helpdeskUser], 'Requested rights form a scoped union');
    $same($ours($selected([])), [], 'An empty right list grants no permission');
    $same($ours($selected('all', [])), [], 'An explicit empty entity scope grants no permission');
    $same($ours($selected('interface', [])), [], 'An empty entity scope stays empty for central profiles even with show-all enabled');
    verify(str_contains(getEntitiesRestrictRequest('', 'glpi_profiles_users', '', [], true), 'IN (NULL)'), 'Legacy SQL scope helper also keeps explicit empty arrays closed');
    $same($ours($selected(without: true)), [$direct, $recursive, $helpdeskUser, $emptyRights, $noRights], 'No-grant inclusion does not admit foreign grants');
    $same($ours($selected(inactive: true)), [$direct, $recursive, $helpdeskUser, $emptyRights, $accounts['inactive'], $accounts['deleted'], $accounts['future'], $accounts['expired']], 'Inactive/deleted override includes lifecycle restrictions');
    $same($ours($selected(used: [$direct])), [$recursive, $helpdeskUser, $emptyRights], 'Used IDs are excluded');
    verify(count($selected(search: addslashes($email))) === 1 && $ids($selected(search: addslashes($email))) === [$direct], 'Email search binds quotes and backslashes');
    verify($ids($selected(search: '^Test direct$')) === [$direct], 'Full-name search and anchors');
    verify($ids($selected(search: '^test DIRECT$')) === [$direct], 'Case-insensitive names on both engines');
    $_SESSION['glpinames_format'] = User::REALNAME_BEFORE;
    verify($ids($selected(search: '^direct Test$')) === [$direct], 'Full-name search follows surname-first preferences');
    $_SESSION['glpinames_format'] = (string)User::FIRSTNAME_BEFORE;
    verify($ids($selected(search: '^Test direct$')) === [$direct], 'String-valued first-name preference remains compatible');
    $writer()->update('glpi_users', $direct, ['phone' => $prefix . '_123']);
    $writer()->update('glpi_users', $recursive, ['phone' => $prefix . 'X123']);
    verify($ids($selected(search: '_123')) === [$direct], 'Search underscores match literally rather than as wildcard characters');
    verify(count($selected(search: 'NULL')) === 0, 'Legacy LIKE NULL search stays empty');
    $all = $ids($selected());
    $page = [];
    for ($offset = 0; $offset < count($all); $offset += 2) {
        $page = array_merge($page, $ids($selected(start: $offset, limit: 2)));
    }
    verify($all === $page && count($all) === count(array_unique($all)), 'Identity deduplication and bounded stable pages');
    verify((int)User::getSqlSearchResult(true, 'all', $child, 0, [], 'no matching text', 2, 1)->next()['CPT'] === count($all), 'Count preserves pre-search eligibility');
    verify(count(User::getSqlSearchResult(false, 'all', $child, $direct)) === count($all), 'Selected value with no used IDs does not create empty IN');
    $cursor = $selected(search: '^Test direct$');
    $row = $cursor->next();
    verify((int)$row['id'] === $direct && json_decode($row['access_custom_shortcuts'], true) === ['quoted' => true], 'Complete scalar row with JSON and legacy first next()');
    verify($cursor->key() === $direct && $cursor->valid() && $cursor->numrows() === 1 && $cursor->next() === null && !$cursor->valid(), 'Row cursor key/count/exhaustion');
    verify($ids($cursor) === [$direct] && $ids($cursor) === [$direct], 'Rewinding exhausted results is repeatable');

    verify(User::getUsersIdByEmails(addslashes($email)) === [$direct], 'Account lookup returns the matching ID despite other account emails');
    verify(User::getUsersIdByEmails(addslashes($email), ['glpi_users.id' => $foreignUser]) === [], 'Joined email conditions bind the user ID');
    verify(User::getUsersIdByEmails(addslashes($email), ['OR' => [['glpi_useremails.is_dynamic' => 0], ['glpi_users.id' => $direct]]]) === [$direct], 'Nested predicates cover mapped email and user fields');
    verify((new User())->getFromDBbyEmail(addslashes($email)), 'Single-account email load');
    $fixtures->create('glpi_useremails', ['users_id' => $foreignUser, 'email' => $email]);
    verify(User::countUsersByEmail(addslashes($email)) === 2 && !(new User())->getFromDBbyEmail(addslashes($email)), 'Email shared between accounts remains ambiguous');

    $group = $fixtures->create('glpi_groups', ['name' => $prefix, 'entities_id' => $entity, 'is_recursive' => true]);
    $unscoped = $fixtures->create('glpi_groups', ['name' => $prefix . ' foreign', 'entities_id' => $foreign]);
    $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $direct, 'is_userdelegate' => true]);
    $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $recursive]);
    $fixtures->create('glpi_groups_users', ['groups_id' => $unscoped, 'users_id' => $direct, 'is_userdelegate' => true]);
    $_SESSION['glpiID'] = $direct;
    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $_SESSION['glpigroups'] = [$group];
    verify(User::getDelegateGroupsForUser($child) === [$group => $group], 'Delegated groups use recursive ownership and entity scope');
    $same($ours($selected('delegate')), [$recursive], 'Delegation includes eligible group members and excludes current helpdesk user');
    $same($ours($selected('groups')), [$recursive], 'Group mode includes other members');
    $_SESSION['glpigroups'] = [];
    verify(count($selected('groups')) === 0 && count($selected('delegate', [])) === 0, 'Empty groups fail closed');
    $_SESSION['glpiactiveprofile']['interface'] = 'central';
    $same($ours($selected('groups')), [$direct], 'Central mode retains self with no groups');
    verify($ids($selected('id')) === [$direct], 'Current-account selector');
    $_SESSION['glpiactiveentities'] = [$child];
    $same($ours($selected('interface', -1)), [$direct, $recursive, $emptyRights], 'Default selector uses active entity scope');
    $writer()->update('glpi_users', $helpdeskUser, ['firstname' => null]);
    verify($ours($selected())[0] === $helpdeskUser, 'NULL sort order remains consistent across engines');

    $em = Orm::create($DB);
    $query = $em->createQueryBuilder()->select('r')->from(\itsmng\Database\Entity\User::class, 'r');
    $compiler = new RecordCriteria($query, $em->getClassMetadata(\itsmng\Database\Entity\User::class));
    foreach ([static fn () => $compiler->where(['glpi_useremails.email' => $email]), static fn () => $compiler->withJoinedMetadata($em->getClassMetadata(\itsmng\Database\Entity\UserEmail::class), 'missing'), static fn () => $compiler->where([new QueryExpression('1 = 1')])] as $unsupported) {
        try {
            $unsupported();
            throw new RuntimeException('Unmapped join/raw expression unexpectedly accepted');
        } catch (UnsupportedCriteria) {
        }
    }
    $SQL_TOTAL_REQUEST = 0;
    $repo = new UserSelectionRepository(Orm::create($DB));
    $repo->byEmail(addslashes($email), []);
    $repo->groupMembers([$group], $direct);
    $repo->delegatedGroups($direct, ['entities_id' => $entity]);
    $repo->search(['id' => $direct], false, [], null, false, true, 0, 1);
    verify($SQL_TOTAL_REQUEST === 0, 'All selector repositories bypass the legacy query adapter');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
}
echo $DB->getProvider() . ": mapped user selectors, scoped rights and groups, email ambiguity, lifecycle, search and stable pages passed.\n";
