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

/* Test for inc/item_softwareversion.class.php */

/**
 * @engine isolate
 */
class Item_SoftwareVersion extends DbTestCase
{
    public function testTypeName()
    {
        $this->string(\Item_SoftwareVersion::getTypeName(1))->isIdenticalTo('Installation');
        $this->string(\Item_SoftwareVersion::getTypeName(0))->isIdenticalTo('Installations');
        $this->string(\Item_SoftwareVersion::getTypeName(10))->isIdenticalTo('Installations');
    }

    /** Public fixtures belong to this method's rollback frame, never to shared dataset links. */
    private function installationFixtures(array $entities = ['_test_root_entity']): array
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $suffix = bin2hex(random_bytes(6));
        $root = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $software = $this->createItem(\Software::class, [
            'name' => 'Installation fixture software ' . $suffix,
            'entities_id' => $root,
            'is_recursive' => 1,
        ]);
        $versions = [];
        foreach ([1, 2] as $number) {
            $versions[] = $this->createItem(\SoftwareVersion::class, [
                'name' => 'Installation fixture version ' . $number . ' ' . $suffix,
                'softwares_id' => $software->getID(),
                'entities_id' => $root,
                'is_recursive' => 1,
            ]);
        }
        $computers = [];
        foreach ($entities as $index => $entity) {
            $computer = $this->createItem(\Computer::class, [
                'name' => 'Installation fixture computer ' . $index . ' ' . $suffix,
                'entities_id' => (int)getItemByTypeName('Entity', $entity, true),
            ]);
            $this->boolean($computer->can($computer->getID(), UPDATE))->isTrue();
            $computers[] = $computer;
        }
        return [$software, $versions, $computers];
    }

    public function testPrepareInputForAdd()
    {
        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        $input = [
           'items_id'  => $computer1->getID(),
           'itemtype'  => 'Computer',
           'name'      => 'A name'
        ];

        $expected = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'A name',
           'entities_id'           => $computer1->getEntityID(),
           'is_recursive'          => 0,
           'is_template_item'      => $computer1->getField('is_template'),
           'is_deleted_item'       => $computer1->getField('is_deleted')
        ];

        $this->setEntity('_test_root_entity', true);
        $this->array($ins->prepareInputForAdd($input))->isIdenticalTo($expected);
    }

    public function testPrepareInputForUpdate()
    {
        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        $input = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'Another name'
        ];

        $expected = [
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'name'                  => 'Another name',
           'entities_id'           => $computer1->getEntityID(),
           'is_template_item'      => $computer1->getField('is_template'),
           'is_deleted_item'       => $computer1->getField('is_deleted')
        ];

        $this->array($ins->prepareInputForUpdate($input))->isIdenticalTo($expected);
    }


    public function testCountInstall()
    {
        [, $versions, $computers] = $this->installationFixtures([
            '_test_root_entity', '_test_child_1', '_test_child_1',
        ]);
        $computer1 = $computers[0]->getID();
        $computer11 = $computers[1]->getID();
        $computer12 = $computers[2]->getID();
        $ver = $versions[0]->getID();

        // Do some installations
        $ins = new \Item_SoftwareVersion();
        $this->integer((int)$ins->add([
           'items_id'              => $computer1,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);
        $this->integer($ins->add([
           'items_id'              => $computer11,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);
        $this->integer($ins->add([
           'items_id'              => $computer12,
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver,
        ]))->isGreaterThan(0);

        // Count installations
        $this->setEntity('_test_root_entity', true);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in all tree')
           ->isIdenticalTo(3);

        $this->setEntity('_test_root_entity', false);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in root')
           ->isIdenticalTo(1);

        $this->setEntity('_test_child_1', false);
        $this->integer((int)\Item_SoftwareVersion::countForVersion($ver), 'count in child')
           ->isIdenticalTo(2);
    }

    public function testUpdateDatasFromComputer()
    {
        global $DB;

        [, $versions, $computers] = $this->installationFixtures();
        $computer1 = $computers[0];
        $ver1 = $versions[0]->getID();
        $ver2 = $versions[1]->getID();
        $c00 = (int)$DB->getDoctrineConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM glpi_computers');
        $this->boolean((new \Computer())->getFromDB($c00))->isFalse();

        // Do some installations
        $softver = new \Item_SoftwareVersion();
        $softver01 = $softver->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver1,
        ]);
        $this->integer((int)$softver01)->isGreaterThan(0);
        $softver02 = $softver->add([
           'items_id'              => $computer1->getID(),
           'itemtype'              => 'Computer',
           'softwareversions_id'   => $ver2,
        ]);
        $this->integer((int)$softver02)->isGreaterThan(0);

        foreach ([$softver01, $softver02] as $tsoftver) {
            $o = new \Item_SoftwareVersion();
            $this->boolean($o->getFromDb($tsoftver))->isTrue();
            $this->variable($o->getField('is_deleted_item'))->isEqualTo(0);
        }

        //computer that does not exists
        $this->boolean($softver->updateDatasForItem('Computer', $c00))->isFalse();

        //update existing computer
        $input = $computer1->fields;
        $input['is_deleted'] = '1';
        $this->boolean($computer1->update($input))->isTrue();

        $this->boolean($softver->updateDatasForItem('Computer', $computer1->getID()))->isTrue();

        //check if all has been updated
        foreach ([$softver01, $softver02] as $tsoftver) {
            $o = new \Item_SoftwareVersion();
            $this->boolean($o->getFromDb($tsoftver))->isTrue();
            $this->variable($o->getField('is_deleted_item'))->isEqualTo(1);
        }

        //restore computer state
        $input['is_deleted'] = '0';
        $this->boolean($computer1->update($input))->isTrue();
    }

    public function testCountForSoftware()
    {
        [$soft1, $versions, $computers] = $this->installationFixtures();
        $ver1 = $versions[0];
        $computer1 = $computers[0];

        $this->integer(
            (int)\Item_SoftwareVersion::countForSoftware($soft1->fields['id'])
        )->isIdenticalTo(0);

        $csoftver = new \Item_SoftwareVersion();
        $this->integer(
            (int)$csoftver->add([
               'items_id'              => $computer1->fields['id'],
               'itemtype'              => 'Computer',
               'softwareversions_id'   => $ver1->fields['id']
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)\Item_SoftwareVersion::countForSoftware($soft1->fields['id'])
        )->isIdenticalTo(1);
    }

    public function testInstalledLicenseIdsKeepVersionPriorityAndHookWrites(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $request = $_REQUEST;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        try {
            [$software, $versions, $computers] = $this->installationFixtures();
            $computer = $computers[0];
            $entity = (int)$software->fields['entities_id'];
            $installations = [];
            $licenses = [];
            foreach ($versions as $index => $version) {
                $installations[] = $this->createItem(\Item_SoftwareVersion::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                    'softwareversions_id' => $version->getID(),
                ]);
                $licenses[] = $this->createItem(\SoftwareLicense::class, [
                    'name' => $this->getUniqueString(), 'softwares_id' => $software->getID(),
                    'entities_id' => $entity, 'number' => -1,
                    'softwareversions_id_buy' => $versions[0]->getID(),
                    // Public zero input becomes an empty owning reference, retaining buy fallback.
                    'softwareversions_id_use' => $index === 0 ? 0 : $version->getID(),
                ]);
                $this->createItem(\Item_SoftwareLicense::class, [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                    'softwarelicenses_id' => $licenses[$index]->getID(),
                ]);
            }
            $this->boolean($licenses[0]->getFromDB($licenses[0]->getID()))->isTrue();
            $this->variable($licenses[0]->fields['softwareversions_id_use'])->isNull();
            $_SESSION['glpiactiveprofile']['software'] = READ;
            $_REQUEST['criterion'] = -1;
            $PLUGIN_HOOKS['item_can'] = [];
            $render = function () use ($computer): array {
                ob_start();
                try {
                    \Item_SoftwareVersion::showForItem($computer);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->integer(preg_match('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $match))->isIdenticalTo(1);
                return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            };
            $table = $render();
            $rows = $table['dataSource']['rows'];
            $this->array($rows)->hasSize(2);
            $this->array(array_column($rows, 3))->isIdenticalTo([
                (string)$licenses[0]->getID(), (string)$licenses[1]->getID(),
            ]);
            $this->array($table['selection']['values'])->isIdenticalTo(array_map(
                static fn ($installation): string => 'item[Item_SoftwareVersion][' . $installation->getID() . ']',
                $installations
            ));
            foreach ($versions as $index => $version) {
                $this->string($rows[$index][0])->isIdenticalTo($software->getLink());
                $this->string($rows[$index][2])->isIdenticalTo($version->getLink());
            }

            $calls = 0;
            $plugins->setValue(null, [...$active, 'effective_license_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['effective_license_fixture' => [\Software::class =>
                static function (\Software $item) use (&$calls, $connection, $licenses, $versions): void {
                    if (++$calls === 1) {
                        // The next installed row must observe this write rather than an earlier batch.
                        $connection->update('glpi_softwarelicenses', [
                            'softwareversions_id_use' => $versions[0]->getID(),
                        ], ['id' => $licenses[1]->getID()]);
                        $item->right = false;
                    }
                }]];
            $hooked = $render();
            $this->integer($calls)->isIdenticalTo(2);
            $this->array(array_column($hooked['dataSource']['rows'], 3))->isIdenticalTo([
                (string)$licenses[0]->getID(), '',
            ]);
            $this->string($hooked['dataSource']['rows'][0][0])->notContains('<a ');
            $this->string($hooked['dataSource']['rows'][1][0])->contains('<a ');
            $this->array($hooked['selection']['values'])->isIdenticalTo($table['selection']['values']);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $_SESSION = $session;
            $_REQUEST = $request;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testInstallationLicenseProjectionIsScopedAndBounded(): void
    {
        global $DB;
        $originalLevel = $DB->getDoctrineConnection()->getTransactionNestingLevel();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $queries = [];

            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql']; // SQL shape only, never credentials or parameters.
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $parameters = $DB->getDoctrineConnection()->getParams();
        $connection = $DB->getProvider() === 'pgsql'
            ? \itsmng\Database\PostgresConnection::create($parameters, $configuration)
            : \itsmng\Database\MySQLConnection::create($parameters, $configuration);
        // An independent real writer owns this rolled-back graph, not DbTestCase's caller frame.
        $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
        try {
            $manager = new \Doctrine\ORM\EntityManager($connection, \itsmng\Database\Orm::configuration($connection->getDatabasePlatform()));
            $root = $manager->getReference(\itsmng\Database\Entity\Entity::class, 0);
            $owner = max(
                (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_computers'),
                (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_monitors')
            ) + 1;
            $subjects = [];
            foreach (['Computer', 'Monitor'] as $kind) {
                $class = '\\itsmng\\Database\\Entity\\' . $kind;
                $metadata = $manager->getClassMetadata($class);
                $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
                $metadata->setIdGenerator(new \Doctrine\ORM\Id\AssignedGenerator());
                $subject = new $class();
                $subject->id = $owner;
                $subject->entities = $root;
                $subject->name = 'License projection ' . $kind;
                $manager->persist($subject);
                $subjects[$kind] = $subject;
            }
            $software = new \itsmng\Database\Entity\Software();
            $software->entities = $root;
            $software->name = 'Installation license projection';
            $manager->persist($software);
            $type = new \itsmng\Database\Entity\SoftwareLicenseType();
            $type->entities = $root;
            $type->name = 'Projection type';
            $manager->persist($type);
            $versions = [];
            $licenses = [];
            $allocate = static function ($license, string $kind) use ($manager, $subjects): void {
                $allocation = new \itsmng\Database\Entity\ItemSoftwareLicense();
                $allocation->itemtype = $kind;
                $association = $allocation::referenceAssociation($kind);
                $allocation->{$association} = $subjects[$kind];
                $allocation->softwarelicenses = $license;
                $manager->persist($allocation);
            };
            // The last real installation is deliberately outside the requested page keys.
            for ($index = 0; $index < 26; ++$index) {
                $version = new \itsmng\Database\Entity\SoftwareVersion();
                $version->entities = $root;
                $version->softwares = $software;
                $version->name = 'Projection version ' . $index;
                $manager->persist($version);
                $versions[] = $version;
                $installation = new \itsmng\Database\Entity\ItemSoftwareVersion();
                $installation->entities = $root;
                $installation->itemtype = 'Computer';
                $installation->computer = $subjects['Computer'];
                $installation->softwareversions = $version;
                $manager->persist($installation);
                $license = new \itsmng\Database\Entity\SoftwareLicense();
                $license->entities = $root;
                $license->softwares = $software;
                $license->name = 'Projection license ' . $index;
                $license->serial = 'serial-' . $index;
                $license->useVersion = $version;
                $license->buyVersion = $version;
                $manager->persist($license);
                $licenses[] = $license;
                $allocate($license, 'Computer');
            }
            $allocate($licenses[0], 'Computer'); // Duplicate allocation, not another displayed license.
            $other = new \itsmng\Database\Entity\SoftwareLicense();
            $other->entities = $root;
            $other->softwares = $software;
            $other->name = 'Buy and use on different versions';
            $other->serial = 'other';
            $other->buyVersion = $versions[0];
            $other->useVersion = $versions[1];
            $other->softwarelicensetypes = $type;
            $manager->persist($other);
            $allocate($other, 'Computer');
            $monitorLicense = new \itsmng\Database\Entity\SoftwareLicense();
            $monitorLicense->entities = $root;
            $monitorLicense->softwares = $software;
            $monitorLicense->name = 'Monitor license';
            $monitorLicense->buyVersion = $versions[0];
            $manager->persist($monitorLicense);
            $allocate($monitorLicense, 'Monitor');
            $monitorInstallation = new \itsmng\Database\Entity\ItemSoftwareVersion();
            $monitorInstallation->entities = $root;
            $monitorInstallation->itemtype = 'Monitor';
            $monitorInstallation->monitor = $subjects['Monitor'];
            $monitorInstallation->softwareversions = $versions[0];
            $manager->persist($monitorInstallation);
            $manager->flush();
            $keys = array_map(static fn ($version): array => [
                'itemtype' => 'Computer', 'items_id' => $owner, 'softwareversions_id' => $version->id,
            ], array_slice($versions, 0, 25));
            $manager->clear();
            $repository = new \itsmng\Database\Repository\SoftwareInstallationRepository($manager);
            $logger->queries = [];
            $this->array($repository->licensesForInstallations([]))->isEmpty();
            $this->array($logger->queries)->isEmpty();
            foreach ([1, 25] as $size) {
                $logger->queries = [];
                $rows = $repository->licensesForInstallations(array_slice($keys, 0, $size));
                $this->array($logger->queries)->hasSize(1);
                $this->array(array_keys($rows))->isIdenticalTo(['Computer']);
                $this->array($rows['Computer'][$owner])->hasSize($size);
                $this->boolean(isset($rows['Computer'][$owner][$versions[25]->id]))->isFalse();
                $first = $rows['Computer'][$owner][$versions[0]->id];
                $expectedIds = [$licenses[0]->id, $other->id];
                sort($expectedIds);
                $this->array(array_keys($first))->isIdenticalTo($expectedIds);
                $this->array($first[$licenses[0]->id])->isIdenticalTo([
                    'id' => $licenses[0]->id, 'name' => 'Projection license 0', 'serial' => 'serial-0', 'type' => null,
                ]);
                $this->string($first[$other->id]['type'])->isIdenticalTo('Projection type');
                if ($size === 25) {
                    $this->boolean(isset($rows['Computer'][$owner][$versions[1]->id][$other->id]))->isTrue();
                }
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
            $logger->queries = [];
            $rows = $repository->licensesForInstallations([$keys[0], $keys[0], [
                'itemtype' => 'Monitor', 'items_id' => $owner, 'softwareversions_id' => $versions[0]->id,
            ]]);
            $this->array($logger->queries)->hasSize(1);
            $this->array(array_keys($rows['Monitor'][$owner][$versions[0]->id]))->isIdenticalTo([$monitorLicense->id]);
            $this->array($rows['Computer'][$owner])->hasSize(1);
            $this->array($rows['Computer'][$owner][$versions[0]->id])->hasSize(2);
            // Match real graph rows on opposite sides of the provider-safe batch boundary.
            $boundaryKeys = [$keys[0]];
            $absentVersion = (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_softwareversions') + 1;
            for ($index = 0; $index < 249; ++$index) {
                $boundaryKeys[] = [
                    'itemtype' => 'Computer', 'items_id' => $owner, 'softwareversions_id' => $absentVersion + $index,
                ];
            }
            $logger->queries = [];
            $boundary = $repository->licensesForInstallations($boundaryKeys);
            $this->array($logger->queries)->hasSize(1);
            $this->array($boundary)->isIdenticalTo(['Computer' => $rows['Computer']]);
            $boundaryKeys[] = [
                'itemtype' => 'Monitor', 'items_id' => $owner, 'softwareversions_id' => $versions[0]->id,
            ];
            $boundaryKeys[] = $keys[0]; // Duplicate tuples must not consume another batch slot.
            $logger->queries = [];
            $boundary = $repository->licensesForInstallations($boundaryKeys);
            $this->array($logger->queries)->hasSize(2);
            $this->array($boundary)->hasSize(2);
            $this->array($boundary['Computer'])->isIdenticalTo($rows['Computer']);
            $this->array($boundary['Monitor'])->isIdenticalTo($rows['Monitor']);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $connection->update('glpi_softwarelicenses', ['name' => 'Changed on supplied writer'], ['id' => $licenses[0]->id]);
            $rows = $repository->licensesForInstallations([$keys[0]]);
            $this->string($rows['Computer'][$owner][$versions[0]->id][$licenses[0]->id]['name'])->isIdenticalTo('Changed on supplied writer');
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();

            // This table uses the effective version, unlike the buy-or-use view above.
            $logger->queries = [];
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, []))->isEmpty();
            $this->array($logger->queries)->isEmpty();
            // This view historically includes locked/deleted allocations; filtering them changes its IDs.
            $connection->update('glpi_items_softwarelicenses', ['is_deleted' => true], ['softwarelicenses_id' => $licenses[0]->id]);
            foreach ([1, 25] as $size) {
                $logger->queries = [];
                $effective = $repository->effectiveLicenseIdsForVersions('Computer', $owner, array_column(array_slice($keys, 0, $size), 'softwareversions_id'));
                $this->array($logger->queries)->hasSize(1);
                $this->array($effective)->hasSize($size);
                // Preserve both allocations, but do not include the other license's purchase version.
                $this->array($effective[$versions[0]->id])->isIdenticalTo([$licenses[0]->id, $licenses[0]->id]);
                if ($size === 25) {
                    $this->array($effective[$versions[1]->id])->isIdenticalTo([$licenses[1]->id, $other->id]);
                }
                $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            }
            // Same numeric owner on another item type; an unset use version falls back to purchase.
            $this->array($repository->effectiveLicenseIdsForVersions('Monitor', $owner, [$versions[0]->id]))
                ->isIdenticalTo([$versions[0]->id => [$monitorLicense->id]]);
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', PHP_INT_MAX, [$versions[0]->id]))->isEmpty();
            $boundaryVersions = [$versions[0]->id, ...range($absentVersion, $absentVersion + 248), $versions[25]->id, $versions[0]->id];
            $logger->queries = [];
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, $boundaryVersions))->isIdenticalTo([
                $versions[0]->id => [$licenses[0]->id, $licenses[0]->id],
                $versions[25]->id => [$licenses[25]->id],
            ]);
            $this->array($logger->queries)->hasSize(2);
            $connection->update('glpi_softwarelicenses', ['softwareversions_id_use' => $versions[0]->id], ['id' => $other->id]);
            $this->array($repository->effectiveLicenseIdsForVersions('Computer', $owner, [$versions[0]->id, $versions[1]->id]))->isIdenticalTo([
                $versions[0]->id => [$licenses[0]->id, $licenses[0]->id, $other->id],
                $versions[1]->id => [$licenses[1]->id],
            ]);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->object($manager->getConnection())->isIdenticalTo($connection);
        } finally {
            try {
                $frame->rollBack();
            } finally {
                $connection->close();
            }
        }
        $this->integer($DB->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
    }
}
