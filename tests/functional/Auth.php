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

use Auth as ApplicationAuth;
use AuthLDAP as ApplicationLdap;
use DateTime;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use itsmng\Database\Entity\AuthLDAP;
use itsmng\Database\Entity\AuthLdapReplicate;
use itsmng\Database\Entity\AuthMail;
use itsmng\Database\Entity\Entity as ScopeEntity;
use itsmng\Database\Entity\Profile as ProfileRecord;
use itsmng\Database\Entity\ProfileUser as ProfileGrant;
use itsmng\Database\Entity\User as UserRecord;
use itsmng\Database\Entity\UserEmail as EmailRecord;
use itsmng\Database\Orm;
use itsmng\Database\UnsupportedCriteria;
use mock\DBmysql as LocalLdapAdapterProbe;
use mock\DBmysql as RuleTypeAdapterProbe;
use ReflectionProperty;
use Rule as LegacyRule;
use RuleRight as LegacyRuleRight;
use stdClass;
use tests\fixtures\ScalarReadProbe;
use Toolbox;
use User as ApplicationUser;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/auth.class.php */

class Auth extends DbTestCase
{
    public function testAccountReadsStayCurrentAndKeepDirtyOwnersAndDirectoryEligibility(): void
    {
        global $DB, $CFG_GLPI;
        $session = $_SESSION;
        $expiration = $CFG_GLPI['password_expiration_delay'];
        $lock = $CFG_GLPI['password_expiration_lock_delay'];
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $connection = $DB->getDoctrineConnection();
            $owner = Orm::create($DB);
            $scope = $owner->find(ScopeEntity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $local = new UserRecord();
            $local->entities = $scope;
            $local->name = 'account_read_' . $this->getUniqueString();
            $local->authtype = ApplicationAuth::DB_GLPI;
            $fixturePassword = 'Local fixture password';
            $local->password = ApplicationAuth::getPasswordHash($fixturePassword);
            $local->password_last_update = new DateTime();
            $directory = new AuthLDAP();
            $directory->name = 'Account directory ' . $this->getUniqueString();
            $directory->is_active = true;
            $external = new UserRecord();
            $external->entities = $scope;
            $external->name = 'directory_read_' . $this->getUniqueString();
            $external->authtype = ApplicationAuth::LDAP;
            $external->authldap = $directory;
            $external->auth_source_code = null;
            $external->password = '';
            $external->user_dn = 'uid=fixture,dc=fixture,dc=invalid';
            $email = new EmailRecord();
            $email->users = $local;
            $email->email = 'account-' . $this->getUniqueString() . '@fixture.invalid';
            $grant = new ProfileGrant();
            $grant->users = $external;
            $grant->profiles = $owner->find(ProfileRecord::class, (int)$_SESSION['glpiactiveprofile']['id']);
            $grant->entities = $scope;
            $grant->is_recursive = false;
            foreach ([$local, $directory, $external, $email, $grant] as $record) {
                $owner->persist($record);
            }
            $owner->flush();
            $CFG_GLPI['password_expiration_delay'] = -1;
            $CFG_GLPI['password_expiration_lock_delay'] = -1;
            $auth = new ApplicationAuth();
            $this->integer($auth->userExists(['AND' => ['name' => $local->name, 'email' => $email->email]]))
                ->isIdenticalTo(ApplicationAuth::USER_EXISTS_WITH_PWD);
            $this->integer($auth->userExists(['name' => $external->name]))->isIdenticalTo(ApplicationAuth::USER_EXISTS_WITHOUT_PWD);
            $this->string($auth->user_dn)->isIdenticalTo($external->user_dn);
            $this->integer($auth->userExists(['name' => 'absent-' . $this->getUniqueString()]))
                ->isIdenticalTo(ApplicationAuth::USER_DOESNT_EXIST);
            $this->exception(static fn () => $auth->userExists(['name' => new stdClass()]))
                ->isInstanceOf(UnsupportedCriteria::class);
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $this->boolean((new ApplicationAuth())->connection_db($local->name, $fixturePassword))->isTrue();
            $this->boolean((new ApplicationAuth())->connection_db($local->name, 'Wrong fixture password'))->isFalse();
            $user = new ApplicationUser();
            $this->boolean($user->getFromDB($external->id))->isTrue();
            $this->output(static fn () => ApplicationAuth::showSynchronizationForm($user))->contains('force_ldap_resynch');
            $connection->update('glpi_authldaps', ['is_active' => false], ['id' => $directory->id], ['is_active' => Types::BOOLEAN]);
            $this->output(static fn () => ApplicationAuth::showSynchronizationForm($user))->notContains('force_ldap_resynch');
            $user->fields['auths_id'] = 0;
            $this->output(static fn () => ApplicationAuth::showSynchronizationForm($user))->notContains('force_ldap_resynch');
            $user->fields['auths_id'] = null;
            $this->output(static fn () => ApplicationAuth::showSynchronizationForm($user))->notContains('force_ldap_resynch');
            $user->fields['auths_id'] = $directory->id;
            $connection->update('glpi_useremails', ['email' => 'changed-' . $email->email], ['id' => $email->id]);
            $this->integer($auth->userExists(['email' => $email->email]))->isIdenticalTo(ApplicationAuth::USER_DOESNT_EXIST);
            $connection->update('glpi_users', ['password' => ApplicationAuth::getPasswordHash('Changed fixture password')], ['id' => $local->id]);
            $this->boolean((new ApplicationAuth())->connection_db($local->name, $fixturePassword))->isFalse();
            $local->name = 'Unflushed independent account';
            $directory->is_active = true;
            Orm::read($DB, function (EntityManager $outer) use ($local, $external, $directory, $owner, $user): void {
                $owned = $outer->find(UserRecord::class, $external->id);
                $owned->user_dn = 'Unflushed enclosing DN';
                $probe = new ApplicationAuth();
                $this->integer($probe->userExists(['id' => $external->id]))->isIdenticalTo(ApplicationAuth::USER_EXISTS_WITHOUT_PWD);
                $this->string($probe->user_dn)->isIdenticalTo('uid=fixture,dc=fixture,dc=invalid');
                $this->output(static fn () => ApplicationAuth::showSynchronizationForm($user))->notContains('force_ldap_resynch');
                $this->boolean($outer->contains($owned))->isTrue();
                $this->string($owned->user_dn)->isIdenticalTo('Unflushed enclosing DN');
                $this->boolean($owner->contains($local))->isTrue();
                $this->string($local->name)->isIdenticalTo('Unflushed independent account');
                $this->boolean($owner->contains($directory))->isTrue();
                $this->boolean($directory->is_active)->isTrue();
            });
        } finally {
            $_SESSION = $session;
            $CFG_GLPI['password_expiration_delay'] = $expiration;
            $CFG_GLPI['password_expiration_lock_delay'] = $lock;
        }
    }

