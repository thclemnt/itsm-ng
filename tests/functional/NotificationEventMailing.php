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
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Orm;
use itsmng\Database\Repository\EntityConfigurationRepository;
use mock\DBmysql;
use NotificationEventMailing as LegacyMailing;

/* Test for inc/notificationeventajax.class.php */

class NotificationEventMailing extends DbTestCase
{
    public function testEntityAdministratorProjectionKeepsExactScopeAndCurrentValues(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $manager = Orm::create($database);
        $owned = $manager->find(EntityRecord::class, 0);
        $owned->name = 'Pending root administrator';
        $before = $manager->getUnitOfWork()->getIdentityMap();
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([Events::postLoad], $loads);
        $settings = new EntityConfigurationRepository($manager);
        $this->mockGenerator->orphanize('__construct');
        $routed = new DBmysql();
        $reads = 0;
        $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$reads) {
            ++$reads;
            return $connection;
        };
        try {
            $this->boolean($database->update('glpi_entities', [
                'admin_email' => 'root-admin@localhost', 'admin_email_name' => null,
            ], ['id' => 0]))->isTrue();
            $this->boolean($database->update('glpi_entities', [
                'admin_email' => null, 'admin_email_name' => 'Child administrator',
            ], ['id' => $child]))->isTrue();
            $DB = $routed;
            $this->array(LegacyMailing::getEntityAdminsData(0))->isIdenticalTo([[
                'language' => $CFG_GLPI['language'], 'email' => 'root-admin@localhost', 'name' => '',
            ]]);
            $this->integer($reads)->isGreaterThan(0);
            // A child with no address does not inherit the valid root address.
            $this->boolean(LegacyMailing::getEntityAdminsData($child))->isFalse();
            foreach ([null, 'NULL', 'null', PHP_INT_MAX, -1] as $missing) {
                $this->boolean(LegacyMailing::getEntityAdminsData($missing))->isFalse();
            }
            $this->array($settings->administratorContact(0))->isIdenticalTo([
                'admin_email' => 'root-admin@localhost', 'admin_email_name' => null,
            ]);
            $this->boolean($database->update('glpi_entities', [
                'admin_email' => 'new-root@localhost', 'admin_email_name' => 'Current root',
            ], ['id' => 0]))->isTrue();
            $this->array($settings->administratorContact(0))->isIdenticalTo([
                'admin_email' => 'new-root@localhost', 'admin_email_name' => 'Current root',
            ]);
            Orm::read($database, function (EntityManager $outer) use ($CFG_GLPI): void {
                $nested = $outer->find(EntityRecord::class, 0);
                $nested->name = 'Pending nested administrator';
                $identity = $outer->getUnitOfWork()->getIdentityMap();
                $this->array(LegacyMailing::getEntityAdminsData(0))->isIdenticalTo([[
                    'language' => $CFG_GLPI['language'], 'email' => 'new-root@localhost', 'name' => 'Current root',
                ]]);
                $this->boolean($outer->contains($nested))->isTrue();
                $this->string($nested->name)->isIdenticalTo('Pending nested administrator');
                $this->array($outer->getUnitOfWork()->getIdentityMap())->isIdenticalTo($identity);
            });
            $this->boolean($database->update('glpi_entities', ['admin_email' => 'invalid-address'], ['id' => 0]))->isTrue();
            $this->boolean(LegacyMailing::getEntityAdminsData(0))->isFalse();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->boolean($manager->contains($owned))->isTrue();
            $this->string($owned->name)->isIdenticalTo('Pending root administrator');
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isIdenticalTo($before);
        } finally {
            $DB = $database;
            $manager->clear();
        }
    }

    public function testGetTargetField()
    {
        $data = [];
        $this->string(\NotificationEventMailing::getTargetField($data))->isIdenticalTo('email');

        $expected = ['email' => null];
        $this->array($data)->isIdenticalTo($expected);

        $data = ['email' => 'user'];
        $this->string(\NotificationEventMailing::getTargetField($data))->isIdenticalTo('email');

        $expected = ['email' => null];
        $this->array($data)->isIdenticalTo($expected);

        $data = ['email' => 'user@localhost'];
        $this->string(\NotificationEventMailing::getTargetField($data))->isIdenticalTo('email');

        $expected = ['email' => 'user@localhost'];
        $this->array($data)->isIdenticalTo($expected);

        $uid = getItemByTypeName('User', TU_USER, true);
        $data = ['users_id' => $uid];

        $this->string(\NotificationEventMailing::getTargetField($data))->isIdenticalTo('email');
        $expected = [
           'users_id'  => $uid,
           'email'     => TU_USER . '@glpi.com'
        ];
        $this->array($data)->isIdenticalTo($expected);
    }

    public function testCanCron()
    {
        $this->boolean(\NotificationEventMailing::canCron())->isTrue();
    }

    public function testGetAdminData()
    {
        global $CFG_GLPI;

        $this->array(\NotificationEventMailing::getAdminData())
           ->isIdenticalTo([
              'email'     => $CFG_GLPI['admin_email'],
              'name'      => $CFG_GLPI['admin_email_name'],
              'language'  => $CFG_GLPI['language']
           ]);

        $CFG_GLPI['admin_email'] = 'adminlocalhost';
        $this->boolean(\NotificationEventMailing::getAdminData())->isFalse();
    }

    public function testGetEntityAdminsData()
    {
        $this->boolean(\NotificationEventMailing::getEntityAdminsData(0))->isFalse();

        $this->login();

        $entity1 = getItemByTypeName('Entity', '_test_child_1');
        $this->boolean(
            $entity1->update([
              'id'                 => $entity1->getId(),
              'admin_email'        => 'entadmin@localhost',
              'admin_email_name'   => 'Entity admin ONE'
         ])
        )->isTrue();

        $entity2 = getItemByTypeName('Entity', '_test_child_2');
        $this->boolean(
            $entity2->update([
              'id'                 => $entity2->getId(),
              'admin_email'        => 'entadmin2localhost',
              'admin_email_name'   => 'Entity admin TWO'
         ])
        )->isTrue();

        $this->array(\NotificationEventMailing::getEntityAdminsData($entity1->getID()))
           ->isIdenticalTo([
              [
                 'language' => 'en_GB',
                 'email' => 'entadmin@localhost',
                 'name' => 'Entity admin ONE'
              ]
           ]);
        $this->boolean(\NotificationEventMailing::getEntityAdminsData($entity2->getID()))->isFalse();

        //reset
        $this->boolean(
            $entity1->update([
              'id'                 => $entity1->getId(),
              'admin_email'        => 'NULL',
              'admin_email_name'   => 'NULL'
         ])
        )->isTrue();
        $this->boolean(
            $entity2->update([
              'id'                 => $entity2->getId(),
              'admin_email'        => 'NULL',
              'admin_email_name'   => 'NULL'
         ])
        )->isTrue();
    }
}
