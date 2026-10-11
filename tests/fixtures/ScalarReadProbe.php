<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\fixtures;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

/** Observe the selected connection without opening another transaction or socket. */
class ScalarReadProbe extends Connection
{
    public int $builders = 0;
    public array $queries = [];

    public function __construct(protected readonly Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        ++$this->builders;
        return parent::createQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
