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

/* Test for inc/item_rack.class.php */

class Item_Rack extends DbTestCase
{
    public function testRackOccupancyBatchesMappedTypesWithoutHydration(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $rack = $this->createItem(\Rack::class, [
            'name' => '_projected_occupancy', 'entities_id' => $entity, 'number_units' => 30,
            'max_weight' => 100, 'max_power' => 1000,
        ]);
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class($connection, \itsmng\Database\Orm::configuration($connection->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
            public array $queries = [];

            public function createQuery(string $dql = ''): \Doctrine\ORM\Query
            {
                return $this->queries[] = parent::createQuery($dql);
            }
        };
        $listener = new class {
            public int $loaded = 0;

            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $repository = new \itsmng\Database\Repository\PlacementRepository($em);
            $this->array($repository->rackOccupancy((int)$rack->getID()))->isEmpty();
            $this->array($em->queries)->hasSize(1);
            $models = [];
            $expected = [];
            $half = [\Rack::POS_LEFT => [1, 1, 0, 0], \Rack::POS_RIGHT => [1, 1, 0, 0]];
            foreach (['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Enclosure', 'PDU', 'PassiveDCEquipment', 'Computer'] as $index => $kind) {
                $models[$kind] ??= $this->createItem($kind . 'Model', [
                    'name' => '_projected_occupancy_' . $kind, 'required_units' => 2, 'depth' => 0.5,
                    'weight' => 10,
                ] + ($kind === 'PDU' ? ['max_power' => 10000] : ['power_consumption' => 100]));
                $asset = $this->createItem($kind, [
                    'name' => '_projected_occupancy_' . $index, 'entities_id' => $entity,
                    strtolower($kind) . 'models_id' => $models[$kind]->getID(),
                ]);
                $position = 3 * $index + 1;
                $this->createItem(\Item_Rack::class, [
                    'racks_id' => $rack->getID(), 'itemtype' => $kind, 'items_id' => $asset->getID(),
                    'position' => $position, 'orientation' => \Rack::FRONT, 'hpos' => \Rack::POS_NONE,
                    'is_reserved' => $index % 2,
                ]);
                $expected[$position] = $expected[$position + 1] = $half;
            }
            $em->queries = [];
            $rows = $repository->rackOccupancy((int)$rack->getID());
            $this->array($rows)->hasSize(8);
            // Two Computer assignments still use one asset/model projection.
            $this->array($em->queries)->hasSize(8);
            foreach ($rows as $row) {
                $this->array($row['dimensions'])->isIdenticalTo(['required_units' => 2, 'depth' => 0.5]);
            }
            $em->queries = [];
            $statistics = $repository->rackStatistics((int)$rack->getID());
            $this->array($statistics)->hasSize(8);
            $this->array($em->queries)->hasSize(8);
            foreach ($statistics as $row) {
                $this->array($row['dimensions'])->isIdenticalTo([
                    'required_units' => 2, 'depth' => 0.5, 'weight' => 10,
                    'power_consumption' => $row['itemtype'] === 'PDU' ? 0 : 100,
                ]);
            }
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->array($rack->getFilled())->isEqualTo($expected);
            $render = static function () use ($rack): string {
                ob_start();
                try {
                    \Item_Rack::showStats($rack);
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            // PDU rated supply power must not become rack power consumption.
            $this->string($render())->contains('.text("53%")')
                ->contains('.text("80 / 100")')->contains('.text("700 / 1000")');

            $managed = $em->find(\itsmng\Database\Entity\ComputerModel::class, (int)$models['Computer']->getID());
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_computermodels', [
                'required_units' => 1, 'depth' => 0.25, 'weight' => 30, 'power_consumption' => 200,
            ], ['id' => $managed->id]);
            $em->queries = [];
            $rows = $repository->rackOccupancy((int)$rack->getID());
            foreach ($rows as $row) {
                $this->array($row['dimensions'])->isIdenticalTo($row['itemtype'] === 'Computer'
                    ? ['required_units' => 1, 'depth' => 0.25]
                    : ['required_units' => 2, 'depth' => 0.5]);
            }
            $this->array($em->queries)->hasSize(8);
            $em->queries = [];
            foreach ($repository->rackStatistics((int)$rack->getID()) as $row) {
                $this->integer($row['dimensions']['weight'])->isIdenticalTo($row['itemtype'] === 'Computer' ? 30 : 10);
                $this->integer($row['dimensions']['power_consumption'])->isIdenticalTo(match ($row['itemtype']) {
                    'Computer' => 200, 'PDU' => 0, default => 100,
                });
            }
            $this->array($em->queries)->hasSize(8);
            $this->integer($managed->required_units)->isIdenticalTo(2);
            $this->float($managed->depth)->isIdenticalTo(0.5);
            $this->integer($managed->weight)->isIdenticalTo(10);
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $quarter = [\Rack::POS_LEFT => [1, 0, 0, 0], \Rack::POS_RIGHT => [1, 0, 0, 0]];
            $expected[1] = $expected[22] = $quarter;
            unset($expected[2], $expected[23]);
            $this->array($rack->getFilled())->isEqualTo($expected);
            $this->string($render())->contains('.text("47%")')
                ->contains('.text("120 / 100")')->contains('.text("900 / 1000")');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
        }
    }

    public function testRackStatsAndOccupancyUseCurrentModelDimensions(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $rack = $this->createItem(\Rack::class, [
            'name' => '_stats_rack', 'entities_id' => $entity, 'number_units' => 10,
            'max_weight' => 100, 'max_power' => 1000,
        ]);
        $model = $this->createItem(\ComputerModel::class, [
            'name' => '_stats_half_model', 'required_units' => 2, 'depth' => 0.5,
            'is_half_rack' => 1, 'weight' => 20, 'power_consumption' => 100,
        ]);
        $computers = $placements = [];
        foreach ([
            [1, \Rack::FRONT, \Rack::POS_LEFT, 0],
            [4, \Rack::REAR, \Rack::POS_RIGHT, 1],
            [7, \Rack::FRONT, \Rack::POS_NONE, 0],
        ] as $index => [$position, $orientation, $hpos, $reserved]) {
            $input = ['name' => '_stats_computer_' . $index, 'entities_id' => $entity];
            if ($index < 2) {
                $input['computermodels_id'] = $model->getID();
            }
            $computer = $this->createItem(\Computer::class, $input);
            $computers[] = (int)$computer->getID();
            $placements[] = $this->createItem(\Item_Rack::class, [
                'racks_id' => $rack->getID(), 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                'position' => $position, 'orientation' => $orientation, 'hpos' => $hpos, 'is_reserved' => $reserved,
            ]);
        }
        $this->boolean($rack->can((int)$rack->getID(), READ))->isTrue();
        $this->boolean($rack->canEdit((int)$rack->getID()))->isTrue();
        $renderItems = static function () use ($rack): string {
            ob_start();
            try {
                \Item_Rack::showItems($rack);
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $html = $renderItems();
        // The list transports its rich links as JSON for the table renderer;
        // the rack graph independently owns the itemrack_name anchor markup.
        $this->integer(preg_match('/<script type="application\/json" id="massItem_Rack[0-9]+_config">(.*?)<\/script>/s', $html, $tableConfig))->isIdenticalTo(1);
        $table = json_decode($tableConfig[1], true, 512, JSON_THROW_ON_ERROR);
        $this->string($table['dataSource']['type'])->isIdenticalTo('local');
        $this->array($table['dataSource']['rows'])->hasSize(3);
        foreach ($placements as $index => $placement) {
            $asset = new \Computer();
            $this->boolean($asset->getFromDB($computers[$index]))->isTrue();
            $this->boolean($asset->can($computers[$index], READ))->isTrue();
            $this->array(array_column($table['dataSource']['rows'], 'item'))->contains($asset->getLink());
            $this->string($html)->contains("<a href='" . $placement->getLinkURL() . "'>")
                ->contains("<a href='" . $asset->getLinkURL() . "' class='itemrack_name'")
                ->contains("'>" . $asset->getName() . '</a>');
        }
        $session = $_SESSION;
        try {
            $_SESSION['glpiactiveprofile'][\Rack::$rightname] = 0;
            $this->string($renderItems())->isIdenticalTo('');
        } finally {
            $_SESSION = $session;
        }
        $frontLeft = [\Rack::POS_LEFT => [1, 1, 0, 0], \Rack::POS_RIGHT => [0, 0, 0, 0]];
        $rearRight = [\Rack::POS_LEFT => [0, 0, 0, 0], \Rack::POS_RIGHT => [0, 0, 1, 1]];
        $full = [\Rack::POS_LEFT => [1, 1, 1, 1], \Rack::POS_RIGHT => [1, 1, 1, 1]];
        $this->array($rack->getFilled())->isEqualTo([1 => $frontLeft, 2 => $frontLeft, 4 => $rearRight, 5 => $rearRight, 7 => $full]);
        $this->array($rack->getFilled('Computer', $computers[0]))->isEqualTo([4 => $rearRight, 5 => $rearRight, 7 => $full]);
        $manager = \itsmng\Database\Orm::create($DB);
        try {
            $rows = (new \itsmng\Database\Repository\PlacementRepository($manager))->rackStatistics((int)$rack->getID());
            $byAsset = array_column($rows, 'dimensions', 'items_id');
            $this->variable($byAsset[$computers[2]])->isNull();
            $this->array($byAsset[$computers[0]])->isIdenticalTo([
                'required_units' => 2, 'depth' => 0.5, 'weight' => 20, 'power_consumption' => 100,
            ]);
        } finally {
            $manager->clear();
        }
        $render = static function () use ($rack): string {
            ob_start();
            try {
                \Item_Rack::showStats($rack);
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        // Reserved assignments count; an asset without a model still occupies one full unit.
        $this->string($render())->contains('.text("30%")')
            ->contains('.text("40 / 100")')->contains('.text("200 / 1000")');

        $this->boolean($DB->update('glpi_computermodels', [
            'required_units' => 3, 'depth' => 1, 'weight' => 30, 'power_consumption' => 200,
        ], ['id' => $model->getID()]))->isTrue();
        $left = [\Rack::POS_LEFT => [1, 1, 1, 1], \Rack::POS_RIGHT => [0, 0, 0, 0]];
        $right = [\Rack::POS_LEFT => [0, 0, 0, 0], \Rack::POS_RIGHT => [1, 1, 1, 1]];
        $this->array($rack->getFilled())->isEqualTo([1 => $left, 2 => $left, 3 => $left, 4 => $right, 5 => $right, 6 => $right, 7 => $full]);
        $this->string($render())->contains('.text("70%")')
            ->contains('.text("60 / 100")')->contains('.text("400 / 1000")');
    }

    /**
     * Models provider
     *
     * @return array
     */
    protected function modelsProvider()
    {
        return [
           [
              'name'            => 'Full',
              'required_units'  => 1,
              'depth'           => 1,
              'is_half_rack'    => 0
           ], [
              'name'            => 'Midrack',
              'required_units'  => 1,
              'depth'           => 1,
              'is_half_rack'    => 1
           ], [
              'name'            => '3U',
              'required_units'  => 3,
              'depth'           => 1,
              'is_half_rack'    => 0
           ], [
              'name'            => '1/2 Depth',
              'required_units'  => 1,
              'depth'           => 0.5,
              'is_half_rack'    => 0
           ], [
              'name'            => 'Mid 1/2 depth',
              'required_units'  => 1,
              'depth'           => 0.5,
              'is_half_rack'    => 1
           ], [
              'name'            => '2U and depth',
              'required_units'  => 2,
              'depth'           => 0.25,
              'is_half_rack'    => 0
           ], [
              'name'            => '2U and mid',
              'required_units'  => 2,
              'depth'           => 1,
              'is_half_rack'    => 1
           ]
        ];
    }

    /**
     * Create models
     *
     * @return void
     */
    protected function createModels()
    {
        $model = new \ComputerModel();
        foreach ($this->modelsProvider() as $row) {
            $this->integer(
                (int)$model->add($row)
            )->isGreaterThan(0);
        }
    }

    /**
     * Computers provider
     *
     * @return array
     */
    protected function computersProvider()
    {
        return [
           [
              'name'   => 'SRV-NUX-1',
              'model'  => 'Full'
           ], [
              'name'   => 'SRV-NUX-2',
              'model'  => 'Full'
           ], [
              'name'   => 'MID-NUX-1',
              'model'  => 'Midrack'
           ], [
              'name'   => 'MID-NUX-2',
              'model'  => 'Midrack'
           ], [
              'name'   => 'MID-NUX-3',
              'model'  => 'Midrack'
           ], [
              'name'   => 'BIG-NUX-1',
              'model'  => '3U'
           ], [
              'name'   => 'DEP-NUX-1',
              'model'  => '1/2 Depth'
           ], [
              'name'   => 'DEP-NUX-2',
              'model'  => '1/2 Depth'
           ], [
              'name'   => 'MAD-NUX-1',
              'model'  => 'Mid 1/2 depth'
           ], [
              'name'   => 'MAD-NUX-2',
              'model'  => 'Mid 1/2 depth'
           ], [
              'name'   => 'MAD-NUX-3',
              'model'  => 'Mid 1/2 depth'
           ], [
              'name'   => 'MAD-NUX-4',
              'model'  => 'Mid 1/2 depth'
           ], [
              'name'   => '2AD-NUX-1',
              'model'  => '2U and depth'
           ], [
              'name'   => '2AM-NUX-1',
              'model'  => '2U and mid'
           ]
        ];
    }

    /**
     * Create computers
     *
     * @return void
     */
    protected function createComputers()
    {
        $computer = new \Computer();
        foreach ($this->computersProvider() as $row) {
            $row['computermodels_id'] = getItemByTypeName('ComputerModel', $row['model'], true);
            $this->integer((int)$row['computermodels_id'])->isGreaterThan(0);
            $row['entities_id'] = 0;
            unset($row['model']);
            $this->integer(
                (int)$computer->add($row)
            )->isGreaterThan(0);
        }
    }


    /**
     * Test for adding items into rack
     *
     * @return void
     */
    public function testAdd()
    {
        $this->createModels();
        $this->createComputers();

        $rack = new \Rack();
        //create a 10u rack
        $this->integer(
            (int)$rack->add([
              'name'         => 'Test rack',
              'number_units' => 10,
              'dcrooms_id'   => 0,
              'position'     => 0,
              'entities_id'  => 0,
         ])
        )->isGreaterThan(0);

        $ira = new \Item_Rack();

        $SRVNUX1 = getItemByTypeName('Computer', 'SRV-NUX-1', true);
        //try to add outside rack capabilities
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 15,
              'itemtype'  => 'Computer',
              'items_id'  => $SRVNUX1
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Item is out of rack bounds']);

        //add item at the first position
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 1,
              'itemtype'  => 'Computer',
              'items_id'  => $SRVNUX1
         ])
        )->isGreaterThan(0);

        $BIGNUX1 = getItemByTypeName('Computer', 'BIG-NUX-1', true);
        //take a 3U item and try to add it at the end
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 10,
              'itemtype'  => 'Computer',
              'items_id'  => $BIGNUX1
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Item is out of rack bounds']);

        //take a 3U item and try to add it at the end - 1
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 9,
              'itemtype'  => 'Computer',
              'items_id'  => $BIGNUX1
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Item is out of rack bounds']);

        //take a 3U item and try to add it at the end - 2
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 8,
              'itemtype'  => 'Computer',
              'items_id'  => $BIGNUX1
         ])
        )->isGreaterThan(0);

