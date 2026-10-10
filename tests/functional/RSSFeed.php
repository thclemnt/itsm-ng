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
use Doctrine\ORM\Events;
use Group_RSSFeed;
use Profile_RSSFeed;
use ReflectionProperty;
use itsmng\Database\Entity\RSSFeed as RSSFeedEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\SharedContentRepository;
use RSSFeed as RSSFeedModel;

class RSSFeed extends DbTestCase
{
    private $server_process = null;
    private $server_port = null;

    public function __destruct()
    {
        $this->stopFixtureServer();
    }

    private function getPhpBinary()
    {
        return PHP_BINARY ?: 'php';
    }

    private function startFixtureServer()
    {
        if (is_resource($this->server_process)) {
            return;
        }

        $this->server_port = random_int(20000, 29999);
        $command = [
           $this->getPhpBinary(),
           '-S',
           '127.0.0.1:' . $this->server_port,
           'tests/router.php',
        ];

        $log_file = sys_get_temp_dir() . '/rssfeed-test-server.log';
        $descriptors = [
           0 => ['pipe', 'r'],
           1 => ['file', $log_file, 'a'],
           2 => ['file', $log_file, 'a'],
        ];

        $this->server_process = proc_open($command, $descriptors, $pipes, GLPI_ROOT);

        if (!is_resource($this->server_process)) {
            throw new \RuntimeException('Unable to start fixture server.');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $deadline = microtime(true) + 5;
        do {
            $connection = @fsockopen('127.0.0.1', $this->server_port);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->stopFixtureServer();
        throw new \RuntimeException('Fixture server did not become ready in time.');
    }

    private function stopFixtureServer()
    {
        if (!is_resource($this->server_process)) {
            return;
        }

        proc_terminate($this->server_process);
        proc_close($this->server_process);
        $this->server_process = null;
        $this->server_port = null;
    }

    private function getFixtureFeedUrl()
    {
        $this->startFixtureServer();

        return 'http://127.0.0.1:' . $this->server_port . '/tests/fixtures/rssfeed.xml';
    }

    public function testGetRSSFeedParsesFixture()
    {
        $feed = \RSSFeed::getRSSFeed($this->getFixtureFeedUrl(), 0);

        $this->variable($feed)->isNotFalse();
        $this->string((string) $feed->get_title())->isIdenticalTo('Test RSS Feed');
        $this->string((string) $feed->get_description())->isIdenticalTo('Test feed used by automated tests.');
        $this->string((string) $feed->get_permalink())->isIdenticalTo('https://example.com/');

        $items = $feed->get_items();
        $this->array($items)->hasSize(2);

        $titles = array_map(static fn ($item) => (string) $item->get_title(), $items);
        sort($titles);
        $this->array($titles)->isIdenticalTo(['Second item', 'Test item']);

        $permalinks = array_map(static fn ($item) => (string) $item->get_permalink(), $items);
        sort($permalinks);
        $this->array($permalinks)->isIdenticalTo([
           'https://example.com/items/1',
           'https://example.com/items/2',
        ]);
    }

    public function testShowDiscoveredFeedsHandlesUnreadableFeed(): void
    {
        $rssfeed = new RSSFeedModel();
        $rssfeed->fields['url'] = 'file:///nonexistent/rssfeed.xml';

        ob_start();
        try {
            $result = $rssfeed->showDiscoveredFeeds();
        } finally {
            ob_end_clean();
        }

        $this->boolean($result)->isFalse();
    }

    public function testPrepareInputForAddKeepsCurrentUserAsOwner()
    {
        $this->login();

        $rssfeed = new \RSSFeed();

        $prepared = $rssfeed->prepareInputForAdd([
           'url' => $this->getFixtureFeedUrl(),
        ]);

        $this->array($prepared)
           ->hasKey('users_id')
           ->integer['users_id']->isIdenticalTo(\Session::getLoginUserID());

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $this->integer((int)$rssfeed->add([
            'url' => $prepared['url'], 'comment' => 'Audience ownership fixture',
        ]))->isGreaterThan(0);
        $feedId = (int)$rssfeed->getID();
        $group = $this->createItem('Group', ['name' => 'RSS group ' . $this->getUniqueString(), 'entities_id' => 0]);
        $profile = $this->createItem('Profile', ['name' => 'RSS profile ' . $this->getUniqueString()]);
        $external = Orm::create($DB);
        $retained = $external->find(RSSFeedEntity::class, $feedId);
        $retainedComment = $retained->comment;
        try {
            foreach ([
                [Group_RSSFeed::class, 'groups_id', 'getGroups', 'groups', (int)$group->getID()],
                [Profile_RSSFeed::class, 'profiles_id', 'getProfiles', 'profiles', (int)$profile->getID()],
            ] as [$class, $key, $read, $property, $audienceId]) {
                $link = $this->createItem($class, [
                    'rssfeeds_id' => $feedId, $key => $audienceId, 'entities_id' => 0, 'is_recursive' => 0,
                ]);
                $table = $class::getTable();
                $linkId = (int)$link->getID();
                $this->integer($connection->update($table, ['entities_id' => null], ['id' => $linkId]))->isIdenticalTo(1);
                $this->integer($connection->insert($table, [
                    'rssfeeds_id' => $feedId, $key => $audienceId, 'entities_id' => 0, 'is_recursive' => 1,
                ]))->isIdenticalTo(1);
                $rows = $class::$read((string)$feedId);
                $this->array(array_keys($rows))->isIdenticalTo([$audienceId]);
                $this->array($rows[$audienceId])->hasSize(2);
                $this->integer((int)$rows[$audienceId][0]['id'])->isIdenticalTo($linkId);
                $this->integer((int)$rows[$audienceId][1]['id'])->isGreaterThan($linkId);
                foreach ($rows[$audienceId] as $index => $row) {
                    $columns = array_keys($row);
                    sort($columns);
                    $expectedColumns = ['id', 'rssfeeds_id', $key, 'entities_id', 'is_recursive'];
                    sort($expectedColumns);
                    $this->array($columns)->isIdenticalTo($expectedColumns);
                    $this->integer((int)$row['rssfeeds_id'])->isIdenticalTo($feedId);
                    $this->integer((int)$row[$key])->isIdenticalTo($audienceId);
                    $this->integer((int)$row['is_recursive'])->isIdenticalTo($index);
                }
                $this->variable($rows[$audienceId][0]['entities_id'])->isNull();
                $this->integer((int)$rows[$audienceId][1]['entities_id'])->isIdenticalTo(0);
                $this->array($class::$read([$feedId]))->isIdenticalTo($rows);
                $this->array($class::$read(PHP_INT_MAX))->isEmpty();
                $this->array($class::$read(null))->isEmpty();
                $this->array($class::$read('NULL'))->isEmpty();
                $this->boolean($rssfeed->getFromDB($feedId))->isTrue();
                $this->array((new ReflectionProperty(RSSFeedModel::class, $property))->getValue($rssfeed))->isIdenticalTo($rows);

                // A new read observes legacy writes; an already returned snapshot does not change.
                $this->integer($connection->update($table, ['is_recursive' => 1, 'rssfeeds_id' => 0], ['id' => $linkId]))->isIdenticalTo(1);
                $this->array($class::$read($feedId)[$audienceId])->hasSize(1);
                $zeroRows = $class::$read(0);
                $zeroIds = array_map('intval', array_column($zeroRows[$audienceId], 'id'));
                $this->array($zeroIds)->contains($linkId);
                $this->array($class::$read(null))->isEmpty();
                $this->array($class::$read('null'))->isEmpty();
                $this->integer((int)$rows[$audienceId][0]['is_recursive'])->isIdenticalTo(0);
                $this->integer($connection->update($table, ['rssfeeds_id' => $feedId], ['id' => $linkId]))->isIdenticalTo(1);
                $fresh = $class::$read($feedId);
                $this->integer((int)$fresh[$audienceId][0]['is_recursive'])->isIdenticalTo(1);
                $this->boolean($link->delete(['id' => $linkId], true))->isTrue();
                $this->array($class::$read($feedId)[$audienceId])->hasSize(1);
                $this->boolean($external->contains($retained))->isTrue();
                $this->string($retained->comment)->isIdenticalTo($retainedComment);
            }

            // Supplied-manager repositories retain their existing post-load dispatch and live entities.
            $listener = new class () {
                public int $loads = 0;
                public function postLoad(): void
                {
                    ++$this->loads;
                }
            };
            $external->getEventManager()->addEventListener([Events::postLoad], $listener);
            $repository = new SharedContentRepository($external);
            $this->array($repository->rssfeedGroups($feedId))->isIdenticalTo(Group_RSSFeed::getGroups($feedId));
            $this->array($repository->rssfeedProfiles($feedId))->isIdenticalTo(Profile_RSSFeed::getProfiles($feedId));
            $this->integer($listener->loads)->isGreaterThanOrEqualTo(2);
            $this->boolean($external->contains($retained))->isTrue();

            $this->boolean($rssfeed->delete(['id' => $feedId], true))->isTrue();
            $this->array(Group_RSSFeed::getGroups($feedId))->isEmpty();
            $this->array(Profile_RSSFeed::getProfiles($feedId))->isEmpty();
            $this->boolean($external->contains($retained))->isTrue();
        } finally {
            $external->clear();
        }
    }

    public function testPrepareInputForAddLoadsFeedMetadata()
    {
        $this->login();

        $rssfeed = new \RSSFeed();
        $prepared = $rssfeed->prepareInputForAdd([
           'url'     => $this->getFixtureFeedUrl(),
           'comment' => '',
        ]);

        $this->array($prepared)
           ->hasKeys(['users_id', 'url', 'have_error', 'name', 'comment'])
           ->integer['users_id']->isIdenticalTo(\Session::getLoginUserID())
           ->string['url']->isIdenticalTo($this->getFixtureFeedUrl())
           ->integer['have_error']->isIdenticalTo(0)
           ->string['name']->isIdenticalTo('Test RSS Feed')
           ->string['comment']->isIdenticalTo('Test feed used by automated tests.');
    }

    public function testPrepareInputForAddKeepsManualComment()
    {
        $this->login();

        $rssfeed = new \RSSFeed();
        $prepared = $rssfeed->prepareInputForAdd([
           'url'     => $this->getFixtureFeedUrl(),
           'comment' => 'Manual comment',
        ]);

        $this->string($prepared['comment'])->isIdenticalTo('Manual comment');
        $this->string($prepared['name'])->isIdenticalTo('Test RSS Feed');
        $this->integer($prepared['have_error'])->isIdenticalTo(0);
    }

    public function testPrepareInputForAddHandlesUnreadableFeed()
    {
        $this->login();

        $rssfeed = new \RSSFeed();
        $prepared = $rssfeed->prepareInputForAdd([
           'url'     => 'file:///nonexistent/rssfeed.xml',
           'comment' => '',
        ]);

        $this->array($prepared)
           ->hasKeys(['users_id', 'have_error', 'name'])
           ->integer['users_id']->isIdenticalTo(\Session::getLoginUserID())
           ->integer['have_error']->isIdenticalTo(1)
           ->string['name']->isIdenticalTo('Without title');
    }
}
