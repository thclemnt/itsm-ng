<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\RejectedEmailReferences;
use itsmng\Database\OptionalReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/mail-collection.php /path/to/test-config\n");
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
$repo = static fn () => new \itsmng\Database\Repository\MailCollectorRepository(Orm::create($DB));
$DB->beginTransaction();
try {
    $beforeCount = MailCollector::countCollectors();
    $beforeActive = MailCollector::countActiveCollectors();
    $active = (new MailCollector())->add(['name' => 'Mapped active mailbox', 'host' => '{invalid.example.test/imap}', 'is_active' => true, 'errors' => 0]);
    $failing = $fixtures->create('glpi_mailcollectors', ['name' => 'Mapped failing mailbox', 'is_active' => true, 'errors' => 2]);
    $inactive = $fixtures->create('glpi_mailcollectors', ['name' => 'Mapped inactive mailbox', 'is_active' => false, 'errors' => 3]);
    verify($active > 0, 'Collector lifecycle creates record without connecting');
    $SQL_TOTAL_REQUEST = 0;
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    verify(MailCollector::countCollectors() === $beforeCount + 3 && MailCollector::countActiveCollectors() === $beforeActive + 2, 'Collector counts and typed active predicate');
    $ids = [$active, $failing, $inactive];
    verify(array_values(array_intersect(array_column($repo()->collectors(), 'id'), $ids)) === $ids, 'All collectors have stable ID order');
    verify(array_values(array_intersect(array_column($repo()->collectors(activeOnly: true), 'id'), $ids)) === [$active, $failing], 'Collection selection excludes inactive rows');
    verify(array_values(array_intersect(array_column($repo()->collectors(activeOnly: true, errorsOnly: true), 'id'), $ids)) === [$failing], 'Error selector excludes healthy and inactive collectors');
    verify($SQL_TOTAL_REQUEST === 0, 'Collector selectors bypass adapter queries');
    $user = $fixtures->create('glpi_users', ['name' => 'Rejected email author']);
    $subject = "O'Connor \\ inbox";
    $first = (new NotImportedEmail())->add(['mailcollectors_id' => $active, 'users_id' => $user, 'subject' => $DB->escape($subject), 'from' => 'sender@example.test', 'to' => 'help@example.test', 'date' => '2030-01-01 10:00:00', 'messageid' => '<first@example.test>']);
    $second = $fixtures->create('glpi_notimportedemails', ['mailcollectors_id' => $failing, 'users_id' => null, 'date' => '2030-01-01 11:00:00', 'messageid' => '<second@example.test>']);
    $unassigned = $fixtures->create('glpi_notimportedemails', ['mailcollectors_id' => null, 'users_id' => null, 'date' => '2030-01-01 12:00:00']);
    verify($first > 0 && $read('glpi_notimportedemails', $first)['subject'] === $subject, 'Rejected subject round-trips through the existing model input boundary');
    $SQL_TOTAL_REQUEST = 0;
    verify(array_column($repo()->rejectedEmails([$second, (string)$first, $first, 0, -1, $unassigned]), 'id') === [$first, $second], 'Selected rejected messages are deduplicated and grouped by real collector');
    verify($repo()->rejectedEmails([]) === [] && $repo()->rejectedEmails([0, -1]) === [], 'Empty selection cannot expand to all rejected emails');
    $blocked = $fixtures->create('glpi_blacklistedmailcontents', ['content' => "REMOVE_THIS\nSECOND_LINE"]);
    verify(in_array("REMOVE_THIS\nSECOND_LINE", array_column($repo()->blacklistedContents(), 'content'), true), 'Mapped blacklist reader retains multiline content');
    $cleaned = (new MailCollector())->cleanContent("Keep\nREMOVE_THIS\nSECOND_LINE\nTail");
    verify(str_contains($cleaned, 'Keep') && str_contains($cleaned, 'Tail') && !str_contains($cleaned, 'REMOVE_THIS') && !str_contains($cleaned, 'SECOND_LINE'), 'Real content cleaner consumes mapped blacklist');
    verify($SQL_TOTAL_REQUEST === 0, 'Rejected mail and blacklist queries bypass adapter');
    $search = Search::getDatas('NotImportedEmail', ['criteria' => [['field' => 4, 'searchtype' => 'equals', 'value' => $active, 'link' => 'AND']], 'sort' => 1, 'order' => 'ASC', 'reset' => 'reset', 'list_limit' => 50]);
    verify(array_map('intval', array_column($search['data']['rows'] ?? [], 'id')) === [$first], 'Rejected-email search resolves mapped collector relation');
    $connection->beginTransaction();
    NotImportedEmail::deleteLog();
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_notimportedemails', []) === 0, 'Log cleanup deletes mapped rows');
    $connection->rollBack();
    verify($read('glpi_notimportedemails', $first) !== null && $read('glpi_notimportedemails', $second) !== null, 'Log cleanup shares caller transaction and does not implicitly commit');
    verify((new User())->delete(['id' => $user], true), 'Requester purge succeeds');
    verify($read('glpi_notimportedemails', $first)['users_id'] === null, 'Historical rejection survives requester purge with NULL association');
    verify((new MailCollector())->delete(['id' => $active], true), 'Collector purge succeeds');
    verify($read('glpi_notimportedemails', $first)['mailcollectors_id'] === null && $read('glpi_notimportedemails', $second)['mailcollectors_id'] === $failing, 'Collector purge releases only its logs');
    verify($repo()->rejectedEmails([$first]) === [], 'Orphaned historical log cannot be retried against a mailbox');
    $replacement = $fixtures->create('glpi_mailcollectors', ['name' => 'Replacement collector']);
    verify((new MailCollector())->delete(['id' => $failing, '_replace_by' => $replacement], true), 'Collector replacement succeeds');
    verify($read('glpi_notimportedemails', $second)['mailcollectors_id'] === $replacement, 'Log follows explicit collector replacement');
    verify((new ForeignKeys())->audit($connection) === [], 'Rejected email graph remains valid');
} finally {
    $DB->rollBack();
    restore_error_handler();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new RejectedEmailReferences();
$legacy = null;
try {
    foreach (OptionalReferences::REJECTED_EMAIL_REFERENCES as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_notimportedemails');
    $connection->insert('glpi_notimportedemails', ['id' => $legacy, $quote('from') => 'sender@example.test', $quote('to') => 'help@example.test', 'messageid' => 'legacy-rejected']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy rejected email migration has a plan');
    verify((int)$connection->fetchOne('SELECT users_id FROM glpi_notimportedemails WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    foreach (['users_id', 'mailcollectors_id'] as $column) {
        $connection->update('glpi_notimportedemails', [$column => 2147483647], ['id' => $legacy]);
        $rejected = false;
        try {
            $migration->apply($connection);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'Nonzero orphaned rejected email');
        }
        verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_notimportedemails')[$column]->getNotnull(), 'Orphan rejected before DDL for ' . $column);
        $connection->update('glpi_notimportedemails', [$column => 0], ['id' => $legacy]);
    }
    $migration->apply($connection);
    $migrated = $connection->fetchAssociative('SELECT users_id, mailcollectors_id FROM glpi_notimportedemails WHERE id = ?', [$legacy]);
    verify($migrated === ['users_id' => null, 'mailcollectors_id' => null], 'Legacy reference defaults become NULL');
    verify($migration->apply($connection) === [], 'rejected email migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_notimportedemails', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": Mail collection queries, rejected-email references, lifecycle and migration passed.\n";