    public function testAccountCredentialReadPinsCustomRouteBeforeLoginConversion(): void
    {
        global $DB, $CFG_GLPI;
        $original = $DB;
        $session = $_SESSION;
        $expiration = $CFG_GLPI['password_expiration_delay'];
        $lock = $CFG_GLPI['password_expiration_lock_delay'];
        try {
            $owner = Orm::create($DB);
            $account = new UserRecord();
            $account->entities = $owner->find(ScopeEntity::class, 0);
            $account->name = 'custom_account_' . $this->getUniqueString();
            $account->authtype = ApplicationAuth::DB_GLPI;
            $account->password = ApplicationAuth::getPasswordHash('Custom fixture password');
            $account->password_last_update = new DateTime();
            $owner->persist($account);
            $owner->flush();
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;

                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;

                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }
            };
            $selected->events = new EventManager();
            $selected->events->addEventListener(['onClear'], $observer);
            $selected->observer = $observer;
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new LocalLdapAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route): Connection {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $name = new class ($this, $connection, $observer, $other, $route, $account->name) {
                public function __construct(
                    private object $test,
                    private Connection $connection,
                    private object $observer,
                    private Connection $other,
                    private Connection &$route,
                    private string $name
                ) {
                }

                public function __toString(): string
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    $this->observer->trace[] = 'converted';
                    $this->route = $this->other;
                    return $this->name;
                }
            };
            $CFG_GLPI['password_expiration_delay'] = -1;
            $CFG_GLPI['password_expiration_lock_delay'] = -1;
            // Wrong fixture password stops after the completed credential read, before live user hooks.
            $this->boolean((new ApplicationAuth())->connection_db($name, 'Wrong custom fixture password'))->isFalse();
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'converted']);
            $this->array($selected->queries)->hasSize(1);
            $this->array($other->queries)->isEmpty();
            $this->integer($observer->clears)->isIdenticalTo(0);
        } finally {
            $DB = $original;
            $_SESSION = $session;
            $CFG_GLPI['password_expiration_delay'] = $expiration;
            $CFG_GLPI['password_expiration_lock_delay'] = $lock;
        }
    }

    protected function loginProvider()
    {
        return [
           ['john', 1],
           ['john doe', 1],
           ['john_doe', 1],
           ['john-doe', 1],
           ['john.doe', 1],
           ['john \'o doe', 1],
           ['john@doe.com', 1],
           ['@doe.com', 1],
           ['john " doe', 0],
           ['john^doe', 0],
           ['john$doe', 0],
           [null, 0],
           ['', 0]
        ];
    }

    /**
     * @dataProvider loginProvider
     */

    public function testIsValidLogin($login, $isvalid)
    {
        $this->variable(ApplicationAuth::isValidLogin($login))->isIdenticalTo($isvalid);
    }

    public function testLocalDirectoryProjectionsAreCurrentAndKeepLiveOwners(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $owner = Orm::create($DB);
        $master = new AuthLDAP();
        $master->name = 'Projection master ' . $this->getUniqueString();
        $master->is_default = true;
        $other = new AuthLDAP();
        $other->name = 'Projection other ' . $this->getUniqueString();
        $other->is_default = false;
        $owner->persist($master);
        $owner->persist($other);
        $zeta = new AuthLdapReplicate();
        $zeta->authldaps = $master;
        $zeta->name = 'Zeta ' . $this->getUniqueString();
        $zeta->host = null;
        $zeta->port = 1636;
        $alpha = new AuthLdapReplicate();
        $alpha->authldaps = $master;
        $alpha->name = 'Alpha ' . $this->getUniqueString();
        $alpha->host = 'fixture.invalid';
        $user = new UserRecord();
        $user->name = 'Projection LDAP user ' . $this->getUniqueString();
        $user->entities = $owner->find(ScopeEntity::class, 0);
        $user->authtype = ApplicationAuth::LDAP;
        $user->authldap = $master;
        $user->sync_field = '';
        foreach ([$zeta, $alpha, $user] as $record) {
            $owner->persist($record);
        }
        $owner->flush();
        $model = new ApplicationLdap();
        $model->fields['id'] = (string)$master->id;
        $expected = [
            ['id' => $zeta->id, 'host' => null, 'port' => 1636],
            ['id' => $alpha->id, 'host' => 'fixture.invalid', 'port' => 389],
        ];
        $this->array(ApplicationLdap::getAllReplicateForAMaster((string)$master->id))->isIdenticalTo($expected);
        $this->array(ApplicationLdap::getAllReplicateForAMaster(0))->isEmpty();
        $this->array(ApplicationLdap::getAllReplicateForAMaster(null))->isEmpty();
        $keys = array_flip([$master->id, $other->id]);
        $servers = array_intersect_key(ApplicationLdap::getLdapServers(), $keys);
        $this->array(array_keys($servers))->isIdenticalTo([$master->id, $other->id]);
        $this->variable($servers[$master->id]['host'])->isNull();
        $this->boolean($model->isSyncFieldUsed())->isTrue();
        $this->output(static fn () => $model->showFormReplicatesConfig())
            ->matches('/' . preg_quote($alpha->name, '/') . '[\s\S]*' . preg_quote($zeta->name, '/') . '/');
        $connection->update('glpi_authldapreplicates', ['host' => 'changed.invalid', 'port' => 1389], ['id' => $zeta->id]);
        $connection->update('glpi_authldaps', ['name' => 'Current projection master'], ['id' => $master->id]);
        $connection->update('glpi_users', ['sync_field' => null], ['id' => $user->id]);
        $fresh = ApplicationLdap::getAllReplicateForAMaster($master->id);
        $this->array($fresh[0])->isIdenticalTo(['id' => $zeta->id, 'host' => 'changed.invalid', 'port' => 1389]);
        $this->array($expected[0])->isIdenticalTo(['id' => $zeta->id, 'host' => null, 'port' => 1636]);
        $this->string(ApplicationLdap::getLdapServers()[$master->id]['name'])->isIdenticalTo('Current projection master');
        $this->boolean($model->isSyncFieldUsed())->isFalse();
        $master->name = 'Unflushed independent master';
        $alpha->host = 'unflushed.invalid';
        Orm::read($DB, function (EntityManager $outer) use ($master, $alpha, $owner, $model, $fresh): void {
            $owned = $outer->find(AuthLDAP::class, $master->id);
            $owned->name = 'Unflushed enclosing master';
            $this->string(ApplicationLdap::getLdapServers()[$master->id]['name'])->isIdenticalTo('Current projection master');
            $this->array(ApplicationLdap::getAllReplicateForAMaster($master->id))->isIdenticalTo($fresh);
            $this->boolean($model->isSyncFieldUsed())->isFalse();
            $this->boolean($outer->contains($owned))->isTrue();
            $this->string($owned->name)->isIdenticalTo('Unflushed enclosing master');
            $this->boolean($owner->contains($master))->isTrue();
            $this->string($master->name)->isIdenticalTo('Unflushed independent master');
            $this->boolean($owner->contains($alpha))->isTrue();
            $this->string($alpha->host)->isIdenticalTo('unflushed.invalid');
        });
    }

    public function testLocalDirectoryCustomProjectionPinsRouteBeforeModelCoercion(): void
    {
        global $DB;
        $original = $DB;
        try {
            $owner = Orm::create($DB);
            $master = new AuthLDAP();
            $master->name = 'Custom local directory ' . $this->getUniqueString();
            $replica = new AuthLdapReplicate();
            $replica->authldaps = $master;
            $replica->host = 'stored.invalid';
            $owner->persist($master);
            $owner->persist($replica);
            $owner->flush();
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof AuthLdapReplicate) {
                        $event->getObject()->host = 'custom.invalid';
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;
                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }
            };
            $selected->observer = $observer;
            $selected->events = new EventManager();
            $selected->events->addEventListener(['postLoad', 'onClear'], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new LocalLdapAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $model = new class ($this, $master->id, $connection, $observer, $other, $route) extends ApplicationLdap {
                public function __construct(private object $test, private int $id, private Connection $connection, private object $observer, private Connection $other, private Connection &$route)
                {
                }
                public function getID()
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    $this->observer->trace[] = 'id';
                    $this->route = $this->other;
                    return (string)$this->id;
                }
            };
            $this->boolean($model->isSyncFieldUsed())->isFalse();
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'id']);
            $this->array($other->queries)->isEmpty();
            $this->array($selected->queries)->isNotEmpty();
            $route = $selected;
            $rows = ApplicationLdap::getAllReplicateForAMaster($master->id);
            $this->array($rows)->isIdenticalTo([['id' => $replica->id, 'host' => 'custom.invalid', 'port' => 389]]);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->string($connection->fetchOne('SELECT host FROM glpi_authldapreplicates WHERE id=?', [$replica->id]))->isIdenticalTo('stored.invalid');
        } finally {
            $DB = $original;
        }
    }

    public function testLocalDirectorySelectionReadsStayCurrentAndKeepOwners(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $existing = $connection->fetchAllAssociative('SELECT id, CASE WHEN is_active THEN 1 ELSE 0 END AS active, CASE WHEN is_default THEN 1 ELSE 0 END AS selected FROM glpi_authldaps');
        $owner = Orm::create($DB);
        $directories = [];
        try {
            $connection->executeStatement(
                'UPDATE glpi_authldaps SET is_active = ?, is_default = ?',
                [false, false],
                [Types::BOOLEAN, Types::BOOLEAN]
            );
            foreach ([
                [true, false, 'mail', ''],
                [true, true, '', 'mail'],
                [true, true, 'mail', ''],
                [true, false, null, null],
                [false, true, 'mail', ''],
            ] as $index => [$active, $default, $firstEmail, $secondEmail]) {
                $directory = new AuthLDAP();
                $directory->name = 'Local selection ' . $index . ' ' . $this->getUniqueString();
                $directory->is_active = $active;
                $directory->is_default = $default;
                $directory->email1_field = $firstEmail;
                $directory->email2_field = $secondEmail;
                $directory->email3_field = null;
                $directory->email4_field = '';
                $owner->persist($directory);
                $directories[] = $directory;
            }
            $owner->flush();
            [$first, $default, $laterDefault, $noEmail] = $directories;
            $this->integer(ApplicationLdap::getNumberOfServers())->isIdenticalTo(4);
            $this->boolean(ApplicationLdap::useAuthLdap())->isTrue();
            $this->integer(ApplicationLdap::getDefault())->isIdenticalTo((int)$default->id);
            $this->array(array_map('intval', ApplicationLdap::getServersWithImportByEmailActive()))
                ->isIdenticalTo([(int)$default->id, (int)$laterDefault->id, (int)$first->id]);
            $connection->update('glpi_authldaps', ['is_active' => false], ['id' => $default->id], ['is_active' => Types::BOOLEAN]);
            $this->integer(ApplicationLdap::getNumberOfServers())->isIdenticalTo(3);
            $this->integer(ApplicationLdap::getDefault())->isIdenticalTo((int)$laterDefault->id);
            $this->array(array_map('intval', ApplicationLdap::getServersWithImportByEmailActive()))
                ->isIdenticalTo([(int)$laterDefault->id, (int)$first->id]);
            $connection->update('glpi_authldaps', ['email1_field' => ''], ['id' => $first->id]);
            $connection->update('glpi_authldaps', ['is_default' => false], ['id' => $laterDefault->id], ['is_default' => Types::BOOLEAN]);
            $this->integer(ApplicationLdap::getDefault())->isIdenticalTo(0);
            $this->array(array_map('intval', ApplicationLdap::getServersWithImportByEmailActive()))->isIdenticalTo([(int)$laterDefault->id]);
            $connection->update('glpi_authldaps', ['is_active' => false], ['id' => $laterDefault->id], ['is_active' => Types::BOOLEAN]);
            $this->array(ApplicationLdap::getServersWithImportByEmailActive())->isEmpty();
            $this->boolean($owner->contains($first))->isTrue();
            Orm::withReadConnection($connection, function (?EntityManager $outer) use ($first): void {
                $managed = $outer->find(AuthLDAP::class, $first->id);
                $name = $managed->name . '-unflushed';
                $managed->name = $name;
                $this->integer(ApplicationLdap::getNumberOfServers())->isIdenticalTo(2);
                $this->integer(ApplicationLdap::getDefault())->isIdenticalTo(0);
                $this->array(ApplicationLdap::getServersWithImportByEmailActive())->isEmpty();
                $this->boolean($outer->contains($managed))->isTrue();
                $this->string($managed->name)->isIdenticalTo($name);
            });
            foreach ([$first, $noEmail] as $directory) {
                $connection->update('glpi_authldaps', ['is_active' => false], ['id' => $directory->id], ['is_active' => Types::BOOLEAN]);
            }
            $this->integer(ApplicationLdap::getNumberOfServers())->isIdenticalTo(0);
            $this->boolean(ApplicationLdap::useAuthLdap())->isFalse();
            $this->integer(ApplicationLdap::getDefault())->isIdenticalTo(0);
            $this->array(ApplicationLdap::getServersWithImportByEmailActive())->isEmpty();
        } finally {
            foreach ($directories as $directory) {
                if ($directory->id !== null) {
                    $connection->delete('glpi_authldaps', ['id' => $directory->id]);
                }
            }
            $owner->clear();
            foreach ($existing as $directory) {
                $connection->update(
                    'glpi_authldaps',
                    ['is_active' => (bool)(int)$directory['active'],
                    'is_default' => (bool)(int)$directory['selected']],
                    ['id' => $directory['id']],
                    ['is_active' => Types::BOOLEAN, 'is_default' => Types::BOOLEAN]
                );
            }
        }
    }

    public function testGetLoginAuthMethods()
    {
        $methods = ApplicationAuth::getLoginAuthMethods();
        $expected = [
           '_default'  => 'local',
           'local'     => 'ITSM-NG internal database'
        ];
        $this->array($methods)->isIdenticalTo($expected);
        $manager = Orm::create($GLOBALS['DB']);
        $connection = $manager->getConnection();
        $mail = new AuthMail();
        $mail->name = 'Login mail source';
        $manager->persist($mail);
        $ldap = null;
        if (Toolbox::canUseLdap()) {
            $ldap = new AuthLDAP();
            $ldap->name = 'Login LDAP source';
            $manager->persist($ldap);
        }
        try {
            $manager->flush();
            $manager->clear();
            // Warm the canonical read owner before measuring repeated enumeration.
            $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($expected);
            $auth = new ApplicationAuth();
            $auth->getAuthMethods();
            $this->array(array_keys($auth->authtypes))->isIdenticalTo(['ldap', 'mail']);
            $this->string($auth->authtypes['mail'][$mail->id]['name'])->isIdenticalTo($mail->name);
            $this->variable($auth->authtypes['mail'][$mail->id]['comment'])->isNull();
            $this->integer($auth->authtypes['mail'][$mail->id]['is_active'])->isIdenticalTo(0);
            $dropdownOptions = ['display' => false, 'noselect2' => true, 'rand' => 731,
                'name' => 'authtype', 'display_emptychoice' => false];
            $inactiveDropdown = ApplicationAuth::dropdown($dropdownOptions);
            $this->string($inactiveDropdown)->contains(__('Authentication on ITSM-NG database'))
                ->notContains(__('Authentication on mail server'))
                ->notContains(__('Authentication on a LDAP directory'));
            $rule = new LegacyRuleRight();
            $pattern = static fn () => $rule->displayCriteriaSelectPattern('rule_type', 'TYPE', LegacyRule::PATTERN_IS, ApplicationAuth::MAIL);
            $this->output($pattern)->contains(__('Authentication on ITSM-NG database'))
                ->notContains(__('Authentication on mail server'))
                ->notContains(__('Authentication on a LDAP directory'))
                ->notContains(__('External authentications'));
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($expected);
            $connection->update(
                'glpi_authmails',
                ['is_active' => true],
                ['id' => $mail->id],
                ['is_active' => Types::BOOLEAN]
            );
            $active = $expected + ['mail-' . $mail->id => $mail->name];
            $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($active);
            $connection->update(
                'glpi_authmails',
                ['name' => 'Fresh login mail'],
                ['id' => $mail->id]
            );
            $active['mail-' . $mail->id] = 'Fresh login mail';
            $connection->update('glpi_authmails', ['comment' => 'NULL'], ['id' => $mail->id]);
            $auth->getAuthMethods();
            $this->string($auth->authtypes['mail'][$mail->id]['name'])->isIdenticalTo('Fresh login mail');
            $this->string($auth->authtypes['mail'][$mail->id]['comment'])->isIdenticalTo('NULL');
            $this->integer($auth->authtypes['mail'][$mail->id]['is_active'])->isIdenticalTo(1);
            $this->string(ApplicationAuth::dropdown($dropdownOptions))
                ->contains(__('Authentication on mail server'));
            $this->output($pattern)->contains(__('Authentication on mail server'))
                ->notContains(__('Authentication on a LDAP directory'));

            // Exercise the User field-selection caller, which requests returned HTML.
            $this->string(ApplicationUser::getSpecificValueToSelect(
                'authtype',
                'authtype',
                ApplicationAuth::MAIL,
                ['noselect2' => true, 'rand' => 731, 'display_emptychoice' => false]
            ))
                ->contains(__('Authentication on mail server'));

            $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($active);
            if ($ldap !== null) {
                $connection->update(
                    'glpi_authldaps',
                    ['is_active' => true, 'is_default' => true],
                    ['id' => $ldap->id],
                    ['is_active' => Types::BOOLEAN, 'is_default' => Types::BOOLEAN]
                );
                $active = [
                    '_default' => 'ldap-' . $ldap->id,
                    'local' => $expected['local'],
                    'ldap-' . $ldap->id => $ldap->name,
                    'mail-' . $mail->id => 'Fresh login mail',
                ];
                $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($active);
                $connection->update(
                    'glpi_authldaps',
                    ['name' => 'Fresh login LDAP', 'is_default' => false],
                    ['id' => $ldap->id],
                    ['is_default' => Types::BOOLEAN]
                );
                $active['_default'] = 'local';
                $active['ldap-' . $ldap->id] = 'Fresh login LDAP';
                $auth->getAuthMethods();
                $this->string($auth->authtypes['ldap'][$ldap->id]['name'])->isIdenticalTo('Fresh login LDAP');
                $this->integer($auth->authtypes['ldap'][$ldap->id]['is_active'])->isIdenticalTo(1);
                $this->integer($auth->authtypes['ldap'][$ldap->id]['is_default'])->isIdenticalTo(0);
                $this->string(ApplicationAuth::dropdown($dropdownOptions))
                    ->contains(__('Authentication on a LDAP directory'))
                    ->contains(__('External authentications'));
                $this->output($pattern)->contains(__('Authentication on a LDAP directory'))
                    ->contains(__('External authentications'))
                    ->contains(__('Authentication on mail server'));


                $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($active);
                $connection->update(
                    'glpi_authldaps',
                    ['is_active' => false],
                    ['id' => $ldap->id],
                    ['is_active' => Types::BOOLEAN]
                );
            }
            $connection->update(
                'glpi_authmails',
                ['is_active' => false],
                ['id' => $mail->id],
                ['is_active' => Types::BOOLEAN]
            );
            $this->array(ApplicationAuth::getLoginAuthMethods())->isIdenticalTo($expected);
            $connection->update('glpi_authmails', ['comment' => null], ['id' => $mail->id]);
            $auth->getAuthMethods();
            $this->variable($auth->authtypes['mail'][$mail->id]['comment'])->isNull();
            $this->integer($auth->authtypes['mail'][$mail->id]['is_active'])->isIdenticalTo(0);
            if ($ldap !== null) {
                $this->integer($auth->authtypes['ldap'][$ldap->id]['is_active'])->isIdenticalTo(0);
            }
            $this->string(ApplicationAuth::dropdown($dropdownOptions))->isIdenticalTo($inactiveDropdown);
            // All positive source-selection and freshness checks precede the genuine old-runtime failure.
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $this->output($pattern)->notContains(__('Authentication on mail server'))
                ->notContains(__('Authentication on a LDAP directory'))
                ->notContains(__('External authentications'));
            $retained = $manager->find(AuthMail::class, $mail->id);
            $retained->name = 'Independent pending rule source';
            $database = $GLOBALS['DB'];
            try {
                $connection->update('glpi_authmails', ['is_active' => true], ['id' => $mail->id], ['is_active' => Types::BOOLEAN]);
                Orm::read(
                    $GLOBALS['DB'],
                    function (EntityManager $outer) use ($mail, $pattern): void {
                        $owned = $outer->find(AuthMail::class, $mail->id);
                        $owned->name = 'Outer pending rule source';
                        $this->output($pattern)->contains(__('Authentication on mail server'));
                        $this->boolean($outer->contains($owned))->isTrue();
                        $this->string($owned->name)->isIdenticalTo('Outer pending rule source');
                    }
                );
                $probe = new ScalarReadProbe($connection);
                $this->mockGenerator()->orphanize('__construct');
                $adapter = new RuleTypeAdapterProbe();
                $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, $database) {
                    $GLOBALS['DB'] = $database;
                    return $probe;
                };
                $GLOBALS['DB'] = $adapter;
                $this->output($pattern)->contains(__('Authentication on mail server'));
                $this->array($probe->queries)->hasSize(1);
                $this->string($probe->queries[0]['sql'])->contains('glpi_authldaps');
                $this->object($GLOBALS['DB'])->isIdenticalTo($database);
                $this->output(function () use ($rule): void {
                    $this->boolean($rule->displayAdditionalRuleCondition(LegacyRule::PATTERN_IS, ['field' => 'other'], 'rule_type', ApplicationAuth::MAIL))->isFalse();
                })->isEmpty();
                $this->array($probe->queries)->hasSize(1);
                $this->boolean($manager->contains($retained))->isTrue();
                $this->string($retained->name)->isIdenticalTo('Independent pending rule source');
            } finally {
                $GLOBALS['DB'] = $database;
                $connection->update('glpi_authmails', ['is_active' => false], ['id' => $mail->id], ['is_active' => Types::BOOLEAN]);
            }

        } finally {
            $manager->clear();
        }
    }

    /**
     * Provides data to test account lock strategy on password expiration.
     *
     * @return array
     */
    protected function lockStrategyProvider()
    {
        $tests = [];

        // test with no password expiration
        $tests[] = [
           'last_update'   => date('Y-m-d H:i:s', strtotime('-10 years')),
           'exp_delay'     => -1,
           'lock_delay'    => -1,
           'expected_lock' => false,
        ];

        // tests with no lock on password expiration
        $cases = [
           '-5 days'  => false,
           '-30 days' => false,
        ];
        foreach ($cases as $last_update => $expected_lock) {
            $tests[] = [
               'last_update'   => date('Y-m-d H:i:s', strtotime($last_update)),
               'exp_delay'     => 15,
               'lock_delay'    => -1,
               'expected_lock' => $expected_lock,
            ];
        }

        // tests with immediate lock on password expiration
        $cases = [
           '-5 days'  => false,
           '-30 days' => true,
        ];
        foreach ($cases as $last_update => $expected_lock) {
            $tests[] = [
               'last_update'   => date('Y-m-d H:i:s', strtotime($last_update)),
               'exp_delay'     => 15,
               'lock_delay'    => 0,
               'expected_lock' => $expected_lock,
            ];
        }

        // tests with delayed lock on password expiration
        $cases = [
           '-5 days'  => false,
           '-20 days' => false,
           '-30 days' => true,
        ];
        foreach ($cases as $last_update => $expected_lock) {
            $tests[] = [
               'last_update'   => date('Y-m-d H:i:s', strtotime($last_update)),
               'exp_delay'     => 15,
               'lock_delay'    => 10,
               'expected_lock' => $expected_lock,
            ];
        }

        return $tests;
    }

    /**
     * Test that account is lock when authentication is done using an expired password.
     *
     * @dataProvider lockStrategyProvider
     */
    public function testAccountLockStrategy(string $last_update, int $exp_delay, int $lock_delay, bool $expected_lock)
    {
        global $CFG_GLPI;

        // reset session to prevent session having less rights to create a user
        $this->login();

        $user = new \User();
        $username = 'test_lock_' . mt_rand();
        $user_id = (int) $user->add([
           'name'         => $username,
           'password'     => 'test',
           'password2'    => 'test',
           '_profiles_id' => 1,
        ]);
        $this->integer($user_id)->isGreaterThan(0);
        $this->boolean($user->update(['id' => $user_id, 'password_last_update' => $last_update]))->isTrue();

        $cfg_backup = $CFG_GLPI;
        $CFG_GLPI['password_expiration_delay'] = $exp_delay;
        $CFG_GLPI['password_expiration_lock_delay'] = $lock_delay;
        $auth = new \Auth();
        $is_logged = $auth->login($username, 'test', true);
        $CFG_GLPI = $cfg_backup;

        $this->boolean($is_logged)->isEqualTo(!$expected_lock);
        $this->boolean($user->getFromDB($user->fields['id']))->isTrue();
        $this->boolean((bool)$user->fields['is_active'])->isEqualTo(!$expected_lock);
    }
}
