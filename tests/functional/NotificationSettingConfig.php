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
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use NotificationChatConfig as ChatConfigModel;
use NotificationChatSetting;
use itsmng\Database\Entity\NotificationChatConfig as ChatConfigEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationChatConfigurationRepository;
use mock\DBmysql as ChatAdapterProbe;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/notificationsettingconfig.class.php */

class NotificationSettingConfig extends DbTestCase
{
    public function testChatSettingsProjectionKeepsNativeFieldsNullsAndLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $manager = null;
        try {
            $this->login();
            $record = $this->createItem(ChatConfigModel::class, [
                'hookurl' => null, 'chat' => null, 'type' => null, 'value' => null,
            ]);
            $id = (int)$record->getID();
            $manager = Orm::create($DB);
            $metadata = $manager->getClassMetadata(ChatConfigEntity::class);
            $this->string($metadata->getTableName())->isIdenticalTo('glpi_notificationchatconfigs');
            $fields = array_keys($metadata->fieldMappings);
            sort($fields);
            $this->array($fields)->isIdenticalTo(['chat', 'hookurl', 'id', 'type', 'value']);
            // These are the whole mapped SELECT* fieldset; the UI consumes all five.
            $repository = new NotificationChatConfigurationRepository($manager);
            $rows = $repository->settingsRows();
            $selected = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['id'] === $id));
            $this->array($selected)->hasSize(1);
            $this->array(array_keys($selected[0]))->isIdenticalTo(['hookurl', 'chat', 'type', 'value', 'id']);
            $this->integer((int)$selected[0]['id'])->isIdenticalTo($id);
            if ($DB->getDoctrineConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $this->integer($selected[0]['id'])->isIdenticalTo($id);
            }
            foreach (['hookurl', 'chat', 'type', 'value'] as $field) {
                $this->variable($selected[0][$field])->isNull();
            }
            $live = $manager->find(ChatConfigEntity::class, $id);
            $live->hookurl = 'Unflushed chat configuration';
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener([Events::postLoad], $loads);
            $connection = $DB->getDoctrineConnection();
            $this->integer($connection->update('glpi_notificationchatconfigs', [
                'hookurl' => 'https://chat.example.test/fresh', 'chat' => (string)CHAT_SLACK,
                'type' => 'all', 'value' => '',
            ], ['id' => $id]))->isIdenticalTo(1);
            $fresh = array_values(array_filter($repository->settingsRows(), static fn (array $row): bool => (int)$row['id'] === $id));
            $this->string($fresh[0]['hookurl'])->isIdenticalTo('https://chat.example.test/fresh');
            $this->variable($selected[0]['hookurl'])->isNull();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->boolean($manager->contains($live))->isTrue();
            $this->string($live->hookurl)->isIdenticalTo('Unflushed chat configuration');
            $nested = Orm::read($DB, function (EntityManager $outer) use ($id): array {
                $owned = $outer->find(ChatConfigEntity::class, $id);
                $owned->hookurl = 'Nested unflushed chat configuration';
                $rows = Orm::read($GLOBALS['DB'], static fn (EntityManager $reader): array =>
                    (new NotificationChatConfigurationRepository($reader))->settingsRows());
                $this->boolean($outer->contains($owned))->isTrue();
                $this->string($owned->hookurl)->isIdenticalTo('Nested unflushed chat configuration');
                return $rows;
            });
            $row = array_values(array_filter($nested, static fn (array $row): bool => (int)$row['id'] === $id))[0];
            $this->string($row['hookurl'])->isIdenticalTo('https://chat.example.test/fresh');
            $this->boolean($manager->contains($live))->isTrue();
        } finally {
            $manager?->clear();
            $_SESSION = $session;
        }
    }

    public function testChatConfigurationTableKeepsFixedRouteAndSelectedConnection(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $table = ChatConfigModel::getTable();
        $manager = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $entity = $this->createItem('Entity', ['name' => 'Chat scope ' . $this->getUniqueString(), 'entities_id' => 0]);
            $first = $this->createItem(ChatConfigModel::class, ['hookurl' => 'https://chat.example.test/all',
                'chat' => (string)CHAT_SLACK, 'type' => 'all', 'value' => 'Literal configuration value']);
            $second = $this->createItem(ChatConfigModel::class, ['hookurl' => 'https://chat.example.test/entity',
                'chat' => (string)CHAT_ROCKET, 'type' => 'entity', 'value' => (string)$entity->getID()]);
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $probe = new ScalarReadProbe($connection);
            $manager = Orm::forConnection($probe);
            $live = $manager->find(ChatConfigEntity::class, (int)$first->getID());
            $live->hookurl = 'Unflushed custom chat owner';
            $this->mockGenerator()->orphanize('__construct');
            $custom = new ChatAdapterProbe();
            $this->calling($custom)->getDoctrineConnection = $probe;
            $this->calling($custom)->getProvider = $original->getProvider();
            $DB = $custom;
            // The settings screen has always ignored the delivery model's forceTable.
            ChatConfigModel::forceTable('unregistered_chat_delivery_route');
            $settings = new NotificationChatSetting();
            $probe->queries = [];
            $rows = $this->renderLocalTableRows(static fn () => $settings->showFormConfig());
            $all = array_values(array_filter($rows, static fn (array $row): bool => $row['hookurl'] === 'https://chat.example.test/all'));
            $scoped = array_values(array_filter($rows, static fn (array $row): bool => $row['hookurl'] === 'https://chat.example.test/entity'));
            $this->array($all)->hasSize(1);
            $this->string($all[0]['chat'])->isIdenticalTo(__('Slack'));
            $this->string($all[0]['type'])->isIdenticalTo(__('All'));
            $this->string($all[0]['value'])->isIdenticalTo('Literal configuration value');
            $this->string($all[0]['actions'])->contains('test=' . $first->getID())->contains('delete=' . $first->getID());
            // Global configuration rows remain visible outside the active entity.
            $this->array($scoped)->hasSize(1);
            $this->string($scoped[0]['chat'])->isIdenticalTo(__('Rocket chat'));
            $this->string($scoped[0]['type'])->isIdenticalTo(__('Entity'));
            $this->string($scoped[0]['value'])->isIdenticalTo($entity->getField('completename'));
            $this->string($scoped[0]['actions'])->contains('test=' . $second->getID())->contains('delete=' . $second->getID());
            $queries = array_values(array_filter($probe->queries, static fn (array $query): bool => str_contains($query['sql'], 'glpi_notificationchatconfigs')));
            $this->array($queries)->hasSize(1);
            $this->string($queries[0]['sql'])->notContains('ORDER BY')->notContains('WHERE')->notContains('unregistered_chat_delivery_route');
            $this->array($queries[0]['params'])->isEmpty();
            $this->boolean($manager->contains($live))->isTrue();
            $this->string($live->hookurl)->isIdenticalTo('Unflushed custom chat owner');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            ChatConfigModel::forceTable($table);
            $DB = $original;
            $manager?->clear();
            $_SESSION = $session;
        }
    }

    public function testUpdate()
    {
        $current_config = \Config::getConfigurationValues('core');

        $this->variable($current_config['use_notifications'])->isEqualTo(0);
        $this->variable($current_config['notifications_mailing'])->isEqualTo(0);
        $this->variable($current_config['notifications_ajax'])->isEqualTo(0);

        $settingconfig = new \NotificationSettingConfig();
        $settingconfig->update([
           'use_notifications' => 1
        ]);

        $current_config = \Config::getConfigurationValues('core');

        $this->variable($current_config['use_notifications'])->isEqualTo(1);
        $this->variable($current_config['notifications_mailing'])->isEqualTo(0);
        $this->variable($current_config['notifications_ajax'])->isEqualTo(0);

        $settingconfig->update([
           'notifications_mailing' => 1
        ]);

        $current_config = \Config::getConfigurationValues('core');

        $this->variable($current_config['use_notifications'])->isEqualTo(1);
        $this->variable($current_config['notifications_mailing'])->isEqualTo(1);
        $this->variable($current_config['notifications_ajax'])->isEqualTo(0);

        $settingconfig->update([
           'use_notifications' => 0
        ]);

        $current_config = \Config::getConfigurationValues('core');

        $this->variable($current_config['use_notifications'])->isEqualTo(0);
        $this->variable($current_config['notifications_mailing'])->isEqualTo(0);
        $this->variable($current_config['notifications_ajax'])->isEqualTo(0);
    }

    public function testShowForm()
    {
        global $CFG_GLPI;

        $settingconfig = new \NotificationSettingConfig();
        $options = ['display' => false];

        $output = $settingconfig->showForm($options);
        $this->string($output)->isEmpty();

        $this->login();

        $this->output(
            function () use ($settingconfig) {
                $settingconfig->showForm();
            }
        )
           ->contains('Notifications configuration')
           ->notContains('Notification templates');

        $CFG_GLPI['use_notifications'] = 1;

        $this->output(
            function () use ($settingconfig) {
                $settingconfig->showForm();
            }
        )
           ->contains('Notifications configuration')
           ->notContains('Notification templates');

        $CFG_GLPI['notifications_ajax'] = 1;

        $this->output(
            function () use ($settingconfig) {
                $settingconfig->showForm();
            }
        )
           ->contains('Notifications configuration')
           ->contains('Notification templates')
           ->contains('Browser followups configuration')
           ->notContains('Email followups configuration');

        $CFG_GLPI['notifications_mailing'] = 1;

        $this->output(
            function () use ($settingconfig) {
                $settingconfig->showForm();
            }
        )
           ->contains('Notifications configuration')
           ->contains('Notification templates')
           ->contains('Browser followups configuration')
           ->contains('Email followups configuration');

        //reset
        $CFG_GLPI['use_notifications'] = 0;
        $CFG_GLPI['notifications_mailing'] = 0;
        $CFG_GLPI['notifications_ajax'] = 0;
    }
}
