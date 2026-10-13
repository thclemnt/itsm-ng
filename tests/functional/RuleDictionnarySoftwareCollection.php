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
use RuleDictionnarySoftwareCollection as DictionaryCollection;
use Software as LegacySoftware;
use SoftwareVersion as LegacyVersion;
use itsmng\Database\Entity\Software as SoftwareRecord;
use itsmng\Database\Entity\SoftwareVersion as VersionRecord;
use itsmng\Database\Orm;
use mock\DBmysql as DictionaryRouteAdapter;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/ruledictionnarysoftwarecollection.class.php */

class RuleDictionnarySoftwareCollection extends DbTestCase
{
    public function testDictionaryReplayKeepsCurrentSnapshotsAndIndependentMutationOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $owner = null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $first = $this->createItem(LegacySoftware::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $later = $this->createItem(LegacySoftware::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $template = $this->createItem(LegacySoftware::class, ['name' => $this->getUniqueString(), 'entities_id' => 0, 'is_template' => 1]);
            $unused = $this->createItem(LegacySoftware::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $one = $this->createItem(LegacyVersion::class, ['name' => '1', 'softwares_id' => $first->getID(), 'entities_id' => 0]);
            $two = $this->createItem(LegacyVersion::class, ['name' => '2', 'softwares_id' => $first->getID(), 'entities_id' => 0]);
            $connection = $DB->getDoctrineConnection();
            $collection = new class () extends DictionaryCollection {
                public object $connection;
                public int $firstId;
                public int $laterId;
                public int $secondVersion;
                public string $laterName;
                public bool $changeVersion = true;
                public array $names = [];
                public array $versions = [];
                public array $active = [];

                public function replayDictionnaryOnOneSoftware(
                    array &$new_softs,
                    array $res_rule,
                    $ID,
                    $entity,
                    $name,
                    $manufacturer,
                    array &$soft_ids
                ) {
                    $this->names[(int)$ID] = $name;
                    parent::replayDictionnaryOnOneSoftware($new_softs, $res_rule, $ID, $entity, $name, $manufacturer, $soft_ids);
                    if ((int)$ID === $this->firstId) {
                        $this->connection->update('glpi_softwares', ['name' => $this->laterName], ['id' => $this->laterId]);
                    }
                }

                public function moveVersions($ID, $new_software_id, $version_id, $old_version, $new_version, $entity)
                {
                    $this->versions[] = $old_version;
                    $this->active[] = $this->connection->isApplicationEntityManagerActive();
                    if ($this->changeVersion) {
                        $this->changeVersion = false;
                        $this->connection->update('glpi_softwareversions', ['name' => 'Callback changed current name'], ['id' => $this->secondVersion]);
                    }
                    parent::moveVersions($ID, $new_software_id, $version_id, $old_version, $new_version, $entity);
                }
            };
            $collection->connection = $connection;
            $collection->firstId = (int)$first->getID();
            $collection->laterId = (int)$later->getID();
            $collection->secondVersion = (int)$two->getID();
            $collection->laterName = $this->getUniqueString();
            $owner = Orm::create($DB);
            $dirtySoftware = $owner->find(SoftwareRecord::class, $first->getID());
            $dirtyVersion = $owner->find(VersionRecord::class, $one->getID());
            $dirtySoftware->name = 'Unflushed software';
            $dirtyVersion->name = 'Unflushed version';
            $collection->replayDictionnaryOnSoftwaresByID(
                [$first->getID(), $template->getID(), $later->getID()],
                ['version_append' => '_dictionary']
            );
            $this->array($collection->versions)->isIdenticalTo(['1', '2']);
            $this->array($collection->active)->isIdenticalTo([false, false]);
            $this->array(array_keys($collection->names))->isIdenticalTo([(int)$first->getID(), (int)$later->getID()]);
            $this->string($collection->names[(int)$later->getID()])->isIdenticalTo($collection->laterName);
            $this->string($connection->fetchOne('SELECT name FROM glpi_softwareversions WHERE id=?', [$two->getID()]))
                ->isIdenticalTo('2_dictionary');
            Orm::read($DB, function (EntityManager $outer) use ($collection, $first): void {
                $nested = $outer->find(SoftwareRecord::class, $first->getID());
                $nested->name = 'Unflushed enclosing software';
                $collection->replayDictionnaryOnSoftwaresByID([$first->getID()], ['version_append' => '_again']);
                $this->boolean($outer->contains($nested))->isTrue();
                $this->string($nested->name)->isIdenticalTo('Unflushed enclosing software');
            });
            $this->array($collection->versions)->isIdenticalTo(['1', '2', '1_dictionary', '2_dictionary']);
            $this->string($connection->fetchOne('SELECT name FROM glpi_softwareversions WHERE id=?', [$one->getID()]))
                ->isIdenticalTo('1_dictionary_again');
            $collection->replayDictionnaryOnSoftwaresByID([$first->getID()], ['version' => 'merged']);
            $this->string($connection->fetchOne('SELECT name FROM glpi_softwareversions WHERE id=?', [$one->getID()]))
                ->isIdenticalTo('merged');
            $this->variable($connection->fetchOne('SELECT id FROM glpi_softwareversions WHERE id=?', [$two->getID()]))
                ->isIdenticalTo(false);
            $collection->putOldSoftsInTrash([$unused->getID(), $first->getID(), $template->getID()]);
            $this->integer((int)$connection->fetchOne('SELECT is_deleted FROM glpi_softwares WHERE id=?', [$unused->getID()]))->isIdenticalTo(1);
            $this->integer((int)$connection->fetchOne('SELECT is_deleted FROM glpi_softwares WHERE id=?', [$template->getID()]))->isIdenticalTo(1);
            $this->integer((int)$connection->fetchOne('SELECT is_deleted FROM glpi_softwares WHERE id=?', [$first->getID()]))->isIdenticalTo(0);
            $this->boolean($owner->contains($dirtySoftware))->isTrue();
            $this->boolean($owner->contains($dirtyVersion))->isTrue();
            $this->string($dirtySoftware->name)->isIdenticalTo('Unflushed software');
            $this->string($dirtyVersion->name)->isIdenticalTo('Unflushed version');
        } finally {
            $owner?->clear();
            $_SESSION = $session;
        }
    }

    public function testDictionarySoftwareSnapshotKeepsCustomSelectedRouteBeforeReplayCallback(): void
    {
        global $DB;
        $original = $DB;
        try {
            $software = getItemByTypeName(LegacySoftware::class, '_test_soft');
            $selected = new class ($DB->getDoctrineConnection()) extends ScalarReadProbe {
                public object $original;
                public EventManager $events;
                public int $constructed = 0;
                public int $clears = 0;
                public function getEventManager(): EventManager
                {
                    ++$this->constructed;
                    $GLOBALS['DB'] = $this->original;
                    return $this->events;
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected->original = $original;
            $selected->events = new EventManager();
            $selected->events->addEventListener(['onClear'], $selected);
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new DictionaryRouteAdapter();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $collection = new class () extends DictionaryCollection {
                public array $names = [];
                public function replayDictionnaryOnOneSoftware(
                    array &$new_softs,
                    array $res_rule,
                    $ID,
                    $entity,
                    $name,
                    $manufacturer,
                    array &$soft_ids
                ) {
                    $this->names[(int)$ID] = $name;
                }
            };
            $DB = $adapter;
            $collection->replayDictionnaryOnSoftwaresByID([(string)$software->getID()], ['marker' => 'retained']);
            $this->array($collection->names)->isIdenticalTo([(int)$software->getID() => $software->fields['name']]);
            $this->array($selected->queries)->hasSize(1);
            $this->integer($selected->constructed)->isIdenticalTo(1);
            $this->integer($selected->clears)->isIdenticalTo(0);
        } finally {
            $DB = $original;
        }
    }

    public function testCleanTestOutputCriterias()
    {
        $collection = new \RuleDictionnarySoftwareCollection();
        $params     = ['manufacturers_id' => 1,
                       '_bad'             => '_value2',
                       '_ignore_import'   => '1'];
        $result     = $collection->cleanTestOutputCriterias($params);
        $expected   = ['manufacturers_id' => 1,
                     '_ignore_import'   => '1'];
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testVersionExists()
    {
        $soft    = getItemByTypeName('Software', '_test_soft');
        $version = getItemByTypeName('SoftwareVersion', '_test_softver_1');

        $collection = new \RuleDictionnarySoftwareCollection();
        $result     = $collection->versionExists($soft->getID(), '_test_softver_1');

        $this->variable($result)->isIdenticalTo($version->getID());

        $collection = new \RuleDictionnarySoftwareCollection();
        $result     = $collection->versionExists($soft->getID(), '_test_softver_111');

        $this->variable($result)->isIdenticalTo(-1);
    }

    public function testMoveLicense()
    {
        $old_software = new \Software();
        $softwares_id = $old_software->add([
           'name'         => 'Software ' .$this->getUniqueString(),
           'is_template'  => 0,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);
        $this->boolean($old_software->getFromDB($softwares_id))->isTrue();

        //ad and link 5 licenses to new software
        for ($i = 0; $i < 5; ++$i) {
            $license = new \SoftwareLicense();
            $license_id = $license->add([
               'name'         => 'Software license ' . $this->getUniqueString(),
               'softwares_id' => $old_software->getID(),
               'entities_id'  => 0
            ]);
            $this->integer((int)$license_id)->isGreaterThan(0);
            $this->boolean($license->getFromDB($license_id))->isTrue();
        }

        $new_software = new \Software();
        $softwares_id = $new_software->add([
           'name'         => 'Software ' .$this->getUniqueString(),
           'is_template'  => 0,
           'entities_id'  => 0
        ]);
        $this->integer((int)$softwares_id)->isGreaterThan(0);
        $this->boolean($new_software->getFromDB($softwares_id))->isTrue();

        $collection = new \RuleDictionnarySoftwareCollection();
        $this->boolean(
            $collection->moveLicenses(
                $old_software->getID(),
                $new_software->getID()
            )
        )->isTrue();

        $this->integer(
            (int)countElementsInTable(
                'glpi_softwarelicenses',
                ['softwares_id' => $old_software->getID()]
            )
        )->isIdenticalTo(0);
        $this->integer(
            (int)countElementsInTable(
                'glpi_softwarelicenses',
                ['softwares_id' => $new_software->getID()]
            )
        )->isIdenticalTo(5);

        $this->boolean($collection->moveLicenses('100', $new_software->getID()))->isFalse();
        $this->boolean($collection->moveLicenses($old_software->getID(), '100'))->isFalse();
    }

    public function testPutOldSoftsInTrash()
    {
        $this->login();

        $collection = new \RuleDictionnarySoftwareCollection();
        $software   = new \Software();

        //Softwares with no version
        $soft_id_1  = $software->add(['name' => 'Soft1', 'entities_id' => 0]);
        $this->integer($soft_id_1)->isGreaterThan(0);
        $soft_id_2  = $software->add(['name' => 'Soft2', 'entities_id' => 0]);
        $this->integer($soft_id_2)->isGreaterThan(0);

        //Software with at least one version (from bootstrap)
        $soft3      = getItemByTypeName('Software', '_test_soft');

        //Software already deleted
        $soft_id_4  = $software->add(['name' => 'Soft4', 'is_deleted' => 1, 'entities_id' => 0]);
        $this->integer($soft_id_4)->isGreaterThan(0);

        //Template of software
        $soft_id_5  = $software->add(['name' => 'Soft5', 'is_template' => 1, 'entities_id' => 0]);
        $this->integer($soft_id_5)->isGreaterThan(0);

        $collection->putOldSoftsInTrash([
           $soft_id_1,
           $soft_id_2,
           $soft3->getID(),
           $soft_id_4,
           $soft_id_5
        ]);

        //Softwares newly put in trash
        $this->integer(
            (int)countElementsInTable('glpi_softwares', ['name' => 'Soft1', 'is_deleted' => 1])
        )->isIdenticalTo(1);
        $this->integer(
            (int)countElementsInTable('glpi_softwares', ['name' => 'Soft2', 'is_deleted' => 1])
        )->isIdenticalTo(1);
        $this->integer(
            (int)countElementsInTable('glpi_softwares', ['name' => 'Soft4', 'is_deleted' => 1])
        )->isIdenticalTo(1);

        //Softwares not affected
        $this->integer(
            (int)countElementsInTable('glpi_softwares', ['name' => '_test_soft', 'is_deleted' => 1])
        )->isIdenticalTo(0);
        $this->integer(
            (int)countElementsInTable('glpi_softwares', ['name' => 'Soft5', 'is_deleted' => 0])
        )->isIdenticalTo(0);
    }

    public function testIgnoreImport()
    {
        $rule       = new \Rule();
        $criteria   = new \RuleCriteria();
        $action     = new \RuleAction();
        $collection = new \RuleDictionnarySoftwareCollection();

        $rules_id = $rule->add(['name'        => 'Ignore import',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                               ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            (int)$criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::PATTERN_IS,
              'pattern'   => 'Mozilla Firefox 52'
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => '_ignore_import',
              'value'       => '1'
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Mozilla Firefox 52',
                  'version'          => '52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];
        $result = $collection->processAllRules($input);
        $expected = ['_ignore_import' => '1', '_ruleid' => $rules_id];
        $this->array($result)->isIdenticalTo($expected);

        $input = ['name'             => 'Mozilla Firefox 53',
                  'version'          => '52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];
        $result = $collection->processAllRules($input);
        $expected = ['_no_rule_matches' => true, '_rule_process' => false];
        $this->array($result)->isIdenticalTo($expected);

    }

    public function testSetSoftwareVersion()
    {
        $rule       = new \Rule();
        $criteria   = new \RuleCriteria();
        $action     = new \RuleAction();
        $collection = new \RuleDictionnarySoftwareCollection();

        $rules_id = $rule->add(['name'        => 'Set version',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                             ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            $criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::REGEX_MATCH,
              'pattern'   => '/Mozilla Firefox (.*)/'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'regex_result',
              'field'       => 'version',
              'value'       => '#0'
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Mozilla Firefox 52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];

        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = ['version' => '52', '_ruleid' => $rules_id];
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testSetSoftwareNameAndVersion()
    {
        $rule       = new \Rule();
        $criteria   = new \RuleCriteria();
        $action     = new \RuleAction();
        $collection = new \RuleDictionnarySoftwareCollection();

        $rules_id = $rule->add(['name'        => 'Set version',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                             ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            $criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::REGEX_MATCH,
              'pattern'   => '/Mozilla Firefox (.*)/'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'regex_result',
              'field'       => 'version',
              'value'       => '#0'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => 'name',
              'value'       => 'Mozilla Firefox'
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Mozilla Firefox 52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];

        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = [
           'version' => '52',
           'name'    => 'Mozilla Firefox',
           '_ruleid' => $rules_id,
        ];
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testSetSoftwareNameAndCategory()
    {
        $rule       = new \Rule();
        $criteria   = new \RuleCriteria();
        $action     = new \RuleAction();
        $collection = new \RuleDictionnarySoftwareCollection();
        $category   = new \SoftwareCategory();
        $categories_id = $category->importExternal('web');

        $rules_id = $rule->add(['name'        => 'Set version',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                             ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            $criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::REGEX_MATCH,
              'pattern'   => '/Mozilla Firefox (.*)/'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => 'softwarecategories_id',
              'value'       => $categories_id
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => 'name',
              'value'       => 'Mozilla Firefox'
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Mozilla Firefox 52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];

        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = [
           'softwarecategories_id' => "$categories_id",
           'name'                  => 'Mozilla Firefox',
           '_ruleid'               => $rules_id
        ];
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testSetManufacturer()
    {
        $rule             = new \Rule();
        $criteria         = new \RuleCriteria();
        $action           = new \RuleAction();
        $collection       = new \RuleDictionnarySoftwareCollection();
        $manufacturer     = new \Manufacturer();
        $manufacturers_id = $manufacturer->importExternal('Mozilla');

        $rules_id = $rule->add(['name'        => 'Set manufacturer',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                               ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            $criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::REGEX_MATCH,
              'pattern'   => '/Mozilla Firefox (.*)/'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'assign',
              'field'       => 'manufacturers_id',
              'value'       => $manufacturers_id
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Mozilla Firefox 52',
                  'manufacturer'     => 'Mozilla',
                  '_system_category' => 'web'
                 ];

        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = ['manufacturers_id' => "$manufacturers_id",
                     '_ruleid'          => $rules_id
                    ];
        $this->array($result)->isIdenticalTo($expected);
    }

    public function testSetSoftwareVersionAppend()
    {
        $rule       = new \Rule();
        $criteria   = new \RuleCriteria();
        $action     = new \RuleAction();
        $collection = new \RuleDictionnarySoftwareCollection();

        $rules_id = $rule->add(['name'        => 'Test append',
                                'is_active'   => 1,
                                'entities_id' => 0,
                                'sub_type'    => 'RuleDictionnarySoftware',
                                'match'       => \Rule::AND_MATCHING,
                                'condition'   => 0,
                                'description' => ''
                             ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $this->integer(
            $criteria->add([
              'rules_id'  => $rules_id,
              'criteria'  => 'name',
              'condition' => \Rule::REGEX_MATCH,
              'pattern'   => '/^Soft (something|else)/'
         ])
        )->isGreaterThan(0);

        $this->integer(
            $action->add([
              'rules_id'    => $rules_id,
              'action_type' => 'append_regex_result',
              'field'       => 'version',
              'value'       => '#0'
         ])
        )->isGreaterThan(0);

        $input = ['name'             => 'Soft something'];
        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = ['version_append' => 'something', 'version' => 'something', '_ruleid' => $rules_id];
        $this->array($result)->isIdenticalTo($expected);

        $input = ['name'             => 'Soft else'];
        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;
        $result   = $collection->processAllRules($input);
        $expected = ['version_append' => 'else', 'version' => 'else', '_ruleid' => $rules_id];
        $this->array($result)->isIdenticalTo($expected);

    }

    public function testAppendRegexResultAppendsToExistingValue()
    {
        $rule = new \RuleDictionnarySoftware();
        $criteria = new \RuleCriteria();
        $action = new \RuleAction();

        $rules_id = $rule->add([
           'name'        => 'Test append to existing value',
           'is_active'   => 1,
           'entities_id' => 0,
           'sub_type'    => 'RuleDictionnarySoftware',
           'match'       => \Rule::AND_MATCHING,
           'condition'   => 0,
           'description' => ''
        ]);
        $this->integer((int)$rules_id)->isGreaterThan(0);

        $criteria_id = $criteria->add([
           'rules_id'  => $rules_id,
           'criteria'  => 'name',
           'condition' => \Rule::REGEX_MATCH,
           'pattern'   => '/^Soft (.*)/'
        ]);
        $this->integer((int)$criteria_id)->isGreaterThan(0);

        $action_id = $action->add([
           'rules_id'    => $rules_id,
           'action_type' => 'append_regex_result',
           'field'       => 'version',
           'value'       => '#0'
        ]);
        $this->integer((int)$action_id)->isGreaterThan(0);

        $rule_instance = new \RuleDictionnarySoftware();
        $this->boolean($rule_instance->getRuleWithCriteriasAndActions($rules_id, 1, 1))->isTrue();

        $input = ['name' => 'Soft base'];
        $output = [];
        $params = ['version' => 'pre-'];
        $rule_instance->process($input, $output, $params);

        $this->array($output)->isIdenticalTo([
           'version' => 'pre-base',
           '_rule_process' => true,
        ]);
    }
}
