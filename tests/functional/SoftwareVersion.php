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
use Doctrine\ORM\Event\PostLoadEventArgs;
use SoftwareVersion as CoreSoftwareVersion;
use itsmng\Database\Orm;
use itsmng\Database\Entity;
use itsmng\Database\Repository\SoftwareRepository;

/* Test for inc/softwareversion.class.php */

class SoftwareVersion extends DbTestCase
{
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
