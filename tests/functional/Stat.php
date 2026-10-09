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
        $create($entity, false, '2025-01-31 23:59:59', 600);
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 2]);
        $this->array($measure('inter_solved'))->isIdenticalTo(['2025-01' => 2]);
        $this->float($measure('inter_avgsolvedtime')['2025-01'])->isEqualTo(400.0);
        $_SESSION['glpiactiveentities'] = [];
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 0]);
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
        }
    }
}
