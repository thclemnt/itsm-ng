<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

use itsmng\Database\Entity;
use itsmng\Database\Orm;

/** Public reporting calls keep fresh rows and application entity/deletion scope. */
class Stat extends \DbTestCase
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
        $entity = (int)(new \Entity())->add(['name' => 'Statistics metadata scope ' . $type, 'entities_id' => 0]);
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
            $record->date = new \DateTime($date);
            $record->solvedate = new \DateTime($date);
            $record->closedate = new \DateTime($date);
            $record->solve_delay_stat = $delay;
            $record->is_deleted = $deleted;
            $manager->persist($record);
            $manager->flush();
        };
        $create($entity, false, '2025-01-15 12:00:00', 200);
        $create($entity, true, '2025-01-15 12:00:00', 900);
        $create(0, false, '2025-01-15 12:00:00', 900);
        $create($entity, false, '2025-02-01 00:00:00', 900);
        $measure = static fn (string $metric): array => \Stat::constructEntryValues($type, $metric, '2025-01-01', '2025-01-31');
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 1]);
        $this->array($measure('inter_solved'))->isIdenticalTo(['2025-01' => 1]);
        $this->float($measure('inter_avgsolvedtime')['2025-01'])->isEqualTo(200.0);
        $create($entity, false, '2025-01-31 23:59:59', 600);
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 2]);
        $this->array($measure('inter_solved'))->isIdenticalTo(['2025-01' => 2]);
        $this->float($measure('inter_avgsolvedtime')['2025-01'])->isEqualTo(400.0);
        $_SESSION['glpiactiveentities'] = [];
        $this->array($measure('inter_total'))->isIdenticalTo(['2025-01' => 0]);
    }
}