        //test half racks
        $MIDNUX1 = getItemByTypeName('Computer', 'MID-NUX-1', true);
        $MIDNUX2 = getItemByTypeName('Computer', 'MID-NUX-2', true);
        $MIDNUX3 = getItemByTypeName('Computer', 'MID-NUX-3', true);
        //item is half rack. hpos is required
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 1,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX1
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['You must define an horizontal position for this item']);

        //try to add a half size on the first row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 1,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX1,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        //add it on second row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX1,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isGreaterThan(0);

        //add second half rack item it on second row, at same position
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX2,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        //add second half rack item it on second row, on the other position
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX2,
              'hpos'      => $rack::POS_RIGHT
         ])
        )->isGreaterThan(0);

        //Unit is full!
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MIDNUX3,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        //test depth < 1
        $DEPNUX1 = getItemByTypeName('Computer', 'DEP-NUX-1', true);
        $DEPNUX2 = getItemByTypeName('Computer', 'DEP-NUX-2', true);

        //item ahs a depth <= 0.5. orientation is required
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 1,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX1
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['You must define an orientation for this item']);

        //try to add on the first row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 1,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX1,
              'orientation'  => $rack::FRONT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        //try to add on the second row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX1,
              'orientation'  => $rack::FRONT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        //add on the third row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 3,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX1,
              'orientation'  => $rack::FRONT
         ])
        )->isGreaterThan(0);

        //add not full depth rack item with same orientation
        //try to add on the first row
        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 3,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX2,
              'orientation'  => $rack::FRONT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 3,
              'itemtype'  => 'Computer',
              'items_id'  => $DEPNUX2,
              'orientation'  => $rack::REAR
         ])
        )->isGreaterThan(0);

        //test hf full depth + 2x hf mid depth
        $MADNUX1 = getItemByTypeName('Computer', 'MAD-NUX-1', true);
        $MADNUX2 = getItemByTypeName('Computer', 'MAD-NUX-2', true);

        //first element on unit2 (MID-NUX-1) is half racked on left; and is full depth
        //drop second element on unit2
        $ira->deleteByCriteria(['items_id' => $MIDNUX2], 1);

        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MADNUX1,
              'orientation'  => $rack::REAR,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MADNUX1,
              'orientation'  => $rack::REAR,
              'hpos'      => $rack::POS_RIGHT
         ])
        )->isGreaterThan(0);

        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MADNUX2,
              'orientation'  => $rack::REAR,
              'hpos'      => $rack::POS_LEFT
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['Not enough space available to place item']);

        $ira->getEmpty();
        $this->integer(
            (int)$ira->add([
              'racks_id'  => $rack->getID(),
              'position'  => 2,
              'itemtype'  => 'Computer',
              'items_id'  => $MADNUX2,
              'orientation'  => $rack::FRONT,
              'hpos'      => $rack::POS_RIGHT
         ])
        )->isGreaterThan(0);
    }
}
