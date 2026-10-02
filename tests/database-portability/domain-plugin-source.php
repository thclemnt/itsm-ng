<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Domain\DomainPluginSnapshot;
use itsmng\Domain\DomainPluginSource;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-plugin-source.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
require GLPI_ROOT . '/vendor/autoload.php';
require GLPI_ROOT . '/inc/dbadapter.class.php';
require GLPI_ROOT . '/inc/dbpgsql.class.php';
require GLPI_ROOT . '/inc/dbmysql.class.php';
require $directory . '/config_db.php';
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
$DB = (new ReflectionClass(DB::class))->newInstanceWithoutConstructor();
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated source database required');
$host = $DB->dbhost;
$port = $DB instanceof DBpgsql ? $DB->dbport : null;
if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $parts) || preg_match('/^([^:]+):(\d+)$/', $host, $parts)) {
    [, $host, $port] = $parts;
}
$connection = $DB instanceof DBpgsql
    ? Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $host, 'port' => $port,
        'user' => $DB->dbuser, 'password' => rawurldecode($DB->dbpassword), 'dbname' => $DB->dbdefault])
    : itsmng\Database\InstallationConnection::mysqlDatabase($DB->dbhost, $DB->dbuser, rawurldecode($DB->dbpassword), $DB->dbdefault);
$manager = $connection->createSchemaManager();
$tables = DomainsPlugin210Export::tables(true);
$created = [];
try {
    foreach ($tables as $table) {
        verify(!$manager->tablesExist([$table->getName()]), 'Own source fixture table ' . $table->getName());
        $manager->createTable($table);
        $created[] = $table->getName();
    }
    $base = 4294971000;
    $connection->insert('glpi_plugin_domains_domaintypes', ['id' => $base, 'entities_id' => 0, 'name' => "O'Reilly 日本語\nNULL", 'comment' => null, 'is_recursive' => 1]);
    $connection->insert('glpi_plugin_domains_domains', ['id' => $base + 1, 'name' => 'NULL', 'plugin_domains_domaintypes_id' => $base, 'date_creation' => '2026-10-01', 'date_expiration' => null,
        'comment' => "C:\\new 日本語\nLiteral null", 'others' => '', 'is_helpdesk_visible' => 0]);
    $connection->insert('glpi_plugin_domains_domains_items', ['id' => $base + 2, 'plugin_domains_domains_id' => $base + 1, 'items_id' => $base + 3, 'itemtype' => 'Computer']);
    $connection->insert('glpi_plugin_domains_configs', ['id' => 1, 'delay_expired' => '30', 'delay_whichexpire' => '45']);
    $before = $connection->createSchemaManager()->introspectSchema();
    $source = new DomainPluginSource($connection);
    $snapshot = $source->read();
    verify($snapshot->counts() === ['types' => 1, 'domains' => 1, 'items' => 1, 'configs' => 1], 'Complete pinned layout');
    verify((string)$snapshot->domains[0]['id'] === (string)($base + 1), 'Wide source identity preserved');
    verify($snapshot->domains[0]['name'] === 'NULL' && $snapshot->domains[0]['others'] === '' && $snapshot->domains[0]['date_expiration'] === null, 'Literal NULL, empty and SQL NULL are distinct');
    $stringified = array_map(static fn ($rows) => array_map(static fn ($row) => array_map(static fn ($v) => $v === null ? null : (string)$v, $row), $rows), [$snapshot->types, $snapshot->domains, $snapshot->items, $snapshot->configs]);
    $same = new DomainPluginSnapshot(...$stringified);
    verify($same->fingerprint() === $snapshot->fingerprint(), 'Driver scalar representations do not change frozen export fingerprint');
    verify($snapshot->fingerprint() === hash('sha256', json_encode([DomainPluginSnapshot::FORMAT, $stringified], JSON_THROW_ON_ERROR)), 'Independent frozen fingerprint algorithm');
    $records = iterator_to_array($snapshot->records(), false);
    verify($records[0][0] === 'DomainType' && $records[1][0] === 'Domain' && $records[2][0] === 'Domain_Item', 'Dependency ordering and correct singular plugin identity');
    verify($records[1][1]['domaintypes_id'] === $snapshot->domains[0]['plugin_domains_domaintypes_id'], 'Type ownership remapped without adopting names');
    verify($records[2][1]['domains_id'] === $snapshot->items[0]['plugin_domains_domains_id'] && $records[2][1]['domainrelations_id'] === null, 'Asset owner distinct from category relationship');
    verify($manager->createComparator()->compareSchemas($before, $manager->introspectSchema())->isEmpty(), 'Source reading cannot rewrite schema');
    verify($source->read()->fingerprint() === $snapshot->fingerprint(), 'Source reading does not mutate data');
    $connection->update('glpi_plugin_domains_domains', ['comment' => 'Changed export'], ['id' => $base + 1]);
    verify($source->read()->fingerprint() !== $snapshot->fingerprint(), 'Changed historical export changes fingerprint');
    $connection->executeStatement('ALTER TABLE glpi_plugin_domains_domains ADD notepad TEXT');
    refused(fn () => $source->read(), 'unexpected=notepad');
    $connection->executeStatement('ALTER TABLE glpi_plugin_domains_domains DROP COLUMN notepad');
    $profile = new Doctrine\DBAL\Schema\Table('glpi_plugin_domains_profiles');
    $profile->addColumn('id', 'integer');
    $manager->createTable($profile);
    $created[] = $profile->getName();
    refused(fn () => $source->read(), 'glpi_plugin_domains_profiles remains');
    $manager->dropTable($profile->getName());
    array_pop($created);
    $manager->dropTable('glpi_plugin_domains_configs');
    array_pop($created);
    refused(fn () => $source->read(), 'Missing Domains plugin source table: glpi_plugin_domains_configs');
} finally {
    foreach (array_reverse($created) as $table) {
        $manager->dropTable($table);
    }
}
echo $DB->getProvider() . ": frozen Domains plugin source, fingerprint, exact layout, ownership roles and read-only export passed.\n";
