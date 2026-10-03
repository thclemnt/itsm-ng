<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Source policy contract: no application bootstrap or physical connection.
define('GLPI_ROOT', dirname(__DIR__, 2));
require GLPI_ROOT . '/vendor/autoload.php';

use itsmng\Database\MySQLConnection;
use itsmng\Database\MySQLManagedConnection;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$base = ['driver' => 'pdo_mysql', 'host' => 'example.invalid', 'user' => 'fixture', 'password' => 'not-used'];
$parameters = MySQLConnection::parameters($base);
verify($parameters['driver'] === 'pdo_mysql' && $parameters['wrapperClass'] === MySQLManagedConnection::class, 'Canonical PDO driver and managed DBAL owner');
verify($parameters['driverOptions'][PDO::ATTR_EMULATE_PREPARES] === false
    && $parameters['driverOptions'][PDO::ATTR_STRINGIFY_FETCHES] === false
    && $parameters['driverOptions'][PDO::ATTR_PERSISTENT] === false, 'Native prepared/numeric values and independent nonpersistent owner');
verify(MySQLConnection::parameters($parameters) === $parameters, 'Normalized supplied owner parameters remain idempotent');
foreach ([
    ['driver' => 'mysqli'], ['driverClass' => stdClass::class], ['wrapperClass' => stdClass::class], ['persistent' => true],
    ['ssl' => 'yes'], ['ssl_verify_server_cert' => 0], ['ssl_unknown' => 'unhandled'],
    ['ssl_key' => ['invalid']], ['ssl_ca' => '/configured/ca.pem'],
    ['driverOptions' => 'invalid'], ['driverOptions' => [123456789 => true]],
    ['driverOptions' => [PDO::ATTR_EMULATE_PREPARES => true]],
    ['driverOptions' => [PDO::ATTR_STRINGIFY_FETCHES => true]],
    ['driverOptions' => [PDO::ATTR_PERSISTENT => true]],
    ['driverOptions' => [PDO::ATTR_TIMEOUT => -1]],
] as $invalid) {
    try {
        MySQLConnection::create(array_replace($base, $invalid));
        throw new LogicException('Unknown or contradictory transport policy accepted before lazy connect');
    } catch (InvalidArgumentException) {
        verify(true, 'Unsupported configuration is diagnosed before any connect');
    }
}
verify(defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'), 'PDO MySQL verification option must exist in the supported environment');
$tls = $base + ['ssl' => true, 'ssl_verify_server_cert' => true, 'ssl_key' => '/configured/key.pem',
    'ssl_cert' => '/configured/cert.pem', 'ssl_ca' => '/configured/ca.pem', 'ssl_capath' => '/configured/cas', 'ssl_cipher' => 'fixture-cipher'];
$translated = MySQLConnection::parameters($tls);
foreach (['ssl_key' => PDO::MYSQL_ATTR_SSL_KEY, 'ssl_cert' => PDO::MYSQL_ATTR_SSL_CERT,
    'ssl_ca' => PDO::MYSQL_ATTR_SSL_CA, 'ssl_capath' => PDO::MYSQL_ATTR_SSL_CAPATH, 'ssl_cipher' => PDO::MYSQL_ATTR_SSL_CIPHER] as $field => $option) {
    verify($translated['driverOptions'][$option] === $tls[$field], 'Every configured TLS field becomes an actual PDO option');
}
verify($translated['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === true, 'Certificate and hostname verification explicitly reaches PDO');
verify(MySQLConnection::parameters($translated) === $translated, 'TLS parameter normalization remains idempotent');
$unverified = MySQLConnection::parameters(array_replace($tls, ['ssl_verify_server_cert' => false]));
verify($unverified['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === false, 'An explicit verification setting is represented exactly, never inferred');
try {
    MySQLConnection::parameters($tls + ['driverOptions' => [PDO::MYSQL_ATTR_SSL_CA => '/different/ca.pem']]);
    throw new LogicException('Conflicting TLS material accepted');
} catch (InvalidArgumentException) {
    verify(true, 'Contradictory raw PDO TLS options are rejected');
}
$lazy = MySQLConnection::create($tls);
verify(!$lazy->isConnected(), 'TLS mapping and policy validation preserve lazy creation and do not access configured files');
echo "MySQL PDO policy, lazy ownership and explicit TLS mappings passed without a connection.\n";
