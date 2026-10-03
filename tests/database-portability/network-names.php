<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\NetworkNameReferences;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/network-names.php /path/to/test-config\n");
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
    foreach (ReferenceHistory::get('optional', 'NETWORK_NAMES') as $table => $relations) {
        $domain = $fixtures->create('glpi_fqdns');
        $replacement = $fixtures->create('glpi_fqdns');
        $child = $fixtures->create($table, ['fqdns_id' => $domain]);
        verify((new FQDN())->delete(['id' => $domain, '_replace_by' => $replacement], true), 'Replace domain');
        verify((int)$read($table, $child)['fqdns_id'] === $replacement, 'Domain reference reassigned');
        verify((new FQDN())->delete(['id' => $replacement], true), 'Purge replacement domain');
        verify($read($table, $child)['fqdns_id'] === null, 'Domain purge preserves its label');
    }
    $domain = $fixtures->create('glpi_fqdns', ['fqdn' => 'orm.example.invalid']);
    $entity = (new Entity())->add(['name' => 'Network name scope']);
    $connection->update('glpi_fqdns', ['entities_id' => $entity], ['id' => $domain]);
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $names = [];
    foreach (['alpha', 'beta', 'gamma'] as $label) {
        $names[$label] = $fixtures->create('glpi_networknames', ['name' => $label, 'fqdns_id' => $domain, 'entities_id' => $entity]);
    }
    $hidden = $fixtures->create('glpi_networknames', ['name' => 'hidden', 'fqdns_id' => $domain]);
    $fixtures->create('glpi_networknames', ['name' => 'deleted', 'fqdns_id' => $domain, 'entities_id' => $entity, 'is_deleted' => true]);
    $aliases = [];
    foreach ([['zeta', 'alpha'], ['delta', 'alpha'], ['charlie', 'beta']] as [$label, $target]) {
        $aliases[$label] = $fixtures->create('glpi_networkaliases', ['name' => $label, 'networknames_id' => $names[$target], 'fqdns_id' => $domain, 'entities_id' => $entity]);
    }
    $fixtures->create('glpi_networkaliases', ['name' => 'foreign', 'networknames_id' => $hidden, 'fqdns_id' => $domain, 'entities_id' => $entity]);
    $fixtures->create('glpi_networkaliases', ['name' => 'hidden-alias', 'networknames_id' => $names['gamma'], 'fqdns_id' => $domain]);
    foreach ([['alpha', 2, 0], ['alpha', 1, 9], ['beta', 1, 5]] as [$label, $high, $low]) {
        $fixtures->create('glpi_ipaddresses', ['name' => 'address-' . $high . '-' . $low, 'items_id' => $names[$label], 'itemtype' => 'NetworkName', 'binary_3' => $high, 'binary_2' => $low]);
    }
    $fixtures->create('glpi_ipaddresses', ['name' => 'deleted', 'items_id' => $names['gamma'], 'itemtype' => 'NetworkName', 'is_deleted' => true]);
    $repo = new \itsmng\Database\Repository\NetworkNameRepository(Orm::create($DB));
    verify($repo->countForItem('FQDN', $domain, [$entity]) === 3, 'Domain count excludes hidden and deleted names');
    verify($repo->identifiersForItem('FQDN', $domain, 'name', 1, 1, [$entity]) === [$names['beta']], 'Name pagination');
    foreach (['alias', 'ip'] as $order) {
        verify($repo->identifiersForItem('FQDN', $domain, $order, null, 0, [$entity]) === [$names['beta'], $names['alpha'], $names['gamma']], 'One name per slot with complete first sort value: ' . $order);
        verify($repo->identifiersForItem('FQDN', $domain, $order, 1, 1, [$entity]) === [$names['alpha']], 'Stable second page: ' . $order);
    }
    verify($repo->identifiersForItem('FQDN', $domain, 'ip', null, 0, []) === [], 'Empty entity scope denies names');
    verify($repo->countAliasesForDomain($domain, [$entity]) === 3, 'Alias count matches visible aliases and targets');
    $rows = $repo->aliasesForDomain($domain, 'realname', 1, 0, [$entity]);
    verify(count($rows) === 1 && (int)$rows[0]['address_id'] === $names['alpha'], 'Alias sort by real name');
    $rows = $repo->aliasesForDomain($domain, 'alias', 1, 0, [$entity]);
    verify((int)$rows[0]['alias_id'] === $aliases['charlie'], 'Alias sort and pagination');
    $equipment = $fixtures->create('glpi_networkequipments', ['entities_id' => $entity]);
    $port = $fixtures->create('glpi_networkports', ['items_id' => $equipment, 'itemtype' => 'NetworkEquipment', 'entities_id' => $entity]);
    verify(NetworkName::affectAddress($names['alpha'], $port, 'NetworkPort'), 'Attach mapped name');
    verify($repo->countForItem('NetworkEquipment', $equipment, [$entity]) === 1, 'Equipment name count');
    verify($repo->identifiersForItem('NetworkPort', $port, 'name', null, 0, [$entity]) === [$names['alpha']], 'Port name list');
    $connection->update('glpi_networkports', ['is_deleted' => $DB->getProvider() === 'pgsql' ? true : 1], ['id' => $port], ['is_deleted' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
    verify($repo->countForItem('NetworkEquipment', $equipment, [$entity]) === 0, 'Deleted port names excluded');
    NetworkName::unaffectAddressesOfItem($port, 'NetworkPort');
    verify((int)$read('glpi_networknames', $names['alpha'])['items_id'] === 0, 'Detach preserves name and aliases');
    verify(FQDN::getFQDNIDByFQDN('ORM.EXAMPLE.INVALID') === $domain, 'Mapped exact FQDN lookup');
    verify(in_array($domain, FQDN::getFQDNIDByFQDN('orm.example.*', true), true), 'Mapped wildcard FQDN lookup');
    $ids = FQDNLabel::getIDsByLabelAndFQDNID('ALPHA', $domain);
    verify($ids['NetworkName'] === [$names['alpha']], 'Mapped label lookup');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    $repo->identifiersForItem('FQDN', $domain, 'ip', 2, 0, [$entity]);
    $repo->aliasesForDomain($domain, 'alias', 2, 0, [$entity]);
    FQDNLabel::getIDsByLabelAndFQDNID('a*', $domain, true);
    FQDN::getFQDNIDByFQDN('orm.example.invalid');
    verify($SQL_TOTAL_REQUEST === 0, 'Network name queries use ORM');
    $fqdn = new FQDN();
    verify($fqdn->getFromDB($domain), 'Load domain');
    $_GET['order'] = 'ip';
    $_GET['start'] = 0;
    ob_start();
    NetworkName::showForItem($fqdn);
    $html = ob_get_clean();
    verify(str_contains($html, 'alpha') && !str_contains($html, 'hidden-alias'), 'Domain name view renders scoped names');
    $_GET['order'] = 'realname';
    ob_start();
    NetworkAlias::showForFQDN($fqdn, 0);
    $html = ob_get_clean();
    verify(str_contains($html, 'charlie') && !str_contains($html, 'hidden-alias'), 'Alias view renders scoped rows');
    $label = new NetworkName();
    verify($label->getFromDB($names['alpha']), 'Load name');
    ob_start();
    NetworkAlias::showForNetworkName($label);
    $html = ob_get_clean();
    verify(str_contains($html, 'delta'), 'Name alias list renders mapped rows');
    verify((new NetworkName())->delete(['id' => $names['alpha']], true), 'Name purge removes required aliases');
    verify($read('glpi_networkaliases', $aliases['delta']) === null, 'Alias child removed');
    verify((new ForeignKeys())->audit($connection) === [], 'Name graph remains valid');
} finally {
    $DB->rollBack();
}
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$migration = new NetworkNameReferences();
$legacy = null;
try {
    foreach (ReferenceHistory::get('optional', 'NETWORK_NAMES') as $table => $relations) {
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
    $legacy = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_networknames');
    // An unattached legacy network name has an explicit empty subject discriminator.
    $connection->insert('glpi_networknames', ['id' => $legacy, 'name' => 'legacy-name', 'itemtype' => '']);
    verify($migration->plan($connection)['sql'] !== [], 'Legacy network name migration has a plan');
    verify((int)$connection->fetchOne('SELECT fqdns_id FROM glpi_networknames WHERE id = ?', [$legacy]) === 0, 'Plan preserves data');
    $connection->update('glpi_networknames', ['fqdns_id' => 2147483647], ['id' => $legacy]);
    $rejected = false;
    try {
        $migration->apply($connection);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'Nonzero orphaned network name');
    }
    verify($rejected && $connection->createSchemaManager()->listTableColumns('glpi_networknames')['fqdns_id']->getNotnull(), 'Orphan rejected before DDL');
    $connection->update('glpi_networknames', ['fqdns_id' => 0], ['id' => $legacy]);
    $migration->apply($connection);
    verify($connection->fetchOne('SELECT fqdns_id FROM glpi_networknames WHERE id = ?', [$legacy]) === null, 'Legacy outlet becomes NULL');
    verify($migration->apply($connection) === [], 'Network name migration is idempotent');
} finally {
    if ($legacy !== null) {
        $connection->delete('glpi_networknames', ['id' => $legacy]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
}
echo $DB->getProvider() . ": network names, aliases, scoped pagination, lifecycle and migration passed.\n";
