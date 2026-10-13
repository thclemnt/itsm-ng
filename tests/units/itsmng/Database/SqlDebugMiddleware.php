<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use atoum\atoum\test;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use itsmng\Database\MySQLConnection;
use itsmng\Database\PostgresConnection;
use itsmng\Database\SqlDebugMiddleware as Middleware;
use ReflectionClass;
use ReflectionProperty;

class SqlDebugMiddleware extends test
{
    private function withDebug(callable $test): void
    {
        $saved = [];
        foreach (['CFG_GLPI', 'DEBUG_SQL', 'SQL_TOTAL_REQUEST', '_SESSION'] as $name) {
            $saved[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        if (!defined('GLPI_ROOT')) {
            define('GLPI_ROOT', dirname(__DIR__, 4));
        }
        require_once GLPI_ROOT . '/inc/session.class.php';
        $GLOBALS['CFG_GLPI']['debug_sql'] = true;
        $GLOBALS['_SESSION']['glpi_use_mode'] = \Session::DEBUG_MODE;
        $GLOBALS['DEBUG_SQL'] = [];
        $GLOBALS['SQL_TOTAL_REQUEST'] = 0;
        try {
            $configuration = new Configuration();
            $configuration->setMiddlewares([new Middleware()]);
            $test(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration));
        } finally {
            foreach ($saved as $name => [$exists, $value]) {
                if ($exists) {
                    $GLOBALS[$name] = $value;
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }
    }

    public function testDirectPreparedAndFailedQueriesShareTheDebugPanel(): void
    {
        $this->withDebug(function ($connection): void {
            $this->integer((int)$connection->fetchOne('SELECT 1'))->isIdenticalTo(1);
            $statement = $connection->prepare('SELECT ?');
            foreach ([2, 3] as $value) {
                $statement->bindValue(1, $value);
                $this->integer((int)$statement->executeQuery()->fetchOne())->isIdenticalTo($value);
            }
            $connection->executeStatement('CREATE TABLE example (id INTEGER)');
            $connection->executeStatement('INSERT INTO example VALUES (?)', [4]);
            $this->exception(fn () => $connection->executeQuery('SELECT * FROM missing_table'))->isInstanceOf(Exception::class);
            $this->exception(fn () => $connection->executeQuery('SELECT * FROM missing_table WHERE id = ?', [1]))->isInstanceOf(Exception::class);
            $this->integer((int)$connection->fetchOne('SELECT 5'))->isIdenticalTo(5);
            $debug = $GLOBALS['DEBUG_SQL'];
            $this->integer($GLOBALS['SQL_TOTAL_REQUEST'])->isIdenticalTo(8);
            $this->array($debug['queries'])->hasSize(8);
            $this->array($debug['times'])->hasSize(8);
            $this->array($debug['errors'])->hasSize(2);
            $this->integer($debug['rows'][5])->isIdenticalTo(1);
            $this->integer($debug['rows'][6])->isIdenticalTo(-1);
            $this->string($debug['queries'][8])->isIdenticalTo('SELECT 5');
        });
    }

    public function testLegacyAdapterAndItsNestedDriverRecordEachQueryOnce(): void
    {
        $this->withDebug(function ($connection): void {
            require_once GLPI_ROOT . '/inc/dbadapter.class.php';
            require_once GLPI_ROOT . '/inc/dbmysql.class.php';
            $adapter = (new ReflectionClass(\DBmysql::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(\DBAdapter::class, 'doctrine'))->setValue($adapter, $connection);
            $result = $adapter->query('SELECT 7 AS number');
            $this->integer((int)$result->fetch_assoc()['number'])->isIdenticalTo(7);
            $prepared = $adapter->prepare('SELECT ? AS number');
            $prepared->bind_param('i', $value);
            $value = 8;
            $this->boolean($prepared->execute())->isTrue();
            $this->integer((int)$prepared->get_result()->fetch_assoc()['number'])->isIdenticalTo(8);
            $this->integer($GLOBALS['SQL_TOTAL_REQUEST'])->isIdenticalTo(2);
            $this->array($GLOBALS['DEBUG_SQL']['queries'])->hasSize(2);
        });
    }

    public function testDebugModeCanBeToggledOnAnExistingConnection(): void
    {
        $this->withDebug(function ($connection): void {
            $GLOBALS['CFG_GLPI']['debug_sql'] = false;
            $connection->fetchOne('SELECT 1');
            $this->integer($GLOBALS['SQL_TOTAL_REQUEST'])->isIdenticalTo(0);
            $GLOBALS['CFG_GLPI']['debug_sql'] = true;
            $GLOBALS['_SESSION']['glpi_use_mode'] = \Session::NORMAL_MODE;
            $connection->fetchOne('SELECT 2');
            $this->integer($GLOBALS['SQL_TOTAL_REQUEST'])->isIdenticalTo(0);
            $GLOBALS['_SESSION']['glpi_use_mode'] = \Session::DEBUG_MODE;
            $connection->fetchOne('SELECT 3');
            $this->integer($GLOBALS['SQL_TOTAL_REQUEST'])->isIdenticalTo(1);
        });
    }

    public function testBothFactoriesInstallOneDebugMiddlewareWithoutMutatingSuppliedConfiguration(): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);
        $mysql = MySQLConnection::configuration(MySQLConnection::configuration($configuration));
        $postgres = PostgresConnection::create(['host' => 'unused.invalid'], $configuration)->getConfiguration();
        foreach ([$mysql, $postgres] as $configured) {
            $this->array(array_filter($configured->getMiddlewares(), static fn ($middleware) => $middleware instanceof Middleware))->hasSize(1);
        }
        $this->array($configuration->getMiddlewares())->hasSize(1);
    }
}
