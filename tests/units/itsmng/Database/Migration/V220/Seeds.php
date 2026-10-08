<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration\V220;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use RuntimeException;
use atoum\atoum\test;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\Seeds as FrozenSeeds;

class Seeds extends test
{
    public function providers(): array
    {
        return [[new MariaDBPlatform()], [new PostgreSQLPlatform()]];
    }

    /** @dataProvider providers */
    public function testFrozenInputsPreserveRowsAndSupplyOnlyMissingComments(AbstractPlatform $platform): void
    {
        $schema = (new Baseline())->build($platform);
        $raw = FrozenSeeds::rows();
        $prepared = FrozenSeeds::prepare($schema, $raw);
        $this->array(array_keys($prepared))->isIdenticalTo(array_keys($raw));
        $this->integer(array_sum(array_map('count', $prepared)))->isIdenticalTo(1741);
        $retained = $added = [];
        foreach ($raw as $name => $records) {
            foreach ($records as $position => $record) {
                $retained[$name][$position] = array_intersect_key($prepared[$name][$position], $record);
                foreach (array_diff_key($prepared[$name][$position], $record) as $field => $value) {
                    $this->string($value)->isIdenticalTo('');
                    $key = $name . '.' . $field;
                    $added[$key] = ($added[$key] ?? 0) + 1;
                }
            }
        }
        $this->array($retained)->isIdenticalTo($raw);
        $this->array($added)->isIdenticalTo(['glpi_rulerightparameters.comment' => 13, 'glpi_ssovariables.comment' => 6]);
        $raw['glpi_rulerightparameters'][0]['comment'] = 'Explicit historical value';
        $this->string(FrozenSeeds::prepare($schema, $raw)['glpi_rulerightparameters'][0]['comment'])->isIdenticalTo('Explicit historical value');
        $raw['glpi_rulerightparameters'][0]['comment'] = null;
        $this->exception(static fn () => FrozenSeeds::prepare($schema, $raw))->isInstanceOf(RuntimeException::class)
            ->hasMessage('Historical seed supplies NULL for required field: glpi_rulerightparameters.comment');
        $raw = FrozenSeeds::rows();
        $schema->getTable('glpi_apiclients')->addColumn('missing_historical_input', 'string');
        $this->exception(static fn () => FrozenSeeds::prepare($schema, $raw))->isInstanceOf(RuntimeException::class)
            ->hasMessage('Historical seed omits required field: glpi_apiclients.missing_historical_input');
    }
}
