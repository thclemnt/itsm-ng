<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\ContactLineReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\ContactRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/contact-lines.php /path/to/test-config\n");
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
$DB->beginTransaction();
try {
    foreach (ReferenceHistory::get('optional', 'CONTACT_LINE_METADATA') as $table => $relations) {
        foreach ($relations as $column => $target) {
            $parent = $fixtures->create($target, ['name' => 'Contact-line metadata parent']);
            $replacement = $fixtures->create($target, ['name' => 'Contact-line metadata replacement']);
            $other = $fixtures->create($target, ['name' => 'Contact-line metadata other']);
            $values = $table === 'glpi_items_devicesimcards'
                ? ['itemtype' => 'Computer', 'items_id' => $fixtures->create('glpi_computers', ['name' => 'SIM host'])] : [];
            $label = $table === 'glpi_items_devicesimcards' ? 'serial' : 'name';
            $child = $fixtures->create($table, [$column => $parent, $label => 'Metadata dependent'] + $values);
            $unrelated = $fixtures->create($table, [$column => $other, $label => 'Metadata unrelated'] + $values);
            $model = getItemForItemtype(getItemTypeForTable($target));
            verify($model->delete(['id' => $parent, '_replace_by' => $replacement], true), 'Replace ' . $target);
            verify((int)$read($table, $child)[$column] === $replacement, 'Replacement updates ' . $column);
            verify($model->delete(['id' => $replacement], true), 'Purge replacement ' . $target);
            verify($read($table, $child) !== null && $read($table, $child)[$column] === null, 'Purge clears ' . $column);
            verify((int)$read($table, $unrelated)[$column] === $other, 'Unrelated reference unchanged ' . $column);
        }
    }
    $childEntity = (new Entity())->add(['name' => 'Contact child scope', 'entities_id' => 0]);
    $foreignEntity = (new Entity())->add(['name' => 'Contact foreign scope', 'entities_id' => 0]);
    $contact = $fixtures->create('glpi_contacts', ['name' => 'Contact root', 'is_recursive' => true]);
    $childContact = $fixtures->create('glpi_contacts', ['name' => 'Contact child', 'entities_id' => $childEntity]);
    $foreignContact = $fixtures->create('glpi_contacts', ['name' => 'Contact foreign', 'entities_id' => $foreignEntity]);
    $empty = $fixtures->create('glpi_contacts', ['name' => 'Contact without suppliers']);
    $rootSupplier = $fixtures->create('glpi_suppliers', ['name' => 'Supplier root', 'is_recursive' => true,
        'website' => 'https://example.com/first', 'address' => "1 O'Reilly street", 'postcode' => '12345', 'town' => 'Town', 'state' => 'State', 'country' => 'Country']);
    $childSupplier = $fixtures->create('glpi_suppliers', ['name' => 'Supplier child', 'entities_id' => $childEntity, 'website' => 'https://example.com/second']);
    $foreignSupplier = $fixtures->create('glpi_suppliers', ['name' => 'Supplier foreign', 'entities_id' => $foreignEntity]);
    $links = [];
    // Deliberately link the higher supplier ID first: company details use supplier ID order.
    foreach ([[$contact, $childSupplier], [$contact, $rootSupplier], [$contact, $foreignSupplier], [$childContact, $rootSupplier], [$foreignContact, $rootSupplier]] as [$contactId, $supplierId]) {
        $links[$contactId . ':' . $supplierId] = $fixtures->create('glpi_contacts_suppliers', ['contacts_id' => $contactId, 'suppliers_id' => $supplierId]);
    }
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$childEntity];
    $supplierScope = getEntitiesRestrictCriteria('glpi_suppliers', '', '', 'auto');
    $contactScope = getEntitiesRestrictCriteria('glpi_contacts', '', '', 'auto');
    $repo = new ContactRepository(Orm::create($DB));
    $rows = $repo->related($contact, true, $supplierScope);
    $ids = array_column($rows, 'id');
    verify(count($ids) === 2 && in_array($rootSupplier, $ids, true) && in_array($childSupplier, $ids, true), 'Supplier list includes recursive ancestor and active entity only');
    foreach ($rows as $row) {
        verify((int)$row['linkid'] === $links[$contact . ':' . $row['id']], 'Supplier association ID retained');
        verify((int)$row['entity'] === (int)$row['entities_id'], 'Supplier entity view model');
    }
    $reverse = $repo->related($rootSupplier, false, $contactScope);
    $ids = array_column($reverse, 'id');
    verify(count($ids) === 2 && in_array($contact, $ids, true) && in_array($childContact, $ids, true), 'Reverse list applies contact entity scope');
    verify($repo->countRelated($contact, true, $supplierScope) === 2 && $repo->countRelated($rootSupplier, false, $contactScope) === 2, 'Counts match scoped lists in both directions');
    verify(count($repo->related($contact, true, null)) === 3 && $repo->countRelated($rootSupplier, false, null) === 3, 'Cron can list and count without interactive entity scope');
    verify($repo->related($empty, true, $supplierScope) === [] && $repo->countRelated($empty, true, $supplierScope) === 0, 'Empty relation result');
    $model = new Contact();
    verify($model->getFromDB($contact), 'Load contact');
    $address = $model->getAddress();
    verify($address['address'] === "1 O'Reilly street" && !array_key_exists('website', $address), 'Company address preserves its row contract');
    verify($model->getWebsite() === 'https://example.com/first', 'Website uses the same deterministic company');
    verify(Contact_Supplier::countForItem($model) === 2, 'Public contact count uses scoped ORM aggregate');
    $supplier = new Supplier();
    verify($supplier->getFromDB($rootSupplier) && Contact_Supplier::countForItem($supplier) === 2, 'Public supplier count uses reverse scope');
    ob_start();
    Contact_Supplier::showForContact($model);
    $contactHtml = ob_get_clean();
    verify(str_contains($contactHtml, 'Supplier root') && str_contains($contactHtml, 'Supplier child') && !str_contains($contactHtml, 'Supplier foreign'), 'Contact view renders scoped suppliers');
    ob_start();
    Contact_Supplier::showForSupplier($supplier);
    $supplierHtml = ob_get_clean();
    verify(str_contains($supplierHtml, 'Contact root') && str_contains($supplierHtml, 'Contact child') && !str_contains($supplierHtml, 'Contact foreign'), 'Supplier view renders scoped contacts');
    $dom = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $dom->loadHTML($supplierHtml);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new DOMXPath($dom);
    verify($xpath->query("//select[@name='contacts_id']//option[@value='$contact' or @value='$childContact']")->length === 0, 'Grouped contact selector excludes existing associations');
    verify($model->getFromDB($empty) && $model->getAddress() === null && $model->getWebsite() === '', 'Missing company preserves empty contracts');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $model->getAddress();
    $model->getWebsite();
    $repo->related($contact, true, $supplierScope);
    $repo->countRelated($rootSupplier, false, $contactScope);
    verify($SQL_TOTAL_REQUEST === 0, 'Contact reads bypass legacy SQL transport');
    $condition = Dropdown::addNewCondition(['glpi_contacts.id' => [$contact, $childContact, $foreignContact]]);
    $dropdown = static function (array $options = []) use ($condition): array {
        $result = Dropdown::getDropdownValue($options + ['itemtype' => 'Contact', 'condition' => $condition,
            'page' => 1, 'page_limit' => 10, 'display_emptychoice' => false], false);
        $rows = [];
        foreach ($result['results'] as $row) {
            array_push($rows, ...($row['children'] ?? [$row]));
        }
        return $rows;
    };
    verify(array_map('intval', array_column($dropdown(['page_limit' => 1]), 'id')) === [$contact], 'Contact picker first page preserves entity/name ordering');
    verify(array_map('intval', array_column($dropdown(['page' => 2, 'page_limit' => 1]), 'id')) === [$childContact], 'Contact picker second page excludes foreign entities');
    verify(array_map('intval', array_column($dropdown(['used' => [$contact]]), 'id')) === [$childContact], 'Contact picker excludes already-used IDs');
    $_SESSION['glpiis_ids_visible'] = true;
    verify(in_array($contact, array_map('intval', array_column($dropdown(['searchText' => (string)$contact]), 'id')), true), 'Numeric contact ID search uses portable LIKE conversion');
    verify(array_map('intval', array_column($dropdown(['searchText' => 'CONTACT CHILD']), 'id')) === [$childContact], 'Contact name search preserves case-insensitive matching');
    verify((new RecordRepository(Orm::create($DB)))->countMatching('glpi_contacts', ['id' => $contact, 'contacttypes_id' => ['LIKE', '%']]) === 0, 'Numeric LIKE preserves NULL associations');
    verify((new ForeignKeys())->audit($connection) === [], 'Contact and line relationship graph remains valid');
} finally {
    $DB->rollBack();
}

$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new ContactLineReferences();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'CONTACT_LINE_METADATA') as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_contacts');
    $connection->insert('glpi_contacts', ['id' => $legacy, 'name' => 'Legacy contact and line']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy contact and line migration has a plan');
    verify((int)$connection->fetchOne('SELECT contacttypes_id FROM glpi_contacts WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_contacts', ['contacttypes_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned contact and line');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_contacts')['contacttypes_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_contacts', ['contacttypes_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT contacttypes_id FROM glpi_contacts WHERE id = ?', [$legacy]) === null, 'Legacy contact type becomes NULL');
    verify($migration->apply($connection) === [], 'Contact and line migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_contacts', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": contact/line lifecycle, scoped company relations, contact details and migration passed.\n";
