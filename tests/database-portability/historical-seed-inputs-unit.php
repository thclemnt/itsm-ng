<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\Seeds20261001;

// Dependency loading only: no application bootstrap or database connection.
require getenv('ITSM_PURE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/Database/Migration/Baseline20261001.php';
require dirname(__DIR__, 2) . '/src/Database/Migration/Seeds20261001.php';
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
$historyDirectory = dirname(__DIR__, 2) . '/src/Database/Migration/';
verify(hash_file('sha256', $historyDirectory . 'Baseline20261001.php') === '10902233fd85c78a94f4a5774446e9f9f16c15f8ee907743fb700552c9c97589', 'Frozen baseline bytes unchanged');
verify(hash_file('sha256', $historyDirectory . 'history/20261001-seeds.php') === '4664dea8553b27cb5c0b786f8920e69f83439a7bc722326e5cef395f12a2ec49', 'Frozen seed bytes unchanged');
$raw = Seeds20261001::rows();
foreach ([new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
    $schema = (new Baseline20261001())->build($platform);
    $omissions = [];
    foreach ($raw as $name => $records) {
        foreach ($records as $record) {
            foreach ($schema->getTable($name)->getColumns() as $column) {
                if ($column->getNotnull() && !$column->getAutoincrement() && $column->getDefault() === null && !array_key_exists($column->getName(), $record)) {
                    $key = $name . '.' . $column->getName();
                    $omissions[$key] = ($omissions[$key] ?? 0) + 1;
                }
            }
        }
    }
    verify($omissions === ($platform instanceof MariaDBPlatform ? ['glpi_rulerightparameters.comment' => 13, 'glpi_ssovariables.comment' => 6] : []), 'Complete frozen seed inventory explains exact strict-mode omissions');
    $prepared = Seeds20261001::prepare($schema, $raw);
    verify(array_keys($prepared) === array_keys($raw) && array_sum(array_map('count', $prepared)) === 1741, 'Historical record identities and ordering retained');
    $added = [];
    foreach ($raw as $name => $records) {
        foreach ($records as $position => $record) {
            verify(array_intersect_key($prepared[$name][$position], $record) === $record, 'Explicit historical source values retained');
            foreach (array_diff_key($prepared[$name][$position], $record) as $field => $value) {
                verify($value === '', 'Only the frozen explicit empty-string input is added');
                $key = $name . '.' . $field;
                $added[$key] = ($added[$key] ?? 0) + 1;
            }
        }
    }
    verify($added === ['glpi_rulerightparameters.comment' => 13, 'glpi_ssovariables.comment' => 6], 'Only nineteen absent historical comment inputs change');
    $explicit = $raw;
    $explicit['glpi_rulerightparameters'][0]['comment'] = 'An explicitly supplied historical comment';
    verify(Seeds20261001::prepare($schema, $explicit)['glpi_rulerightparameters'][0]['comment'] === $explicit['glpi_rulerightparameters'][0]['comment'], 'Explicit value is never replaced by the missing-input addendum');
    $explicit['glpi_rulerightparameters'][0]['comment'] = null;
    try {
        Seeds20261001::prepare($schema, $explicit);
        throw new LogicException('Supplied required NULL accepted');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Historical seed supplies NULL for required field: glpi_rulerightparameters.comment', 'NULL is supplied, not an absent input');
    }
    $drift = clone $schema;
    $drift->getTable('glpi_apiclients')->addColumn('missing_historical_input', 'string');
    try {
        Seeds20261001::prepare($drift, $raw);
        throw new LogicException('Undeclared required input guessed');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Historical seed omits required field: glpi_apiclients.missing_historical_input', 'New omissions diagnose instead of deriving an implicit default');
    }
}
echo "pure: $assertions frozen seed input assertions passed without connections\n";
