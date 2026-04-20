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
