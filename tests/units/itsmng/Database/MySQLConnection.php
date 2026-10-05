<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use itsmng\Database\MySQLConnection as Policy;
use itsmng\Database\MySQLManagedConnection;
use PDO;

/** Session and lazy transport policy are independent of application configuration and servers. */
class MySQLConnection extends \atoum\atoum\test
{
    private function parameters(): array
    {
        return ['driver' => 'pdo_mysql', 'host' => 'example.invalid', 'user' => 'fixture', 'password' => 'not-used'];
    }

    public function testStrictModesPreserveConfiguredPolicyAndAreIdempotent(): void
    {
        foreach ([
            '' => 'STRICT_ALL_TABLES',
            'NO_ENGINE_SUBSTITUTION' => 'NO_ENGINE_SUBSTITUTION,STRICT_ALL_TABLES',
            'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER' => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,STRICT_ALL_TABLES',
            'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE' => 'ANSI_QUOTES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,STRICT_ALL_TABLES',
            'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES' => 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES',
            'strict_all_tables' => 'strict_all_tables',
            ' ,STRICT_TRANS_TABLES, ' => 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES',
        ] as $configured => $expected) {
            $this->string(Policy::strictModes($configured))->isIdenticalTo($expected);
            $this->string(Policy::strictModes($expected))->isIdenticalTo($expected);
        }
    }

    public function testCanonicalPdoOwnershipAndNativeValues(): void
    {
        $parameters = Policy::parameters($this->parameters());
        $this->string($parameters['driver'])->isIdenticalTo('pdo_mysql');
        $this->string($parameters['wrapperClass'])->isIdenticalTo(MySQLManagedConnection::class);
        $this->boolean($parameters['driverOptions'][PDO::ATTR_EMULATE_PREPARES])->isFalse();
        $this->boolean($parameters['driverOptions'][PDO::ATTR_STRINGIFY_FETCHES])->isFalse();
        $this->boolean($parameters['driverOptions'][PDO::ATTR_PERSISTENT])->isFalse();
        $this->array(Policy::parameters($parameters))->isIdenticalTo($parameters);
    }

    public function testInvalidTransportPolicyIsDiagnosedBeforeLazyCreation(): void
    {
        $base = $this->parameters();
        foreach ([
            ['driver' => 'mysqli'], ['driverClass' => \stdClass::class], ['wrapperClass' => \stdClass::class], ['persistent' => true],
            ['ssl' => 'yes'], ['ssl_verify_server_cert' => 0], ['ssl_unknown' => 'unhandled'],
            ['ssl_key' => ['invalid']], ['ssl_ca' => '/configured/ca.pem'],
            ['driverOptions' => 'invalid'], ['driverOptions' => [123456789 => true]],
            ['driverOptions' => [PDO::ATTR_EMULATE_PREPARES => true]],
            ['driverOptions' => [PDO::ATTR_STRINGIFY_FETCHES => true]],
            ['driverOptions' => [PDO::ATTR_PERSISTENT => true]],
            ['driverOptions' => [PDO::ATTR_TIMEOUT => -1]],
        ] as $invalid) {
            $this->exception(static fn () => Policy::create(array_replace($base, $invalid)))
                ->isInstanceOf(\InvalidArgumentException::class);
        }
    }

    public function testExplicitTlsMaterialAndVerificationReachPdo(): void
    {
        $this->boolean(defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'))->isTrue('The supported runtime must provide PDO MySQL TLS verification');
        $tls = $this->parameters() + ['ssl' => true, 'ssl_verify_server_cert' => true, 'ssl_key' => '/configured/key.pem',
            'ssl_cert' => '/configured/cert.pem', 'ssl_ca' => '/configured/ca.pem', 'ssl_capath' => '/configured/cas', 'ssl_cipher' => 'fixture-cipher'];
        $translated = Policy::parameters($tls);
        foreach (['ssl_key' => PDO::MYSQL_ATTR_SSL_KEY, 'ssl_cert' => PDO::MYSQL_ATTR_SSL_CERT,
            'ssl_ca' => PDO::MYSQL_ATTR_SSL_CA, 'ssl_capath' => PDO::MYSQL_ATTR_SSL_CAPATH, 'ssl_cipher' => PDO::MYSQL_ATTR_SSL_CIPHER] as $field => $option) {
            $this->string($translated['driverOptions'][$option])->isIdenticalTo($tls[$field]);
        }
        $this->boolean($translated['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT])->isTrue();
        $this->array(Policy::parameters($translated))->isIdenticalTo($translated);
        $unverified = Policy::parameters(array_replace($tls, ['ssl_verify_server_cert' => false]));
        $this->boolean($unverified['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT])->isFalse();
        $this->exception(static fn () => Policy::parameters($tls + ['driverOptions' => [PDO::MYSQL_ATTR_SSL_CA => '/different/ca.pem']]))
            ->isInstanceOf(\InvalidArgumentException::class);
        $this->boolean(Policy::create($tls)->isConnected())->isFalse('TLS mapping and ownership remain lazy without accessing configured files');
    }
}
