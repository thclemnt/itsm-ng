<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\IncomingProjectionReferences as Capture;

class IncomingProjectionReferences extends \atoum\atoum\test
{
    public function platforms(): array
    {
        return [[new PostgreSQLPlatform()], [new MySQL80Platform()]];
    }

    /**
     * @dataProvider platforms
     */
    public function testCatalogueCaptureIsLazyAndLocalToOnePlanningCall(AbstractPlatform $platform): void
    {
        $connection = new IncomingReferenceFixtureConnection($platform);
        $connection->rows = [
            ['referenced_schema' => 'application', 'referenced_table' => 'first'],
            ['referenced_schema' => 'application', 'referenced_table' => 'second'],
            ['referenced_schema' => 'application', 'referenced_table' => 'second'],
            ['referenced_schema' => 'external', 'referenced_table' => 'first'],
        ];
        $snapshot = new Capture($connection);
        $this->integer($connection->reads)->isIdenticalTo(0);
        $this->boolean($snapshot->has('application', 'first'))->isTrue();
        $this->boolean($snapshot->has('application', 'second'))->isTrue();
        $this->boolean($snapshot->has('external', 'first'))->isTrue();
        $this->boolean($snapshot->has('external', 'second'))->isFalse();
        $this->boolean($snapshot->has('application', 'missing'))->isFalse();
        $this->integer($connection->reads)->isIdenticalTo(1);
        $connection->rows = [];
        $fresh = new Capture($connection);
        $this->boolean($fresh->has('application', 'first'))->isFalse();
        $this->integer($connection->reads)->isIdenticalTo(2);
        $connection->rows = [['referenced_schema' => 'application', 'referenced_table' => 'third']];
        $fresh = new Capture($connection);
        $this->boolean($fresh->has('application', 'third'))->isTrue();
        $this->boolean($fresh->has('application', 'first'))->isFalse();
        $this->integer($connection->reads)->isIdenticalTo(3);
    }
}

/** A deterministic DBAL test double; it cannot construct a native driver. */
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
