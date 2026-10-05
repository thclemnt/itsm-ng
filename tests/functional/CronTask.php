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

/* Test for inc/crontask.class.php */

class CronTask extends DbTestCase
{
    protected function registerProvider()
    {
        return [
           [
              'itemtype'        => 'CoreNonExistent',
              'name'            => 'CoreTest1',
              'should_register' => false, // Non-existent core class
           ],
           [
              'itemtype'        => 'CronTask',
              'name'            => 'CoreTest2',
              'should_register' => true, // Existing core class
           ],
           [
              'itemtype'        => 'PluginTestItemtype',
              'name'            => 'PluginTest1',
              'should_register' => true, // Plugin class. Existence not checked.
           ],
           [
              'itemtype'        => 'GlpiPlugin\\Tester\\TestItemtype',
              'name'            => 'NamespacedPluginTest1',
              'should_register' => true, // Plugin class with namespace. Existence not checked.
           ],
        ];
    }

    /**
     * @dataProvider registerProvider
     */
    public function testRegister(string $itemtype, string $name, bool $should_register)
    {
        $result = \CronTask::register($itemtype, $name, 30);
        if ($should_register) {
            $this->variable($result)->isNotEqualTo(false);
        } else {
            $this->variable($result)->isEqualTo(false);
        }
    }

    protected function unregisterProvider()
    {
        // Only plugins are supported with the unregister method.
        return [
           [
              'plugin_name'       => 'Test',
              'itemtype'          => 'PluginTestItemtype',
              'name'              => 'PluginTest1',
              'should_unregister' => true,
           ],
           [
              'plugin_name'       => 'Tester',
              'itemtype'          => 'GlpiPlugin\\Tester\\TestItemtype',
              'name'              => 'NamespacedPluginTest1',
              'should_unregister' => true,
           ],
           [
              'plugin_name'       => 'Tester',
              'itemtype'          => 'GlpiPlugin\\TesterNg\\TestItemtype',
              'name'              => 'NamespacedPluginTest2',
              'should_unregister' => false, // plugin name does not match class namespace
           ],
        ];
    }

    /**
     * @dataProvider unregisterProvider
     */
    public function testUnregister(string $plugin_name, string $itemtype, string $name, bool $should_unregister)
    {
        global $DB;

        // Register task .
        $plugin_task = \CronTask::register($itemtype, $name, 30, []);
        $this->variable($plugin_task)->isNotEqualTo(false);

        // Check the task has been created in DB
        $iterator = $DB->request([
           'SELECT' => ['id'],
           'FROM'   => \CronTask::getTable(),
           'WHERE'  => ['itemtype' => addslashes($itemtype), 'name' => $name]
        ]);
        $this->integer($iterator->count())->isEqualTo(1);

        // Try un-registering the task
        $result = \CronTask::unregister($plugin_name);
        $this->boolean($result)->isTrue();

        // Check the delete actually worked
        $iterator = $DB->request([
           'SELECT' => ['id'],
           'FROM'   => \CronTask::getTable(),
           'WHERE'  => ['itemtype' => addslashes($itemtype), 'name' => $name]
        ]);
        $this->integer($iterator->count())->isEqualTo($should_unregister ? 0 : 1);
    }

    protected function getNeedToRunProvider()
    {
        return [
           [
              'itemtype'    => 'CronTask',
              'name'        => 'CoreTest1',
              'should_run'  => true,
           ],
           [
              'itemtype'    => 'PluginTestItemtype',
              'name'        => 'PluginTest1',
              'should_run'  => false, // Inactive plugin
           ],
           [
              'itemtype'    => 'PluginTesterItemtype',
              'name'        => 'PluginTest2',
              'should_run'  => true,
           ],
           [
              'itemtype'    => 'GlpiPlugin\\Tester\\TestItemtype',
              'name'        => 'NamespacedPluginTest',
              'should_run'  => true,
           ],
        ];
    }

