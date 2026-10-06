<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
*/

namespace tests\units;

use PHPMailer\PHPMailer\PHPMailer;
use DbTestCase;
use Log;
use Session;

/* Test for inc/config.class.php */

class Config extends DbTestCase
{
    public function testSchemaInspectionDetectsCurrentConfigEditsWithoutChangingStorage(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $platform = $connection->getDatabasePlatform();
        $schemaManager = $connection->createSchemaManager();
        $before = $schemaManager->introspectTable('glpi_configs');
        $rowsHash = static fn (): string => hash('sha256', serialize($connection->fetchAllAssociative(
            'SELECT id, context, name, value FROM glpi_configs ORDER BY id'
        )));
        // Compare digests so a failed read-only check cannot print configuration secrets.
        $beforeRows = $rowsHash();
        $level = $connection->getTransactionNestingLevel();
        $manager = \itsmng\Database\Orm::create($DB);
        try {
            $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\Config::class);
            $metadata->fieldMappings['context']->length = 173;
            $expected = (new \itsmng\Database\BaselineSchema($manager))->build($platform)->getTable('glpi_configs');
            $this->array((new \itsmng\Database\SchemaCheck())->differences(
                $connection,
                new \Doctrine\DBAL\Schema\Schema([clone $expected])
            ))->isIdenticalTo(['Changed column: glpi_configs.context']);
            $after = $schemaManager->introspectTable('glpi_configs');
            $this->boolean($schemaManager->createComparator()->compareTables($before, $after)->isEmpty())->isTrue();
            $this->array($after->getOptions())->isIdenticalTo($before->getOptions());
            $this->string($rowsHash())->isIdenticalTo($beforeRows);
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $manager->clear();
        }
    }

    public function testGetTypeName()
    {
        $this->string(\Config::getTypeName())->isIdenticalTo('Setup');
    }

    public function testAcls()
    {
        //check ACLs when not logged
        $this->boolean(\Config::canView())->isFalse();
        $this->boolean(\Config::canCreate())->isFalse();

        $conf = new \Config();
        $this->boolean($conf->canViewItem())->isFalse();

        //check ACLs from superadmin profile
        $this->login();
        $this->boolean((bool)\Config::canView())->isTrue();
        $this->boolean(\Config::canCreate())->isFalse();
        $this->boolean($conf->canViewItem())->isFalse();

        $this->boolean($conf->getFromDB(1))->isTrue();
        $this->boolean($conf->canViewItem())->isTrue();

        //check ACLs from tech profile
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
        $this->boolean((bool)\Config::canView())->isFalse();
        $this->boolean(\Config::canCreate())->isFalse();
        $this->boolean($conf->canViewItem())->isTrue();
    }

    public function testGetMenuContent()
    {
        $this->boolean(\Config::getMenuContent())->isFalse();

        $this->login();
        $this->array(\Config::getMenuContent())
           ->hasSize(4)
           ->hasKeys(['title', 'page', 'options', 'icon']);
    }

    public function testDefineTabs()
    {
        $expected = [
           'Config$1'      => 'General setup',
           'Config$2'      => 'Default values',
           'Config$3'      => 'Assets',
           'Config$4'      => 'Assistance',
           'Log$1'         => 'Historical',
        ];
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);

        //Standards users do not have extra tabs
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);

        //check extra tabs from superadmin profile
        $this->login();
        $expected = [
           'Config$1'      => 'General setup',
           'Config$2'      => 'Default values',
           'Config$3'      => 'Assets',
           'Config$4'      => 'Assistance',
           'Config$9'      => 'Logs purge',
           'Config$5'      => 'System',
           'Config$10'     => 'Security',
           'Config$7'      => 'Performance',
           'Config$8'      => 'API',
           'Config$11'      => \Impact::getTypeName(),
           'Log$1'         => 'Historical',
        ];
        $this
           ->given($this->newTestedInstance)
              ->then
                 ->array($this->testedInstance->defineTabs())
                 ->isIdenticalTo($expected);
    }

    public function testPrepareInputForUpdate()
    {
        global $DB;

        $this->login();
        $this->boolean((bool)\Config::canUpdate())->isTrue();
        $rows = static fn (string $table, array $criteria): array => (new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB)))->matching($table, $criteria, ['id ASC']);
        \Config::setConfigurationValues('core', ['is_ids_visible' => 0]);
        $before = $rows('glpi_configs', ['context' => 'core']);
        $setting = $rows('glpi_configs', ['context' => 'core', 'name' => 'is_ids_visible']);
        $this->array($setting)->hasSize(1);
        $this->string($setting[0]['value'])->isIdenticalTo('0');
        $historyCriteria = ['itemtype' => \Config::getType(), 'old_value' => ['LIKE', 'is_ids_visible %']];
        $historyBefore = $rows('glpi_logs', $historyCriteria);

        // The actual default-values form stores configuration during preparation
        // and deliberately returns false to stop the outer record update.
        $config = new \Config();
        $this->boolean($config->prepareInputForUpdate([
            'id' => $setting[0]['id'],
            'is_ids_visible' => 1,
            'update' => 'Save',
            '_glpi_csrf_token' => $_SESSION['_glpi_csrf_token'],
            '_no_history' => 1,
        ]))->isFalse();
        $this->array(\Config::getConfigurationValues('core', ['is_ids_visible']))->isIdenticalTo(['is_ids_visible' => '1']);

        $expected = $before;
        foreach ($expected as &$row) {
            if ($row['id'] === $setting[0]['id']) {
                $row['value'] = '1';
            }
        }
        unset($row);
        // Exact ORM rows also prove that id/update/CSRF/_no_history became no
        // accidental core settings, and that unrelated configuration is intact.
        $this->array($rows('glpi_configs', ['context' => 'core']))->isIdenticalTo($expected);
        $history = array_slice($rows('glpi_logs', $historyCriteria), count($historyBefore));
        $this->array($history)->hasSize(1);
        $this->string($history[0]['old_value'])->isIdenticalTo('is_ids_visible 0');
        $this->string($history[0]['new_value'])->isIdenticalTo('1');
        $actor = Session::getLoginUserID(false);
        $this->string($history[0]['user_name'])->isIdenticalTo(sprintf(__('%1$s (%2$s)'), getUserName($actor), $actor));
    }

    public function testUnsetUndisclosedFields()
    {
        $input = [
           'context'   => 'core',
           'name'      => 'name',
           'value'     => 'value'
        ];
        $expected = $input;

        \Config::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);

        $input = [
           'context'   => 'core',
           'name'      => 'proxy_passwd',
           'value'     => 'value'
        ];
        $expected = $input;
        unset($expected['value']);

        \Config::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);

        $input = [
           'context'   => 'core',
           'name'      => 'smtp_passwd',
           'value'     => 'value'
        ];
        $expected = $input;
        unset($expected['value']);

        \Config::unsetUndisclosedFields($input);
        $this->array($input)->isIdenticalTo($expected);
    }

    public function testValidatePassword()
    {
        global $CFG_GLPI;
        $this->boolean((bool)$CFG_GLPI['use_password_security'])->isFalse();

        $this->boolean(\Config::validatePassword('mypass'))->isTrue();

        $CFG_GLPI['use_password_security'] = 1;
        $this->integer((int)$CFG_GLPI['password_min_length'])->isIdenticalTo(8);
        $this->integer((int)$CFG_GLPI['password_need_number'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_letter'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_caps'])->isIdenticalTo(1);
        $this->integer((int)$CFG_GLPI['password_need_symbol'])->isIdenticalTo(1);
        $this->boolean(\Config::validatePassword(''))->isFalse();

        $expected = [
           'Password too short!',
           'Password must include at least a digit!',
           'Password must include at least a lowercase letter!',
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->hasSessionMessages(ERROR, $expected);
        $expected = [
           'Password must include at least a digit!',
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->boolean(\Config::validatePassword('mypassword'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_min_length'] = strlen('mypass');
        $this->boolean(\Config::validatePassword('mypass'))->isFalse();
        $CFG_GLPI['password_min_length'] = 8; //reset

        $this->hasSessionMessages(ERROR, $expected);

        $expected = [
           'Password must include at least a uppercase letter!',
           'Password must include at least a symbol!'
        ];
        $this->boolean(\Config::validatePassword('my1password'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_need_number'] = 0;
        $this->boolean(\Config::validatePassword('mypassword'))->isFalse();
        $CFG_GLPI['password_need_number'] = 1; //reset
        $this->hasSessionMessages(ERROR, $expected);

        $expected = [
           'Password must include at least a symbol!'
        ];
        $this->boolean(\Config::validatePassword('my1paSsword'))->isFalse();
        $this->hasSessionMessages(ERROR, $expected);

        $CFG_GLPI['password_need_caps'] = 0;
        $this->boolean(\Config::validatePassword('my1password'))->isFalse();
        $CFG_GLPI['password_need_caps'] = 1; //reset
        $this->hasSessionMessages(ERROR, $expected);

        $this->boolean(\Config::validatePassword('my1paSsw@rd'))->isTrue();
        $this->hasNoSessionMessage(ERROR);

        $CFG_GLPI['password_need_symbol'] = 0;
        $this->boolean(\Config::validatePassword('my1paSsword'))->isTrue();
        $CFG_GLPI['password_need_symbol'] = 1; //reset
        $this->hasNoSessionMessage(ERROR);
    }

    public function testGetLibraries()
    {
        $actual = $expected = [];
        $deps = \Config::getLibraries(true);
        foreach ($deps as $dep) {
            // composer names only (skip htmlLawed)
            if (strpos((string) $dep['name'], '/')) {
                $actual[] = $dep['name'];
            }
        }
        sort($actual);
        $this->array($actual)->isNotEmpty();
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        foreach (array_keys($composer['require']) as $dep) {
            // composer names only (skip php, ext-*, ...)
            if (strpos((string) $dep, '/')) {
                $expected[] = $dep;
            }
        }
        sort($expected);
        $this->array($expected)->isNotEmpty();
        $this->array(array_values(array_diff($actual, $expected)))->isEmpty();
    }

    public function testGetLibraryDir()
    {
        $this->boolean(\Config::getLibraryDir(''))->isFalse();
        $this->boolean(\Config::getLibraryDir('abcde'))->isFalse();

        $expected = realpath(__DIR__ . '/../../vendor/phpmailer/phpmailer/src');
        if (is_dir($expected)) { // skip when system library is used
            $this->string(\Config::getLibraryDir('PHPMailer\PHPMailer\PHPMailer'))->isIdenticalTo($expected);

            $mailer = new PHPMailer();
            $this->string(\Config::getLibraryDir($mailer))->isIdenticalTo($expected);
        }

        $expected = realpath(__DIR__ . '/../');
        $this->string(\Config::getLibraryDir('getItemByTypeName'))->isIdenticalTo($expected);
    }

    public function testCheckExtensions()
    {
        $this->array(\Config::checkExtensions())
           ->hasKeys(['error', 'good', 'missing', 'may']);

        $expected = [
           'error'     => 0,
           'good'      => [
              'mysqli' => 'mysqli extension is installed',
           ],
           'missing'   => [],
           'may'       => []
        ];

        //check extension from class name
        $list = [
           'mysqli' => [
              'required'  => true,
              'class'     => 'mysqli'
           ]
        ];
        $report = \Config::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //check extension from method name
        $list = [
           'mysqli' => [
              'required'  => true,
              'function'  => 'mysqli_commit'
           ]
        ];
        $report = \Config::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //check extension from its name
        $list = [
           'mysqli' => [
              'required'  => true
           ]
        ];
        $report = \Config::checkExtensions($list);
        $this->array($report)->isIdenticalTo($expected);

        //required, missing extension
        $list['notantext'] = [
           'required'  => true
        ];
        $report = \Config::checkExtensions($list);
        $expected = [
           'error'     => 2,
           'good'      => [
              'mysqli' => 'mysqli extension is installed',
           ],
           'missing'   => [
              'notantext' => 'notantext extension is missing'
           ],
           'may'       => []
        ];
        $this->array($report)->isIdenticalTo($expected);

        //not required, missing extension
        unset($list['notantext']);
        $list['totally_optionnal'] = ['required' => false];
        $report = \Config::checkExtensions($list);
        $expected = [
           'error'     => 1,
           'good'      => [
              'mysqli' => 'mysqli extension is installed',
           ],
           'missing'   => [],
           'may'       => [
              'totally_optionnal' => 'totally_optionnal extension is not present'
           ]
        ];
        $this->array($report)->isIdenticalTo($expected);
    }

    public function testCacheBackendBootstrapReadsFreshRawConfiguration(): void
    {
        global $DB, $PHP_LOG_HANDLER;
        $connection = $DB->getDoctrineConnection();
        $context = "cache-bootstrap-'" . bin2hex(random_bytes(6));
        $otherContext = $context . '-other';
        $name = 'cache_db';
        $table = $connection->quoteIdentifier(\Config::getTable());
        $hadCache = array_key_exists('GLPI_CACHE', $GLOBALS);
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $settings = static fn (string $namespace, int $ttl): string => json_encode([
            'adapter' => 'memory',
            'options' => ['namespace' => $namespace, 'ttl' => $ttl],
        ], JSON_THROW_ON_ERROR);
        $encrypted = \Toolbox::sodiumEncrypt($settings('must-not-decrypt', 99));
        $memory = new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: false);
        try {
            $connection->insert($table, ['context' => $context, 'name' => $name, 'value' => $settings('first', 17)]);
            $connection->insert($table, ['context' => $otherContext, 'name' => $name, 'value' => $settings('wrong-context', 31)]);
            $connection->insert($table, ['context' => $context, 'name' => 'other-cache', 'value' => $settings('wrong-name', 43)]);
            unset($GLOBALS['GLPI_CACHE']);
            $first = \Config::getCache($name, $context, false);
            $this->object($first)->isInstanceOf(\Laminas\Cache\Storage\Adapter\Memory::class);
            $this->string($first->getOptions()->getNamespace())->isIdenticalTo('first');
            $this->integer($first->getOptions()->getTtl())->isIdenticalTo(17);

            $GLOBALS['GLPI_CACHE'] = new \Symfony\Component\Cache\Psr16Cache($memory);
            $connection->update($table, ['value' => $settings('second', 29)], ['context' => $context, 'name' => $name]);
            $second = \Config::getCache($name, $context, false);
            $this->object($second)->isNotIdenticalTo($first);
            $this->string($second->getOptions()->getNamespace())->isIdenticalTo('second');
            $this->integer($second->getOptions()->getTtl())->isIdenticalTo(29);
            $this->string($first->getOptions()->getNamespace())->isIdenticalTo('first');

            // Neither SQL NULL, JSON null nor ciphertext is an adapter declaration.
            foreach ([null, 'null', $encrypted] as $value) {
                $connection->update($table, ['value' => $value], ['context' => $context, 'name' => $name]);
                $fallback = \Config::getCache($name, $context, false);
                $this->object($fallback)->isInstanceOf(\Laminas\Cache\Storage\Adapter\Filesystem::class);
                $this->integer($fallback->getOptions()->getTtl())->isIdenticalTo(600);
            }
            $connection->delete($table, ['context' => $context, 'name' => $name]);
            $this->object(\Config::getCache($name, $context, false))->isInstanceOf(\Laminas\Cache\Storage\Adapter\Filesystem::class);
            $this->array($memory->getValues())->isEmpty('Cache backend construction does not populate the ORM metadata cache');

            // Consume only the four deliberate getCache debug messages, after
            // checking their complete decoded payloads and order.
            $expectedPayloads = [];
            foreach ([['first', 17], ['second', 29]] as [$namespace, $ttl]) {
                $expectedPayloads[] = 'CACHE CONFIG  cache_db ' . str_replace("\n", "\n  ", print_r([
                    'adapter' => 'memory',
                    'options' => ['namespace' => $namespace, 'ttl' => $ttl],
                ], true));
            }
            $expectedPayloads[] = 'CACHE CONFIG  cache_db NULL ';
            $expectedPayloads[] = 'CACHE CONFIG  cache_db NULL ';
            $records = $PHP_LOG_HANDLER->getRecords();
            $this->array($records)->hasSize(4);
            foreach ($records as $index => $record) {
                $this->string($record['level_name'])->isIdenticalTo('DEBUG');
                [$caller, $payload] = explode("\n", $record['message'], 2);
                $this->integer(preg_match(
                    '~^' . preg_quote('Config::getCache() in ' . realpath(GLPI_ROOT . '/inc/config.class.php') . ' line ', '~') . '[0-9]+$~D',
                    $caller
                ))->isIdenticalTo(1);
                $this->string($payload)->isIdenticalTo($expectedPayloads[$index]);
            }
            $PHP_LOG_HANDLER->clear();
        } finally {
            $connection->delete($table, ['context' => $context]);
            $connection->delete($table, ['context' => $otherContext]);
            if ($hadCache) {
                $GLOBALS['GLPI_CACHE'] = $previous;
            } else {
                unset($GLOBALS['GLPI_CACHE']);
            }
        }
    }

    public function testOwnedMetadataCacheStartsAfterBootstrapAndKeepsManagersIsolated(): void
    {
        global $DB;
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $memory = new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: false);
        $pool = new \Symfony\Component\Cache\Psr16Cache($memory);
        $listener = new class () {
            public int $loads = 0;
            public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $event): void
            {
                if ($event->getClassMetadata()->name === \itsmng\Database\Entity\Config::class) {
                    ++$this->loads;
                }
            }
        };
        $owned = static function () use ($DB, $listener): \Doctrine\ORM\EntityManager {
            $manager = \itsmng\Database\Orm::create($DB);
            $manager->getEventManager()->addEventListener(\Doctrine\ORM\Events::loadClassMetadata, $listener);
            return $manager;
        };
        try {
            unset($GLOBALS['GLPI_CACHE']);
            $bootstrap = \itsmng\Database\Orm::create($DB);
            $this->object($bootstrap->getConfiguration()->getMetadataCache())->isInstanceOf(\Symfony\Component\Cache\Adapter\ArrayAdapter::class);
            $bootstrap->getClassMetadata(\itsmng\Database\Entity\Config::class);
            $GLOBALS['GLPI_CACHE'] = $pool;
            $first = $owned();
            $original = $first->getClassMetadata(\itsmng\Database\Entity\Config::class)->generatorType;
            $this->integer($listener->loads)->isIdenticalTo(1);
            $first->getClassMetadata(\itsmng\Database\Entity\Config::class)->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            $second = $owned();
            $this->object($second)->isNotIdenticalTo($first);
            $this->object($second->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
            $this->integer($second->getClassMetadata(\itsmng\Database\Entity\Config::class)->generatorType)->isIdenticalTo($original);
            $this->integer($listener->loads)->isIdenticalTo(1, 'A fresh manager uses the persisted mapping without attribute discovery');
            $this->array($memory->getValues())->isNotEmpty();
            foreach ($memory->getValues() as $value) {
                $this->string($value, 'Even an object-retaining backend receives only serialized bytes');
            }
            $public = \itsmng\Database\Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform());
            $this->object($public->getMetadataCache())->isInstanceOf(\Symfony\Component\Cache\Adapter\ArrayAdapter::class);
            $this->variable($public->getQueryCache())->isNull();
            $pool->clear(); // The ordinary application cache-clear boundary.
            $owned()->getClassMetadata(\itsmng\Database\Entity\Config::class);
            $this->integer($listener->loads)->isIdenticalTo(2);
            $GLOBALS['GLPI_CACHE'] = new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: false));
            $owned()->getClassMetadata(\itsmng\Database\Entity\Config::class);
            $this->integer($listener->loads)->isIdenticalTo(3, 'Replacing the configured pool cannot reuse the previous pool');
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testOwnedMetadataCacheKeepsProviderDeclarationsSeparate(): void
    {
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $GLOBALS['GLPI_CACHE'] = new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: false));
        try {
            foreach ([['pdo_mysql', '8.0.0'], ['pdo_pgsql', '15.0'], ['pdo_mysql', '8.0.0']] as [$driver, $version]) {
                $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $version]);
                $this->mockGenerator->orphanize('__construct');
                $adapter = new \mock\DBmysql();
                $this->calling($adapter)->getDoctrineConnection = $connection;
                try {
                    $manager = \itsmng\Database\Orm::create($adapter);
                    $metadata = $manager->getClassMetadata(\itsmng\Database\Entity\Computer::class);
                    $declaration = $metadata->fieldMappings['date_creation']->columnDefinition;
                    if ($driver === 'pdo_mysql') {
                        $this->string($declaration)->contains('TIMESTAMP');
                    } else {
                        $this->variable($declaration)->isNull();
                    }
                    $this->object($manager->getConnection())->isIdenticalTo($connection);
                    $this->boolean($connection->isConnected())->isFalse();
                } finally {
                    $connection->close();
                }
            }
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
        }
    }

    public function testOwnedQueryCacheRetainsFreshManagersAndLiveValues(): void
    {
        global $DB;
        $context = 'query-cache-' . bin2hex(random_bytes(6));
        \Config::setConfigurationValues($context, ['first' => 'before', 'second' => 'other']);
        ConfigQueryCacheWalker::$compilations = 0;
        $first = \itsmng\Database\Orm::create($DB);
        $second = \itsmng\Database\Orm::create($DB);
        $cache = $first->getConfiguration()->getQueryCache();
        $this->object($cache)->isIdenticalTo($second->getConfiguration()->getQueryCache());
        $this->object($first)->isNotIdenticalTo($second);
        $this->object($first->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
        $this->object($second->getConnection())->isIdenticalTo($DB->getDoctrineConnection());
        $this->variable(\itsmng\Database\Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform())->getQueryCache())->isNull();
        $original = $first->getClassMetadata(\itsmng\Database\Entity\Config::class)->generatorType;
        $first->getClassMetadata(\itsmng\Database\Entity\Config::class)->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
        $this->integer($second->getClassMetadata(\itsmng\Database\Entity\Config::class)->generatorType)->isIdenticalTo($original);
        $read = static function (\Doctrine\ORM\EntityManager $manager, string $name) use ($context): string {
            return $manager->createQuery('SELECT c.value FROM ' . \itsmng\Database\Entity\Config::class . ' c WHERE c.context = :context AND c.name = :name')
                ->setParameter('context', $context, \Doctrine\DBAL\Types\Types::STRING)
                ->setParameter('name', $name, \Doctrine\DBAL\Types\Types::STRING)
                ->setHint(\Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER, ConfigQueryCacheWalker::class)
                ->getSingleScalarResult();
        };
        try {
            $cache->clear();
            $this->string($read($first, 'first'))->isIdenticalTo('before');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(1);
            $this->string($read($second, 'second'))->isIdenticalTo('other');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(1);
            \Config::setConfigurationValues($context, ['first' => 'after']);
            $this->string($read(\itsmng\Database\Orm::create($DB), 'first'))->isIdenticalTo('after');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(1);
            $cache->clear();
            $this->string($read(\itsmng\Database\Orm::create($DB), 'first'))->isIdenticalTo('after');
            $this->integer(ConfigQueryCacheWalker::$compilations)->isIdenticalTo(2);
        } finally {
            \Config::deleteConfigurationValues($context, ['first', 'second']);
        }
    }

    public function testGetConfigurationValues()
    {
        $conf = \Config::getConfigurationValues('core');
        $this->array($conf)
           ->hasKeys(['version', 'dbversion'])
           ->size->isGreaterThan(170);

        $conf = \Config::getConfigurationValues('core', ['version', 'dbversion']);
        $this->array($conf)->isEqualTo([
           'dbversion' => \ITSM_SCHEMA_VERSION,
           'version'   => \ITSM_VERSION
        ]);
    }

    public function testSetConfigurationValues()
    {
        $conf = \Config::getConfigurationValues('core', ['version', 'notification_to_myself']);
        $this->array($conf)->isEqualTo([
           'notification_to_myself'   => '1',
           'version'                  => \ITSM_VERSION
        ]);

        //update configuration value
        \Config::setConfigurationValues('core', ['notification_to_myself' => 0]);
        $conf = \Config::getConfigurationValues('core', ['version', 'notification_to_myself']);
        $this->array($conf)->isEqualTo([
           'notification_to_myself'   => '0',
           'version'                  => \ITSM_VERSION
        ]);
        \Config::setConfigurationValues('core', ['notification_to_myself' => 1]); //reset

        //check new configuration key does not exists
        $conf = \Config::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'version' => \ITSM_VERSION
        ]);

        //add new configuration key
        \Config::setConfigurationValues('core', ['new_configuration_key' => 'test']);
        $conf = \Config::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'new_configuration_key' => 'test',
           'version'               => \ITSM_VERSION
        ]);

        //drop new configuration key
        \Config::deleteConfigurationValues('core', ['new_configuration_key']);
        $conf = \Config::getConfigurationValues('core', ['version', 'new_configuration_key']);
        $this->array($conf)->isEqualTo([
           'version' => \ITSM_VERSION
        ]);
    }

    public function testGetRights()
    {
        $conf = new \Config();
        $this->array($conf->getRights())->isIdenticalTo([
           READ     => 'Read',
           UPDATE   => 'Update'
        ]);
    }

    public function testGetPalettes()
    {
        $palettes_dir = GLPI_ROOT . '/css/palettes/';
        if (!is_dir($palettes_dir)) {
            $this->boolean(is_dir($palettes_dir))->isFalse();
            return;
        }

        $expected = [];
        foreach (scandir($palettes_dir) as $file) {
            if (strpos($file, '.scss') !== false) {
                $name = substr($file, 1, -5);
                $expected[$name] = ucfirst($name);
            }
        }
        $this
           ->if($this->newTestedInstance)
           ->then
              ->array($this->testedInstance->getPalettes())
              ->isIdenticalTo($expected);

    }

    /**
     * Database engines data provider
     *
     * @return array
     */
    protected function dbEngineProvider()
    {
        return [
           [
              'raw'       => '10.2.14-MariaDB',
              'version'   => '10.2.14',
              'compat'    => false // Native CHECK catalogue is available from 10.2.22.
           ], [
              'raw'       => '5.5.10-MariaDB',
              'version'   => '5.5.10',
              'compat'    => false
           ], [
              'raw'       => '5.6.38-log',
              'version'   => '5.6.38',
              'compat'    => false // CHECK syntax without enforcement is insufficient.
           ], [
              'raw'       => '5-5-57',
              'version'   => '5',
              'compat'    => false
           ], [
              'raw'       => '5-6-31',
              'version'   => '5',
              'compat'    => false // since version is 5, this is not compat.
           ], [
              'raw'       => '10-2-35',
              'version'   => '10',
              'compat'    => false // An ambiguous version cannot establish capabilities.
           ], [
              'raw'       => '8.0.15',
              'version'   => '8.0.15',
              'compat'    => false
           ], [
              'raw'       => '8.0.16',
              'version'   => '8.0.16',
              'compat'    => true
           ], [
              'raw'       => '10.2.0-MariaDB',
              'version'   => '10.2.0',
              'compat'    => false
           ], [
              'raw'       => '5.5.5-10.2.22-MariaDB',
              'version'   => '10.2.22',
              'compat'    => true
           ]
        ];
    }

    /**
     * @dataProvider dbEngineProvider
     */
    public function testCheckDbEngine($raw, $version, $compat)
    {
        global $DB;

        // The explicit diagnostic input keeps DbTestCase's actual writer and
        // transaction intact throughout every version-provider invocation.
        $result = \Config::checkDbEngine($raw);
        $this->array($result)->isIdenticalTo([$version => $compat]);
        $this->array(\Config::checkDbEngine())->isIdenticalTo(\Config::checkDbEngine($DB->getVersion()));
    }

    public function testGetLanguage()
    {
        $this
           ->if($this->newTestedInstance)
           ->then
              ->string($this->testedInstance->getLanguage('fr'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('fr_FR'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('fr-FR'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('Français'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('french'))
                 ->isIdenticalTo('fr_FR')
              ->string($this->testedInstance->getLanguage('notalang'))
                 ->isIdenticalTo('');

    }

    /**
     * Provides list of classes that can be linked to configuration.
     *
     * @return array
     */
    protected function itemtypeLinkedToConfigurationProvider()
    {
        return [
           [
              'key'      => 'documentcategories_id_forticket',
              'itemtype' => 'DocumentCategory',
           ],
           [
              'key'      => 'default_requesttypes_id',
              'itemtype' => 'RequestType',
           ],
           [
              'key'      => 'softwarecategories_id_ondelete',
              'itemtype' => 'SoftwareCategory',
           ],
           [
              'key'      => 'ssovariables_id',
              'itemtype' => 'SsoVariable',
           ],
           [
              'key'      => 'transfers_id_auto',
              'itemtype' => 'Transfer',
           ],
        ];
    }

    /**
     * Check that relation between items and configuration are correctly cleaned.
     *
     * @param string $key
     * @param string $itemtype
     *
     * @dataProvider itemtypeLinkedToConfigurationProvider
     */
    public function testCleanRelationDataOfLinkedItems($key, $itemtype)
    {

        // Case 1: used item is cleaned without replacement
        $item = new $itemtype();
        $item->fields = ['id' => 15];

        \Config::setConfigurationValues('core', [$key => $item->fields['id']]);

        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isTrue();
        }
        $item->cleanRelationData();
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $this->array(\Config::getConfigurationValues('core', [$key]))
           ->hasKey($key)
           ->variable[$key]->isEqualTo(0);

        // Case 2: unused item is cleaned without effect
        $item = new $itemtype();
        $item->fields = ['id' => 15];

        $random_id = mt_rand(20, 100);

        \Config::setConfigurationValues('core', [$key => $random_id]);

        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $item->cleanRelationData();
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $this->boolean($item->isUsed())->isFalse();
        }
        $this->array(\Config::getConfigurationValues('core', [$key]))
           ->hasKey($key)
           ->variable[$key]->isEqualTo($random_id);

        // Case 3: used item is cleaned with replacement (CommonDropdown only)
        if (is_a($itemtype, 'CommonDropdown', true)) {
            $replacement_item = new $itemtype();
            $replacement_item->fields = ['id' => 12];

            $item = new $itemtype();
            $item->fields = ['id' => 15];
            $item->input = ['_replace_by' => $replacement_item->fields['id']];

            \Config::setConfigurationValues('core', [$key => $item->fields['id']]);

            $this->boolean($item->isUsed())->isTrue();
            $this->boolean($replacement_item->isUsed())->isFalse();
            $item->cleanRelationData();
            $this->boolean($item->isUsed())->isFalse();
            $this->boolean($replacement_item->isUsed())->isTrue();
            $this->array(\Config::getConfigurationValues('core', [$key]))
               ->hasKey($key)
               ->variable[$key]
                  ->isEqualTo($replacement_item->fields['id']);
        }
    }

    public function testDevicesInMenu()
    {
        global $CFG_GLPI, $DB;

        $conf = new \Config();
        $this->array($CFG_GLPI['devices_in_menu'])->isIdenticalTo([
           'Item_DeviceSimcard'
        ]);

        //Config::prepareInputForUpdate() always return false.
        $conf->update([
           'id'                       => 1,
           '_update_devices_in_menu'  => 1,
           'devices_in_menu'          => ['Item_DeviceSimcard', 'Item_DeviceBattery']
        ]);

        //check values in db
        $res = $DB->request([
           'SELECT' => 'value',
           'FROM'   => $conf->getTable(),
           'WHERE'  => ['name' => 'devices_in_menu']
        ])->next();
        $this->array($res)->isIdenticalTo(
            ['value' => exportArrayToDB(['Item_DeviceSimcard', 'Item_DeviceBattery'])]
        );
    }

    /**
     * Test password expiration delay configuration update.
     */
    public function testPasswordExpirationDelayUpdate()
    {
        global $DB;

        $conf = new \Config();
        $crontask = new \CronTask();

        // create some non local users for the test
        foreach ([\Auth::LDAP, \Auth::EXTERNAL, \Auth::CAS] as $authtype) {
            $user = new \User();
            $user_id = $user->add(
                [
                  'name'     => 'test_user_' . mt_rand(),
                  'authtype' => $authtype,
            ]
            );
            $this->integer($user_id)->isGreaterThan(0);
        }

        // get count of users using local auth
        $local_users_count = countElementsInTable(
            \User::getTable(),
            ['authtype' => \Auth::DB_GLPI]
        );
        // get count of users using external auth
        $external_users_count = countElementsInTable(
            \User::getTable(),
            ['NOT' => ['authtype' => \Auth::DB_GLPI]]
        );
        // reset 'password_last_update' to null for the test
        $DB->update(\User::getTable(), ['password_last_update' => null], [true]);

        // initial data:
        //  - password expiration is not active
        //  - users from installation data have no value for password_last_update
        //  - crontask is not active
        $values = \Config::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(-1);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => null]
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
        $this->boolean($crontask->getFromDBbyName(\User::getType(), 'passwordexpiration'))->isTrue();
        $this->integer((int)$crontask->fields['state'])->isIdenticalTo(0);

        // check that activation of password expiration reset `password_last_update` to current date
        // for all local users but not for external users
        // and activate passwordexpiration crontask
        $current_time = $_SESSION['glpi_currenttime'];
        $update_datetime = date('Y-m-d H:i:s', strtotime('-15 days')); // arbitrary date
        $_SESSION['glpi_currenttime'] = $update_datetime;
        $conf->update(
            [
              'id'                        => 1,
              'password_expiration_delay' => 30
         ]
        );
        $_SESSION['glpi_currenttime'] = $current_time;
        $values = \Config::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(30);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => $update_datetime]
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
        $this->boolean($crontask->getFromDBbyName(\User::getType(), 'passwordexpiration'))->isTrue();
        $this->integer((int)$crontask->fields['state'])->isIdenticalTo(1);

        // check that changing password expiration delay does not reset `password_last_update` to current date
        // if password expiration was already active
        $current_time = $_SESSION['glpi_currenttime'];
        $new_update_datetime = date('Y-m-d H:i:s', strtotime('-5 days')); // arbitrary date
        $_SESSION['glpi_currenttime'] = $new_update_datetime;
        $conf->update(
            [
              'id'                        => 1,
              'password_expiration_delay' => 45
         ]
        );
        $_SESSION['glpi_currenttime'] = $current_time;
        $values = \Config::getConfigurationValues('core');
        $this->array($values)->hasKey('password_expiration_delay');
        $this->integer((int)$values['password_expiration_delay'])->isIdenticalTo(45);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['authtype' => \Auth::DB_GLPI, 'password_last_update' => $update_datetime] // previous config update
            )
        )->isEqualTo($local_users_count);
        $this->integer(
            countElementsInTable(
                \User::getTable(),
                ['NOT' => ['authtype' => \Auth::DB_GLPI], 'password_last_update' => null]
            )
        )->isEqualTo($external_users_count);
    }

    protected function logConfigChangeProvider()
    {
        global $PLUGIN_HOOKS;

        $PLUGIN_HOOKS['secured_configs']['tester'] = ['passwd'];

        return [
           [
              'context'          => 'core',
              'name'             => 'unexisting_config',
              'is_secured'       => false,
              'old_value_prefix' => 'unexisting_config ',
           ],
           [
              'context'          => 'plugin:tester',
              'name'             => 'check',
              'is_secured'       => false,
              'old_value_prefix' => 'check (plugin:tester) ',
           ],
           [
              'context'          => 'plugin:tester',
              'name'             => 'passwd',
              'is_secured'       => true,
              'old_value_prefix' => 'passwd (plugin:tester) ',
           ]
        ];
    }

    /**
     * @dataProvider logConfigChangeProvider
     */
    public function testLogConfigChange(string $context, string $name, bool $is_secured, string $old_value_prefix)
    {
        $history_crit = ['itemtype' => \Config::getType(), 'old_value' => ['LIKE', $name . ' %']];

        $expected_history = [];
        $history_entry_fields = [
           'itemtype'         => \Config::getType(),
           'items_id'         => 1,
           'itemtype_link'    => '',
           'linked_action'    => 0,
           'user_name'        => Session::getLoginUserID(false),
           'date_mod'         => $_SESSION['glpi_currenttime'],
           'id_search_option' => 1,
        ];

        $clean_ids = function (&$value, $key) {
            unset($value['id']);
        };

        // History on first value
        \Config::setConfigurationValues($context, [$name => 'first value']);
        $expected_history = [
           $history_entry_fields + [
              'old_value' => $old_value_prefix . ($is_secured ? '********' : ''),
              'new_value' => $is_secured ? '********' : 'first value',
           ],
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);

        // History on updated value
        \Config::setConfigurationValues($context, [$name => 'new value']);
        $expected_history[] = $history_entry_fields + [
           'old_value' => $old_value_prefix . ($is_secured ? '********' : 'first value'),
           'new_value' => $is_secured ? '********' : 'new value',
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);

        // History on config deletion
        \Config::deleteConfigurationValues($context, [$name]);
        $expected_history[] = $history_entry_fields + [
           'old_value' => $old_value_prefix . ($is_secured ? '********' : 'new value'),
           'new_value' => $is_secured ? '********' : '',
        ];

        $found_history = array_values(getAllDataFromTable(Log::getTable(), $history_crit));
        array_walk($found_history, $clean_ids);
        $this->array($found_history)->isEqualTo($expected_history);
    }

    public function testAutoCreateInfocom()
    {
        global $CFG_GLPI, $DB;

        $this->login();

        $infocom_types = $CFG_GLPI["infocom_types"];
        $excluded_types = [
           'Cartridge', // Should inherit from CartridgeItem
           'Consumable', // Should inherit from ConsumableItem
        ];
        $infocom_types = array_diff($infocom_types, $excluded_types);

        $had_auto_create = array_key_exists('auto_create_infocoms', $CFG_GLPI);
        $auto_create_original = $CFG_GLPI['auto_create_infocoms'] ?? null;
        $em = \itsmng\Database\Orm::create($DB);
        $parents = [];
        $inputFor = function (\CommonDBTM $item, string $name) use ($em, &$createParent): array {
            $input = [];
            if ($item->isField($item::getNameField())) {
                $input[$item::getNameField()] = $name;
            }
            if ($item->isField('entities_id')) {
                $input['entities_id'] = (int)$_SESSION['glpiactive_entity'];
            }
            $metadata = $em->getClassMetadata(\itsmng\Database\EntityRegistry::tables()[$item::getTable()]);
            foreach ($metadata->associationMappings as $mapping) {
                if (!$mapping->isToOneOwningSide()) {
                    continue;
                }
                foreach ($mapping->joinColumns as $join) {
                    if (!$join->nullable && !array_key_exists($join->name, $input)) {
                        $target = $em->getClassMetadata($mapping->targetEntity)->getTableName();
                        $input[$join->name] = $createParent(getItemTypeForTable($target));
                    }
                }
            }
            // Item_Devices owns a subject through its actual public role fields.
            // Use an existing, authorized Computer instead of a fabricated ID.
            if ($item instanceof \Item_Devices) {
                $this->array($item::itemAffinity())->contains('Computer');
                $input[$item::$itemtype_1] = 'Computer';
                $input[$item::$items_id_1] = $createParent('Computer');
            }
            return $input;
        };
        $createParent = function (string $type) use (&$parents, $inputFor): int {
            if (!array_key_exists($type, $parents)) {
                $parent = new $type();
                $id = $parent->add($inputFor($parent, 'auto_infocom_parent_' . $type));
                $this->integer($id)->isGreaterThan(0);
                $parents[$type] = $id;
            }
            return $parents[$type];
        };

        try {
            $infocom = new \Infocom();
            foreach ($infocom_types as $asset_type) {
                // Prepare public parents before either child control. Required
                // ownership comes from entity mappings, not a fixture catalogue.
                $CFG_GLPI['auto_create_infocoms'] = 0;
                $asset = new $asset_type();
                $input = $inputFor($asset, 'auto_infocom_test');
                $CFG_GLPI['auto_create_infocoms'] = 1;
                $asset_id = $asset->add($input);
                $this->integer($asset_id)->isGreaterThan(0);
                $this->boolean($infocom->getFromDBforDevice($asset_type, $asset_id))->isTrue();

                $CFG_GLPI['auto_create_infocoms'] = 0;
                $asset = new $asset_type();
                $asset_id2 = $asset->add($inputFor($asset, 'auto_infocom_test2'));
                $this->integer($asset_id2)->isGreaterThan(0);
                $this->boolean($infocom->getFromDBforDevice($asset_type, $asset_id2))->isFalse();
            }
        } finally {
            if ($had_auto_create) {
                $CFG_GLPI['auto_create_infocoms'] = $auto_create_original;
            } else {
                unset($CFG_GLPI['auto_create_infocoms']);
            }
        }
    }
}

/** Count real SQL compilation while retaining Doctrine's standard finalizer. */
final class ConfigQueryCacheWalker extends \Doctrine\ORM\Query\SqlOutputWalker
{
    public static int $compilations = 0;

    public function getFinalizer(\Doctrine\ORM\Query\AST\DeleteStatement|\Doctrine\ORM\Query\AST\UpdateStatement|\Doctrine\ORM\Query\AST\SelectStatement $AST): \Doctrine\ORM\Query\Exec\SqlFinalizer
    {
        ++self::$compilations;
        return parent::getFinalizer($AST);
    }
}
