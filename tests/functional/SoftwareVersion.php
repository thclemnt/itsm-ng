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
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Software as CoreSoftware;
use TypeError;
use mock\DBmysql as SoftwareVersionAdapterProbe;
use tests\fixtures\ScalarReadProbe;
use SoftwareVersion as CoreSoftwareVersion;
use itsmng\Database\Orm;
use itsmng\Database\Entity;
use itsmng\Database\Repository\SoftwareRepository;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/softwareversion.class.php */

class SoftwareVersion extends DbTestCase
{
    public function testPublicVersionViewsStayFreshAndPreserveLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $software = $this->createItem(CoreSoftware::class, [
                'name' => 'Version views ' . $this->getUniqueString(),
                'entities_id' => (int)$_SESSION['glpiactive_entity'],
            ]);
            $version = $this->createItem(CoreSoftwareVersion::class, [
                'name' => 'Initial public release', 'comment' => 'Initial version comment',
                'softwares_id' => $software->getID(),
                'entities_id' => (int)$_SESSION['glpiactive_entity'],
            ]);
            $options = ['softwares_id' => (string)$software->getID(), 'value' => $version->getID(),
                'display' => false, 'readonly' => true];
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options))->contains('Initial public release');
            $this->output(static fn () => CoreSoftwareVersion::showForSoftware($software))
                ->contains('Initial public release')->contains('Initial version comment')
                ->contains(CoreSoftwareVersion::getFormURLWithID($version->getID()));
            $connection = $DB->getDoctrineConnection();
            $this->integer($connection->update('glpi_softwareversions', [
                'name' => 'Current public release', 'comment' => 'Current version comment',
            ], ['id' => $version->getID()]))->isIdenticalTo(1);
            $writer = Orm::create($DB);
            $pending = $writer->find(Entity\SoftwareVersion::class, (int)$version->getID());
            $pending->name = 'Pending independent release';
            $pending->comment = 'Pending independent comment';
            Orm::read($DB, function (EntityManager $owner) use ($DB, $software, $version, $options, $writer, $pending): void {
                $outer = $owner->find(Entity\SoftwareVersion::class, (int)$version->getID());
                $outer->name = 'Pending outer release';
                $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options))
                    ->contains('Current public release')->notContains('Pending independent release')->notContains('Pending outer release');
                $this->output(static fn () => CoreSoftwareVersion::showForSoftware($software))
                    ->contains('Current public release')->contains('Current version comment')
                    ->notContains('Pending independent release')->notContains('Pending outer release');
                $this->boolean($owner->contains($outer))->isTrue();
                $this->string($outer->name)->isIdenticalTo('Pending outer release');
                $this->boolean($writer->contains($pending))->isTrue();
                $this->string($pending->name)->isIdenticalTo('Pending independent release');
                $this->string($writer->getConnection()->fetchOne('SELECT name FROM glpi_softwareversions WHERE id=?', [$version->getID()]))
                    ->isIdenticalTo('Current public release');
            });
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options))->contains('Current public release');
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options + ['used' => [(string)$version->getID()]]))
                ->notContains('Current public release');
            $this->exception(static fn () => CoreSoftwareVersion::dropdownForOneSoftware($options + ['used' => null]))
                ->isInstanceOf(TypeError::class);
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options))->contains('Current public release');
            $writer->flush();
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options))->contains('Pending independent release');
            $this->output(static fn () => CoreSoftwareVersion::showForSoftware($software))
                ->contains('Pending independent release')->contains('Pending independent comment');
            $writer->clear();
            $_SESSION['glpiactiveprofile']['software'] = 0;
            $this->output(static fn () => CoreSoftwareVersion::showForSoftware($software))->isEmpty();
        } finally {
            $_SESSION = $session;
        }
    }

    public function testPublicVersionViewsKeepCustomRouteAndHydrationCallbacks(): void
    {
        global $DB;
        $originalAdapter = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $software = $this->createItem(CoreSoftware::class, [
                'name' => 'Custom version views ' . $this->getUniqueString(),
                'entities_id' => (int)$_SESSION['glpiactive_entity'],
            ]);
            $version = $this->createItem(CoreSoftwareVersion::class, [
                'name' => 'Stored custom release', 'comment' => 'Stored custom comment',
                'softwares_id' => $software->getID(),
                'entities_id' => (int)$_SESSION['glpiactive_entity'],
            ]);
            $events = new EventManager();
            $observer = new class () {
                public array $versions = [];
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    $record = $event->getObject();
                    if ($record instanceof Entity\SoftwareVersion) {
                        $this->versions[] = $record->id;
                        $record->name = 'Custom hydrated release';
                        $record->comment = 'Custom hydrated comment';
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events->addEventListener(['postLoad', 'onClear'], $observer);
            $probe = new class ($DB->getDoctrineConnection()) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $probe->events = $events;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new SoftwareVersionAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
            $DB = $adapter;
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware([
                'softwares_id' => $software->getID(), 'value' => $version->getID(),
                'display' => false, 'readonly' => true,
            ]))->contains('Stored custom release')->notContains('Custom hydrated release');
            $this->array($observer->versions)->isEmpty();
            $this->output(static fn () => CoreSoftwareVersion::showForSoftware($software))
                ->contains('Custom hydrated release')->contains('Custom hydrated comment');
            $this->array($observer->versions)->isIdenticalTo([(int)$version->getID()]);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->array(array_values(array_filter($probe->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'glpi_softwareversions')
                && in_array((int)$software->getID(), array_map('intval', $query['params']), true))))->isNotEmpty();
            $this->string($originalAdapter->getDoctrineConnection()->fetchOne(
                'SELECT name FROM glpi_softwareversions WHERE id=?',
                [$version->getID()]
            ))->isIdenticalTo('Stored custom release');
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $session;
        }
    }

    public function testVersionChoiceProjectionPreservesDropdownLabels(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $session = $_SESSION;
        $manager = Orm::create($DB);
        try {
            $root = $manager->find(Entity\Entity::class, $_SESSION['glpiactive_entity']);
            $software = new Entity\Software();
            $software->entities = $root;
            $software->name = 'Choices ' . $this->getUniqueString();
            $peer = new Entity\Software();
            $peer->entities = $root;
            $peer->name = $software->name . ' peer';
            $state = new Entity\State();
            $state->entities = $root;
            $state->name = 'Available ' . $this->getUniqueString();
            foreach ([$software, $peer, $state] as $record) {
                $manager->persist($record);
            }
            $versions = [];
            foreach ([['A release', null, $software], ['Release <\"\'&', $state, $software],
                ['Release <\"\'&', null, $software], [null, null, $software], ['Peer only', $state, $peer]] as [$name, $status, $owner]) {
                $version = new Entity\SoftwareVersion();
                $version->softwares = $owner;
                $version->entities = $root;
                $version->name = $name;
                $version->states = $status;
                $manager->persist($version);
                $versions[] = $version;
            }
            $manager->flush();
            $manager->clear();
            $loads = new class () {
                public int $count = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener(['postLoad'], $loads);
            $repository = new SoftwareRepository($manager);
            $choices = $repository->versionChoices($software->id);
            $this->array($choices)->hasSize(4);
            $this->array(array_column(array_values(array_filter($choices, static fn ($row) => $row['name'] === 'Release <\"\'&')), 'id'))
                ->isIdenticalTo([$versions[1]->id, $versions[2]->id]);
            $this->boolean(in_array(['id' => $versions[3]->id, 'name' => null, 'status_name' => null], $choices, true))->isTrue();
            foreach ($choices as $choice) {
                $this->array(array_keys($choice))->isIdenticalTo(['id', 'name', 'status_name']);
            }
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $this->array($repository->versionChoices(0))->isEmpty();
            $this->array(array_column($repository->versionChoices($software->id, [$versions[0]->id, (string)$versions[1]->id]), 'id'))
                ->notContains($versions[0]->id)->notContains($versions[1]->id)->notContains($versions[4]->id);

            $_SESSION['glpiis_ids_visible'] = false;
            $options = ['softwares_id' => $software->id, 'display' => false, 'readonly' => true];
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options + ['value' => $versions[1]->id]))
                ->contains(sprintf(__('%1$s - %2$s'), 'Release <\"\'&', $state->name));
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options + ['value' => $versions[3]->id]))
                ->contains(sprintf(__('%1$s (%2$s)'), '', $versions[3]->id));
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options + ['value' => $versions[0]->id, 'used' => [$versions[0]->id]]))
                ->notContains('A release');
            $_SESSION['glpiis_ids_visible'] = true;
            $this->string(CoreSoftwareVersion::dropdownForOneSoftware($options + ['value' => $versions[2]->id]))
                ->contains(sprintf(__('%1$s (%2$s)'), 'Release <\"\'&', $versions[2]->id));
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);

            $writer = Orm::create($DB);
            $writer->find(Entity\SoftwareVersion::class, $versions[0]->id)->name = 'Fresh release';
            $writer->flush();
            $writer->clear();
            $this->boolean(in_array(
                ['id' => $versions[0]->id, 'name' => 'Fresh release', 'status_name' => null],
                $repository->versionChoices($software->id),
                true
            ))->isTrue();
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $this->integer($loads->count)->isEqualTo(0);
            $managedVersion = $manager->find(Entity\SoftwareVersion::class, $versions[0]->id);
            $managedVersion->name = 'Pending release';
            $rows = $repository->versions($software->id);
            $this->array($rows[0])->hasKeys(['entities_id', 'softwares_id', 'comment', 'states_id']);
            $this->string(array_column($rows, 'name', 'id')[$managedVersion->id])->isEqualTo('Pending release');
            $this->boolean($manager->contains($managedVersion))->isTrue();
            $this->integer($loads->count)->isGreaterThan(0);
            $manager->flush();
            $this->string($manager->getConnection()->fetchOne(
                'SELECT name FROM glpi_softwareversions WHERE id = ?',
                [$managedVersion->id]
            ))->isEqualTo('Pending release');
        } finally {
            $manager->clear();
            $_SESSION = $session;
        }
    }

    public function testDropdownForOneSoftware()
    {
        $this
           ->string(CoreSoftwareVersion::dropdownForOneSoftware([
              "display" => false
           ]))
           ->isNotEmpty();
    }
}
