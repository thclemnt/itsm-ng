<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\IncomingProjectionReferences;

// Exercise call-local capture without constructing a driver or connecting.
if (!class_exists(Connection::class)) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}
require_once dirname(__DIR__, 2) . '/src/Database/Migration/IncomingProjectionReferences.php';

final class IncomingReferenceFixtureConnection extends Connection
{
    public int $reads = 0;
    public array $rows = [];
    public array $queries = [];

    public function __construct(private readonly AbstractPlatform $fixturePlatform)
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->fixturePlatform;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        ++$this->reads;
        $this->queries[] = [$query, $params, $types];
        return $this->rows;
    }
}

$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
foreach ([new PostgreSQLPlatform(), new MySQL80Platform(), new MariaDBPlatform()] as $platform) {
    $mysql = $platform instanceof AbstractMySQLPlatform;
    $connection = new IncomingReferenceFixtureConnection($platform);
    $connection->rows = [
        ['referenced_schema' => 'application', 'referenced_table' => 'first'],
        ['referenced_schema' => 'application', 'referenced_table' => 'second'],
        ['referenced_schema' => 'application', 'referenced_table' => 'second'],
        ['referenced_schema' => 'external', 'referenced_table' => 'first'],
    ];
    $snapshot = new IncomingProjectionReferences($connection);
    $verify($connection->reads === 0, 'An unused read-only planning context does not inspect a catalogue');
    $verify($snapshot->has('application', 'first') && $snapshot->has('application', 'second'), 'One capture covers distinct referenced tables');
    $verify($snapshot->has('external', 'first') && !$snapshot->has('external', 'second'), 'Referenced schema remains part of native relation identity');
    $verify(!$snapshot->has('application', 'missing') && $connection->reads === ($mysql ? 5 : 1), 'Capture inspects each requested MySQL target once or the unchanged PostgreSQL inventory once');
    $reads = $connection->reads;
    $verify($snapshot->has('application', 'first') && !$snapshot->has('application', 'missing') && $connection->reads === $reads,
        'Both positive and negative guards reuse only this planning context');
    $connection->rows = [];
    $fresh = new IncomingProjectionReferences($connection);
    $verify(!$fresh->has('application', 'first') && $connection->reads === ($mysql ? 6 : 2), 'A later planning call observes a removed native reference on the same connection');
    $connection->rows = [['referenced_schema' => 'application', 'referenced_table' => 'third']];
    $fresh = new IncomingProjectionReferences($connection);
    $verify($fresh->has('application', 'third') && !$fresh->has('application', 'first') && $connection->reads === ($mysql ? 8 : 3), 'A later planning call observes an added native reference without leaking earlier captures');
    if ($mysql) {
        foreach ($connection->queries as [$query, $params, $types]) {
            $verify($query === 'SELECT referenced_table_schema AS referenced_schema, referenced_table_name AS referenced_table '
                . 'FROM information_schema.key_column_usage WHERE referenced_table_schema = ? '
                . 'AND referenced_table_name = ? AND referenced_column_name = ?'
                && count($params) === 3 && $params[2] === 'items_id' && $types === [],
                'MySQL reads scope only the actual referenced target and column, leaving every referencing owner visible');
        }
        $verify(array_column($connection->queries, 1) === [
            ['application', 'first', 'items_id'], ['application', 'second', 'items_id'],
            ['external', 'first', 'items_id'], ['external', 'second', 'items_id'],
            ['application', 'missing', 'items_id'], ['application', 'first', 'items_id'],
            ['application', 'third', 'items_id'], ['application', 'first', 'items_id'],
        ], 'Each target identity is bound independently and fresh contexts repeat native inspection');
        $schema = "quoted.schema'name";
        $table = 'quoted.table`name';
        $connection->rows = [
            ['referenced_schema' => strtoupper($schema), 'referenced_table' => $table],
            ['referenced_schema' => $schema, 'referenced_table' => strtoupper($table)],
            ['referenced_schema' => 'other', 'referenced_table' => $table],
        ];
        $fresh = new IncomingProjectionReferences($connection);
        $verify(!$fresh->has($schema, $table), 'Catalogue collation lookalikes and unrelated returned rows cannot alias an exact target');
        $verify($connection->queries[array_key_last($connection->queries)][1] === [$schema, $table, 'items_id'],
            'Literal target punctuation is bound unchanged instead of interpolated or parsed');
        $connection->rows[] = ['referenced_schema' => $schema, 'referenced_table' => $table];
        $later = new IncomingProjectionReferences($connection);
        $verify($later->has($schema, $table) && !$fresh->has($schema, $table),
            'Only a fresh planning context admits a newly present exact target while the earlier negative stays local');
    }
}
echo "Pure incoming projection capture: $assertions assertions passed without connecting.\n";
