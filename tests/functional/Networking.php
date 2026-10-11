<?php

namespace tests\units;

use DbTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Entity;
use FQDN;
use IPAddress;
use IPAddress_IPNetwork;
use IPNetwork as Network;
use IPNetwork_Vlan;
use itsmng\Database\Entity\IPNetwork as NetworkRecord;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\Orm;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Database\Repository\IPNetworkRepository;
use mock\DBmysql as RebuildRouteAdapter;
use Netpoint;
use NetworkAlias;
use NetworkName;
use Plugin;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Vlan;

class IPNetwork extends DbTestCase
{
    public function testNetworkNameAndAliasWithIps()
    {
        $this->login();

        $fqdn = new FQDN();
        $domain = 'networking-' . strtolower($this->getUniqueString()) . '.example';
        $fqdns_id = (int)$fqdn->add([
           'name' => 'fqdn-' . $this->getUniqueString(),
           'entities_id' => $_SESSION['glpiactive_entity'],
           'fqdn' => $domain,
        ]);
        $this->integer($fqdns_id)->isGreaterThan(0);
        $this->boolean($fqdn->getFromDB($fqdns_id))->isTrue();
        $this->boolean($fqdn->can($fqdns_id, READ))->isTrue();

        $networkname = new NetworkName();
        $networknames_id = (int)$networkname->add([
           'name'         => 'host-' . strtolower($this->getUniqueString()),
           'entities_id' => $_SESSION['glpiactive_entity'],
           'fqdns_id'     => $fqdns_id,
           '_ipaddresses' => ['-1' => '10.42.0.10'],
        ]);
        $this->integer($networknames_id)->isGreaterThan(0);
        $this->boolean($networkname->getFromDB($networknames_id))->isTrue();
        $this->integer((int)$networkname->fields['fqdns_id'])->isEqualTo($fqdns_id);
        $this->string($networkname->fields['name'])->contains('host-');

        $this->integer((int)countElementsInTable(
            IPAddress::getTable(),
            [
                'itemtype' => 'NetworkName',
                'items_id' => $networknames_id,
                'name'     => '10.42.0.10',
            ]
        ))->isEqualTo(1);

        $alias = new NetworkAlias();
        $alias_id = (int)$alias->add([
           'networknames_id' => $networknames_id,
           'entities_id' => $_SESSION['glpiactive_entity'],
           'name'            => 'alias-' . strtolower($this->getUniqueString()),
           'fqdns_id'        => $fqdns_id,
        ]);
        $this->integer($alias_id)->isGreaterThan(0);
        $this->boolean($alias->getFromDB($alias_id))->isTrue();
        $this->string(NetworkAlias::getInternetNameFromID($alias_id))->contains($alias->fields['name']);

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $entities = $_SESSION['glpiactiveentities'];
        $this->output(static fn () => NetworkName::showForItem($fqdn))
            ->contains($networkname->fields['name'])->contains($alias->fields['name'])->contains('10.42.0.10');
        $this->integer(NetworkName::countForItem($fqdn))->isIdenticalTo(1);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $factories->getValue();
        try {
            $this->integer(NetworkName::countForItem($fqdn))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_networknames', ['is_deleted' => 1], ['id' => $networknames_id]))
                ->isIdenticalTo(1);
            $this->integer(NetworkName::countForItem($fqdn))->isIdenticalTo(0);
            $this->output(static fn () => NetworkName::getHTMLTableCellsForItem(null, $fqdn))->isEmpty();
            $this->integer($connection->update('glpi_networknames', ['is_deleted' => 0], ['id' => $networknames_id]))
                ->isIdenticalTo(1);
            $this->integer(NetworkName::countForItem($fqdn))->isIdenticalTo(1);
            $_SESSION['glpiactiveentities'] = [];
            $this->integer(NetworkName::countForItem($fqdn))->isIdenticalTo(0);
            $this->output(static fn () => NetworkName::getHTMLTableCellsForItem(null, $fqdn))->isEmpty();
        } finally {
            $_SESSION['glpiactiveentities'] = $entities;
            $connection->update('glpi_networknames', ['is_deleted' => 0], ['id' => $networknames_id]);
        }
        $this->integer($factories->getValue() - $before)->isIdenticalTo(0);

        // The FQDN tab has its own count/page reads and must see current aliases.
        $session = $_SESSION;
        $request = $_GET;
        $aliasName = $alias->fields['name'];
        $entityId = (int)$alias->fields['entities_id'];
        $outside = $this->createItem(Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => $entityId]);
        try {
            $_SESSION['glpiactiveentities'] = [$entityId];
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpilist_limit'] = 1;
            $_GET['start'] = 0;
            $_GET['order'] = 'alias';
            $this->output(static fn () => NetworkAlias::showForFQDN($fqdn, 0))->contains($aliasName);
            $connection->update('glpi_networkaliases', ['name' => 'fresh-alias', 'comment' => 'fresh-comment'], ['id' => $alias_id]);
            $this->output(static fn () => NetworkAlias::showForFQDN($fqdn, 0))
                ->contains('fresh-alias')->contains('fresh-comment');
            $_GET['start'] = 1;
            $this->output(static fn () => NetworkAlias::showForFQDN($fqdn, 0))->notContains('fresh-alias');
            $_GET['start'] = 0;
            foreach (['glpi_networkaliases' => $alias_id, 'glpi_networknames' => $networknames_id] as $table => $id) {
                $connection->update($table, ['entities_id' => $outside->getID()], ['id' => $id]);
                $this->output(static fn () => NetworkAlias::showForFQDN($fqdn, 0))
                    ->contains('No item found')->notContains('fresh-alias');
                $connection->update($table, ['entities_id' => $entityId], ['id' => $id]);
                $this->output(static fn () => NetworkAlias::showForFQDN($fqdn, 0))->contains('fresh-alias');
            }
        } finally {
            $_SESSION = $session;
            $_GET = $request;
            $connection->update('glpi_networkaliases', ['name' => $aliasName, 'comment' => $alias->fields['comment'], 'entities_id' => $entityId], ['id' => $alias_id]);
            $connection->update('glpi_networknames', ['entities_id' => $entityId], ['id' => $networknames_id]);
        }
    }

    public function testFqdnAndFqdnLabelValidation()
    {
        $this->login();

        $fqdn = new FQDN();
        $this->integer((int)$fqdn->add([
           'name' => 'invalid-fqdn-' . $this->getUniqueString(),
           'fqdn' => 'invalid..example',
        ]))->isEqualTo(0);
        $this->hasSessionMessages(ERROR, ['FQDN is not valid']);

        $networkname = new NetworkName();
        $this->integer((int)$networkname->add([
           'name' => '-badlabel',
        ]))->isEqualTo(0);
        $this->hasSessionMessages(ERROR, ['Invalid internet name: -badlabel']);
    }

    public function testIpNetworkAddAndRejectInvalid()
    {
        $this->login();

        $net = new Network();
        $suffix = (int)mt_rand(50, 200);
        $id = (int)$net->add([
           'name'       => 'net-' . $this->getUniqueString(),
           'entities_id' => 0,
           'network'    => "10.$suffix.20.0/24",
           'gateway'    => "10.$suffix.20.1",
           'addressable' => 1,
        ]);
        $this->integer($id)->isGreaterThan(0);

        $this->integer((int)$net->add([
           'name'       => 'invalid-net-' . $this->getUniqueString(),
           'entities_id' => 0,
           'network'    => "10.$suffix.20.0",
           'gateway'    => "10.$suffix.20.1",
        ]))->isEqualTo(0);
        $this->hasSessionMessages(ERROR, ['Invalid input format for the network']);
    }

    public function testIpAddressAutoLinksToIpNetwork()
    {
        $this->login();

        $suffix = (int)mt_rand(50, 200);
        $ipnetwork = new Network();
        $ipnetworks_id = (int)$ipnetwork->add([
           'name'       => 'autolink-net-' . $this->getUniqueString(),
           'entities_id' => 0,
           'network'    => "10.$suffix.30.0/24",
           'gateway'    => "10.$suffix.30.1",
        ]);
        $this->integer($ipnetworks_id)->isGreaterThan(0);

        $networkname = new NetworkName();
        $networknames_id = (int)$networkname->add([
           'name' => 'autolink-host-' . strtolower($this->getUniqueString()),
        ]);
        $this->integer($networknames_id)->isGreaterThan(0);

        $ipaddress = new IPAddress();
        $ipaddresses_id = (int)$ipaddress->add([
           'itemtype' => 'NetworkName',
           'items_id' => $networknames_id,
           'name'     => "10.$suffix.30.20",
        ]);
        $this->integer($ipaddresses_id)->isGreaterThan(0);

        $this->integer((int)countElementsInTable(
            IPAddress_IPNetwork::getTable(),
            [
                'ipaddresses_id' => $ipaddresses_id,
                'ipnetworks_id'  => $ipnetworks_id,
            ]
        ))->isEqualTo(1);

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $condition = [
            'address' => "10.$suffix.30.20", 'netmask' => '255.255.255.255',
            'fields' => ['id', 'name'], 'where' => ['id' => $ipnetworks_id],
        ];
        $initial = Network::searchNetworks('contains', $condition, 0, false);
        $this->array(array_map('intval', array_column($initial, 'id')))->isIdenticalTo([$ipnetworks_id]);
        $this->string($initial[0]['name'])->isIdenticalTo($ipnetwork->fields['name']);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $factories->getValue();
        try {
            $this->integer($connection->update('glpi_ipnetworks', ['name' => 'Current matching network'], ['id' => $ipnetworks_id]))
                ->isIdenticalTo(1);
            $current = Network::searchNetworks('contains', $condition, 0, false);
            $this->array(array_map('intval', array_column($current, 'id')))->isIdenticalTo([$ipnetworks_id]);
            $this->string($current[0]['name'])->isIdenticalTo('Current matching network');
            $this->array(Network::searchNetworks('contains', $condition + ['exclude IDs' => [$ipnetworks_id]], 0, false))
                ->isEmpty();
        } finally {
            $connection->update('glpi_ipnetworks', ['name' => $initial[0]['name']], ['id' => $ipnetworks_id]);
        }
        $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
    }

    public function testIpNetworkVlanAssignAndUnassign()
    {
        global $DB;

        $this->login();
        $this->setEntity(0, true);

        $suffix = (int)mt_rand(50, 200);
        $ipnetwork = new Network();
        $ipnetworks_id = (int)$ipnetwork->add([
           'name'       => 'vlan-net-' . $this->getUniqueString(),
           'entities_id' => 0,
           'network'    => "10.$suffix.40.0/24",
           'gateway'    => "10.$suffix.40.1",
        ]);
        $this->integer($ipnetworks_id)->isGreaterThan(0);

        $vlan = new Vlan();
        $vlans_id = (int)$vlan->add([
           'name' => 'vlan-' . $this->getUniqueString(),
           'tag'  => (int)mt_rand(200, 3500),
           'entities_id' => 0,
           'is_recursive' => 1,
           'comment' => null,
           'date_mod' => null,
           'date_creation' => null,
        ]);
        $this->integer($vlans_id)->isGreaterThan(0);

        $relation = new IPNetwork_Vlan();
        $relation_id = (int)$relation->assignVlan($ipnetworks_id, $vlans_id);
        $this->integer($relation_id)->isGreaterThan(0);
        $this->boolean($ipnetwork->can($ipnetworks_id, READ))->isTrue();
        $this->array(array_map('intval', IPNetwork_Vlan::getVlansForIPNetwork($ipnetworks_id)))
            ->isIdenticalTo([$vlans_id => $vlans_id]);
        $this->array(IPNetwork_Vlan::getVlansForIPNetwork(null))->isEmpty();
        $this->array(IPNetwork_Vlan::getVlansForIPNetwork('NULL'))->isEmpty();

        $other_network = $this->createItem(Network::class, [
            'name' => 'other-vlan-net-' . $this->getUniqueString(),
            'entities_id' => 0,
            'network' => sprintf(__('%1$s / %2$s'), "10.$suffix.41.0", '255.255.255.0'),
        ]);
        $other_relation = (int)$relation->assignVlan($other_network->getID(), $vlans_id);
        $this->integer($other_relation)->isGreaterThan(0);
        $read = static fn (): array => Orm::read($DB, static fn (EntityManager $em): array =>
            (new IPNetworkRepository($em))->vlansForNetwork($ipnetworks_id));
        $rows = $read();
        $this->integer(count($rows))->isIdenticalTo(1);
        $keys = array_keys($rows[0]);
        sort($keys);
        $expected_keys = ['assocID', 'id', 'entities_id', 'is_recursive', 'name', 'comment',
            'tag', 'date_mod', 'date_creation'];
        sort($expected_keys);
        $this->array($keys)->isIdenticalTo($expected_keys);
        $this->integer((int)$rows[0]['entities_id'])->isIdenticalTo(0);
        $this->integer((int)$rows[0]['is_recursive'])->isIdenticalTo(1);
        $this->variable($rows[0]['comment'])->isNull();
        $this->variable($rows[0]['date_mod'])->isNull();
        $this->variable($rows[0]['date_creation'])->isNull();
        $this->integer((int)$rows[0]['assocID'])->isIdenticalTo($relation_id);
        $this->integer((int)$rows[0]['id'])->isIdenticalTo($vlans_id);
        $this->string($rows[0]['name'])->isIdenticalTo($vlan->fields['name']);
        $this->integer((int)$rows[0]['tag'])->isIdenticalTo((int)$vlan->fields['tag']);
        $vlan_url = "/front/vlan.form.php?id=$vlans_id";
        $this->output(static fn () => IPNetwork_Vlan::showForIPNetwork($ipnetwork))
            ->contains($vlan_url);
        $fresh_name = 'fresh-vlan-' . $this->getUniqueString();
        $this->integer($DB->getDoctrineConnection()->update('glpi_vlans', [
            'name' => $fresh_name, 'comment' => 'fresh VLAN comment',
            'date_mod' => '2021-02-03 04:05:06', 'date_creation' => '2020-01-02 03:04:05',
        ], ['id' => $vlans_id]))
            ->isIdenticalTo(1);
        $fresh_rows = $read();
        $this->string($fresh_rows[0]['name'])->isIdenticalTo($fresh_name);
        $this->string($fresh_rows[0]['comment'])->isIdenticalTo('fresh VLAN comment');
        $this->string($fresh_rows[0]['date_mod'])->isIdenticalTo('2021-02-03 04:05:06');
        $this->string($fresh_rows[0]['date_creation'])->isIdenticalTo('2020-01-02 03:04:05');
        $this->variable($rows[0]['comment'])->isNull();
        $this->variable($rows[0]['date_mod'])->isNull();
        $this->variable($rows[0]['date_creation'])->isNull();
        $this->string($rows[0]['name'])->isIdenticalTo($vlan->fields['name']);
        $this->output(static fn () => IPNetwork_Vlan::showForIPNetwork($ipnetwork))->contains($fresh_name);

        $this->boolean($relation->unassignVlan($ipnetworks_id, $vlans_id))->isTrue();
        $this->integer((int)countElementsInTable(
            IPNetwork_Vlan::getTable(),
            [
                'ipnetworks_id' => $ipnetworks_id,
                'vlans_id'      => $vlans_id,
            ]
        ))->isEqualTo(0);
        $this->array(IPNetwork_Vlan::getVlansForIPNetwork($ipnetworks_id))->isEmpty();
        $this->array($read())->isEmpty();
        $this->array(array_map('intval', IPNetwork_Vlan::getVlansForIPNetwork($other_network->getID())))
            ->isIdenticalTo([$vlans_id => $vlans_id]);
        $this->output(static fn () => IPNetwork_Vlan::showForIPNetwork($ipnetwork))->notContains($vlan_url);
    }

    public function testNetpointExecuteAddMulti()
    {
        $this->login();
        $this->setEntity(0, true);

        $locations_id = getItemByTypeName('Location', '_location01', true);
        $prefix = 'netpoint-' . strtolower($this->getUniqueString()) . '-';
        $netpoint = new Netpoint();

        $netpoint->executeAddMulti([
            'entities_id'  => 0,
            'locations_id' => $locations_id,
            '_before'      => $prefix,
            '_after'       => '',
            '_from'        => 1,
            '_to'          => 3,
        ]);

        foreach ([1, 2, 3] as $index) {
            $this->integer((int)countElementsInTable(
                Netpoint::getTable(),
                [
                    'name'         => $prefix . $index,
                    'locations_id' => $locations_id,
                    'entities_id'  => 0,
                ]
            ))->isEqualTo(1);
        }
    }

    public function testNetworkTreeRebuildRetainsDomainValuesAndDirtyOwners(): void
    {
        global $DB, $GLPI_CACHE;
        $this->login();
        $this->setEntity(0, true);
        $networks = $this->createRebuildNetworks();
        $ids = array_map(static fn (Network $network): int => (int)$network->getID(), $networks);
        $connection = $DB->getDoctrineConnection();
        $host = new NetworkName();
        $hostId = (int)$host->add(['name' => 'rebuild-host-' . strtolower($this->getUniqueString()), 'entities_id' => 0]);
        $this->integer($hostId)->isGreaterThan(0);
        $address = new IPAddress();
        $parts = explode('.', $networks[0]->fields['address']);
        $parts[3] = '20';
        $addressId = (int)$address->add(['itemtype' => NetworkName::class, 'items_id' => $hostId, 'name' => implode('.', $parts)]);
        $this->integer($addressId)->isGreaterThan(0);
        $links = $connection->fetchAllAssociative('SELECT * FROM glpi_ipaddresses_ipnetworks WHERE ipaddresses_id=? ORDER BY id', [$addressId]);
        $this->array(array_map('intval', array_column($links, 'ipnetworks_id')))->contains($ids[0])->contains($ids[1]);
        $domainColumns = 'id, entities_id, name, version, address, netmask, gateway, addressable';
        $domain = $connection->fetchAllAssociative('SELECT ' . $domainColumns . ' FROM glpi_ipnetworks WHERE id IN (?, ?, ?) ORDER BY id', $ids);
        foreach ($ids as $id) {
            $connection->update('glpi_ipnetworks', ['ipnetworks_id' => null, 'level' => 1, 'completename' => 'Broken tree'], ['id' => $id]);
            $GLPI_CACHE->set('ancestors_cache_glpi_ipnetworks_' . $id, ['stale']);
            $GLPI_CACHE->set('sons_cache_glpi_ipnetworks_' . $id, ['stale']);
        }
        $GLPI_CACHE->set('ancestors_cache_glpi_ipnetworks_0', ['stale']);
        $GLPI_CACHE->set('sons_cache_glpi_ipnetworks_0', ['stale']);
        $independent = Orm::create($DB);
        $pending = $independent->find(NetworkRecord::class, $ids[0]);
        $pending->comment = 'Unflushed independent rebuild owner';
        try {
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($ids, $connection, $domainColumns, $domain): void {
                    $dirty = $outer->find(NetworkRecord::class, $ids[1]);
                    $dirty->comment = 'Unflushed enclosing rebuild owner';
                    Network::recreateTree();
                    $this->boolean($outer->contains($dirty))->isTrue();
                    $this->string($dirty->comment)->isIdenticalTo('Unflushed enclosing rebuild owner');
                    $this->array($connection->fetchAllAssociative('SELECT ' . $domainColumns . ' FROM glpi_ipnetworks WHERE id IN (?, ?, ?) ORDER BY id', $ids))
                        ->isIdenticalTo($domain);
                }
            );
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_ipaddresses_ipnetworks WHERE ipaddresses_id=? ORDER BY id', [$addressId]))
                ->isIdenticalTo($links);
            $this->boolean($independent->contains($pending))->isTrue();
            $this->string($pending->comment)->isIdenticalTo('Unflushed independent rebuild owner');
            $rows = $connection->fetchAllAssociative('SELECT id, ipnetworks_id, level, completename FROM glpi_ipnetworks WHERE id IN (?, ?, ?) ORDER BY id', $ids);
            $this->variable($rows[0]['ipnetworks_id'])->isNull();
            $this->integer((int)$rows[0]['level'])->isIdenticalTo(1);
            $this->integer((int)$rows[1]['ipnetworks_id'])->isIdenticalTo($ids[0]);
            $this->integer((int)$rows[1]['level'])->isIdenticalTo(2);
            $this->string($rows[1]['completename'])->isIdenticalTo($networks[0]->fields['name'] . ' > ' . $networks[1]->fields['name']);
            $this->variable($rows[2]['ipnetworks_id'])->isNull();
            foreach ([0, ...$ids] as $id) {
                $this->variable($GLPI_CACHE->get('ancestors_cache_glpi_ipnetworks_' . $id))->isNull();
                $this->variable($GLPI_CACHE->get('sons_cache_glpi_ipnetworks_' . $id))->isNull();
            }
            $this->boolean($networks[1]->update(['id' => $ids[1], 'name' => 'Current rebuilt child']))->isTrue();
            Network::recreateTree();
            $this->string($connection->fetchOne('SELECT completename FROM glpi_ipnetworks WHERE id=?', [$ids[1]]))
                ->isIdenticalTo($networks[0]->fields['name'] . ' > Current rebuilt child');
            $stable = $connection->fetchAllAssociative('SELECT id, ipnetworks_id, level, completename FROM glpi_ipnetworks WHERE id IN (?, ?, ?) ORDER BY id', $ids);
            Network::recreateTree();
            $this->array($connection->fetchAllAssociative('SELECT id, ipnetworks_id, level, completename FROM glpi_ipnetworks WHERE id IN (?, ?, ?) ORDER BY id', $ids))
                ->isIdenticalTo($stable);
            $this->variable($connection->fetchOne('SELECT comment FROM glpi_ipnetworks WHERE id=?', [$ids[0]]))
                ->isNotIdenticalTo('Unflushed independent rebuild owner');
            $this->variable($connection->fetchOne('SELECT comment FROM glpi_ipnetworks WHERE id=?', [$ids[1]]))
                ->isNotIdenticalTo('Unflushed enclosing rebuild owner');
        } finally {
            $independent->clear();
        }
    }

    public function testNetworkTreeRebuildCancellationAndRouteChangesRetainOwnedFrame(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity(0, true);
        $networks = $this->createRebuildNetworks();
        $ids = array_map(static fn (Network $network): int => (int)$network->getID(), $networks);
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $scope = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $source = $connection->fetchAllAssociative('SELECT * FROM glpi_ipnetworks ORDER BY id');
        $audit = $connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id');
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $this->mockGenerator()->orphanize('__construct');
        $alternate = new RebuildRouteAdapter();
        $this->calling($alternate)->getDoctrineConnection = $connection;
        try {
            $plugins->setValue(null, [...$active, 'network_rebuild_fixture']);
            foreach (['cancel', 'route'] as $mode) {
                $visited = [];
                $PLUGIN_HOOKS['pre_item_update']['network_rebuild_fixture'][Network::class] =
                    static function (Network $network) use ($ids, $mode, $alternate, &$visited): void {
                        if (!in_array((int)$network->getID(), $ids, true)) {
                            return;
                        }
                        $visited[] = (int)$network->getID();
                        if ((int)$network->getID() === $ids[2]) {
                            if ($mode === 'cancel') {
                                $network->input = false;
                            } else {
                                $GLOBALS['DB'] = $alternate;
                            }
                        }
                    };
                $failure = null;
                try {
                    Network::recreateTree();
                } catch (Throwable $error) {
                    $failure = $error;
                } finally {
                    $DB = $database;
                }
                $this->array($visited)->isIdenticalTo($ids);
                $this->object($failure)->isInstanceOf($mode === 'cancel' ? RuntimeException::class : TransactionOwnershipMismatch::class);
                if ($mode === 'cancel') {
                    $this->string($failure->getMessage())->isIdenticalTo('Unable to rebuild IP network tree at ' . $ids[2]);
                }
                $scope->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_ipnetworks ORDER BY id'))->isIdenticalTo($source);
                $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_logs ORDER BY id'))->isIdenticalTo($audit);
            }
            unset($PLUGIN_HOOKS['pre_item_update']['network_rebuild_fixture'][Network::class]);
            Network::recreateTree();
            $scope->assertActive();
            $this->integer((int)$connection->fetchOne('SELECT ipnetworks_id FROM glpi_ipnetworks WHERE id=?', [$ids[1]]))
                ->isIdenticalTo($ids[0]);
        } finally {
            $DB = $database;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testNetworkTreeRebuildRejectsGetterAndNestedFrameReplacement(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity(0, true);
        $network = $this->createRebuildNetworks()[0];
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $scope = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $source = $connection->fetchAllAssociative('SELECT * FROM glpi_ipnetworks ORDER BY id');
        $getters = 0;
        $this->mockGenerator()->orphanize('__construct');
        $routed = new RebuildRouteAdapter();
        $this->calling($routed)->getDoctrineConnection = static function () use ($database, $connection, &$getters): Connection {
            ++$getters;
            $GLOBALS['DB'] = $database;
            return $connection;
        };
        try {
            $DB = $routed;
            $failure = null;
            try {
                Network::recreateTree();
            } catch (Throwable $error) {
                $failure = $error;
            }
            $this->object($failure)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->integer($getters)->isIdenticalTo(1);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_ipnetworks ORDER BY id'))->isIdenticalTo($source);
        } finally {
            $DB = $database;
        }
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $replacement = null;
        try {
            $plugins->setValue(null, [...$active, 'network_rebuild_frame_fixture']);
            $PLUGIN_HOOKS['pre_item_update']['network_rebuild_frame_fixture'][Network::class] =
                static function (Network $item) use ($network, $connection, &$replacement): void {
                    if ((int)$item->getID() === (int)$network->getID() && $replacement === null) {
                        $connection->beginTransaction();
                        $replacement = $connection->captureManagedTransactionScope();
                    }
                };
            $failure = null;
            try {
                Network::recreateTree();
            } catch (Throwable $error) {
                $failure = $error;
            }
            $this->object($failure)->isInstanceOf(MutationRollbackFailure::class);
            $this->object($failure->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->object($failure->cleanup)->isInstanceOf(TransactionOwnershipMismatch::class);
            $replacement->assertActive();
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 2);
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        }
        $scope->assertActive();
        $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_ipnetworks ORDER BY id'))->isIdenticalTo($source);
        Network::recreateTree();
    }

    private function createRebuildNetworks(): array
    {
        $suffix = mt_rand(1, 250);
        $prefix = 'rebuild-' . $this->getUniqueString();
        $definitions = [
            [$prefix . '-parent', '198.18.' . $suffix . '.0/24'],
            [$prefix . '-child', '198.18.' . $suffix . '.0/25'],
            [$prefix . '-ipv6', 'fdab:' . dechex($suffix) . '::/48'],
        ];
        $networks = [];
        foreach ($definitions as [$name, $cidr]) {
            $network = new Network();
            $id = (int)$network->add(['name' => $name, 'entities_id' => 0, 'network' => $cidr, 'gateway' => '']);
            $this->integer($id)->isGreaterThan(0);
            $this->boolean($network->getFromDB($id))->isTrue();
            $this->string($network->fields['name'])->isIdenticalTo($name);
            $networks[] = $network;
        }
        return $networks;
    }

}