    /**
     * @dataProvider getNeedToRunProvider
     */
    public function testGetNeedToRun(string $itemtype, string $name, bool $should_run)
    {
        global $DB;

        // Deactivate all registered tasks
        $crontask = new \CronTask();
        $DB->update(\CronTask::getTable(), ['state' => \CronTask::STATE_DISABLE], [1]);
        $this->boolean($crontask->getNeedToRun())->isFalse();

        // Register task for active plugin.
        $plugin_task = \CronTask::register(
            $itemtype,
            $name,
            30,
            [
              'state'   => \CronTask::STATE_WAITING,
              'hourmin' => 0,
              'hourmax' => 24,
         ]
        );
        $this->variable($plugin_task)->isNotEqualTo(false);
        $this->boolean($crontask->getNeedToRun())->isEqualTo($should_run);
        if ($should_run) {
            $this->variable($crontask->fields['itemtype'])->isEqualTo($itemtype);
            $this->variable($crontask->fields['name'])->isEqualTo($name);
        }
    }

    public function testOverdueTaskSelection()
    {
        global $DB;

        $connection = $DB->getDoctrineConnection();
        $depth = $connection->getTransactionNestingLevel();
        $em = \itsmng\Database\Orm::create($DB);
        $em->createQuery('UPDATE ' . \itsmng\Database\Entity\CronTask::class . ' t SET t.state = :waiting')
            ->setParameter('waiting', \CronTask::STATE_WAITING)->execute();
        $prefix = 'Overdue ' . bin2hex(random_bytes(6));
        $now = new \DateTimeImmutable('2030-01-10 12:00:00', new \DateTimeZone('UTC'));
        $cases = [
            ['frequency overdue', 60, 121, \CronTask::STATE_RUNNING, true],
            ['two-hour overdue', 86400, 7201, \CronTask::STATE_RUNNING, true],
            ['frequency exact', 60, 120, \CronTask::STATE_RUNNING, false],
            ['two-hour exact', 86400, 7200, \CronTask::STATE_RUNNING, false],
            ['one-second overdue', 1, 3, \CronTask::STATE_RUNNING, true],
            ['one-second exact', 1, 2, \CronTask::STATE_RUNNING, false],
            ['recent', 1, 1, \CronTask::STATE_RUNNING, false],
            ['never run', 60, null, \CronTask::STATE_RUNNING, false],
            ['future', 60, -60, \CronTask::STATE_RUNNING, false],
            ['waiting', 60, 86400, \CronTask::STATE_WAITING, false],
            ['disabled', 60, 86400, \CronTask::STATE_DISABLE, false],
        ];
        $expected = [];
        foreach ($cases as [$name, $frequency, $age, $state, $overdue]) {
            $task = new \itsmng\Database\Entity\CronTask();
            $task->itemtype = 'CronTask';
            $task->name = $prefix . ' ' . $name;
            $task->frequency = $frequency;
            $task->state = $state;
            $task->lastrun = $age === null ? null : \DateTime::createFromImmutable($now->modify(sprintf('%+d seconds', -$age)));
            $em->persist($task);
            if ($overdue) {
                $expected[] = $task->name;
            }
        }
        $em->flush();
        $repository = new \itsmng\Database\Repository\CronTaskRepository($em);
        $this->array($repository->overdueNames($now))->isEqualTo($expected);
        $this->array(array_column($repository->overdue($now), 'name'))->isEqualTo($expected);
        // The operational clock is whole seconds, including an injected fractional-second clock.
        $this->array($repository->overdueNames($now->modify('+999999 microseconds')))->isEqualTo($expected);
        $after = $expected;
        array_splice($after, 2, 0, [$prefix . ' frequency exact', $prefix . ' two-hour exact']);
        $after[] = $prefix . ' one-second exact';
        $this->array($repository->overdueNames($now->modify('+1 second')))->isEqualTo($after);
        $this->variable($em->getConnection())->isIdenticalTo($connection);
        $this->integer($connection->getTransactionNestingLevel())->isEqualTo($depth);
        $DB->assertManagedTransaction();
    }

}
