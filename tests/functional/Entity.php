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

use DbTestCase;
use Profile_User;

/* Test for inc/entity.class.php */

class Entity extends DbTestCase
{
    protected $cached_methods = [
       'testChangeEntityParentCached'
    ];

    public function testSonsAncestors()
    {
        $ent0 = getItemByTypeName('Entity', '_test_root_entity');
        $this->string($ent0->getField('completename'))
           ->isIdenticalTo('Root entity > _test_root_entity');

        $ent1 = getItemByTypeName('Entity', '_test_child_1');
        $this->string($ent1->getField('completename'))
           ->isIdenticalTo('Root entity > _test_root_entity > _test_child_1');

        $ent2 = getItemByTypeName('Entity', '_test_child_2');
        $this->string($ent2->getField('completename'))
           ->isIdenticalTo('Root entity > _test_root_entity > _test_child_2');

        $this->array(array_keys(getAncestorsOf('glpi_entities', $ent0->getID())))
           ->isIdenticalTo([0]);
        $this->array(array_values(getAncestorsOf('glpi_entities', $ent0->getID())))
           ->isIdenticalTo([0]);
        $this->array(array_keys(getSonsOf('glpi_entities', $ent0->getID())))
           ->isEqualTo([$ent0->getID(), $ent1->getID(), $ent2->getID()]);
        $this->array(array_values(getSonsOf('glpi_entities', $ent0->getID())))
           ->isIdenticalTo([$ent0->getID(), $ent1->getID(), $ent2->getID()]);

        $this->array(array_keys(getAncestorsOf('glpi_entities', $ent1->getID())))
           ->isEqualTo([0, $ent0->getID()]);
        $this->array(array_values(getAncestorsOf('glpi_entities', $ent1->getID())))
           ->isEqualTo([0, $ent0->getID()]);
        $this->array(array_keys(getSonsOf('glpi_entities', $ent1->getID())))
           ->isEqualTo([$ent1->getID()]);
        $this->array(array_values(getSonsOf('glpi_entities', $ent1->getID())))
           ->isEqualTo([$ent1->getID()]);

        $this->array(array_keys(getAncestorsOf('glpi_entities', $ent2->getID())))
           ->isEqualTo([0, $ent0->getID()]);
        $this->array(array_values(getAncestorsOf('glpi_entities', $ent2->getID())))
           ->isEqualTo([0, $ent0->getID()]);
        $this->array(array_keys(getSonsOf('glpi_entities', $ent2->getID())))
           ->isEqualTo([$ent2->getID()]);
        $this->array(array_values(getSonsOf('glpi_entities', $ent2->getID())))
           ->isEqualTo([$ent2->getID()]);
    }

    public function testPrepareInputForAdd()
    {
        $this->login();
        $entity = new \Entity();

        $this->boolean(
            $entity->prepareInputForAdd([
              'name' => ''
         ])
        )->isFalse();
        $this->hasSessionMessages(ERROR, ["You can't add an entity without name"]);

        $this->boolean(
            $entity->prepareInputForAdd([
              'anykey' => 'anyvalue'
         ])
        )->isFalse();
        $this->hasSessionMessages(ERROR, ["You can't add an entity without name"]);

        $this->array(
            $entity->prepareInputForAdd([
              'name' => 'entname'
         ])
        )
           ->string['name']->isIdenticalTo('entname')
           ->string['completename']->isIdenticalTo('entname')
           ->integer['level']->isIdenticalTo(1)
           ->integer['entities_id']->isIdenticalTo(0);
    }

    public function testRootEntityFormUsesUpdateButton()
    {
        $this->login();
        $_SESSION['glpiactiveprofile']['entity'] = READ | UPDATE;
        $_SESSION['glpiactiveentities'] = [0];

        $entity = new \Entity();

        ob_start();
        $entity->showForm(0, ['candel' => false]);
        $html = ob_get_clean();

        $this->string($html)->contains("name='update'");
        $this->string($html)->notContains("name='add'");
        $this->string($html)->notContains("name='delete'");
        $this->string($html)->notContains("name='purge'");
    }

