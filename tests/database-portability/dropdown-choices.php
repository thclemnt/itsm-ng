<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DropdownChoiceContext;
use itsmng\Database\Entity\Manufacturer as ManufacturerEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\DropdownChoiceRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/dropdown-choices.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
if (!defined('PLUGINS_DIRECTORIES')) {
    define('PLUGINS_DIRECTORIES', [GLPI_ROOT . '/plugins', GLPI_ROOT . '/tests/fixtures/plugins']);
}
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
/** Flatten widget groups without confusing the group label with a selectable row. */
function choices(array $response): array
{
    $rows = [];
    foreach ($response['results'] as $row) {
        if (isset($row['children'])) {
            foreach ($row['children'] as $child) {
                $rows[] = $child;
            }
        } else {
            $rows[] = $row;
        }
    }
    return $rows;
}
function choiceIds(array $response): array
{
    return array_map('intval', array_column(choices($response), 'id'));
}
/** A mapped model's tree-parent read still runs its application lifecycle hook. */
class DropdownChoiceHookCategory extends TaskCategory
{
    public static array $loaded = [];

    public static function getTable($classname = null)
    {
        return 'glpi_taskcategories';
    }

    public function post_getFromDB()
    {
        self::$loaded[] = $this->fields['id'];
        parent::post_getFromDB();
    }
}
/** Installed third-party models can supply unmapped tables independently of core ORM. */
class PluginDropdownFixtureChoice extends CommonDropdown
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dropdown_fixture_choices';
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_dropdown_choices'), 'Exclusive dropdown database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$session = $_SESSION;
$configuration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['glpishowallentities'] = false;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiis_ids_visible'] = true;
$_SESSION['glpiuse_flat_dropdowntree'] = 1;
$pluginTable = new \Doctrine\DBAL\Schema\Table(PluginDropdownFixtureChoice::getTable(), options: ['engine' => 'InnoDB']);
$pluginTable->addColumn('id', 'integer');
$pluginTable->addColumn('name', 'string', ['length' => 255]);
$pluginTable->addColumn('comment', 'text', ['notnull' => false]);
$pluginTable->setPrimaryKey(['id']);
$schema = $DB->getDoctrineConnection()->createSchemaManager();
$schema->createTable($pluginTable);
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Choices-' . bin2hex(random_bytes(5));
    $select = static fn (string $kind, array $options = []): array => Dropdown::getDropdownValue($options + [
        'itemtype' => $kind, 'searchText' => '', 'page' => 1, 'page_limit' => 50,
        'entity_restrict' => [0], 'display_emptychoice' => false,
    ], false);
    $DB->getDoctrineConnection()->insert($pluginTable->getName(), ['id' => 1, 'name' => $prefix . '-plugin-label', 'comment' => 'Third-party field']);
    $plugin = $select(PluginDropdownFixtureChoice::class, ['searchText' => $prefix . '-plugin']);
    verify($plugin['count'] === 1 && choiceIds($plugin) === [1] && str_contains(choices($plugin)[0]['title'], 'Third-party field'), 'Explicit unmapped plugin boundary retains the installed text-choice response');
    $category = $fixtures->create('glpi_taskcategories', ['id' => 4294967811, 'name' => $prefix . '-category', 'completename' => $prefix . '-category', 'level' => 1]);
    verify(choiceIds($select('TaskCategory', ['searchText' => (string)$category])) === [$category], 'Numeric tree search uses typed metadata, including BIGINT identifiers');
    $manufacturer = $fixtures->create('glpi_manufacturers', ['id' => 4294967812, 'name' => $prefix . '-maker', 'comment' => 'Original comment']);
    verify(choiceIds($select('Manufacturer', ['searchText' => (string)$manufacturer])) === [$manufacturer], 'Numeric ordinary search uses typed metadata');
    $computer = $fixtures->create('glpi_computers', ['name' => $prefix . '-asset', 'manufacturers_id' => $manufacturer]);
    verify(choiceIds($select('Computer', ['searchText' => (string)$manufacturer, 'displaywith' => ['manufacturers_id']])) === [$computer], 'Display-with search uses the owning association identifier type');
    $technician = $fixtures->create('glpi_users', ['name' => $prefix . '-technician']);
    $technicalAsset = $fixtures->create('glpi_computers', ['name' => $prefix . '-technical', 'users_id_tech' => $technician]);
    $technicalChoice = choices($select('Computer', ['searchText' => $prefix . '-technical', 'displaywith' => ['users_id_tech']]));
    verify(count($technicalChoice) === 1 && str_contains($technicalChoice[0]['text'], $prefix . '-technician'), 'Display-with target comes from the owning technician association instead of its column spelling');
    $deleted = $fixtures->create('glpi_computers', ['name' => $prefix . '-asset-deleted', 'is_deleted' => true]);
    $template = $fixtures->create('glpi_computers', ['name' => $prefix . '-asset-template', 'is_template' => true]);
    verify(choiceIds($select('Computer', ['searchText' => $prefix . '-asset'])) === [$computer], 'Deleted/template exclusions retain public behavior');
    verify($select('Computer', ['searchText' => $prefix, 'used' => [$computer, $technicalAsset]])['count'] === 0, 'Used values stay excluded');
    $_SESSION['glpicondition']['dropdown-choice-condition'] = ['id' => $manufacturer];
    $result = $select('Manufacturer', ['condition' => 'dropdown-choice-condition', 'display_emptychoice' => true, 'emptylabel' => 'None', 'toadd' => [-4 => 'Special']]);
    verify(choiceIds($result) === [0, -4, $manufacturer] && $result['count'] === 1, 'Stored condition, default choice and custom values retain count semantics');

    // Pagination is deterministic across equal/null names; filters do not change order.
    $same = [];
    foreach ([null, 'same', 'same', 'same', 'same'] as $name) {
        $same[] = $fixtures->create('glpi_manufacturers', ['name' => $name]);
    }
    $_SESSION['glpicondition']['dropdown-choice-pages'] = ['id' => $same];
    $pages = [];
    for ($page = 1; $page <= 3; ++$page) {
        $result = $select('Manufacturer', ['condition' => 'dropdown-choice-pages', 'page_limit' => 2, 'page' => $page]);
        $pages = array_merge($pages, choiceIds($result));
        verify($result['pagination']['more'] === ($page < 3), 'Page continuation reflects real choices');
    }
    verify($pages === $same, 'Equal/null labels yield stable provider-independent pages');
    $manager = Orm::create($DB);
    $repository = $manager->getRepository(ManufacturerEntity::class);
    verify($repository instanceof DropdownChoiceRepository && $repository->find($manufacturer)->name === $prefix . '-maker'
        && count($repository->findBy(['name' => $prefix . '-maker'])) === 1 && $repository->count(['name' => $prefix . '-maker']) === 1, 'Default choice repository preserves ordinary Doctrine repository APIs');

    foreach ([['name', 'Fabricant tradüit'], ['comment', 'Commentaire tradüit']] as [$field, $value]) {
        $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Manufacturer', 'items_id' => $manufacturer, 'language' => 'fr_FR', 'field' => $field, 'value' => $value]);
    }
    foreach ([['name', 'Catégorie tradüite'], ['completename', 'Arbre tradüit'], ['comment', 'Arbre commentaire']] as [$field, $value]) {
        $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'TaskCategory', 'items_id' => $category, 'language' => 'fr_FR', 'field' => $field, 'value' => $value]);
    }
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Manufacturer', 'items_id' => $category, 'language' => 'fr_FR', 'field' => 'name', 'value' => 'Wrong kind']);
    $fixtures->create('glpi_dropdowntranslations', ['itemtype' => 'Manufacturer', 'items_id' => $manufacturer, 'language' => 'de_DE', 'field' => 'name', 'value' => 'Wrong language']);
    $CFG_GLPI['translate_dropdowns'] = 1;
    $_SESSION['glpilanguage'] = 'fr_FR';
    $_SESSION['glpi_dropdowntranslations'] = DropdownTranslation::getAvailableTranslations('fr_FR');
    $translated = $select('Manufacturer', ['searchText' => 'Fabricant tradüit']);
    verify($translated['count'] === 1 && choiceIds($translated) === [$manufacturer] && str_contains(choices($translated)[0]['title'], 'Commentaire tradüit'), 'Independent translated name/comment roles keep kind/language and one logical choice');
    $translated = $select('TaskCategory', ['searchText' => 'Arbre tradüit']);
    verify($translated['count'] === 1 && choiceIds($translated) === [$category] && str_contains(choices($translated)[0]['text'], 'Arbre tradüit'), 'Tree name/completename/comment use separate typed translation roles');
    $_SESSION['glpilanguage'] = $session['glpilanguage'];
    $_SESSION['glpi_dropdowntranslations'] = [];
    $CFG_GLPI['translate_dropdowns'] = 0;

    $otherEntity = $fixtures->create('glpi_entities', ['name' => $prefix . '-restricted', 'completename' => $prefix . '-restricted', 'level' => 1]);
    $otherComputer = $fixtures->create('glpi_computers', ['entities_id' => $otherEntity, 'name' => $prefix . '-outside']);
    verify($select('Computer', ['entity_restrict' => [$otherEntity], 'searchText' => $prefix])['count'] === 0, 'Explicit caller scope cannot escape current session scope');
    verify($select('Computer', ['entity_restrict' => [], 'searchText' => $prefix])['count'] === 0, 'Empty caller scope matches nothing');
    $_SESSION['glpiactiveentities'] = [];
    verify($select('Computer', ['searchText' => $prefix])['count'] === 0, 'Empty session scope matches nothing');
    $_SESSION['glpiactiveentities'] = [0];

    $parent = $fixtures->create('glpi_taskcategories', ['name' => $prefix . '-parent', 'completename' => $prefix . '-parent', 'level' => 1]);
    $child = $fixtures->create('glpi_taskcategories', ['name' => $prefix . '-child', 'completename' => $prefix . '-parent > child', 'taskcategories_id' => $parent, 'level' => 2]);
    $_SESSION['glpicondition']['dropdown-choice-tree-condition'] = ['glpi_taskcategories.id' => $parent];
    verify($select('TaskCategory', ['_one_id' => $child, 'condition' => 'dropdown-choice-tree-condition'])['count'] === 0, 'Selected tree identifier is an additional filter and cannot replace a component condition');
    $_SESSION['glpiuse_flat_dropdowntree'] = 0;
    $tree = choices($select('TaskCategory', ['_one_id' => $child]));
    verify(array_column($tree, 'id') === [$parent, $child] && $tree[0]['disabled'] === true, 'Tree response reconstructs disabled parent through the public model lifecycle');
    $tree = choices($select('TaskCategory', ['_one_id' => $child, 'permit_select_parent' => true]));
    verify(!isset($tree[0]['disabled']) && isset($tree[0]['selection_text']), 'Explicit parent selection retains parent metadata');
    $tree = choices($select('TaskCategory', ['_one_id' => $child, 'permit_select_parent' => 'false']));
    verify($tree[0]['disabled'] === true, 'HTTP false parent flag keeps actual query/render semantics false');
    $select(DropdownChoiceHookCategory::class, ['_one_id' => $child]);
    verify(in_array($parent, DropdownChoiceHookCategory::$loaded, true), 'Mapped choice parent reads retain actual post_getFromDB hooks');
    $_SESSION['glpiuse_flat_dropdowntree'] = 1;
    $otherCategory = $fixtures->create('glpi_taskcategories', ['entities_id' => $otherEntity, 'name' => $prefix . '-other-category', 'completename' => $prefix . '-other-category', 'level' => 1]);
    $_SESSION['glpiactiveentities'] = [0, $otherEntity];
    $_SESSION['glpicondition']['dropdown-choice-tree-pages'] = ['id' => [$parent, $child, $otherCategory]];
    $treePages = [];
    foreach ([1, 2] as $page) {
        $treePages = array_merge($treePages, choiceIds($select('TaskCategory', ['entity_restrict' => [0, $otherEntity], 'condition' => 'dropdown-choice-tree-pages', 'page' => $page, 'page_limit' => 2])));
    }
    verify($treePages === [$parent, $child, $otherCategory], 'Paged tree entity groups preserve each selectable row across group boundaries');
    $_SESSION['glpiactiveentities'] = [0];

    $contact = $fixtures->create('glpi_contacts', ['name' => $prefix . '-Surname', 'firstname' => 'Given']);
    verify(str_contains(choices($select('Contact', ['searchText' => $prefix]))[0]['text'], 'Surname Given'), 'Contact choice presents its owned full name');
    $profile = $fixtures->create('glpi_profiles', ['name' => $prefix . '-profile']);
    foreach (['choice_a', 'choice_b'] as $right) {
        $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => $right, 'rights' => READ]);
    }
    $_SESSION['glpicondition']['dropdown-choice-rights'] = ['glpi_profilerights.name' => ['choice_a', 'choice_b'], 'glpi_profilerights.rights' => ['&', READ]];
    $profiles = $select('Profile', ['condition' => 'dropdown-choice-rights', 'searchText' => $prefix]);
    verify($profiles['count'] === 1 && choiceIds($profiles) === [$profile], 'Profile right fanout returns each profile once');

    $userProfile = $fixtures->create('glpi_profiles', ['name' => $prefix . '-user-profile', 'interface' => 'central']);
    $userRight = $fixtures->create('glpi_profilerights', ['profiles_id' => $userProfile, 'name' => 'project', 'rights' => READ]);
    $allowedUser = $fixtures->create('glpi_users', ['name' => $prefix . '-choice-user-allowed']);
    $foreignUser = $fixtures->create('glpi_users', ['name' => $prefix . '-choice-user-foreign']);
    $fixtures->create('glpi_profiles_users', ['profiles_id' => $userProfile, 'users_id' => $allowedUser, 'entities_id' => 0]);
    $fixtures->create('glpi_profiles_users', ['profiles_id' => $userProfile, 'users_id' => $foreignUser, 'entities_id' => $otherEntity]);
    $users = $select('User', ['right' => 'project', 'entity_restrict' => [0, $otherEntity], 'searchText' => $prefix . '-choice-user']);
    verify(choiceIds($users) === [$allowedUser], 'User list scopes intersect current entities through owned grant selection');
    $_SESSION['glpishowallentities'] = true;
    $users = $select('User', ['right' => 'project', 'entity_restrict' => [0, $otherEntity], 'restrict_session_scope' => true, 'searchText' => $prefix . '-choice-user']);
    verify(choiceIds($users) === [$allowedUser], 'Explicit component grant scope remains restrictive in all-entities mode');
    $users = $select('User', ['right' => 'project', 'entity_restrict' => [0, $otherEntity], 'searchText' => $prefix . '-choice-user']);
    verify($users['count'] === 2 && array_diff(choiceIds($users), [$allowedUser, $foreignUser]) === [], 'All-entities mode retains ordinary authorized broader User choices');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpicondition']['dropdown-choice-user-condition'] = ['id' => $foreignUser];
    verify($select('User', ['right' => 'project', 'condition' => 'dropdown-choice-user-condition', 'searchText' => $prefix . '-choice-user'])['count'] === 0, 'Stored User conditions narrow the authoritative grant query');
    verify($select('User', ['right' => 'id', 'entity_restrict' => []])['count'] === 0, 'Empty User request scope cannot bypass restrictions through special role selection');
    (new \itsmng\Database\Repository\RecordWriter(Orm::create($DB)))->update('glpi_profilerights', $userRight, ['rights' => 0]);
    verify($select('User', ['right' => 'project', 'searchText' => $prefix . '-choice-user'])['count'] === 0, 'Changed target-user grants apply on each owned selection query');

    $viewer = (int)Session::getLoginUserID();
    $otherUser = $fixtures->create('glpi_users', ['name' => $prefix . '-other']);
    $owned = $fixtures->create('glpi_projects', ['name' => $prefix . '-owned-project', 'users_id' => $viewer]);
    $team = $fixtures->create('glpi_projects', ['name' => $prefix . '-team-project', 'users_id' => $otherUser]);
    $hidden = $fixtures->create('glpi_projects', ['name' => $prefix . '-hidden-project', 'users_id' => $otherUser]);
    $fixtures->create('glpi_projectteams', ['projects_id' => $team, 'itemtype' => 'User', 'items_id' => $viewer]);
    $group = $fixtures->create('glpi_groups', ['name' => $prefix . '-team-group']);
    $_SESSION['glpigroups'][] = $group;
    $fixtures->create('glpi_projectteams', ['projects_id' => $team, 'itemtype' => 'Group', 'items_id' => $group]);
    $_SESSION['glpiactiveprofile']['project'] = Project::READMY;
    $projects = $select('Project', ['searchText' => $prefix]);
    verify($projects['count'] === 2 && array_diff(choiceIds($projects), [$owned, $team]) === [], 'Project ownership/team policy retains multiplicity without duplicates');
    $_SESSION['glpiactiveprofile']['project'] = Project::READALL;
    verify($select('Project', ['searchText' => $prefix])['count'] === 3, 'Updated Project rights are applied on each choice query');
    $_SESSION['glpiactiveprofile']['project'] = 0;
    verify($select('Project', ['searchText' => $prefix])['count'] === 0, 'Missing Project capability cannot be supplied by previous ownership or token');

    $article = $fixtures->create('glpi_knowbaseitems', ['name' => $prefix . '-article', 'users_id' => $otherUser]);
    $hiddenArticle = $fixtures->create('glpi_knowbaseitems', ['name' => $prefix . '-hidden-article', 'users_id' => $otherUser]);
    $fixtures->create('glpi_knowbaseitems_users', ['knowbaseitems_id' => $article, 'users_id' => $viewer]);
    $fixtures->create('glpi_entities_knowbaseitems', ['knowbaseitems_id' => $article, 'entities_id' => 0]);
    $_SESSION['glpiactiveprofile']['knowbase'] = READ;
    $articles = $select('KnowbaseItem', ['searchText' => $prefix]);
    verify($articles['count'] === 1 && choiceIds($articles) === [$article], 'Knowledge-base audience union selects a shared article once and hides unshared articles');
    $_SESSION['glpiactiveprofile']['knowbase'] = 0;
    verify($select('KnowbaseItem', ['searchText' => $prefix])['count'] === 0, 'Changed knowledge-base rights cannot be overridden by a prior component');

    $options = ['entity_restrict' => '[0]', 'displaywith' => [], 'condition' => 'dropdown-choice-condition'];
    $token = DropdownChoiceContext::token('Manufacturer', $options);
    verify(Session::validateIDOR($options + ['itemtype' => 'Manufacturer', '_idor_token' => $token, '_dropdown_choice_context' => DropdownChoiceContext::encode($options)]), 'Component-issued complete context validates');
    $forged = $options;
    $forged['displaywith'] = ['password'];
    verify(!Session::validateIDOR($forged + ['itemtype' => 'Manufacturer', '_idor_token' => $token, '_dropdown_choice_context' => DropdownChoiceContext::encode($forged)]), 'Scalar context rejects array-superset forgery');
    verify(DropdownChoiceContext::encode(['entity_restrict' => [0]]) === DropdownChoiceContext::encode(['entity_restrict' => '[0]']), 'HTTP and internal entity context normalize identically');
    verify(DropdownChoiceContext::encode(['right' => 1, 'permit_select_parent' => false]) === DropdownChoiceContext::encode(['right' => '1', 'permit_select_parent' => 'false']), 'HTTP integer rights and false booleans preserve the issued context');
} finally {
    $DB->rollBack();
    $schema->dropTable($pluginTable->getName());
    $_SESSION = $session;
    $CFG_GLPI = $configuration;
}
echo $DB->getProvider() . ": public ORM choices preserve numeric searches, options, translations, stable pages, empty/current scopes, domain visibility, rights fanout and strict issued context.\n";
