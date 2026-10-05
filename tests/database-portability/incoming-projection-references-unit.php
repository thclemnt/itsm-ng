<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
foreach ([new PostgreSQLPlatform(), new MySQL80Platform()] as $platform) {
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
    $verify(!$snapshot->has('application', 'missing') && $connection->reads === 1, 'Repeated guards issue exactly one native catalogue read');
    $connection->rows = [];
    $fresh = new IncomingProjectionReferences($connection);
    $verify(!$fresh->has('application', 'first') && $connection->reads === 2, 'A later planning call observes a removed native reference on the same connection');
    $connection->rows = [['referenced_schema' => 'application', 'referenced_table' => 'third']];
    $fresh = new IncomingProjectionReferences($connection);
    $verify($fresh->has('application', 'third') && !$fresh->has('application', 'first') && $connection->reads === 3, 'A later planning call observes an added native reference without leaking earlier captures');
}
echo "Pure incoming projection capture: $assertions assertions passed without connecting.\n";
