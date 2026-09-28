<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/network-search.php /path/to/test-config\n");
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
$connection = $DB->getDoctrineConnection();
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $scope = (new Entity())->add(['name' => 'Network scope', 'entities_id' => 0]);
    $outside = (new Entity())->add(['name' => 'Outside networks', 'entities_id' => 0]);
    $network = static function (string $address, int $prefix, int $entity) use ($fixtures): int {
        $ip = new IPAddress($address);
        $mask = new IPNetmask((string)$prefix, $ip->getVersion());
        $values = ['entities_id' => $entity, 'name' => $address . '/' . $prefix];
        $values = $ip->setArrayFromAddress($values, 'version', 'address', 'address');
        $values = $mask->setArrayFromAddress($values, '', 'netmask', 'netmask');
        return $fixtures->create('glpi_ipnetworks', $values);
    };
    $broad4 = $network('10.211.0.0', 16, $scope);
    $near4 = $network('10.211.4.0', 24, $scope);
    $network('10.212.4.0', 24, $scope);
    $network('10.211.4.0', 24, $outside);
    verify(IPNetwork::searchNetworksContainingIP('10.211.4.8', $scope, false) === [$near4, $broad4], 'IPv4 nearest first and entity scope');
    $broad6 = $network('2001:db8::', 32, $scope);
    $near6 = $network('2001:db8:abcd:1::', 64, $scope);
    $network('2002:db8:abcd:1::', 64, $scope);
    $network('2001:db8:abcd:2::', 64, $scope);
    $network('2001:db8:abcd:1:1234:5678::', 96, $scope);
    $network('2001:db8:abcd:1::', 64, $outside);
    verify(IPNetwork::searchNetworksContainingIP('2001:db8:abcd:1::8', $scope, false) === [$near6, $broad6], 'IPv6 checks all four words');
    verify(IPNetwork::searchNetworks('equals', ['address' => '2001:db8:abcd:1::', 'netmask' => '64'], $scope, false) === [$near6], 'IPv6 exact network');
    verify(IPNetwork::searchNetworks('is contained by', ['address' => '10.211.0.0', 'netmask' => '16'], $scope, false) === [$broad4, $near4], 'Contained networks broad first');
    verify(IPNetwork::searchNetworks('contains', ['address' => '10.211.4.8', 'netmask' => '32', 'exclude IDs' => [$near4]], $scope, false) === [$broad4], 'Excluded network IDs');
    $rows = IPNetwork::searchNetworksContainingIP('10.211.4.8', $scope, false, ['id', 'entities_id'], ['id' => $near4]);
    verify($rows === [['id' => $near4, 'entities_id' => (int)$scope]], 'Mapped projections and structured filters');
    $root = $network('172.22.0.0', 16, 0);
    verify(IPNetwork::searchNetworksContainingIP('172.22.2.4', $scope) === [$root], 'Contains includes ancestor entities');
    verify(IPNetwork::searchNetworksContainingIP('172.22.2.4', $scope, false) === [], 'Nonrecursive excludes ancestor entities');
    verify(IPNetwork::searchNetworks('equals', ['address' => '10.211.4.0', 'netmask' => '24'], $scope, false, 6) === false, 'Version mismatch');
    $rejected = false;
    try {
        IPNetwork::searchNetworksContainingIP('10.211.4.8', $scope, false, 'id', '1 = 1');
    } catch (InvalidArgumentException $error) {
        $rejected = true;
    }
    verify($rejected, 'Raw SQL filters are refused');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    IPNetwork::searchNetworksContainingIP('2001:db8:abcd:1::8', $scope, false);
    verify($SQL_TOTAL_REQUEST === 0, 'Network matching executes through ORM');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped IPv4/IPv6 network matching, ordering and scope passed.\n";
