<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\Ledger;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Domain\DomainImportValidation;
use itsmng\Domain\DomainPluginImport;
use itsmng\Domain\DomainPluginSource;
use Symfony\Component\Console\Tester\CommandTester;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-plugin-import.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/domains-plugin-2.1.0/Export.php';
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
function refused(callable $operation, string $diagnostic): void
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException $error) {
        verify(str_contains($error->getMessage(), $diagnostic), $diagnostic . '; actual: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $diagnostic);
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated canonical import fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrative fixture login');
$connection = $DB->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$createdTables = [];
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$savedHooks = $PLUGIN_HOOKS;
$pluginProperty = new ReflectionProperty(Plugin::class, 'activated_plugins');
$savedPlugins = $pluginProperty->getValue();
$transaction = false;
$savedTimezone = null;
$savedDefaultEngine = null;
$savedLogEngine = null;
try {
    if ($connection->getDatabasePlatform() instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        $savedDefaultEngine = $connection->fetchOne('SELECT @@SESSION.default_storage_engine');
        $connection->executeStatement("SET SESSION default_storage_engine='MyISAM'");
    }
    foreach (DomainsPlugin210Export::tables(true) as $table) {
        verify(!$manager->tablesExist([$table->getName()]), 'Fixture owns pinned source table ' . $table->getName());
        $manager->createTable($table);
        $createdTables[] = $table->getName();
    }
    $external = new Doctrine\DBAL\Schema\Table('glpi_plugin_accounts_domain_fixture');
    $external->addColumn('id', 'bigint');
    $external->addColumn('itemtype', 'string', ['length' => 100]);
    $external->addColumn('items_id', 'bigint');
    $external->setPrimaryKey(['id']);
    $external->addOption('engine', 'InnoDB');
    verify(!$manager->tablesExist([$external->getName()]), 'Fixture owns external source binding table');
    $manager->createTable($external);
    $createdTables[] = $external->getName();
    verify(Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'No prior current receipt in fixture');
    if ($savedDefaultEngine !== null) {
        $savedLogEngine = $connection->fetchOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='glpi_logs'");
        try {
            $connection->executeStatement('ALTER TABLE glpi_logs ENGINE=MyISAM');
            verify((new itsmng\Database\SchemaCheck())->differences($connection) === [], 'Column/index/FK comparison alone does not prove transactional audit storage');
            refused(fn () => (new DomainPluginImport($DB))->plan(), 'Domains lifecycle import requires transactional core tables: glpi_logs');
            verify(Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Nontransactional audit storage refuses before import journal/data writes');
        } finally {
            $connection->executeStatement('ALTER TABLE glpi_logs ENGINE=' . $connection->quoteIdentifier($savedLogEngine));
            $savedLogEngine = null;
        }
    }
    $connection->beginTransaction();
    $transaction = true;
    if ($connection->getDatabasePlatform() instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        $savedTimezone = $connection->fetchOne('SELECT @@SESSION.time_zone');
        $connection->executeStatement("SET SESSION time_zone='+02:00'");
    } else {
        $connection->executeStatement("SET LOCAL TIME ZONE '+02:00'");
    }
    $fixtures = new FixtureRecords($DB);
    $entityA = $fixtures->create('glpi_entities', ['name' => 'Domain source scope A']);
    $entityB = $fixtures->create('glpi_entities', ['name' => 'Domain source scope B']);
    $supplier = $fixtures->create('glpi_suppliers', ['entities_id' => $entityA, 'name' => 'Registrar supplier']);
    $financialSupplier = $fixtures->create('glpi_suppliers', ['entities_id' => $entityA, 'name' => 'Financial supplier']);
    $user = $fixtures->create('glpi_users');
    $group = $fixtures->create('glpi_groups', ['entities_id' => $entityA]);
    $assets = [];
    foreach (['Computer' => 'glpi_computers', 'Monitor' => 'glpi_monitors', 'NetworkEquipment' => 'glpi_networkequipments',
        'Peripheral' => 'glpi_peripherals', 'Phone' => 'glpi_phones', 'Printer' => 'glpi_printers', 'Software' => 'glpi_softwares'] as $kind => $table) {
        $assets[$kind] = $fixtures->create($table, ['entities_id' => count($assets) % 3 === 1 ? $entityB : $entityA]);
        $publicAsset = new $kind();
        foreach ([true, false] as $recursive) {
            $connection->update($table, ['is_recursive' => $recursive], ['id' => $assets[$kind]], ['is_recursive' => Doctrine\DBAL\ParameterType::BOOLEAN]);
            verify($publicAsset->getFromDB($assets[$kind]) && $publicAsset->isEntityAssign() && $publicAsset->maybeRecursive()
                && (bool)$publicAsset->isRecursive() === $recursive, 'Actual public asset capabilities honor stored entity/recursion policy: ' . $kind);
            $entity = Orm::create($DB)->find(itsmng\Database\EntityRegistry::tables()[$table], $assets[$kind]);
            verify($entity->entities->id === (int)$publicAsset->getEntityID() && $entity->is_recursive === (bool)$publicAsset->isRecursive(), 'ORM asset ownership matches actual public capabilities: ' . $kind);
        }
    }
    $base = 4294972000;
    $export = DomainsPlugin210Export::rows(['entity_a' => $entityA, 'entity_b' => $entityB, 'supplier' => $supplier,
        'user' => $user, 'group' => $group, 'assets' => $assets], $base);
    foreach ($export as $table => $rows) {
        foreach ($rows as $row) {
            $connection->insert($table, $row);
        }
    }
    // Existing names, category roles, and another Domain's asset links belong to core users.
    $coreType = $fixtures->create('glpi_domaintypes', ['entities_id' => $entityA, 'name' => 'Duplicate 日本語']);
    $coreDomain = $fixtures->create('glpi_domains', ['entities_id' => $entityA, 'domaintypes_id' => $coreType, 'name' => 'same.example 日本語']);
    $category = $fixtures->create('glpi_domainrelations', ['name' => 'Category role']);
    $coreLink = $fixtures->create('glpi_domains_items', ['domains_id' => $coreDomain, 'domainrelations_id' => $category, 'itemtype' => 'Computer', 'items_id' => $assets['Computer']]);
    $financial = $fixtures->create('glpi_infocoms', ['itemtype' => DomainPluginSource::ITEMTYPE, 'items_id' => $base + 10, 'entities_id' => $entityA,
        'suppliers_id' => $financialSupplier, 'comment' => "Original invoice O'Reilly 日本語"]);
    $note = $fixtures->create('glpi_notepads', ['itemtype' => DomainPluginSource::ITEMTYPE, 'items_id' => $base + 11, 'content' => "Private note C:\\new 日本語"]);
    $history = $fixtures->create('glpi_logs', ['itemtype' => DomainPluginSource::ITEMTYPE, 'items_id' => $base + 10,
        'itemtype_link' => DomainPluginSource::TYPE, 'old_value' => 'Literal old plugin URL /plugins/domains/', 'new_value' => 'Original label']);
    $retiredHistory = $fixtures->create('glpi_logs', ['itemtype' => DomainPluginSource::ITEMTYPE, 'items_id' => $coreDomain,
        'new_value' => 'Retired plugin subject; same number as unrelated core Domain']);
    $typeHistory = $fixtures->create('glpi_logs', ['itemtype' => 'PluginDomainsDomaintype', 'items_id' => $base, 'new_value' => 'Original type label']);
    $impact = $fixtures->create('glpi_impactrelations', ['itemtype_source' => DomainPluginSource::ITEMTYPE, 'items_id_source' => $base + 10,
        'itemtype_impacted' => 'Computer', 'items_id_impacted' => $assets['Computer']]);
    $translation = $fixtures->create('glpi_dropdowntranslations', ['itemtype' => DomainPluginSource::TYPE, 'items_id' => $base,
        'field' => 'name', 'language' => 'fr_FR', 'value' => 'Type traduit']);
    $profile = $fixtures->create('glpi_profiles', ['name' => 'Domain import profile', 'helpdesk_item_type' => exportArrayToDB([
        0 => 'Computer', '日本語' => 'C:\\new 日本語', DomainPluginSource::ITEMTYPE => 'Unrelated', 'embedded' => 'Prefix' . DomainPluginSource::ITEMTYPE . 'Suffix'])]);
    $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'plugin_domains', 'rights' => 127]);
    $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'plugin_domains_dropdown', 'rights' => 1]);
    $staleProfile = $fixtures->create('glpi_profiles', ['name' => 'Stale plugin type without an open-ticket grant',
        'helpdesk_item_type' => exportArrayToDB(['Computer', DomainPluginSource::ITEMTYPE])]);
    $sourceTicketGrant = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'plugin_domains_open_ticket', 'rights' => 1]);
    $globalGrant = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'dropdown', 'rights' => 2]);
    $domainGrant = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'domain', 'rights' => 0]);
    $typeGrant = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'domaintype', 'rights' => 0]);
    $template = $fixtures->create('glpi_notificationtemplates', ['itemtype' => DomainPluginSource::ITEMTYPE, 'name' => 'Custom domain template', 'comment' => 'Original template']);
    $templateTranslation = $fixtures->create('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $template, 'language' => 'fr_FR',
        'subject' => '##domain.name##', 'content_text' => 'Texte original', 'content_html' => '<p>##domain.name##</p>']);
    $notification = $fixtures->create('glpi_notifications', ['itemtype' => DomainPluginSource::ITEMTYPE, 'entities_id' => $entityA,
        'event' => 'ExpiredDomains', 'name' => 'Scoped custom expiration', 'is_recursive' => true, 'is_active' => true]);
    $delivery = $fixtures->create('glpi_notifications_notificationtemplates', ['notifications_id' => $notification, 'notificationtemplates_id' => $template, 'mode' => 'mailing']);
    $target = $fixtures->create('glpi_notificationtargets', ['notifications_id' => $notification, 'type' => 1, 'items_id' => 1]);
    $repository = new RecordRepository(Orm::create($DB));
    $cron = $repository->find('glpi_crontasks', 'id', 38);
    verify($cron['itemtype'] === 'Domain' && $cron['name'] === 'DomainsAlert', 'Frozen seeded scheduler identity');
    unset($cron['id']);
    $cron['itemtype'] = DomainPluginSource::ITEMTYPE;
    $pluginCron = $fixtures->create('glpi_crontasks', $cron);
    // Explicit operator reconciliation precedes import; the importer must never silently override it.
    $connection->update('glpi_entities', ['send_domains_alert_expired_delay' => 30, 'send_domains_alert_close_expiries_delay' => 45, 'use_domains_alert' => 1], ['id' => 0]);
    $connection->executeStatement("UPDATE glpi_notifications SET is_active=? WHERE itemtype=?", [false, 'Domain'], [Doctrine\DBAL\ParameterType::BOOLEAN, Doctrine\DBAL\ParameterType::STRING]);
    $disjointCoreNotification = $fixtures->create('glpi_notifications', ['itemtype' => 'Domain', 'entities_id' => $entityB,
        'event' => 'ExpiredDomains', 'name' => 'Disjoint existing core policy', 'is_recursive' => false, 'is_active' => true]);
    $display = $fixtures->create('glpi_displaypreferences', ['itemtype' => DomainPluginSource::ITEMTYPE, 'num' => 4, 'rank' => 1, 'users_id' => $user]);
    $saved = $fixtures->create('glpi_savedsearches', ['itemtype' => DomainPluginSource::ITEMTYPE, 'type' => 1, 'path' => 'plugins/domains/front/domain.php',
        'query' => http_build_query(['itemtype' => DomainPluginSource::ITEMTYPE, 'criteria' => [['field' => 4, 'searchtype' => 'equals', 'value' => $supplier]], 'sort' => 11])]);
    $CFG_GLPI['auto_create_infocoms'] = true;
    $CFG_GLPI['use_notifications'] = false;
    $importer = new DomainPluginImport($DB);
    verify($importer->plan()->sourcePlugin === null, 'A completed export without a plugin registration can be imported');
    $sourcePlugin = $fixtures->create('glpi_plugins', ['directory' => 'domains', 'name' => 'Historical Domains 日本語',
        'version' => '2.1.0', 'state' => Plugin::NOTACTIVATED, 'author' => 'Infotel', 'homepage' => 'https://github.com/InfotelGLPI/domains', 'license' => 'GPLv2+']);
    $unrelatedPlugin = $fixtures->create('glpi_plugins', ['directory' => 'unrelated_import_fixture', 'name' => 'Unrelated plugin', 'version' => '1.0', 'state' => Plugin::ACTIVATED]);
    $originalSourcePlugin = $connection->fetchAssociative('SELECT * FROM glpi_plugins WHERE id=?', [$sourcePlugin]);
    $originalUnrelatedPlugin = $connection->fetchAssociative('SELECT * FROM glpi_plugins WHERE id=?', [$unrelatedPlugin]);
    $counts = static function () use ($connection): array {
        $result = [];
        foreach (['glpi_domains', 'glpi_domaintypes', 'glpi_domains_items', 'glpi_infocoms', 'glpi_notepads', 'glpi_logs', 'glpi_profilerights', 'glpi_plugins', 'itsmng_migrations'] as $table) {
            $result[$table] = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table));
        }
        return $result;
    };
    $before = $counts();
    $connection->update('glpi_plugin_domains_domaintypes', ['entities_id' => 0, 'is_recursive' => 1], ['id' => $base]);
    $connection->update('glpi_suppliers', ['entities_id' => 0, 'is_recursive' => 1], ['id' => $supplier]);
    verify($importer->plan()->counts['domains'] === 3, 'Recursive root type and registrar are valid in a descendant entity');
    $connection->update('glpi_plugin_domains_domaintypes', ['entities_id' => $entityA, 'is_recursive' => 1], ['id' => $base]);
    $connection->update('glpi_suppliers', ['entities_id' => $entityA, 'is_recursive' => 0], ['id' => $supplier]);
    $fingerprint = (new DomainPluginSource($connection))->read()->fingerprint();
    $plan = $importer->plan();
    $graphValidator = new DomainImportValidation(Orm::create($DB));
    refused(fn () => $graphValidator->graph([...$plan->records, $plan->records[0]]), 'Duplicate Domains plugin identifier');
    $duplicateLink = $plan->records[5];
    $duplicateLink['values']['id'] = $base + 200;
    $duplicateLink['input']['id'] = $base + 200;
    refused(fn () => $graphValidator->graph([...$plan->records, $duplicateLink]), 'Domains import unique collision');
    verify($plan->counts === ['types' => 2, 'domains' => 3, 'items' => 7, 'configs' => 1], 'Complete graph preview');
    verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Plan reads without writes');
    $descendant = $fixtures->create('glpi_entities', ['entities_id' => $entityA, 'name' => 'Domain asset descendant']);
    $scopeSession = $_SESSION;
    // The administrative fixture may select these entities without changing grants.
    $_SESSION['glpiactiveentities'] = [0, $entityA, $entityB, $descendant];
    $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
    try {
        $publicInput = ['domains_id' => $coreDomain, 'itemtype' => 'Computer', 'items_id' => $assets['Computer']];
        verify((new Domain_Item())->can(-1, UPDATE, $publicInput), 'Public Domain form relation guard accepts same-entity endpoints');
        $connection->update('glpi_computers', ['entities_id' => $entityB], ['id' => $assets['Computer']]);
        verify(!(new Domain_Item())->can(-1, UPDATE, $publicInput), 'Public Domain form relation guard rejects sibling endpoints');
        refused(fn () => $importer->import(), 'Domains import relation entity scope mismatch');
        verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Sibling asset import refuses before lifecycle or receipt writes');
        $connection->update('glpi_computers', ['entities_id' => $descendant], ['id' => $assets['Computer']]);
        $connection->update('glpi_domains', ['is_recursive' => 1], ['id' => $coreDomain]);
        verify((new Domain_Item())->can(-1, UPDATE, $publicInput) && $importer->plan()->counts['items'] === 7, 'Recursive Domain may link a descendant asset on public and import paths');
        $connection->update('glpi_plugin_domains_domains', ['is_recursive' => 0], ['id' => $base + 10]);
        refused(fn () => $importer->plan(), 'Domains import relation entity scope mismatch');
        $connection->update('glpi_plugin_domains_domains', ['is_recursive' => 1], ['id' => $base + 10]);
        $connection->update('glpi_domains', ['is_recursive' => 0], ['id' => $coreDomain]);
        $connection->update('glpi_computers', ['entities_id' => 0, 'is_recursive' => 1], ['id' => $assets['Computer']]);
        verify((new Domain_Item())->can(-1, UPDATE, $publicInput) && $importer->plan()->counts['items'] === 7, 'Recursive ancestor asset may link a child Domain on public and import paths');
        $connection->update('glpi_computers', ['is_recursive' => 0], ['id' => $assets['Computer']]);
        verify(!(new Domain_Item())->can(-1, UPDATE, $publicInput), 'Nonrecursive ancestor asset fails the public relation guard');
        refused(fn () => $importer->plan(), 'Domains import relation entity scope mismatch');
    } finally {
        $connection->update('glpi_computers', ['entities_id' => $entityA, 'is_recursive' => 0], ['id' => $assets['Computer']]);
        $connection->update('glpi_domains', ['is_recursive' => 0], ['id' => $coreDomain]);
        $connection->update('glpi_plugin_domains_domains', ['is_recursive' => 1], ['id' => $base + 10]);
        $_SESSION = $scopeSession;
    }
    // Current typed CHECKs cannot store a legacy plugin kind. Validate a proposed
    // retag against actual persisted owner rows without manufacturing permissive data.
    foreach ([
        [Document_Item::class, 'glpi_documents', 'glpi_documents_items', 'documents_id'],
        [Contract_Item::class, 'glpi_contracts', 'glpi_contracts_items', 'contracts_id'],
        [Certificate_Item::class, 'glpi_certificates', 'glpi_certificates_items', 'certificates_id'],
        [Item_Project::class, 'glpi_projects', 'glpi_items_projects', 'projects_id'],
        [Item_Problem::class, 'glpi_problems', 'glpi_items_problems', 'problems_id'],
        [Change_Item::class, 'glpi_changes', 'glpi_changes_items', 'changes_id'],
    ] as [$publicModel, $ownerTable, $bindingTable, $ownerColumn]) {
        $owner = $fixtures->create($ownerTable, ['entities_id' => $entityA]);
        $bindingId = $fixtures->create($bindingTable, [$ownerColumn => $owner, 'itemtype' => 'Domain', 'items_id' => $coreDomain]);
        $binding = ['class' => itsmng\Database\EntityRegistry::tables()[$bindingTable], 'table' => $bindingTable, 'id' => $bindingId,
            'associations' => ['domain' => ['class' => itsmng\Database\Entity\Domain::class, 'id' => $base + 10]]];
        $validateBinding = static fn () => (new DomainImportValidation(Orm::create($DB)))->bindings($plan->records, [$binding]);
        $validateBinding();
        $connection->update($ownerTable, ['entities_id' => $entityB], ['id' => $owner]);
        refused($validateBinding, 'Domains import relation entity scope mismatch');
        $connection->update($ownerTable, ['entities_id' => 0, 'is_recursive' => 1], ['id' => $owner]);
        $validateBinding();
        $connection->update($ownerTable, ['is_recursive' => 0], ['id' => $owner]);
        refused($validateBinding, 'Domains import relation entity scope mismatch');
        $connection->update($ownerTable, ['entities_id' => $descendant], ['id' => $owner]);
        $validateBinding(); // Incoming Domain is recursive in the ancestor entity.
        verify(Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Ownership preflight never writes an import receipt: ' . $publicModel);
        $connection->update($ownerTable, ['entities_id' => $entityA, 'is_recursive' => 0], ['id' => $owner]);
    }
    $before = $counts(); // Ownership probes are explicit fixture rows, not imported aggregates.
    $impactSibling = $fixtures->create('glpi_computers', ['entities_id' => $entityB]);
    $connection->update('glpi_impactrelations', ['items_id_impacted' => $impactSibling], ['id' => $impact]);
    refused(fn () => $importer->import(), 'Domains import relation entity scope mismatch: glpi_impactrelations');
    verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Sibling impact endpoint refuses before retag or lifecycle writes');
    $connection->update('glpi_impactrelations', ['items_id_impacted' => $assets['Computer']], ['id' => $impact]);
    $application = new Symfony\Component\Console\Application();
    $application->add(new Glpi\Console\Migration\DomainsPluginToCoreCommand());
    $command = new CommandTester($application->find('itsmng:migration:domains_plugin_to_core'));
    verify($command->execute(['--dry-run' => true, '--no-interaction' => true]) === 0 && str_contains($command->getDisplay(), 'no data written'), 'Actual CLI dry run');
    verify($counts() === $before, 'CLI preview read-only');
    foreach ([
        ['glpi_plugins', $sourcePlugin, ['state' => Plugin::ACTIVATED], ['state' => Plugin::NOTACTIVATED], 'Domains plugin must be inactive before import'],
        ['glpi_plugins', $sourcePlugin, ['state' => Plugin::TOBECONFIGURED], ['state' => Plugin::NOTACTIVATED], 'Domains plugin must be inactive before import'],
        ['glpi_plugins', $sourcePlugin, ['directory' => 'Domains '], ['directory' => 'domains'], 'Unsupported Domains plugin directory spelling'],
        ['glpi_plugin_domains_domains', $base + 10, ['is_recursive' => 2], ['is_recursive' => 1], 'Invalid Domains import boolean'],
        ['glpi_plugin_domains_domains', $base + 10, ['plugin_domains_domaintypes_id' => $coreType], ['plugin_domains_domaintypes_id' => $base], 'Missing Domains source owner'],
        ['glpi_plugin_domains_domains', $base + 10, ['plugin_domains_domaintypes_id' => $base + 1], ['plugin_domains_domaintypes_id' => $base], 'Domains import entity scope mismatch'],
        ['glpi_plugin_domains_domains_items', $base + 100, ['plugin_domains_domains_id' => $coreDomain], ['plugin_domains_domains_id' => $base + 10], 'Missing Domains source owner'],
        ['glpi_suppliers', $supplier, ['entities_id' => $entityB], ['entities_id' => $entityA], 'Domains import entity scope mismatch'],
        ['glpi_plugin_domains_domains', $base + 10, ['suppliers_id' => 999999999], ['suppliers_id' => $supplier], 'Invalid Domains import target'],
        ['glpi_plugin_domains_domains_items', $base + 100, ['itemtype' => 'PluginAccountsAccount'], ['itemtype' => 'Computer'], 'Unsupported Domains plugin asset kind'],
        ['glpi_plugin_domains_domains_items', $base + 100, ['items_id' => 999999999], ['items_id' => $assets['Computer']], 'Invalid Domains import target'],
        ['glpi_notifications', $disjointCoreNotification, ['entities_id' => $entityA], ['entities_id' => $entityB], 'Domains notification delivery conflict'],
        ['glpi_entities', $entityA, ['use_domains_alert' => -1], ['use_domains_alert' => -2], 'Domains alert enablement conflict'],
        ['glpi_entities', 0, ['use_domains_alert' => 0], ['use_domains_alert' => 1], 'Domains alert enablement conflict'],
        ['glpi_entities', 0, ['send_domains_alert_expired_delay' => 7], ['send_domains_alert_expired_delay' => 30], 'Domains expiry settings conflict'],
        ['glpi_profilerights', $domainGrant, ['rights' => 1], ['rights' => 0], 'Domains permission conflict'],
        ['glpi_crontasks', $pluginCron, ['frequency' => 1], ['frequency' => $cron['frequency']], 'Domains scheduler settings conflict'],
        ['glpi_displaypreferences', $display, ['users_id' => null], ['users_id' => $user], 'Domains import unique collision'],
        ['glpi_savedsearches', $saved, ['query' => 'criteria[0][field]=999'], ['query' => http_build_query(['itemtype' => DomainPluginSource::ITEMTYPE, 'criteria' => [['field' => 4, 'searchtype' => 'equals', 'value' => $supplier]], 'sort' => 11])], 'Unsupported Domains source search field'],
    ] as [$table, $id, $invalid, $valid, $diagnostic]) {
        $connection->update($table, $invalid, ['id' => $id]);
        refused(fn () => $importer->plan(), $diagnostic);
        verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Invalid source refuses before writes: ' . $diagnostic);
        $connection->update($table, $valid, ['id' => $id]);
    }
    $originalProfileTypes = $connection->fetchOne('SELECT helpdesk_item_type FROM glpi_profiles WHERE id=?', [$profile]);
    foreach ([['Computer', [DomainPluginSource::ITEMTYPE]], [DomainPluginSource::ITEMTYPE, true], ['PluginDomainsDomains'], ['plugindomainsdomain'], [' PluginDomainsDomain ']] as $invalidTypes) {
        $connection->update('glpi_profiles', ['helpdesk_item_type' => exportArrayToDB($invalidTypes)], ['id' => $profile]);
        refused(fn () => $importer->plan(), count($invalidTypes) === 1 ? 'Unsupported Domains helpdesk identity' : 'Invalid encoded Domains helpdesk types');
        verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Malformed profile refuses before granting access or creating records');
    }
    $connection->update('glpi_profiles', ['helpdesk_item_type' => $originalProfileTypes], ['id' => $profile]);
    foreach ([DomainPluginSource::ITEMTYPE, 'plugindomainsdomain', ' PluginDomainsDomain '] as $externalKind) {
        $connection->insert($external->getName(), ['id' => 1, 'itemtype' => $externalKind, 'items_id' => $base + 10]);
        refused(fn () => $importer->plan(), 'Unsupported external Domains plugin binding');
        verify($counts() === $before, 'Unknown extension refuses before writes');
        $connection->delete($external->getName(), ['id' => 1]);
    }
    $connection->update('glpi_logs', ['itemtype_link' => 'PluginDomainsDomains'], ['id' => $history]);
    refused(fn () => $importer->plan(), 'Unsupported Domains source identity spelling');
    $connection->update('glpi_logs', ['itemtype_link' => DomainPluginSource::TYPE], ['id' => $history]);
    $validator = new DomainImportValidation(Orm::create($DB));
    refused(fn () => $validator->normalize(itsmng\Database\Entity\Domain::class, ['id' => $base + 10, 'entities_id' => $entityA, 'date_expiration' => '2026-02-30']), 'Invalid Domains import calendar date');
    if ($connection->getDatabasePlatform() instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        refused(fn () => $validator->normalize(itsmng\Database\Entity\Domain::class, ['id' => $base + 10, 'entities_id' => $entityA, 'date_expiration' => '1000-01-01']), 'native TIMESTAMP range');
    }
    foreach (['plugindomainsdomain', 'PluginDomainsDomain ', ' PluginDomainsDomain'] as $invalidKind) {
        $connection->update('glpi_logs', ['itemtype_link' => $invalidKind], ['id' => $history]);
        refused(fn () => $importer->plan(), 'Unsupported Domains source identity spelling');
        verify($counts() === $before, 'Provider collation/case/padding cannot hide an unsupported source class');
    }
    $connection->update('glpi_logs', ['itemtype_link' => DomainPluginSource::TYPE], ['id' => $history]);
    $pluginProperty->setValue(null, [...$savedPlugins, 'domain_import_fixture']);
    $created = [];
    foreach ([DomainType::class => $export['glpi_plugin_domains_domaintypes'], Domain::class => $export['glpi_plugin_domains_domains']] as $model => $rows) {
        $sourceFlags = [];
        foreach ($rows as $row) {
            $sourceFlags[$row['id']] = array_intersect_key($row, array_fill_keys(['is_recursive', 'is_deleted', 'is_helpdesk_visible'], true));
        }
        $PLUGIN_HOOKS['pre_item_add']['domain_import_fixture'][$model] = static function ($item) use ($sourceFlags): void {
            verify(is_int($item->input['id']), 'Domains pre-add hook retains the normalized native wide identifier');
            foreach ($sourceFlags[$item->input['id']] as $column => $value) {
                verify($item->input[$column] === (bool)$value, 'Domains pre-add hook retains normalized true/false source flags: ' . $column);
            }
        };
    }
    foreach ([DomainType::class, Domain::class, Domain_Item::class] as $model) {
        $PLUGIN_HOOKS['item_add']['domain_import_fixture'][$model] = static function ($item) use (&$created): void {
            verify(!array_key_exists('clone', $item->input), 'Assigned-ID import runs the real lifecycle without clone bypass');
            if ($item instanceof DomainType || $item instanceof Domain) {
                verify(in_array($item->input['is_recursive'], [0, 1], true), 'Domains actual add hook retains the lifecycle zero/one recursion representation');
            }
            $created[] = [$item->getType(), $item->getID()];
        };
    }
    $sessionBefore = $_SESSION;
    foreach (['glpi_domaintypes', 'glpi_domains', 'glpi_domains_items', 'receipt'] as $phase) {
        refused(fn () => $importer->import(static function (string $event, string $table) use ($phase): void {
            if ($table === $phase) {
                throw new RuntimeException('Injected Domain phase interruption: ' . $phase);
            }
        }), 'Injected Domain phase interruption');
        verify($counts() === $before && Ledger::state($connection, DomainPluginImport::RECEIPT) === null, 'Entire aggregate/bindings/receipt rolls back at ' . $phase);
        verify($_SESSION === $sessionBefore && $CFG_GLPI['auto_create_infocoms'] === true, 'Session/config restored at ' . $phase);
        $created = [];
    }
    verify($command->execute(['--no-interaction' => true]) === 0 && str_contains($command->getDisplay(), 'Domains import completed'), 'Actual CLI lifecycle import');
    verify(count($created) === 12, 'All type/domain/link public creation hooks run exactly once');
    $repository = new RecordRepository(Orm::create($DB));
    $domain = $repository->find('glpi_domains', 'id', $base + 10);
    verify($domain['suppliers_id'] === $supplier && $domain['users_id_tech'] === $user && $domain['groups_id_tech'] === $group, 'Direct supplier/technical ownership preserved');
    verify($domain['date_creation'] === '2026-01-01 00:00:00' && $domain['date_expiration'] === '2027-12-31 00:00:00', 'Calendar DATE becomes session midnight without native TIMESTAMP replacement');
    verify($domain['comment'] === $export['glpi_plugin_domains_domains'][0]['comment'], 'Quotes, backslash, newline and Unicode preserved');
    verify($repository->find('glpi_domains', 'id', $base + 11)['is_helpdesk_visible'] === 0, 'Hidden source domains remain hidden');
    verify($repository->find('glpi_domains', 'id', $base + 12)['name'] === 'NULL' && $repository->find('glpi_domains', 'id', $base + 12)['date_creation'] === null, 'Literal NULL and absent calendar date preserved');
    verify($repository->find('glpi_domaintypes', 'id', $base)['is_recursive'] === 1, 'Type recursion preserved');
    verify($repository->find('glpi_domains_items', 'id', $coreLink)['domainrelations_id'] === $category && $repository->find('glpi_domains', 'id', $coreDomain)['domaintypes_id'] === $coreType, 'Existing core category role and same-name domain/type untouched');
    foreach ($export['glpi_plugin_domains_domains_items'] as $link) {
        $actual = $repository->find('glpi_domains_items', 'id', $link['id']);
        verify($actual['domains_id'] === $link['plugin_domains_domains_id'] && $actual['items_id'] === $link['items_id'] && $actual['domainrelations_id'] === null, 'Individual source link ID/owner/projection preserved');
    }
    verify($repository->find('glpi_infocoms', 'id', $financial)['itemtype'] === 'Domain' && $repository->find('glpi_infocoms', 'id', $financial)['suppliers_id'] === $financialSupplier, 'Original financial supplier distinct from direct registrar');
    verify($repository->countMatching('glpi_infocoms', ['itemtype' => 'Domain', 'items_id' => $base + 10]) === 1, 'No duplicate automatic source financial child');
    verify($repository->countMatching('glpi_infocoms', ['itemtype' => 'Domain', 'items_id' => $base + 11]) === 1, 'Configured automatic financial lifecycle retained for other Domains');
    verify($repository->find('glpi_notepads', 'id', $note)['itemtype'] === 'Domain', 'Existing note ID preserved');
    verify($repository->find('glpi_logs', 'id', $history)['itemtype'] === 'Domain' && $repository->find('glpi_logs', 'id', $history)['itemtype_link'] === 'DomainType', 'Primary and class-label audit roles converted');
    verify($repository->find('glpi_logs', 'id', $retiredHistory)['itemtype'] === DomainPluginSource::ITEMTYPE, 'Retired audit is never adopted by a colliding core ID');
    verify($repository->find('glpi_logs', 'id', $typeHistory)['itemtype'] === 'DomainType' && $repository->find('glpi_dropdowntranslations', 'id', $translation)['itemtype'] === 'DomainType', 'Historical type spelling explicitly normalized');
    verify($repository->find('glpi_impactrelations', 'id', $impact)['itemtype_source'] === 'Domain'
        && $repository->find('glpi_impactrelations', 'id', $impact)['items_id_impacted'] === $assets['Computer'], 'Impact source identity retag preserves coherent actual endpoint ownership');
    verify($repository->find('glpi_logs', 'id', $history)['old_value'] === 'Literal old plugin URL /plugins/domains/', 'Audit display content and old URLs retained unchanged');
    verify($repository->find('glpi_profilerights', 'id', $domainGrant)['rights'] === 127 && $repository->find('glpi_profilerights', 'id', $typeGrant)['rights'] === 1 && $repository->find('glpi_profilerights', 'id', $globalGrant)['rights'] === 2, 'Source permissions preserve note rights and dedicated type scope without global union');
    $types = importArrayFromDB($repository->find('glpi_profiles', 'id', $profile)['helpdesk_item_type']);
    verify(in_array('Domain', $types, true) && $types['日本語'] === 'C:\\new 日本語' && $types[DomainPluginSource::ITEMTYPE] === 'Unrelated', 'Source open-ticket grant adds exact Domain value without changing keys/other values');
    $staleTypes = importArrayFromDB($repository->find('glpi_profiles', 'id', $staleProfile)['helpdesk_item_type']);
    verify(in_array('Computer', $staleTypes, true) && !in_array('Domain', $staleTypes, true) && !in_array(DomainPluginSource::ITEMTYPE, $staleTypes, true), 'Missing source open-ticket grant does not authorize a stale plugin type');
    verify($repository->find('glpi_notifications', 'id', $disjointCoreNotification)['entities_id'] === $entityB
        && $repository->find('glpi_notifications', 'id', $disjointCoreNotification)['is_active'] === 1, 'Disjoint existing core notification policy remains active without overwrites');
    verify($repository->find('glpi_notificationtemplates', 'id', $template)['itemtype'] === 'Domain' && $repository->find('glpi_notifications', 'id', $notification)['itemtype'] === 'Domain', 'Custom templates and scoped notification identity preserved');
    verify($repository->find('glpi_notificationtemplatetranslations', 'id', $templateTranslation)['content_text'] === 'Texte original' && $repository->find('glpi_notifications_notificationtemplates', 'id', $delivery)['notifications_id'] === $notification && $repository->find('glpi_notificationtargets', 'id', $target)['notifications_id'] === $notification, 'Translation/delivery/recipient IDs and contents survive');
    verify($repository->find('glpi_crontasks', 'id', $pluginCron)['state'] === 0 && $repository->find('glpi_crontasks', 'id', 38)['state'] === 1, 'Matched core scheduler retains seeded ID; original plugin job retired with history');
    verify($repository->find('glpi_displaypreferences', 'id', $display)['num'] === 4 && $repository->find('glpi_savedsearches', 'id', $saved)['path'] === 'front/domain.php', 'Supplier search semantic ID and canonical path preserved');
    verify((new DomainPluginSource($connection))->read()->fingerprint() === $fingerprint, 'Pinned plugin export never rewritten or truncated');
    $receipt = Ledger::state($connection, DomainPluginImport::RECEIPT);
    verify($receipt['complete'] && $receipt['fingerprint'] === $fingerprint && count($receipt['generated']['infocoms']) === 2, 'Same migration ledger records frozen provenance and generated children');
    verify(
        $receipt['source_plugin'] === $plan->sourcePlugin && $receipt['source_plugin']['state'] === Plugin::NOTACTIVATED
        && $connection->fetchAssociative('SELECT * FROM glpi_plugins WHERE id=?', [$sourcePlugin]) === $originalSourcePlugin,
        'Inactive historical plugin registration and provenance remain unchanged'
    );
    verify($connection->fetchAssociative('SELECT * FROM glpi_plugins WHERE id=?', [$unrelatedPlugin]) === $originalUnrelatedPlugin, 'Unrelated active plugin registration remains unchanged');
    $nextType = (new DomainType())->add(['name' => 'Next public type', 'entities_id' => $entityA]);
    $nextDomain = (new Domain())->add(['name' => 'Next public domain', 'entities_id' => $entityA, 'domaintypes_id' => $nextType]);
    $nextLink = (new Domain_Item())->add(['domains_id' => $nextDomain, 'itemtype' => 'Computer', 'items_id' => $assets['Computer']]);
    verify($nextType > $base + 1 && $nextDomain > $base + 12 && $nextLink > $base + 106, 'Monotonic sequence allocation after assigned import IDs');
    $created = [];
    verify((new Domain())->update(['id' => $base + 10, 'name' => 'Application edited import']), 'Application may edit imported Domain');
    verify((new Domain_Item())->delete(['id' => $base + 100], true), 'Application may purge an imported link');
    $after = $counts();
    verify($importer->import()->alreadyImported && $created === [] && $counts() === $after, 'Exact retry retains later edits/purge without replaying hooks or creating records');
    $connection->update('glpi_plugins', ['state' => Plugin::ACTIVATED], ['id' => $sourcePlugin]);
    refused(fn () => $importer->import(), 'Domains plugin must be inactive before import');
    verify($counts() === $after && $created === [], 'Reactivated source plugin refuses a completed retry without lifecycle writes');
    $connection->update('glpi_plugins', ['state' => Plugin::NOTACTIVATED], ['id' => $sourcePlugin]);
    $canonicalProfileTypes = $connection->fetchOne('SELECT helpdesk_item_type FROM glpi_profiles WHERE id=?', [$profile]);
    foreach ([DomainPluginSource::ITEMTYPE, 'plugindomainsdomain', ' PluginDomainsDomain ', [DomainPluginSource::ITEMTYPE]] as $newLegacyType) {
        $connection->update('glpi_profiles', ['helpdesk_item_type' => exportArrayToDB(['Computer', 'Domain', $newLegacyType])], ['id' => $profile]);
        refused(fn () => $importer->import(), 'New Domains plugin helpdesk binding after completed import');
        verify($counts() === $after && $created === [], 'Completed retry refuses new legacy encoded profile values before writes');
    }
    $connection->update('glpi_profiles', ['helpdesk_item_type' => $canonicalProfileTypes], ['id' => $profile]);
    $repository = new RecordRepository(Orm::create($DB)); // Public lifecycle writes use their own entity manager.
    verify($repository->find('glpi_domains', 'id', $base + 10)['name'] === 'Application edited import' && $repository->find('glpi_domains_items', 'id', $base + 100) === null, 'Exact retry never restores original export over users');
    $connection->update('glpi_profilerights', ['rights' => 0], ['id' => $domainGrant]);
    verify($importer->import()->alreadyImported && (new RecordRepository(Orm::create($DB)))->find('glpi_profilerights', 'id', $domainGrant)['rights'] === 0, 'Exact retry preserves a later canonical authorization edit');
    $connection->update('glpi_profilerights', ['rights' => 0], ['id' => $sourceTicketGrant]);
    refused(fn () => $importer->plan(), 'Changed or new Domains plugin permission');
    verify($created === [] && $repository->find('glpi_profilerights', 'id', $domainGrant)['rights'] === 0, 'Changed retained source grants require reconciliation without replaying canonical permissions');
    $connection->update('glpi_profilerights', ['rights' => 1], ['id' => $sourceTicketGrant]);
    $newNote = $fixtures->create('glpi_notepads', ['itemtype' => DomainPluginSource::ITEMTYPE, 'items_id' => $base + 10, 'content' => 'New source binding']);
    refused(fn () => $importer->plan(), 'New Domains plugin binding after completed import');
    $connection->delete('glpi_notepads', ['id' => $newNote]);
    $connection->update('glpi_plugin_domains_domains', ['name' => 'Changed source export'], ['id' => $base + 10]);
    refused(fn () => $importer->import(), 'Domains source differs from its completed import receipt');
    verify($created === [] && $counts() === $after, 'Changed source refuses without replaying the completed aggregate');
} finally {
    if ($transaction && $connection->isTransactionActive()) {
        $connection->rollBack();
    }
    if ($savedLogEngine !== null) {
        $connection->executeStatement('ALTER TABLE glpi_logs ENGINE=' . $connection->quoteIdentifier($savedLogEngine));
    }
    if ($savedDefaultEngine !== null) {
        $connection->executeStatement('SET SESSION default_storage_engine=?', [$savedDefaultEngine]);
    }
    if ($savedTimezone !== null) {
        $connection->executeStatement('SET SESSION time_zone=?', [$savedTimezone]);
    }
    foreach (array_reverse($createdTables) as $table) {
        $manager->dropTable($table);
    }
    $_SESSION = $savedSession;
    $CFG_GLPI = $savedConfig;
    $PLUGIN_HOOKS = $savedHooks;
    $pluginProperty->setValue(null, $savedPlugins);
}
echo $DB->getProvider() . ": canonical Domains lifecycle, exact ownership/provenance, supplier/helpdesk/permissions, notifications/policy, audit, atomic rollback, CLI/retry and source diagnostics passed.\n";