    /**
     * Run getSonsOf tests
     *
     * @param boolean $cache Is cache enabled?
     *
     * @return void
     */
    public function runChangeEntityParent($cache = false)
    {
        global $DB, $GLPI_CACHE;

        $this->login();
        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        $sckey_ent1 = 'sons_cache_glpi_entities_' . $ent1;
        $sckey_ent2 = 'sons_cache_glpi_entities_' . $ent2;

        $this->boolean($DB->getDoctrineConnection()->isTransactionActive())->isTrue();
        $predicted = (int)$DB->getDoctrineConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM glpi_entities');
        $privateKey = 'ancestors_cache_glpi_entities_' . $predicted;
        $stale = [$ent2 => $ent2];
        if ($cache === true) {
            $this->boolean(\Toolbox::useCache())->isTrue();
            $GLPI_CACHE->set($privateKey, $stale);
            $this->boolean($GLPI_CACHE->has($privateKey))->isTrue();
            $this->array($GLPI_CACHE->get($privateKey))->isIdenticalTo($stale);
        }

        $entity = new \Entity();
        $new_id = (int)$entity->add([
           'name'         => 'Sub child entity',
           'entities_id'  => $ent1
        ]);
        $this->integer($new_id)->isGreaterThan(0);
        $this->integer($new_id)->isIdenticalTo($predicted);
        $ackey_new_id = 'ancestors_cache_glpi_entities_' . $new_id;

        $expected = [0 => 0, $ent0 => $ent0, $ent1 => $ent1];
        if ($cache === true) {
            $this->boolean(!$GLPI_CACHE->has($ackey_new_id) || $GLPI_CACHE->get($ackey_new_id) === $stale)->isTrue();
        }

        $ancestors = getAncestorsOf('glpi_entities', $new_id);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ackey_new_id))->isFalse();
        }

        $expected = [$ent1 => $ent1, $new_id => $new_id];

        $sons = getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($sckey_ent1))->isFalse();
        }

        //change parent entity
        $this->boolean(
            $entity->update([
              'id'           => $new_id,
              'entities_id'  => $ent2
         ])
        )->isTrue();

        if ($cache === true) {
            $GLPI_CACHE->set($ackey_new_id, [0 => 0, $ent0 => $ent0, $ent1 => $ent1]);
            $this->boolean($GLPI_CACHE->has($ackey_new_id))->isTrue();
            $this->array($GLPI_CACHE->get($ackey_new_id))->isIdenticalTo([0 => 0, $ent0 => $ent0, $ent1 => $ent1]);
        }
        $expected = [0 => 0, $ent0 => $ent0, $ent2 => $ent2];

        $ancestors = getAncestorsOf('glpi_entities', $new_id);
        $this->array($ancestors)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($ackey_new_id))->isFalse();
        }

        $expected = [$ent1 => $ent1];
        $sons = getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($sckey_ent1))->isFalse();
        }

        $expected = [$ent2 => $ent2, $new_id => $new_id];
        $sons = getSonsOf('glpi_entities', $ent2);
        $this->array($sons)->isIdenticalTo($expected);

        if ($cache === true) {
            $this->boolean($GLPI_CACHE->has($sckey_ent2))->isFalse();
        }

        $connection = $DB->getDoctrineConnection();
        $outerDepth = $connection->getTransactionNestingLevel();
        $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
        $primary = null;
        try {
            $this->boolean($entity->update(['id' => $new_id, 'entities_id' => $ent1]))->isTrue();
            $this->array(getAncestorsOf('glpi_entities', $new_id))->isIdenticalTo([0 => 0, $ent0 => $ent0, $ent1 => $ent1]);
            $this->array(getSonsOf('glpi_entities', $ent1))->isIdenticalTo([$ent1 => $ent1, $new_id => $new_id]);
            $this->array(getSonsOf('glpi_entities', $ent2))->isIdenticalTo([$ent2 => $ent2]);
            $frame->assertActive();
            if ($cache === true) {
                $this->boolean($GLPI_CACHE->has($ackey_new_id))->isFalse();
                $this->boolean($GLPI_CACHE->has($sckey_ent1))->isFalse();
                $this->boolean($GLPI_CACHE->has($sckey_ent2))->isFalse();
            }
        } catch (\Throwable $error) {
            $primary = $error;
        }
        try {
            $frame->rollBack();
        } catch (\Throwable $cleanup) {
            if ($primary !== null) {
                throw new \itsmng\Database\MutationRollbackFailure($primary, $cleanup);
            }
            throw $cleanup;
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($outerDepth);
        $this->boolean($entity->getFromDB($new_id))->isTrue();
        $this->integer($entity->fields['entities_id'])->isIdenticalTo($ent2);
        $this->array(getAncestorsOf('glpi_entities', $new_id))->isIdenticalTo([0 => 0, $ent0 => $ent0, $ent2 => $ent2]);
        $this->array(getSonsOf('glpi_entities', $ent1))->isIdenticalTo([$ent1 => $ent1]);
        $this->array(getSonsOf('glpi_entities', $ent2))->isIdenticalTo([$ent2 => $ent2, $new_id => $new_id]);

        //clean new entity
        $this->boolean(
            $entity->delete(['id' => $new_id], true)
        )->isTrue();
    }

    private function checkParentsSonsAreReset()
    {
        $ent0 = getItemByTypeName('Entity', '_test_root_entity', true);
        $ent1 = getItemByTypeName('Entity', '_test_child_1', true);
        $ent2 = getItemByTypeName('Entity', '_test_child_2', true);

        $expected = [0 => 0, 1 => $ent0];
        $ancestors = getAncestorsOf('glpi_entities', $ent1);
        $this->array($ancestors)->isIdenticalTo($expected);

        $ancestors = getAncestorsOf('glpi_entities', $ent2);
        $this->array($ancestors)->isIdenticalTo($expected);

        $expected = [$ent1 => $ent1];
        $sons = getSonsOf('glpi_entities', $ent1);
        $this->array($sons)->isIdenticalTo($expected);

        $expected = [$ent2 => $ent2];
        $sons = getSonsOf('glpi_entities', $ent2);
        $this->array($sons)->isIdenticalTo($expected);
    }

    public function testChangeEntityParent()
    {
        global $DB;
        //ensure db cache are unset
        $DB->update(
            'glpi_entities',
            [
              'ancestors_cache' => null,
              'sons_cache'      => null
         ],
            [true]
        );
        $this->runChangeEntityParent();
        // Preserve default tree results and repeat without durable cache publication.
        $this->checkParentsSonsAreReset();
        $this->runChangeEntityParent();
    }

    /**
     * @extensions apcu
     */
    public function testChangeEntityParentCached()
    {
        //run with cache
        // Cold private reads must not publish shared cache.
        $this->runChangeEntityParent(true);
        // Preserve default tree results and repeat without private cache publication.
        // Repeated private reads must not publish shared cache.
        $this->checkParentsSonsAreReset();
        $this->runChangeEntityParent(true);
    }

    public function testDeleteEntity()
    {
        $this->login();
        $root_id = getItemByTypeName('Entity', '_test_root_entity', true);

        $entity = new \Entity();
        $entity_id = (int)$entity->add(
            [
              'name'         => 'Test entity',
              'entities_id'  => $root_id,
         ]
        );
        $this->integer($entity_id)->isGreaterThan(0);

        $user_id = getItemByTypeName('User', 'normal', true);
        $profile_id = getItemByTypeName('Profile', 'Admin', true);

        $profile_user = new Profile_User();
        $profile_user_id = (int)$profile_user->add(
            [
              'entities_id' => $entity_id,
              'profiles_id' => $profile_id,
              'users_id'    => $user_id,
         ]
        );
        $this->integer($profile_user_id)->isGreaterThan(0);

        // Profile_User exists
        $this->boolean($profile_user->getFromDB($profile_user_id))->isTrue();

        $this->boolean($entity->delete(['id' => $entity_id]))->isTrue();

        // Profile_User has been deleted when entity has been deleted
        $this->boolean($profile_user->getFromDB($profile_user_id))->isFalse();
    }

    protected function inheritanceProvider()
    {
        return [
           ['admin_email', "username+admin@domain.tld"],
           ['admin_email_name', "Username admin"],
           ['admin_reply', "username+admin+reply@domain.tld"],
           ['admin_reply_name', "Username admin reply"],
        ];
    }

    /**
     * @dataProvider inheritanceProvider
     */
    public function testGetUsedConfig(string $field, $value)
    {
        $this->login();

        $root    = getItemByTypeName('Entity', 'Root entity', true);
        $parent  = getItemByTypeName('Entity', '_test_root_entity', true);
        $child_1 = getItemByTypeName('Entity', '_test_child_1', true);
        $child_2 = getItemByTypeName('Entity', '_test_child_2', true);

        $entity = new \Entity();
        $this->boolean($entity->update([
           'id'   => $root,
           $field => $value."_root",
        ]));

        $this->string(\Entity::getUsedConfig($field, $parent))->isEqualTo($value."_root");
        $this->string(\Entity::getUsedConfig($field, $child_1))->isEqualTo($value."_root");
        $this->string(\Entity::getUsedConfig($field, $child_2))->isEqualTo($value."_root");

        $this->boolean($entity->update([
           'id'   => $parent,
           $field => $value."_parent",
        ]));

        $this->string(\Entity::getUsedConfig($field, $parent))->isEqualTo($value."_parent");
        $this->string(\Entity::getUsedConfig($field, $child_1))->isEqualTo($value."_parent");
        $this->string(\Entity::getUsedConfig($field, $child_2))->isEqualTo($value."_parent");

        $this->boolean($entity->update([
           'id'   => $child_1,
           $field => $value."_child_1",
        ]));

        $this->string(\Entity::getUsedConfig($field, $parent))->isEqualTo($value."_parent");
        $this->string(\Entity::getUsedConfig($field, $child_1))->isEqualTo($value."_child_1");
        $this->string(\Entity::getUsedConfig($field, $child_2))->isEqualTo($value."_parent");

        $this->boolean($entity->update([
           'id'   => $child_2,
           $field => $value."_child_2",
        ]));

        $this->string(\Entity::getUsedConfig($field, $parent))->isEqualTo($value."_parent");
        $this->string(\Entity::getUsedConfig($field, $child_1))->isEqualTo($value."_child_1");
        $this->string(\Entity::getUsedConfig($field, $child_2))->isEqualTo($value."_child_2");

    }


    public function testEntityIdentifierLookups(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);

        foreach ([
            'ldap_dn' => 'getEntityIDByDN',
            'tag' => 'getEntityIDByTag',
            'mail_domain' => 'getEntityIDByDomain',
            'completename' => 'getEntityIDByCompletename',
        ] as $field => $method) {
            $value = "_identifier_" . $field . "_O'Reilly\\branch_%";
            $this->integer(\Entity::$method(addslashes($value)))->isIdenticalTo(-1);
            $connection->update('glpi_entities', [$field => $value], ['id' => $child]);
            $this->integer(\Entity::$method(addslashes($value)))->isIdenticalTo($child);
            // A second exact match is ambiguous, not an arbitrary first result.
            $connection->update('glpi_entities', [$field => $value], ['id' => $sibling]);
            $this->integer(\Entity::$method(addslashes($value)))->isIdenticalTo(-1);
            $connection->update('glpi_entities', [$field => $value . '_root'], ['id' => 0]);
            $this->integer(\Entity::$method(addslashes($value . '_root')))->isIdenticalTo(0);
        }
    }

    public function testEntityIdentifierNullAndEmptyValues(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);
        // Own the null population; DbTestCase rolls back these fixture changes.
        $connection->executeStatement('UPDATE glpi_entities SET tag = ?', ['_identifier_non_null']);
        $this->integer(\Entity::getEntityIDByTag(null))->isIdenticalTo(-1);
        $connection->update('glpi_entities', ['tag' => null], ['id' => $child]);
        foreach ([null, 'NULL', 'null', 'NuLl'] as $value) {
            $this->integer(\Entity::getEntityIDByTag($value))->isIdenticalTo($child);
        }
        $connection->update('glpi_entities', ['tag' => null], ['id' => $sibling]);
        $this->integer(\Entity::getEntityIDByTag(null))->isIdenticalTo(-1);
        $connection->update('glpi_entities', ['tag' => ''], ['id' => $child]);
        $this->integer(\Entity::getEntityIDByTag(''))->isIdenticalTo($child);
        $this->integer(\Entity::getEntityIDByTag(null))->isIdenticalTo($sibling);
    }

    public function testEntityIdentifierProjectionPreservesManagedState(): void
    {
        global $DB;
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $em = \itsmng\Database\Orm::create($DB);
        $connection = $em->getConnection();
        $this->object($connection)->isIdenticalTo($DB->getDoctrineConnection());
        $connection->update('glpi_entities', ['tag' => '_identifier_before'], ['id' => $child]);
        $listener = new class {
            public int $loaded = 0;

            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $settings = new \itsmng\Database\Repository\EntityConfigurationRepository($em);
            $this->integer($settings->uniqueIdentifier('tag', '_identifier_before'))->isIdenticalTo($child);
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            $managed = $em->find(\itsmng\Database\Entity\Entity::class, $child);
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_entities', ['tag' => '_identifier_after'], ['id' => $child]);
            // Read current database values without refreshing or detaching another caller's object.
            $this->integer($settings->uniqueIdentifier('tag', '_identifier_before'))->isIdenticalTo(-1);
            $this->integer($settings->uniqueIdentifier('tag', '_identifier_after'))->isIdenticalTo($child);
            $this->string($managed->tag)->isIdenticalTo('_identifier_before');
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
        } finally {
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
        }
    }

    public function testGetUsedConfigProjectionValues(): void
    {
        global $DB;
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $this->boolean($DB->update('glpi_entities', [
            'admin_email' => 'parent@example.test', 'comment' => 'Parent setting',
        ], ['id' => $parent]))->isTrue();
        $this->boolean($DB->update('glpi_entities', [
            'admin_email' => '', 'comment' => null, 'max_closedate' => '2026-02-03 04:05:06',
            'calendars_id' => null, 'calendar_mode' => 'inherit',
        ], ['id' => $child]))->isTrue();
        $em = \itsmng\Database\Orm::create($DB);
        $settings = new \itsmng\Database\Repository\EntityConfigurationRepository($em);
        $this->string($settings->usedConfiguration('admin_email', $child, 'comment', 'fallback'))
            ->isIdenticalTo('Parent setting');
        // A numeric default considers an empty string explicit; a string default inherits it.
        $this->variable($settings->usedConfiguration('admin_email', $child, 'comment', -2))->isNull();
        $this->integer($settings->usedConfiguration('id', $child, 'id', -2))->isIdenticalTo($child);
        $this->integer($settings->usedConfiguration('id', $child, 'entities_id', -2))->isIdenticalTo($parent);
        $this->string($settings->usedConfiguration('id', $child, 'max_closedate', -2))
            ->isIdenticalTo('2026-02-03 04:05:06');
        $this->string($settings->usedConfiguration('id', $child, 'calendar_mode', -2))->isIdenticalTo('inherit');
        $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

        // Reusing the repository must see writes on its supplied connection, including NULL.
        $this->boolean($DB->update('glpi_entities', ['admin_email' => 'child@example.test'], ['id' => $child]))->isTrue();
        $this->variable($settings->usedConfiguration('admin_email', $child, 'comment', 'fallback'))->isNull();
        $this->boolean($DB->update('glpi_entities', ['comment' => 'Changed setting'], ['id' => $child]))->isTrue();
        $this->string($settings->usedConfiguration('admin_email', $child, 'comment', 'fallback'))
            ->isIdenticalTo('Changed setting');
        $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
    }

    public function testGetUsedConfigProjectionReferences(): void
    {
        global $DB;
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $calendar = new \Calendar();
        $calendarId = (int)$calendar->add(['name' => 'Configuration projection ' . $this->getUniqueString()]);
        $this->integer($calendarId)->isGreaterThan(0);
        $this->boolean($DB->update('glpi_entities', [
            'calendars_id' => $calendarId, 'calendar_mode' => 'explicit',
            'entities_id_software' => 0, 'software_entity_mode' => 'explicit',
        ], ['id' => $parent]))->isTrue();
        $this->boolean($DB->update('glpi_entities', [
            'calendars_id' => null, 'calendar_mode' => 'inherit',
            'entities_id_software' => null, 'software_entity_mode' => 'unchanged',
        ], ['id' => $child]))->isTrue();
        $em = \itsmng\Database\Orm::create($DB);
        $settings = new \itsmng\Database\Repository\EntityConfigurationRepository($em);
        $this->integer($settings->usedConfiguration('calendars_id', $child, 'calendars_id', -2))
            ->isIdenticalTo($calendarId);
        $this->integer($settings->usedConfiguration('entities_id_software', $child, 'entities_id_software', -2))
            ->isIdenticalTo(-10);
        // Both the gate and value need their own mode when they are different references.
        $this->integer($settings->usedConfiguration('calendars_id', $child, 'entities_id_software', -2))
            ->isIdenticalTo(0);
        $this->integer($settings->usedConfiguration('entities_id_software', $child, 'calendars_id', -2))
            ->isIdenticalTo(-2);
        $this->boolean($DB->update('glpi_entities', [
            'calendars_id' => null, 'calendar_mode' => 'explicit',
            'entities_id_software' => 0, 'software_entity_mode' => 'explicit',
        ], ['id' => $child]))->isTrue();
        $this->integer($settings->usedConfiguration('calendars_id', $child, 'calendars_id', -2))->isIdenticalTo(0);
        $this->integer($settings->usedConfiguration('entities_id_software', $child, 'entities_id_software', -2))
            ->isIdenticalTo(0);
        $this->string($settings->usedConfiguration('id', $child, 'calendar_mode', -2))->isIdenticalTo('explicit');
        $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
    }

    public function testGetUsedConfigProjectionMissingAndCycles(): void
    {
        global $DB;
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $em = \itsmng\Database\Orm::create($DB);
        $settings = new \itsmng\Database\Repository\EntityConfigurationRepository($em);
        foreach ([-1, PHP_INT_MAX, 0, $child] as $id) {
            $this->string($settings->usedConfiguration('unknown_setting', $id, 'comment', 'fallback'))
                ->isIdenticalTo('fallback');
        }
        $this->string($settings->usedConfiguration('id', $child, 'unknown_setting', 'fallback'))
            ->isIdenticalTo('fallback');
        $this->string($settings->usedConfiguration('id', $child, 'name) FROM invalid', 'fallback'))
            ->isIdenticalTo('fallback');
        $this->variable($settings->usedConfiguration('id', 0, 'entities_id', -2))->isNull();
        $this->integer($settings->usedConfiguration('id', 0, 'id', -2))->isIdenticalTo(0);
        $this->string($settings->usedConfiguration('id', 0, 'id', 'fallback'))->isIdenticalTo('fallback');
        // Valid foreign keys can still form a cycle. Missing fields must not bypass its diagnostic.
        $this->boolean($DB->update('glpi_entities', ['entities_id' => $child], ['id' => $parent]))->isTrue();
        try {
            $this->exception(static fn () => $settings->usedConfiguration('unknown_setting', $child, 'comment', 'fallback'))
                ->isInstanceOf(\RuntimeException::class)->hasMessage('Cyclic entity configuration inheritance');
        } finally {
            $this->boolean($DB->update('glpi_entities', ['entities_id' => 0], ['id' => $parent]))->isTrue();
        }
        $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
    }

    protected function customCssProvider()
    {

        $root_id  = getItemByTypeName('Entity', 'Root entity', true);
        $child_id = getItemByTypeName('Entity', '_test_child_1', true);

        return [
           [
              // Do not output custom CSS if not enabled
              'entity_id'               => $root_id,
              'root_enable_custom_css'  => 0,
              'root_custom_css_code'    => 'body { color:blue; }',
              'child_enable_custom_css' => 0,
              'child_custom_css_code'   => '',
              'expected'                => '',
           ],
           [
              // Output custom CSS if enabled
              'entity_id'               => $root_id,
              'root_enable_custom_css'  => 1,
              'root_custom_css_code'    => 'body { color:blue; }',
              'child_enable_custom_css' => 0,
              'child_custom_css_code'   => '',
              'expected'                => '<style>body { color:blue; }</style>',
           ],
           [
              // Do not output custom CSS if empty
              'entity_id'               => $root_id,
              'root_enable_custom_css'  => 1,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => 0,
              'child_custom_css_code'   => '',
              'expected'                => '',
           ],
           [
              // Do not output custom CSS from parent if disabled in parent
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 0,
              'root_custom_css_code'    => 'body { color:blue; }',
              'child_enable_custom_css' => \Entity::CONFIG_PARENT,
              'child_custom_css_code'   => '',
              'expected'                => '',
           ],
           [
              // Do not output custom CSS from parent if empty
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 1,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => \Entity::CONFIG_PARENT,
              'child_custom_css_code'   => '',
              'expected'                => '',
           ],
           [
              // Output custom CSS from parent
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 1,
              'root_custom_css_code'    => '.link::before { content: "test"; }',
              'child_enable_custom_css' => \Entity::CONFIG_PARENT,
              'child_custom_css_code'   => '',
              'expected'                => '<style>.link::before { content: "test"; }</style>',
           ],
           [
              // Do not output custom CSS from entity itself if disabled
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 0,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => 0,
              'child_custom_css_code'   => 'body { color:blue; }',
              'expected'                => '',
           ],
           [
              // Do not output custom CSS from entity itself if empty
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 1,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => 1,
              'child_custom_css_code'   => '',
              'expected'                => '',
           ],
           [
              // Output custom CSS from entity itself
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 0,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => 1,
              'child_custom_css_code'   => 'body > a { color:blue; }',
              'expected'                => '<style>body > a { color:blue; }</style>',
           ],
           [
              // Output cleaned custom CSS
              'entity_id'               => $child_id,
              'root_enable_custom_css'  => 0,
              'root_custom_css_code'    => '',
              'child_enable_custom_css' => 1,
              'child_custom_css_code'   => '</style><script>alert(1);</script>',
              'expected'                => '<style>alert(1);</style>',
           ],
        ];
    }

    /**
     * @dataProvider customCssProvider
     */
    public function testGetCustomCssTag(
        int $entity_id,
        int $root_enable_custom_css,
        string $root_custom_css_code,
        int $child_enable_custom_css,
        string $child_custom_css_code,
        string $expected
    ): void {
        $this->login();

        $entity = new \Entity();

        // Define configuration values
        $update = $entity->update(
            [
              'id'                => getItemByTypeName('Entity', 'Root entity', true),
              'enable_custom_css' => $root_enable_custom_css,
              'custom_css_code'   => $root_custom_css_code
         ]
        );
        $this->boolean($update)->isTrue();
        $update = $entity->update(
            [
              'id'                => getItemByTypeName('Entity', '_test_child_1', true),
              'enable_custom_css' => $child_enable_custom_css,
              'custom_css_code'   => $child_custom_css_code
         ]
        );
        $this->boolean($update)->isTrue();

        // Validate method result
        $this->boolean($entity->getFromDB($entity_id))->isTrue();
        $this->string($entity->getCustomCssTag())->isEqualTo($expected);
    }

    public function testMultipleClones()
    {
        $this->login();

        $this->createItems('Entity', [
           [
              'name'        => 'test clone entity',
              'entities_id' => 0,
           ]
        ]);

        // Check that no clones exists
        $entity = new \Entity();
        $res = $entity->find(['name' => ['LIKE', 'test clone entity %']]);
        $this->array($res)->hasSize(0);

        // Clone multiple times
        $entity = getItemByTypeName('Entity', 'test clone entity', false);
        $this->boolean($entity->cloneMultiple(4))->isTrue();

        // Check that 4 clones were created
        $entity = new \Entity();
        $res = $entity->find(['name' => ['LIKE', 'test clone entity %']]);
        $this->array($res)->hasSize(4);

        // Try to read each clones
        $this->integer(getItemByTypeName('Entity', 'test clone entity (copy)', true))->isGreaterThan(0);
        $this->integer(getItemByTypeName('Entity', 'test clone entity (copy 2)', true))->isGreaterThan(0);
        $this->integer(getItemByTypeName('Entity', 'test clone entity (copy 3)', true))->isGreaterThan(0);
        $this->integer(getItemByTypeName('Entity', 'test clone entity (copy 4)', true))->isGreaterThan(0);
    }
}
