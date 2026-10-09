<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

use DateTime;
use DbTestCase;
use Entity as LegacyEntity;
use Stat as LegacyStat;
use ReflectionProperty;
use itsmng\Database\Entity;
use itsmng\Database\Orm;

/** Public reporting calls keep fresh rows and application entity/deletion scope. */
class Stat extends DbTestCase
{
    protected function types(): array
    {
        return [
            ['Ticket', Entity\Ticket::class],
            ['Problem', Entity\Problem::class],
            ['Change', Entity\Change::class],
        ];
    }

    /** @dataProvider types */
    public function testMonthlyMetricsReadCurrentRows(string $type, string $recordClass): void
    {
        global $DB;
        $this->login();
        $entity = (int)(new LegacyEntity())->add(['name' => 'Statistics metadata scope ' . $type, 'entities_id' => 0]);
        $this->integer($entity)->isGreaterThan(0);
        $_SESSION['glpishowallentities'] = false;
        $_SESSION['glpiactiveentities'] = [$entity];
        $manager = Orm::create($DB);
        $model = new $type();
        $closed = (int)$model->getClosedStatusArray()[0];
        // Typed data fixtures, owned by DbTestCase's outer rollback, exercise the
        // actual Stat caller without modifying its routing or caching row data.
        $create = static function (int $scope, bool $deleted, string $date, int $delay) use ($manager, $recordClass, $closed): void {
            $record = new $recordClass();
            $record->entities = $manager->getReference(Entity\Entity::class, $scope);
            $record->name = 'Statistics current data';
            $record->content = 'Functional reporting fixture';
            $record->status = $closed;
            $record->date = new DateTime($date);
            $record->solvedate = new DateTime($date);
            $record->closedate = new DateTime($date);
            $record->solve_delay_stat = $delay;
            $record->is_deleted = $deleted;
            $manager->persist($record);
            $manager->flush();
        };
        $create($entity, false, '2025-01-15 12:00:00', 200);
        $create($entity, true, '2025-01-15 12:00:00', 900);
        $create(0, false, '2025-01-15 12:00:00', 900);
        $create($entity, false, '2025-02-01 00:00:00', 900);
        $measure = static fn (string $metric): array => LegacyStat::constructEntryValues($type, $metric, '2025-01-01', '2025-01-31');
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 1]);
        $this->array($measure('inter_solved'))->isIdenticalTo(['2025-01' => 1]);
        $this->float($measure('inter_avgsolvedtime')['2025-01'])->isEqualTo(200.0);
        $monthlyFactories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeMonthlyReads = $monthlyFactories->getValue();
        $create($entity, false, '2025-01-31 23:59:59', 600);
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 2]);
        $this->array($measure('inter_solved'))->isIdenticalTo(['2025-01' => 2]);
        $this->float($measure('inter_avgsolvedtime')['2025-01'])->isEqualTo(400.0);
        $_SESSION['glpiactiveentities'] = [];
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 0]);
        $monthlyAllocations = $monthlyFactories->getValue() - $beforeMonthlyReads;
        $selectorSession = $_SESSION;
        $connection = $manager->getConnection();
        $selectorAllocations = 0;
        try {
            $_SESSION['glpiactiveentities'] = [$entity];
            $locations = [];
            foreach ([[$entity, 'A node', 'A full name'], [$entity, 'Z sibling', 'Z full name'], [0, 'Outside node', 'Outside full name']] as [$scope, $name, $complete]) {
                $location = new Entity\Location();
                $location->entities = $manager->getReference(Entity\Entity::class, $scope);
                $location->name = $name . ' ' . $type;
                $location->completename = $complete;
                $manager->persist($location);
                $locations[] = $location;
            }
            $manager->flush();
            $priorities = static fn (): array => $model->getUsedPriorityBetween('2025-01-01', '2025-01-31');
            $classifications = static fn (string $dimension = 'locations_id', int $parent = 0): array =>
                LegacyStat::getItems($type, '2025-01-01', '2025-01-31', $dimension, $parent);
            $this->array(array_column($priorities(), 'id'))->isIdenticalTo([1]);
            $labels = array_column($classifications(), 'link', 'id');
            $this->string($labels[$locations[0]->id])->isIdenticalTo('A full name');
            $this->string($labels[$locations[1]->id])->isIdenticalTo('Z full name');
            $this->boolean(array_key_exists($locations[2]->id, $labels))->isFalse();
            $this->array($classifications('locations_tree', $locations[0]->id))
                ->isIdenticalTo([['id' => $locations[0]->id, 'link' => 'A node ' . $type]]);
            $beforeSelectorReads = $monthlyFactories->getValue();
            $connection->update($model::getTable(), ['priority' => 4], ['entities_id' => $entity]);
            $connection->update('glpi_locations', ['name' => 'Renamed node', 'completename' => 'Renamed full name'], ['id' => $locations[0]->id]);
            $this->array(array_column($priorities(), 'id'))->isIdenticalTo([4]);
            $labels = array_column($classifications(), 'link', 'id');
            $this->string($labels[$locations[0]->id])->isIdenticalTo('Renamed full name');
            $this->boolean(array_key_exists($locations[2]->id, $labels))->isFalse();
            $this->array($classifications('locations_tree', $locations[0]->id))
                ->isIdenticalTo([['id' => $locations[0]->id, 'link' => 'Renamed node']]);
            $connection->update('glpi_locations', ['completename' => null], ['id' => $locations[0]->id]);
            $labels = array_column($classifications(), 'link', 'id');
            $this->boolean(array_key_exists($locations[0]->id, $labels))->isTrue();
            $this->variable($labels[$locations[0]->id])->isNull();
            $_SESSION['glpiactiveentities'] = [];
            $this->array($priorities())->isEmpty();
            $this->array($classifications())->isEmpty();
            $this->array($classifications('locations_tree', $locations[0]->id))->isEmpty();
            $_SESSION['glpiactiveentities'] = [$entity];
            $connection->update($model::getTable(), ['priority' => 2], ['entities_id' => $entity]);
            $this->array(array_column($priorities(), 'id'))->isIdenticalTo([2]);
            $labels = array_column($classifications(), 'link', 'id');
            $this->variable($labels[$locations[0]->id])->isNull();
            $selectorAllocations = $monthlyFactories->getValue() - $beforeSelectorReads;
        } finally {
            $_SESSION = $selectorSession;
        }
        if ($type === 'Ticket') {
            $session = $_SESSION;
            $get = $_GET;
            $connection = $manager->getConnection();
            $rootName = $connection->fetchOne('SELECT completename FROM glpi_entities WHERE id = 0');
            try {
                $_SESSION['glpi_multientitiesmode'] = 1;
                $_SESSION['glpiactiveentities'] = [0, $entity];
                $_SESSION['glpilist_limit'] = 20;
                $_GET = ['export_all' => 1];
                $connection->update('glpi_entities', ['completename' => 'Report shared entity'], ['id' => $entity]);
                $connection->update('glpi_entities', ['completename' => 'Report root entity'], ['id' => 0]);
                $assets = [];
                foreach ([$entity, $entity, 0] as $index => $scope) {
                    $asset = new Entity\Computer();
                    $asset->entities = $manager->getReference(Entity\Entity::class, $scope);
                    $asset->name = 'Report asset ' . $index;
                    $manager->persist($asset);
                    $ticket = new Entity\Ticket();
                    $ticket->entities = $asset->entities;
                    $ticket->name = 'Report ticket ' . $index;
                    $ticket->date = new DateTime('2026-02-15 12:00:00');
                    $manager->persist($ticket);
                    $link = new Entity\ItemTicket();
                    $link->itemtype = 'Computer';
                    $link->computer = $asset;
                    $link->tickets = $ticket;
                    $manager->persist($link);
                    $assets[] = $asset;
                }
                $manager->flush();
                $manager->clear();
                $read = static function (): string {
                    ob_start();
                    try {
                        LegacyStat::showItems('/front/stat.item.php', '2026-02-01', '2026-02-28', 0);
                        return ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                };
                $warm = $read();
                $this->string($warm)->contains('Report root entity')->contains('Report asset 0')
                    ->contains('Report asset 1')->contains('Report asset 2');
                $this->integer(substr_count($warm, 'Report shared entity'))->isIdenticalTo(2);
                $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
                $before = $factories->getValue();
                $connection->update('glpi_entities', ['completename' => 'Renamed report entity'], ['id' => $entity]);
                $connection->update('glpi_computers', ['name' => 'Renamed report asset'], ['id' => $assets[0]->id]);
                $renamed = $read();
                $this->string($renamed)->contains('Renamed report asset')->notContains('Report asset 0')
                    ->contains('Report root entity')->notContains('Report shared entity');
                $this->integer(substr_count($renamed, 'Renamed report entity'))->isIdenticalTo(2);
                $connection->update('glpi_entities', ['completename' => null], ['id' => $entity]);
                $nullName = $read();
                $this->string($nullName)->contains('Renamed report asset')->contains('Report root entity')
                    ->notContains('Renamed report entity');
                $_SESSION['glpiactiveentities'] = [$entity];
                $scoped = $read();
                $this->string($scoped)->contains('Renamed report asset')->contains('Report asset 1')
                    ->notContains('Report asset 2')->notContains('Report root entity');
                $_SESSION['glpiactiveentities'] = [];
                $this->string($read())->isEmpty();
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            } finally {
                $connection->update('glpi_entities', ['completename' => $rootName], ['id' => 0]);
                $_SESSION = $session;
                $_GET = $get;
            }
            // Monthly and selector freshness are checked for all three itemtypes;
            // their canonical read path shares this allocation check.
            $this->integer($monthlyAllocations + $selectorAllocations)->isIdenticalTo(0);
        }
    }
}
