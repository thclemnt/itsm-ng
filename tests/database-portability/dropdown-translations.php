<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\DropdownTranslationRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/dropdown-translations.php /path/to/test-config\n");
    exit(2);
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
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$savedCache = $GLPI_CACHE;
$savedTranslations = $_SESSION['glpi_dropdowntranslations'] ?? [];
$savedLanguage = $_SESSION['glpilanguage'];
$savedSetting = $CFG_GLPI['translate_dropdowns'];
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(['memory_limit' => '256M']), GLPI_CACHE_DIR, false);
$CFG_GLPI['translate_dropdowns'] = true;
$_SESSION['glpilanguage'] = 'fr_FR';
$_SESSION['glpi_dropdowntranslations'] = [];
$fixtures = new FixtureRecords($DB);
$repo = static fn () => new DropdownTranslationRepository(Orm::create($DB));
$addTranslation = static function (int $id, string $kind, string $field, string $value, string $language = 'fr_FR'): int {
    $translation = new DropdownTranslation();
    $result = $translation->add(['items_id' => $id, 'itemtype' => $kind, 'field' => $field, 'language' => $language, 'value' => addslashes($value)]);
    verify($result > 0, 'Public translation creation');
    return $result;
};
$DB->beginTransaction();
try {
    $prefix = 'Translation ' . bin2hex(random_bytes(5));
    $sameId = 900000081;
    $literal = "O'Reilly \\ 日本語 " . $prefix;
    $fixtures->create('glpi_locations', ['id' => $sameId, 'name' => $literal, 'completename' => $literal, 'address' => '123 Main', 'town' => 'Paris', 'country' => 'France']);
    $fixtures->create('glpi_manufacturers', ['id' => $sameId, 'name' => $prefix . ' manufacturer', 'comment' => 'Original manufacturer comment']);
    $name = $addTranslation($sameId, 'Location', 'name', 'Lieu français');
    $comment = $addTranslation($sameId, 'Location', 'comment', "Commentaire ' \\ 日本語");
    $addTranslation($sameId, 'Manufacturer', 'name', 'Fabricant français');
    $addTranslation($sameId, 'Location', 'name', 'Deutscher Ort', 'de_DE');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Location') === 'Lieu français', 'Session language selects the name translation');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Manufacturer') === 'Fabricant français', 'Kind isolates overlapping numeric IDs');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Location', 'name', 'de_DE') === 'Deutscher Ort', 'Explicit language isolates translations');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Location', 'comment') === "Commentaire ' \\ 日本語", 'Field and literal translated values are preserved');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Location', 'name', 'es_ES') === $literal, 'Missing language falls back to the stored dropdown value');
    verify(DropdownTranslation::getTranslatedValue(2147483647, 'Location') === '', 'Missing dropdown returns the existing empty fallback');
    verify(DropdownTranslation::getTranslatedValue(0, 'Location', value: 'Supplied zero fallback') === 'Supplied zero fallback', 'Zero retains the caller fallback');
    verify(DropdownTranslation::getTranslatedValue($sameId, 'Location', 'not_translated', value: 'Original') === 'Original', 'A field without session translations retains its supplied value');
    verify(DropdownTranslation::getTranslationID($sameId, 'Location', 'name', 'fr_FR') === $name, 'Exact logical translation key');
    verify(DropdownTranslation::getTranslationID($sameId, 'Location', 'name', 'es_ES') === 0, 'Absent translation identifier is zero');
    $rows = DropdownTranslation::getTranslationsForAnItem('Location', $sameId, 'name');
    verify(count($rows) === 2 && isset($rows[$name]) && $rows[$name]['value'] === 'Lieu français', 'Rows retain identifiers and exclude other kinds/fields');
    verify(DropdownTranslation::getTranslationByName('Location', 'name', $literal) === 'Lieu français', 'Raw apostrophes, backslashes and Unicode select the original dropdown');
    verify(DropdownTranslation::getTranslationByName('Location', 'name', $prefix . ' absent') === $prefix . ' absent', 'Unmatched name retains its supplied value');

    $nullName = $fixtures->create('glpi_manufacturers', ['name' => 'NULL']);
    $nullRecord = $fixtures->create('glpi_manufacturers', ['name' => null]);
    $addTranslation($nullName, 'Manufacturer', 'name', 'Texte NULL');
    verify($repo()->dropdownId('glpi_manufacturers', 'name', 'NULL') === $nullName && $nullRecord !== $nullName, 'Literal NULL name is distinct from SQL NULL');
    verify(DropdownTranslation::getTranslationByName('Manufacturer', 'name', 'NULL') === 'Texte NULL', 'Public name lookup treats NULL as literal data');
    $nullValue = $fixtures->create('glpi_dropdowntranslations', ['items_id' => $sameId, 'itemtype' => 'Location', 'field' => 'NULL', 'language' => 'NULL', 'value' => null]);
    $_SESSION['glpi_dropdowntranslations']['Location']['NULL'] = 'NULL';
    verify(DropdownTranslation::getTranslationID($sameId, 'Location', 'NULL', 'NULL') === $nullValue && DropdownTranslation::getTranslatedValue($sameId, 'Location', 'NULL', 'NULL') === null, 'Literal field/language keys and a nullable translated value remain distinct');
    verify((new DropdownTranslation())->delete(['id' => $nullValue], true), 'Remove synthetic non-form field before rendering');
    $available = DropdownTranslation::getAvailableTranslations('fr_FR');
    verify(($available['Location']['name'] ?? null) === 'name' && ($available['Location']['comment'] ?? null) === 'comment' && ($available['Manufacturer']['name'] ?? null) === 'name', 'Available fields group by kind and language');

    $root = new Location();
    $rootId = $root->add(['name' => $prefix . ' root', 'entities_id' => 0]);
    $child = new Location();
    $childId = $child->add(['name' => $prefix . ' child', 'locations_id' => $rootId, 'entities_id' => 0]);
    $grandchild = new Location();
    $grandchildId = $grandchild->add(['name' => $prefix . ' grandchild', 'locations_id' => $childId, 'entities_id' => 0]);
    verify($rootId > 0 && $childId > 0 && $grandchildId > 0, 'Public dropdown tree');
    $rootName = $addTranslation($rootId, 'Location', 'name', 'Racine');
    $addTranslation($childId, 'Location', 'name', 'Enfant');
    verify(DropdownTranslation::getTranslatedValue($grandchildId, 'Location', 'completename') === 'Racine > Enfant > ' . $prefix . ' grandchild', 'Ancestor translation propagates through untranslated descendants');
    verify((new DropdownTranslation())->update(['id' => $rootName, 'items_id' => $rootId, 'itemtype' => 'Location', 'field' => 'name', 'language' => 'fr_FR', 'value' => 'Nouvelle racine']), 'Public translation update');
    verify(DropdownTranslation::getTranslatedValue($grandchildId, 'Location', 'completename') === 'Nouvelle racine > Enfant > ' . $prefix . ' grandchild', 'Translation update regenerates all descendant complete names');
    verify((new DropdownTranslation())->delete(['id' => $rootName], true), 'Public translation purge');
    verify(DropdownTranslation::getTranslatedValue($grandchildId, 'Location', 'completename') === $prefix . ' root > Enfant > ' . $prefix . ' grandchild', 'Purged ancestor translation restores its default name without losing child translations');
    verify(count(array_filter($repo()->available('fr_FR'), static fn ($row) => $row['itemtype'] === 'Location' && $row['field'] === 'name')) === 1, 'Available field projection is DISTINCT across dropdown IDs');

    $manufacturerComment = "Fabricant ' \\ commentaire";
    $addTranslation($sameId, 'Manufacturer', 'comment', $manufacturerComment);
    $plainManufacturer = $fixtures->create('glpi_manufacturers', ['name' => "Plain ' \\ maker", 'comment' => "Plain\ncomment"]);
    $blankComputer = $fixtures->create('glpi_computers', ['name' => '']);
    $contact = $fixtures->create('glpi_contacts', ['name' => 'Contact', 'firstname' => 'First', 'phone' => '01234', 'comment' => 'Stored comment']);
    // Public label helpers also serve history after reference updates.
    $SQL_TOTAL_REQUEST = 0;
    $label = Dropdown::getDropdownName('glpi_manufacturers', $sameId, true);
    verify($label === ['name' => 'Fabricant français', 'comment' => $manufacturerComment], 'Name/comment translation joins retain literal values and overlapping kind scope');
    verify(Dropdown::getDropdownName('glpi_manufacturers', $sameId, false, false) === $prefix . ' manufacturer', 'Translation-disabled label retains the stored value');
    verify(Dropdown::getDropdownName('glpi_manufacturers', $plainManufacturer, true) === ['name' => "Plain ' \\ maker", 'comment' => "Plain\ncomment"], 'Missing translations fall back to stored name and comment');
    $_SESSION['glpilanguage'] = 'de_DE';
    verify(Dropdown::getDropdownName('glpi_manufacturers', $sameId) === $prefix . ' manufacturer', 'A different language cannot reuse the French label');
    $_SESSION['glpilanguage'] = 'fr_FR';
    verify(Dropdown::getDropdownName('glpi_computers', $blankComputer) === '(' . $blankComputer . ')', 'Blank computer name retains numeric fallback');
    $contactLabel = Dropdown::getDropdownName('glpi_contacts', $contact, true);
    verify($contactLabel['name'] === 'Contact First' && str_contains($contactLabel['comment'], '01234'), 'Contact tooltip retains its first name and phone');
    verify(Dropdown::getDropdownName('glpi_contacts', $contact, true, true, false) === ['name' => 'Contact First', 'comment' => 'Stored comment'], 'Disabling tooltip excludes phone decorations');
    $treeLabel = Dropdown::getDropdownName('glpi_locations', $grandchildId, true, true, false);
    verify($treeLabel['name'] === $prefix . ' root &gt; Enfant &gt; ' . $prefix . ' grandchild', 'Tree labels retain translated hierarchy and encoded separator');
    $locationLabel = Dropdown::getDropdownName('glpi_locations', $sameId, true);
    verify(str_contains($locationLabel['comment'], '123 Main') && str_contains($locationLabel['comment'], 'Paris - France')
        && str_contains($locationLabel['comment'], "Commentaire ' \\ 日本語"), 'Tree tooltip retains address and translated comment');
    verify(Dropdown::getDropdownName('glpi_entities', 0) !== '&nbsp;', 'Real root entity keeps its tree label');
    verify(Dropdown::getDropdownName('glpi_manufacturers', 2147483647) === '&nbsp;'
        && Dropdown::getDropdownName('glpi_locations', 2147483647, true) === ['name' => '&nbsp;', 'comment' => ''], 'Missing plain and tree labels preserve fallback shapes');
    verify($SQL_TOTAL_REQUEST === 0, 'Public plain/tree/history label helpers bypass adapter SQL');

    $location = new Location();
    verify($location->getFromDB($sameId), 'Render source');
    ob_start();
    DropdownTranslation::showTranslations($location);
    $html = ob_get_clean();
    verify(str_contains($html, 'Lieu français') && str_contains($html, 'Deutscher Ort') && !str_contains($html, '<td>completename</td>'), 'Translation list renders scoped fields/languages');
    ob_start();
    DropdownTranslation::dropdownFields($location, 'fr_FR');
    $fields = ob_get_clean();
    $document = new DOMDocument();
    @$document->loadHTML(preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $fields));
    $xpath = new DOMXPath($document);
    verify($xpath->query('//select[@name="field"]')->length === 1, 'Translated-field selector exists');
    $options = [];
    foreach ($xpath->query('//select[@name="field"]/option') as $option) {
        $options[] = $option->getAttribute('value');
    }
    verify(!in_array('name', $options, true) && !in_array('comment', $options, true), 'Already translated fields are excluded from the selector');

    // Warm registry/model metadata before measuring application reads rather than bootstrap.
    DropdownTranslation::getTranslationByName('Location', 'name', $literal);
    $before = $SQL_TOTAL_REQUEST;
    DropdownTranslation::getAvailableTranslations('fr_FR');
    DropdownTranslation::getTranslatedValue($sameId, 'Location');
    DropdownTranslation::getTranslatedValue($sameId, 'Location', 'name', 'es_ES');
    DropdownTranslation::getTranslationID($sameId, 'Location', 'name', 'fr_FR');
    DropdownTranslation::getTranslationsForAnItem('Location', $sameId, 'name');
    DropdownTranslation::getTranslationByName('Location', 'name', $literal);
    verify($SQL_TOTAL_REQUEST === $before, 'Warmed public translation reads execute no legacy adapter queries');
    $CFG_GLPI['translate_dropdowns'] = false;
    verify(DropdownTranslation::getAvailableTranslations('fr_FR') === [], 'Disabled translation catalogue remains empty');
} finally {
    $DB->rollBack();
    $GLPI_CACHE = $savedCache;
    $CFG_GLPI['translate_dropdowns'] = $savedSetting;
    $_SESSION['glpilanguage'] = $savedLanguage;
    $_SESSION['glpi_dropdowntranslations'] = $savedTranslations;
}
echo $DB->getProvider() . ": ORM dropdown translations, literal keys, language/type scope, tree regeneration, rendering and adapter query boundary passed.\n";
