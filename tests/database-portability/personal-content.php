<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\PersonalContentOwners;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/personal-content.php /path/to/test-config\n");
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
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$repo = static fn () => new \itsmng\Database\Repository\SharedContentRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $viewer = $fixtures->create('glpi_users', ['name' => 'content-viewer']);
    $other = $fixtures->create('glpi_users', ['name' => 'content-other']);
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'Content scope', 'entities_id' => 0]);
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Outside scope', 'entities_id' => 0]);
    $group = $fixtures->create('glpi_groups', ['name' => 'Content readers']);
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Content profile']);
    $access = new \itsmng\Database\SharedContentAccess($viewer, true, [$group], $profile, [$entity], [0]);
    $privateOnly = new \itsmng\Database\SharedContentAccess($viewer, false, [$group], $profile, [$entity], [0]);
    $anonymous = new \itsmng\Database\SharedContentAccess(0, true, [$group], $profile, [$entity], [0]);
    $at = new DateTimeImmutable('2030-01-02 12:00:00');
    $all = [];
    foreach (['reminder' => 'reminders', 'rssfeed' => 'rssfeeds'] as $kind => $plural) {
        $ids = [];
        foreach (['own', 'direct', 'group', 'profile', 'recursive', 'denied', 'unowned'] as $name) {
            $values = ['name' => $name . ' content', 'users_id' => $name === 'own' ? $viewer : (in_array($name, ['profile', 'unowned']) ? null : $other)];
            if ($kind === 'rssfeed') {
                $values['is_active'] = true;
            }
            $ids[$name] = $fixtures->create('glpi_' . $plural, $values);
        }
        $fixtures->create('glpi_' . $plural . '_users', [$plural . '_id' => $ids['direct'], 'users_id' => $viewer]);
        $fixtures->create('glpi_groups_' . $plural, [$plural . '_id' => $ids['group'], 'groups_id' => $group, 'entities_id' => null]);
        $fixtures->create('glpi_groups_' . $plural, [$plural . '_id' => $ids['direct'], 'groups_id' => $group, 'entities_id' => $entity]);
        $fixtures->create('glpi_profiles_' . $plural, [$plural . '_id' => $ids['profile'], 'profiles_id' => $profile, 'entities_id' => $entity]);
        $fixtures->create('glpi_profiles_' . $plural, [$plural . '_id' => $ids['denied'], 'profiles_id' => $profile, 'entities_id' => $outside]);
        $fixtures->create('glpi_entities_' . $plural, [$plural . '_id' => $ids['recursive'], 'entities_id' => 0, 'is_recursive' => true]);
        $fixtures->create('glpi_entities_' . $plural, [$plural . '_id' => $ids['direct'], 'entities_id' => $entity]);
        $SQL_TOTAL_REQUEST = 0;
        $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
        $visible = $repo()->listing($kind, $access, false, false, $at);
        $visibleIds = array_values(array_intersect(array_column($visible, 'id'), array_values($ids)));
        verify($visibleIds === [$ids['direct'], $ids['group'], $ids['own'], $ids['profile'], $ids['recursive']], 'Sharing scopes, profile entity restriction and stable ordering for ' . $kind);
        verify(count($visibleIds) === count(array_unique($visibleIds)), 'Several sharing rows cannot duplicate ' . $kind);
        verify(array_column($repo()->listing($kind, $privateOnly, false, false, $at), 'id') === [$ids['own']], 'Public read permission required for shares');
        verify(array_column($repo()->listing($kind, $access, true, false, $at), 'id') === [$ids['own']], 'Personal listing selects only positive owner');
        verify($repo()->listing($kind, $anonymous, false, false, $at) === [], 'Anonymous viewer does not own NULL content');
        $publicIds = array_column($repo()->listing($kind, $access, false, true, $at), 'id');
        verify(!in_array($ids['own'], $publicIds, true) && in_array($ids['profile'], $publicIds, true), 'Public section excludes own items but retains ownerless shared items');
        verify($SQL_TOTAL_REQUEST === 0, 'Shared listing bypasses adapter queries');
        $all[$kind] = $ids;
    }
    $own = $all['reminder']['own'];
    $firstTranslation = $fixtures->create('glpi_remindertranslations', ['reminders_id' => $own, 'language' => 'fr_FR', 'name' => 'Premier rappel', 'text' => 'Premier texte', 'users_id' => $viewer]);
    $fixtures->create('glpi_remindertranslations', ['reminders_id' => $own, 'language' => 'fr_FR', 'name' => 'Duplicate reminder', 'users_id' => $other]);
    $fixtures->create('glpi_remindertranslations', ['reminders_id' => $own, 'language' => 'en_GB', 'name' => 'English reminder', 'users_id' => null]);
    $translated = $repo()->listing('reminder', $access, true, false, $at, 'fr_FR');
    verify(count($translated) === 1 && $translated[0]['transname'] === 'Premier rappel', 'First translation chosen without duplicate reminders');
    $model = new Reminder();
    $model->getFromDB($own);
    verify(ReminderTranslation::getAlreadyTranslatedForItem($model) === ['en_GB' => 'en_GB', 'fr_FR' => 'fr_FR'], 'Translated language list is distinct and scoped');
    verify(ReminderTranslation::getNumberOfTranslationsForItem($model) === 3, 'Translation count uses mapped parent');
    $fixtures->create('glpi_groups_reminders', ['reminders_id' => $own, 'groups_id' => $group, 'entities_id' => null]);
    $fixtures->create('glpi_groups_reminders', ['reminders_id' => $own, 'groups_id' => $group, 'entities_id' => $entity]);
    verify(count(array_filter($repo()->calendarReminders(group: $group), static fn ($row) => $row['id'] === $own)) === 1, 'Calendar group export deduplicates sharing rows');
    verify(array_column($repo()->calendarReminders(user: $viewer), 'id') === [$own] && $repo()->calendarReminders(user: 0) === [], 'Calendar owner selection rejects zero');
    $searchSession = $_SESSION;
    try {
        $_SESSION['glpiID'] = $viewer;
        $_SESSION['glpigroups'] = [$group];
        $_SESSION['glpiactiveprofile']['id'] = $profile;
        $_SESSION['glpiactiveprofile']['reminder_public'] = READ;
        $_SESSION['glpiactiveprofile']['rssfeed_public'] = READ;
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string)$entity;
        $_SESSION['glpiactive_entity'] = $entity;
        $_SESSION['glpishowallentities'] = 0;
        foreach (['Reminder' => 'reminder', 'RSSFeed' => 'rssfeed'] as $class => $kind) {
            $result = Search::getDatas($class, ['criteria' => [['field' => 1, 'searchtype' => 'contains', 'value' => ' content', 'link' => 'AND']], 'sort' => 1, 'order' => 'ASC', 'reset' => 'reset', 'list_limit' => 50]);
            $searchIds = array_map('intval', array_column($result['data']['rows'] ?? [], 'id'));
            $ids = $all[$kind];
            verify($searchIds === [$ids['direct'], $ids['group'], $ids['own'], $ids['profile'], $ids['recursive']], 'Search sharing scope agrees with mapped central listing for ' . $class);
        }
        $_SESSION['glpiactiveprofile']['rssfeed_public'] = 0;
        verify(RSSFeed::getVisibilityCriteria()['WHERE'] === ['glpi_rssfeeds.users_id' => $viewer], 'RSS search requires public-read right for shares');
    } finally {
        $_SESSION = $searchSession;
    }
    $inactive = $fixtures->create('glpi_rssfeeds', ['users_id' => $viewer, 'name' => 'Inactive feed', 'is_active' => false]);
    verify(!in_array($inactive, array_column($repo()->listing('rssfeed', $access, true, false, $at), 'id'), true), 'Personal feeds require active flag');
    $notStarted = $fixtures->create('glpi_reminders', ['users_id' => $viewer, 'name' => 'Start boundary', 'begin_view_date' => $at->format('Y-m-d H:i:s')]);
    $pastPlanned = $fixtures->create('glpi_reminders', ['users_id' => $viewer, 'name' => 'Past planned reminder', 'is_planned' => true, 'end' => '2030-01-01 23:59:59']);
    $personalIds = array_column($repo()->listing('reminder', $access, true, false, $at), 'id');
    verify(!in_array($notStarted, $personalIds, true) && !in_array($pastPlanned, $personalIds, true), 'Personal reminder visibility start and planning date boundaries');
    $dates = [];
    foreach (['expired' => ['2029-12-01 11:59:59', null, false], 'boundary' => ['2029-12-01 12:00:00', null, false], 'recent' => ['2030-01-01 00:00:00', null, false], 'future' => ['2031-01-01 00:00:00', null, false], 'planned' => [null, '2029-01-01 00:00:00', true], 'unplanned' => [null, '2029-01-01 00:00:00', false], 'no_end' => [null, null, true]] as $name => [$endView, $end, $planned]) {
        $dates[$name] = $fixtures->create('glpi_reminders', ['name' => $name, 'users_id' => $other, 'end_view_date' => $endView, 'end' => $end, 'is_planned' => $planned]);
    }
    $expired = array_column($repo()->expiredReminders(new DateTimeImmutable('2029-12-01 12:00:00')), 'id');
    verify(array_values(array_intersect($expired, array_values($dates))) === [$dates['expired'], $dates['planned']], 'Expiry retains cutoff and planned-state predicates; nonnull end dates alone never qualify');
    $closed = $fixtures->create('glpi_reminders', ['users_id' => $viewer, 'name' => 'Hidden at exact boundary', 'end_view_date' => $at->format('Y-m-d H:i:s')]);
    verify(!in_array($closed, array_column($repo()->listing('reminder', $access, true, false, $at), 'id'), true), 'Listing respects exclusive visibility end boundary');
    $author = $fixtures->create('glpi_users', ['name' => 'content-author']);
    $feed = $fixtures->create('glpi_rssfeeds', ['users_id' => $author, 'name' => 'Surviving feed', 'date_mod' => '2029-01-01 00:00:00']);
    $translation = $fixtures->create('glpi_remindertranslations', ['users_id' => $author, 'reminders_id' => $all['reminder']['direct'], 'language' => 'fr_FR', 'name' => 'Historical author', 'date_mod' => '2029-01-01 00:00:00']);
    $personal = $fixtures->create('glpi_reminders', ['users_id' => $author, 'name' => 'Deleted personal note']);
    verify((new User())->delete(['id' => $author], true), 'User purge handles content owner FKs');
    verify($read('glpi_reminders', $personal) === null, 'User purge retains personal reminder deletion policy');
    verify($read('glpi_rssfeeds', $feed)['users_id'] === null && $read('glpi_remindertranslations', $translation)['users_id'] === null, 'Surviving feed and translation release deleted owner');
    verify($read('glpi_rssfeeds', $feed)['date_mod'] === '2029-01-01 00:00:00' && $read('glpi_remindertranslations', $translation)['date_mod'] === '2029-01-01 00:00:00', 'Owner maintenance preserves content timestamps and avoids feed fetching');
    $savedSession = $_SESSION;
    try {
        unset($_SESSION['glpiID']);
        foreach (['Reminder' => $all['reminder']['unowned'], 'RSSFeed' => $all['rssfeed']['unowned']] as $class => $id) {
            $item = new $class();
            $item->getFromDB($id);
            verify(!$item->canCreateItem() && !$item->canViewItem(), 'Ownerless content does not confer anonymous ownership');
        }
    } finally {
        $_SESSION = $savedSession;
    }
    $rendered = $fixtures->create('glpi_reminders', ['users_id' => Session::getLoginUserID(), 'uuid' => 'personal-content-render-uuid', 'name' => 'Rendered reminder', 'text' => 'Calendar content', 'is_planned' => true, 'begin' => (new DateTimeImmutable('+1 year'))->format('Y-m-d 10:00:00'), 'end' => (new DateTimeImmutable('+1 year'))->format('Y-m-d 11:00:00'), 'state' => Planning::TODO]);
    $fixtures->create('glpi_remindertranslations', ['reminders_id' => $rendered, 'users_id' => null, 'language' => $_SESSION['glpilanguage'], 'name' => 'Rendered translation', 'text' => 'Translated content']);
    $oldTranslate = $CFG_GLPI['translate_reminders'];
    try {
        $CFG_GLPI['translate_reminders'] = 1;
        ob_start();
        Reminder::showListForCentral();
        $html = ob_get_clean();
        verify(str_contains($html, 'Rendered translation'), 'Central view renders mapped translated rows');
    } finally {
        $CFG_GLPI['translate_reminders'] = $oldTranslate;
    }
    $calendars = Reminder::getUserItemsAsVCalendars(Session::getLoginUserID());
    verify(count(array_filter($calendars, static fn ($calendar) => str_contains($calendar->serialize(), 'personal-content-render-uuid'))) === 1, 'Mapped calendar selection produces a VCalendar');
    $groupCalendar = $fixtures->create('glpi_reminders', ['users_id' => $other, 'uuid' => 'personal-content-group-calendar', 'name' => 'Group calendar', 'text' => 'Shared calendar content', 'is_planned' => true, 'begin' => '2031-01-01 10:00:00', 'end' => '2031-01-01 11:00:00', 'state' => Planning::TODO]);
    $fixtures->create('glpi_groups_reminders', ['reminders_id' => $groupCalendar, 'groups_id' => $group, 'entities_id' => null]);
    $calendarSession = $_SESSION;
    try {
        $_SESSION['glpigroups'] = [$group];
        $_SESSION['glpiactiveprofile']['reminder_public'] = READ;
        $calendars = Reminder::getGroupItemsAsVCalendars($group);
        verify(count(array_filter($calendars, static fn ($calendar) => str_contains($calendar->serialize(), 'personal-content-group-calendar'))) === 1, 'Group calendar export hydrates sharing before permission check');
        $_SESSION['glpiactiveprofile']['reminder_public'] = 0;
        verify(Reminder::getGroupItemsAsVCalendars($group) === [], 'Calendar group selection never bypasses per-item permissions');
    } finally {
        $_SESSION = $calendarSession;
    }
    $replacement = $fixtures->create('glpi_users', ['name' => 'content-replacement']);
    $replacedOwner = $fixtures->create('glpi_users', ['name' => 'content-replaced']);
    $replacedFeed = $fixtures->create('glpi_rssfeeds', ['users_id' => $replacedOwner, 'name' => 'Replaced feed']);
    verify((new User())->delete(['id' => $replacedOwner, '_replace_by' => $replacement], true), 'Explicit content owner replacement succeeds');
    verify($read('glpi_rssfeeds', $replacedFeed)['users_id'] === $replacement, 'RSS owner replacement is retained');
    verify((new ForeignKeys())->audit($connection) === [], 'Content owner graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new PersonalContentOwners();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'PERSONAL_CONTENT_OWNERS') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $connection->executeStatement($platform->getDropForeignKeySQL(ForeignKeys::name($table, $column), $table));
            $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = 0 WHERE ' . $quote($column) . ' IS NULL');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        foreach ($relations as $column => $target) {
            $after->getColumn($column)->setNotnull(true)->setDefault(0);
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
        }
    }
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_reminders');
    $connection->insert('glpi_reminders', ['id' => $legacy, 'name' => 'legacy-name']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy personal content owner migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_reminders WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_reminders', ['users_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned personal content owner');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_reminders')['users_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_reminders', ['users_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT users_id FROM glpi_reminders WHERE id = ?', [$legacy]) === null, 'Legacy owner default becomes NULL');
    verify($migration->apply($connection) === [], 'personal content owner migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_reminders', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Personal content ownership, sharing, translations, expiry, purge and migration passed.\n";
