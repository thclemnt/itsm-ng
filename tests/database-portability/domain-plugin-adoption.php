<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\DomainIntegration20261006;
use itsmng\Database\Migration\DomainsPluginAdoption20261006;
use itsmng\Database\Migration\DomainsPluginSnapshot20261006;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\Seeds20261001;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-plugin-adoption.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), $diagnostic), $diagnostic . '; actual: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $diagnostic);
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable parent database required');
$parent = $DB->getDoctrineConnection();
$platform = $parent->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$name = getenv('PORT_DOMAIN_ADOPTION_DB') ?: $DB->dbdefault . '_domain_adoption';
verify(str_starts_with($name, 'itsm_port_') && $name !== $DB->dbdefault, 'Separate disposable historical database required');
verify($parent->fetchOne($postgres ? 'SELECT 1 FROM pg_database WHERE datname = ?' : 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]) === false, 'Historical fixture name must be unused');
$parent->executeStatement('CREATE DATABASE ' . $platform->quoteIdentifier($name));
$adapter = DBConnection::createConnection($DB->getProvider(), $DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $name);
verify($adapter->connected, 'Historical fixture connection');
$connection = $adapter->getDoctrineConnection();
$manager = $connection->createSchemaManager();
$savedTimezone = null;
$started = microtime(true);
$checkpoint = static function (string $phase) use ($started): void {
    echo $phase . ': elapsed=' . number_format(microtime(true) - $started, 3, '.', '') . "s\n";
};
try {
    $baseline = new Baseline20261001();
    foreach ($baseline->toSql($platform) as $sql) {
        $connection->executeStatement($sql);
    }
    (new Seeds20261001())->apply($connection);
    $manager->dropTable(LegacyToOrm::LEDGER);
    $checkpoint('Raw frozen baseline and seeds, ledger removed');
    // These are raw historical records, deliberately independent of today's
    // entities, generated projections, repositories and input normalization.
    $connection->insert('glpi_suppliers', ['id' => 9000, 'name' => 'Registrar']);
    $connection->insert('glpi_suppliers', ['id' => 9001, 'name' => 'Financial vendor']);
    $connection->insert('glpi_computers', ['id' => 9002, 'name' => 'Existing asset']);
    $connection->insert('glpi_tickets', ['id' => 9003, 'name' => 'Existing ticket']);
    $connection->insert('glpi_documents', ['id' => 9004, 'name' => 'Retained attachment']);
    $connection->insert('glpi_domains', ['id' => 9005, 'name' => 'Pre-existing core domain']);
    $connection->insert('glpi_entities', ['id' => 10, 'entities_id' => 0, 'name' => 'Source branch A']);
    $connection->insert('glpi_entities', ['id' => 11, 'entities_id' => 0, 'name' => 'Source branch B']);
    $connection->insert('glpi_profiles', ['id' => 99, 'name' => 'Historical policy', 'helpdesk_item_type' => json_encode(['kept' => 'Computer', 'domain' => 'PluginDomainsDomain'], JSON_THROW_ON_ERROR)]);
    foreach (['dropdown' => 1, 'plugin_domains' => 127, 'plugin_domains_dropdown' => 1, 'plugin_domains_open_ticket' => 1] as $right => $mask) {
        $connection->insert('glpi_profilerights', ['profiles_id' => 99, 'name' => $right, 'rights' => $mask]);
    }
    $connection->insert('glpi_plugins', ['id' => 9006, 'directory' => 'domains', 'name' => 'Pinned Domains', 'version' => '2.1.0', 'state' => 4]);
    $connection->insert('glpi_plugins', ['id' => 9007, 'directory' => 'other', 'name' => 'Other plugin', 'version' => '1.0.0', 'state' => 1]);
    verify($connection->fetchOne('SELECT version FROM glpi_plugins WHERE id = 9007') === '1.0.0', 'Unrelated historical plugin supplies and retains its required version');
    $connection->update('glpi_entities', ['use_domains_alert' => 0, 'send_domains_alert_expired_delay' => 30, 'send_domains_alert_close_expiries_delay' => 45], ['id' => 0]);
    foreach (DomainsPlugin210Export::tables(true) as $table) {
        $manager->createTable($table);
    }
    $rows = DomainsPlugin210Export::rows(['entity_a' => 0, 'entity_b' => 0, 'user' => 2, 'group' => 0, 'supplier' => 9000, 'assets' => ['Computer' => 9002]], 100000);
    $rows['glpi_plugin_domains_domains'][0]['comment'] = "UPDATE notes are data; O'Reilly 日本語";
    foreach ($rows as $table => $records) {
        foreach ($records as $row) {
            $connection->insert($table, $row);
        }
    }
    verify($connection->fetchOne('SELECT comment FROM glpi_plugin_domains_domains WHERE id=100010') === "UPDATE notes are data; O'Reilly 日本語", 'The pinned UTF-8 export retains exact source text before adoption');
    $unbound = (new DomainsPluginAdoption20261006())->plan($connection);
    verify($unbound['version'] === DomainsPluginAdoption20261006::VERSION && $unbound['receipt']['counts'] === ['types' => 2, 'domains' => 3, 'items' => 1, 'configs' => 1]
        && !$unbound['updates'] && !Ledger::assertTransactional($connection), 'An unbound supported export is explicitly planned without writes or a no-source completion marker');
    $connection->executeStatement('ALTER TABLE glpi_plugin_domains_configs RENAME TO glpi_plugin_test_config_hold');
    try {
        refused(fn () => (new DomainsPluginAdoption20261006())->plan($connection), 'Missing frozen Domains source table: glpi_plugin_domains_configs');
        verify(!Ledger::assertTransactional($connection), 'Partial export refuses before ledger bootstrap');
    } finally {
        $connection->executeStatement('ALTER TABLE glpi_plugin_test_config_hold RENAME TO glpi_plugin_domains_configs');
    }
    $connection->insert('glpi_plugin_domains_domaintypes', ['id' => 4294972901, 'entities_id' => 0, 'name' => 'Already-wide historical type', 'is_recursive' => 0]);
    refused(fn () => (new DomainsPluginAdoption20261006())->plan($connection), 'identifier exceeds native target width');
    verify(!Ledger::assertTransactional($connection), 'A wide export cannot silently narrow or create an adoption journal');
    // This models an existing installation whose parent identifier has already
    // been widened, independently of this data prerequisite's production DDL.
    $connection->executeStatement($postgres ? 'ALTER TABLE glpi_domaintypes ALTER COLUMN id TYPE BIGINT' : 'ALTER TABLE glpi_domaintypes MODIFY id BIGINT NOT NULL AUTO_INCREMENT');
    if ($postgres) {
        $connection->executeStatement('ALTER SEQUENCE glpi_domaintypes_id_seq AS BIGINT');
    }
    $connection->insert('glpi_items_tickets', ['id' => 9010, 'tickets_id' => 9003, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 100010]);
    $connection->insert('glpi_infocoms', ['id' => 9011, 'entities_id' => 0, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 100010, 'suppliers_id' => 9001]);
    $connection->insert('glpi_logs', ['id' => 9012, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 100010]);
    $connection->insert('glpi_logs', ['id' => 9013, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 99999]);
    $connection->insert('glpi_logs', ['id' => 9014, 'itemtype' => 'Computer', 'items_id' => 9002, 'itemtype_link' => 'PluginDomainsDomain']);
    $connection->insert('glpi_notepads', ['id' => 9015, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 100010, 'content' => "Full note 日本語\nNULL"]);
    if ($postgres) {
        $connection->executeStatement("SET TIME ZONE 'UTC'");
    } else {
        $savedTimezone = $connection->fetchOne('SELECT @@SESSION.time_zone');
        $connection->executeStatement("SET time_zone = '+00:00'");
    }
    foreach ([9016 => ['PluginDomainsDomain', 100010], 9017 => ['Domain', 9005]] as $id => [$kind, $subject]) {
        $connection->insert('glpi_documents_items', ['id' => $id, 'documents_id' => 9004, 'itemtype' => $kind, 'items_id' => $subject, 'entities_id' => 0, 'is_recursive' => $postgres ? true : 1, 'users_id' => 0, 'timeline_position' => 1,
            'date_mod' => '2026-01-02 03:04:05', 'date_creation' => '2026-01-03 04:05:06', 'date' => '2026-01-04 05:06:07'], $postgres ? ['is_recursive' => Types::BOOLEAN] : []);
    }
    $instantSql = $postgres ? 'SELECT EXTRACT(EPOCH FROM date_mod) FROM glpi_documents_items WHERE id = 9016' : 'SELECT UNIX_TIMESTAMP(date_mod) FROM glpi_documents_items WHERE id = 9016';
    $instant = $connection->fetchOne($instantSql);
    $connection->executeStatement($postgres ? "SET TIME ZONE '+02:00'" : "SET time_zone = '+02:00'");
    $migration = new DomainsPluginAdoption20261006();
    $history = new History();
    $snapshot = DomainsPluginSnapshot20261006::read($connection);
    verify(DomainsPluginSnapshot20261006::fingerprint($snapshot) === (new itsmng\Domain\DomainPluginSource($connection))->read()->fingerprint(), 'Frozen and current source wire formats agree independently');
    $preview = $history->plan($connection);
    verify(isset($preview['domain_prerequisite']) && str_contains($preview['canonical_preflight'], 'Deferred'), 'Source-only preview honestly defers canonical unsupported-kind audits');
    verify(!Ledger::assertTransactional($connection) && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domaintypes WHERE id >= 100000') === 0
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_documents_items WHERE id IN (9016,9017)') === 2, 'Preview leaves ledger, graph and deferred documents unchanged');
    $previewConfig = sys_get_temp_dir() . '/itsm-domain-adoption-preview-' . bin2hex(random_bytes(6));
    mkdir($previewConfig, 0700);
    $source = '<?php class DB extends ' . ($postgres ? 'DBpgsql' : 'DBmysql') . ' {';
    foreach (['dbhost' => $DB->dbhost, 'dbuser' => $DB->dbuser, 'dbpassword' => $DB->dbpassword, 'dbdefault' => $name] as $field => $value) {
        $source .= ' public $' . $field . ' = ' . var_export($value, true) . ';';
    }
    file_put_contents($previewConfig . '/config_db.php', $source . '}');
    chmod($previewConfig . '/config_db.php', 0600);
    $key = (new itsmng\Database\Upgrade($DB))->expectedSecurityKeyPath();
    verify($key !== null && is_file($key), 'Configured parent encryption key is retained for the preview');
    copy($key, $previewConfig . '/glpicrypt.key');
    chmod($previewConfig . '/glpicrypt.key', 0600);
    try {
        $process = proc_open([PHP_BINARY, GLPI_ROOT . '/bin/console', '--config-dir=' . $previewConfig, '--no-interaction', 'db:migrate'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, GLPI_ROOT);
        verify(is_resource($process), 'Actual historical preview CLI starts');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        verify($status === 0 && str_contains($output, 'Elective data prerequisite: ' . DomainsPluginAdoption20261006::VERSION)
            && str_contains($output, '"domains":3') && str_contains($output, 'canonical audits') && str_contains($output, 'deferred document rows: 2')
            && !str_contains($output, 'UPDATE notes are data') && str_contains($output, 'No changes.'), 'Actual CLI shows frozen counts/deferred canonical audits without misreading source text as SQL: ' . $output);
        verify(!Ledger::assertTransactional($connection) && $connection->fetchOne('SELECT itemtype FROM glpi_items_tickets WHERE id=9010') === 'PluginDomainsDomain', 'Actual source-only CLI preview creates no ledger or remap');
    } finally {
        unlink($previewConfig . '/config_db.php');
        unlink($previewConfig . '/glpicrypt.key');
        rmdir($previewConfig);
    }
    $checkpoint('Read-only source plan and actual CLI preview');
    foreach ([1, 3] as $state) {
        $connection->update('glpi_plugins', ['state' => $state], ['id' => 9006]);
        refused(fn () => $migration->plan($connection), 'requires the source domains plugin inactive');
    }
    $connection->update('glpi_plugins', ['state' => 4, 'directory' => 'Domains '], ['id' => 9006]);
    refused(fn () => $migration->plan($connection), 'requires the source domains plugin inactive');
    $connection->update('glpi_plugins', ['directory' => 'domains'], ['id' => 9006]);
    foreach (['plugindomainsdomain', ' PluginDomainsDomain '] as $variant) {
        $connection->update('glpi_logs', ['itemtype' => $variant], ['id' => 9013]);
        refused(fn () => $migration->plan($connection), 'Unsupported frozen Domains identity spelling');
        $connection->update('glpi_logs', ['itemtype' => 'PluginDomainsDomain'], ['id' => 9013]);
        $connection->update('glpi_profiles', ['helpdesk_item_type' => json_encode(['domain' => $variant], JSON_THROW_ON_ERROR)], ['id' => 99]);
        refused(fn () => $migration->plan($connection), 'Unsupported frozen Domains helpdesk profile shape or spelling');
    }
    $connection->update('glpi_profiles', ['helpdesk_item_type' => json_encode(['kept' => 'Computer', 'domain' => 'PluginDomainsDomain'], JSON_THROW_ON_ERROR)], ['id' => 99]);
    $connection->update('glpi_plugin_domains_domains', ['entities_id' => 10], ['id' => 100012]);
    $connection->update('glpi_plugin_domains_domains_items', ['plugin_domains_domains_id' => 100012], ['id' => 100100]);
    $connection->update('glpi_computers', ['entities_id' => 11], ['id' => 9002]);
    refused(fn () => $migration->plan($connection), 'relationship entity incoherence: glpi_domains_items.100100');
    verify(!Ledger::assertTransactional($connection), 'Sibling asset scope refuses before any data or journal writes');
    $connection->update('glpi_computers', ['entities_id' => 0, 'is_recursive' => $postgres ? true : 1], ['id' => 9002], $postgres ? ['is_recursive' => Types::BOOLEAN] : []);
    $migration->plan($connection); // Recursive asset ancestor permits the child Domain.
    $connection->update('glpi_computers', ['entities_id' => 10, 'is_recursive' => $postgres ? false : 0], ['id' => 9002], $postgres ? ['is_recursive' => Types::BOOLEAN] : []);
    $connection->update('glpi_plugin_domains_domains', ['entities_id' => 0, 'is_recursive' => 1], ['id' => 100012]);
    $migration->plan($connection); // Recursive Domain ancestor permits the child asset.
    $connection->update('glpi_plugin_domains_domains_items', ['plugin_domains_domains_id' => 100010], ['id' => 100100]);
    $connection->update('glpi_computers', ['entities_id' => 0], ['id' => 9002]);
    $connection->update('glpi_plugin_domains_domains', ['entities_id' => 10, 'is_recursive' => 0], ['id' => 100012]);
    $guarded = [
        ['glpi_contracts_items', 'contracts_id', 'glpi_contracts'],
        ['glpi_certificates_items', 'certificates_id', 'glpi_certificates'],
        ['glpi_items_projects', 'projects_id', 'glpi_projects'],
        ['glpi_items_problems', 'problems_id', 'glpi_problems'],
        ['glpi_changes_items', 'changes_id', 'glpi_changes'],
    ];
    foreach ($guarded as $offset => [$table, $column, $ownerTable]) {
        $owner = 9100 + $offset;
        $connection->insert($ownerTable, ['id' => $owner, 'entities_id' => 11, 'name' => 'Historical scoped owner']);
        $connection->insert($table, ['id' => 9200, $column => $owner, 'itemtype' => 'PluginDomainsDomain', 'items_id' => 100012]);
        refused(fn () => $migration->plan($connection), 'relationship entity incoherence: ' . $table . '.9200');
        $connection->update($ownerTable, ['entities_id' => 10], ['id' => $owner]);
        $migration->plan($connection);
        $connection->delete($table, ['id' => 9200]);
    }
    $connection->update('glpi_documents', ['entities_id' => 11], ['id' => 9004]);
    $connection->update('glpi_documents_items', ['items_id' => 100012], ['id' => 9016]);
    refused(fn () => $migration->plan($connection), 'relationship entity incoherence: glpi_documents_items.9016');
    $connection->update('glpi_documents_items', ['items_id' => 100010], ['id' => 9016]);
    $connection->update('glpi_documents', ['entities_id' => 0], ['id' => 9004]);
    $connection->update('glpi_computers', ['entities_id' => 11], ['id' => 9002]);
    $connection->insert('glpi_impactrelations', ['id' => 9210, 'itemtype_source' => 'PluginDomainsDomain', 'items_id_source' => 100012, 'itemtype_impacted' => 'Computer', 'items_id_impacted' => 9002]);
    refused(fn () => $migration->plan($connection), 'relationship entity incoherence: glpi_impactrelations.9210');
    $connection->update('glpi_computers', ['entities_id' => 10], ['id' => 9002]);
    $migration->plan($connection);
    $connection->delete('glpi_impactrelations', ['id' => 9210]);
    $connection->update('glpi_computers', ['entities_id' => 0], ['id' => 9002]);
    $connection->update('glpi_plugin_domains_domains', ['entities_id' => 0], ['id' => 100012]);
    $external = new Doctrine\DBAL\Schema\Table('glpi_plugin_test_domain_reference');
    $external->addColumn('id', 'integer');
    $external->addColumn('itemtype', 'string', ['length' => 100]);
    $external->setPrimaryKey(['id']);
    $external->addOption('engine', 'InnoDB');
    $manager->createTable($external);
    $connection->insert($external->getName(), ['id' => 1, 'itemtype' => 'plugindomainsdomain']);
    refused(fn () => $migration->plan($connection), 'Unsupported external frozen Domains identity');
    $manager->dropTable($external->getName());
    $connection->update('glpi_profilerights', ['rights' => 2], ['profiles_id' => 99, 'name' => 'dropdown']);
    refused(fn () => $migration->plan($connection), 'permission conflict');
    $connection->update('glpi_profilerights', ['rights' => 1], ['profiles_id' => 99, 'name' => 'dropdown']);
    $connection->update('glpi_entities', ['send_domains_alert_expired_delay' => 31], ['id' => 0]);
    refused(fn () => $migration->plan($connection), 'entity alert policy conflict');
    $connection->update('glpi_entities', ['send_domains_alert_expired_delay' => 30], ['id' => 0]);
    $connection->update('glpi_plugin_domains_domains', ['date_creation' => '2040-01-01'], ['id' => 100010]);
    if (!$postgres) {
        refused(fn () => $migration->plan($connection), 'exceeds native TIMESTAMP');
    }
    $connection->update('glpi_plugin_domains_domains', ['date_creation' => '2026-01-01'], ['id' => 100010]);
    $incoming = new Doctrine\DBAL\Schema\Table('glpi_plugin_test_document_owner');
    $incoming->addColumn('id', 'integer');
    $incoming->addColumn('binding_id', 'integer');
    $incoming->setPrimaryKey(['id']);
    $incoming->addForeignKeyConstraint('glpi_documents_items', ['binding_id'], ['id'], [], 'test_incoming_document_binding');
    $incoming->addOption('engine', 'InnoDB');
    $manager->createTable($incoming);
    refused(fn () => $migration->plan($connection), 'refuses incoming foreign keys');
    $manager->dropTable($incoming->getName());
    if (!$postgres) {
        $connection->executeStatement('ALTER TABLE glpi_documents_items ENGINE=MyISAM');
        refused(fn () => $migration->plan($connection), 'must use InnoDB');
        $connection->executeStatement('ALTER TABLE glpi_documents_items ENGINE=InnoDB');
    }
    $connection->update('glpi_computers', ['locations_id' => 987654], ['id' => 9002]);
    refused(fn () => $history->upgrade($connection), 'glpi_computers.locations_id');
    verify(!Ledger::assertTransactional($connection), 'Invalid unrelated core graph refuses before even empty MySQL ledger bootstrap');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domains WHERE id >= 100000') === 0
        && $connection->fetchOne('SELECT itemtype FROM glpi_items_tickets WHERE id=9010') === 'PluginDomainsDomain'
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_documents_items WHERE id IN (9016,9017)') === 2, 'Rollback validation preserves source kinds, target records and original document rows');
    $connection->update('glpi_computers', ['locations_id' => 0], ['id' => 9002]);
    // Each software companion participates in the real ledgerless Domain trial.
    // A valid source installation must not allow an invalid licence to reach DDL,
    // and the reverse family must be audited before remap receipts are committed.
    $connection->insert('glpi_softwares', ['id' => 9030, 'name' => 'Historical assignment Software']);
    $connection->insert('glpi_softwareversions', ['id' => 9031, 'softwares_id' => 9030]);
    $connection->insert('glpi_softwarelicenses', ['id' => 9032, 'softwares_id' => 9030, 'number' => -1]);
    $connection->insert('glpi_items_softwareversions', ['id' => 9033, 'softwareversions_id' => 9031, 'itemtype' => 'Computer', 'items_id' => 9002]);
    $connection->insert('glpi_items_softwarelicenses', ['id' => 9034, 'softwarelicenses_id' => 9032, 'itemtype' => 'Computer', 'items_id' => 9002]);
    $originalDocuments = $connection->fetchAllAssociative('SELECT * FROM glpi_documents_items WHERE id IN (9016,9017) ORDER BY id');
    $originalPlugin = DomainsPluginSnapshot20261006::fingerprint(DomainsPluginSnapshot20261006::read($connection));
    foreach (['glpi_items_softwareversions' => 9033, 'glpi_items_softwarelicenses' => 9034] as $table => $id) {
        $connection->update($table, ['itemtype' => 'PluginInventoryAsset'], ['id' => $id]);
        $invalidSource = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
        refused(fn () => $history->upgrade($connection), $table);
        verify(
            !Ledger::assertTransactional($connection)
            && !$manager->introspectTable('glpi_items_softwareversions')->hasColumn('computers_id')
            && !$manager->introspectTable('glpi_items_softwarelicenses')->hasColumn('computers_id')
            && \Doctrine\DBAL\Types\Type::lookupName($manager->listTableColumns('glpi_computers')['id']->getType()) === 'integer',
            'Invalid software companion refuses before ledger bootstrap, identifier widening or either assignment DDL: ' . $table
        );
        verify(
            $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]) === $invalidSource
            && $connection->fetchAllAssociative('SELECT * FROM glpi_documents_items WHERE id IN (9016,9017) ORDER BY id') === $originalDocuments
            && DomainsPluginSnapshot20261006::fingerprint(DomainsPluginSnapshot20261006::read($connection)) === $originalPlugin
            && $connection->fetchOne('SELECT itemtype FROM glpi_items_tickets WHERE id=9010') === 'PluginDomainsDomain'
            && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domains WHERE id >= 100000') === 0,
            'Failed software canonical preflight rolls back the complete Domain remap and preserves invalid source data: ' . $table
        );
        $connection->update($table, ['itemtype' => 'Computer'], ['id' => $id]);
    }
    $checkpoint('Invalid-data/scope/native storage audits and rollback-only canonical validation');
    refused(fn () => $history->upgrade($connection, static function (string $step): void {
        if (str_starts_with($step, 'Frozen Domains identity prerequisite committed')) {
            throw new RuntimeException('Injected remap receipt rollback');
        }
    }), 'Injected remap receipt rollback');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domains WHERE id >= 100000') === 0
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_documents_items WHERE id IN (9016,9017)') === 2, 'Receipt/DML failure rolls back the remap and document deletion together');
    if (!$postgres) {
        verify(Ledger::state($connection, DomainsPluginAdoption20261006::VERSION)['phase'] === 'validated', 'Bootstrap journal records validation without claiming data adoption complete');
    }
    refused(fn () => $history->upgrade($connection, static function (string $step): void {
        if (!str_starts_with($step, 'Frozen Domains identity prerequisite committed')) {
            throw new RuntimeException('Injected canonical DDL interruption');
        }
    }), 'Injected canonical DDL interruption');
    if (!$postgres) {
        $receipt = Ledger::state($connection, DomainsPluginAdoption20261006::VERSION);
        verify($receipt['complete'] && !$receipt['documents_restored'] && count($receipt['deferred_documents']) === 2
            && $receipt['timestamp_timezone'] === '+00:00' && $receipt['deferred_documents'][0]['original']['date_mod'] === '2026-01-02 03:04:05', 'MySQL committed prerequisite freezes complete UTC document rows before resumable nontransactional canonical DDL');
        verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_documents_items WHERE id IN (9016,9017)') === 0, 'Deferred rows stay outside the historical scalar-to-typed stage');
        $profile = $connection->fetchOne('SELECT helpdesk_item_type FROM glpi_profiles WHERE id=99');
        $connection->update('glpi_profiles', ['helpdesk_item_type' => json_encode(['nested' => ['PluginDomainsDomain']], JSON_THROW_ON_ERROR)], ['id' => 99]);
        refused(fn () => $migration->plan($connection), 'New frozen Domains helpdesk binding after committed adoption');
        $connection->update('glpi_profiles', ['helpdesk_item_type' => $profile], ['id' => 99]);
    }
    $connection->executeStatement($postgres ? "SET TIME ZONE '-05:00'" : "SET time_zone = '-05:00'");
    $checkpoint('Atomic remap receipt and canonical DDL interruption tests');
    $replayStarted = microtime(true);
    $history->upgrade($connection);
    echo 'Final supported populated history replay: ' . number_format(microtime(true) - $replayStarted, 3, '.', '') . "s\n";
    verify((new SchemaCheck())->differences($connection) === [] && History::pendingVersions($connection) === [], 'Populated frozen source adoption converges through complete canonical history');
    verify(Ledger::state($connection, Baseline20261001::VERSION)['origin'] === 'adopted'
        && Ledger::state($connection, Seeds20261001::VERSION)['data'] === 'preserved', 'Upgrade adopts baseline/seeds instead of replaying seeds');
    $receipt = Ledger::state($connection, DomainsPluginAdoption20261006::VERSION);
    verify($receipt['complete'] && $receipt['documents_restored'] && $receipt['source_plugin']['directory'] === 'domains' && $receipt['source_plugin']['state'] === 4, 'Canonical document restoration and source registration provenance use the same ledger');
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domains WHERE id BETWEEN 100010 AND 100012') === 3
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domaintypes WHERE id BETWEEN 100000 AND 100001') === 2, 'Duplicate names across source rows preserve separate stable IDs');
    verify($connection->fetchOne('SELECT name FROM glpi_domaintypes WHERE id=4294972901') === 'Already-wide historical type', 'Already-wide historical storage preserves imported identifiers above unsigned 32-bit range');
    verify((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_domains WHERE id=100010') === 9000
        && (int)$connection->fetchOne('SELECT suppliers_id FROM glpi_infocoms WHERE id=9011') === 9001
        && !(bool)$connection->fetchOne('SELECT is_helpdesk_visible FROM glpi_domains WHERE id=100011'), 'Direct registrar and financial supplier stay distinct while real false survives');
    verify($connection->fetchOne('SELECT comment FROM glpi_domains WHERE id=100010') === "UPDATE notes are data; O'Reilly 日本語", 'Historical text that resembles SQL preserves exact content');
    verify($connection->fetchOne('SELECT itemtype FROM glpi_logs WHERE id=9012') === 'Domain'
        && $connection->fetchOne('SELECT itemtype FROM glpi_logs WHERE id=9013') === 'PluginDomainsDomain'
        && $connection->fetchOne('SELECT itemtype_link FROM glpi_logs WHERE id=9014') === 'Domain', 'Audit subject and linked display labels preserve their different roles');
    verify((int)$connection->fetchOne('SELECT domains_id FROM glpi_items_tickets WHERE id=9010') === 100010
        && (int)$connection->fetchOne('SELECT computers_id FROM glpi_domains_items WHERE id=100100') === 9002
        && $connection->fetchOne('SELECT domainrelations_id FROM glpi_domains_items WHERE id=100100') === null, 'Ticket ownership and individual asset links preserve distinct optional category role');
    verify((float)$connection->fetchOne($instantSql) === (float)$instant
        && (int)$connection->fetchOne('SELECT domains_id FROM glpi_documents_items WHERE id=9016') === 100010
        && (int)$connection->fetchOne('SELECT items_id FROM glpi_documents_items WHERE id=9017') === 9005
        && $connection->fetchOne('SELECT users_id FROM glpi_documents_items WHERE id=9016') === null, 'Document IDs, native timestamp instants, generated subject projections and nullable actor survive different retry timezone');
    verify($connection->fetchOne('SELECT content FROM glpi_notepads WHERE id=9015') === "Full note 日本語\nNULL"
        && (int)$connection->fetchOne('SELECT state FROM glpi_plugins WHERE id=9007') === 1, 'Notes and unrelated plugin state are preserved');
    verify((int)$connection->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id=99 AND name='domaintype'") === 1
        && (int)$connection->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id=99 AND name='dropdown'") === 1
        && json_decode($connection->fetchOne('SELECT helpdesk_item_type FROM glpi_profiles WHERE id=99'), true, flags: JSON_THROW_ON_ERROR)['domain'] === 'Domain', 'Dedicated rights and exact encoded helpdesk membership preserve global authorization');
    $connection->update('glpi_domains', ['name' => 'Later user edit'], ['id' => 100010]);
    $connection->delete('glpi_documents_items', ['id' => 9017]);
    $history->upgrade($connection);
    verify($connection->fetchOne('SELECT name FROM glpi_domains WHERE id=100010') === 'Later user edit'
        && !$connection->fetchOne('SELECT 1 FROM glpi_documents_items WHERE id=9017'), 'Completed replay never rewrites edited imported rows or resurrects purged historical document links');
    $checkpoint('Full schema convergence, preserved application data and completed retry');
} finally {
    if ($savedTimezone !== null) {
        $connection->executeStatement('SET time_zone = ?', [$savedTimezone]);
    }
    $adapter->close();
    $parent->executeStatement('DROP DATABASE ' . $platform->quoteIdentifier($name));
}
echo $DB->getProvider() . ": frozen Domains prerequisite, ledgerless invalid graph, populated adoption, atomic receipts, UTC documents, nontransactional DDL retry and full schema convergence passed.\n";
