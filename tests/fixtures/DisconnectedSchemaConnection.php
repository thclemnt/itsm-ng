<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;
use Doctrine\DBAL\ServerVersionProvider;
use LogicException;

/** SchemaTool uses the requested provider while every native connection attempt refuses. */
final class DisconnectedSchemaConnection extends Connection
{
    public function __construct(AbstractPlatform $platform)
    {
        $driver = new class ($platform) implements Driver {
            public function __construct(private AbstractPlatform $platform)
            {
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                throw new LogicException('Metadata ownership tests cannot connect or execute SQL.');
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->platform;
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                throw new LogicException('A disconnected metadata test has no native exceptions to convert.');
            }
        };
        parent::__construct([], $driver);
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        $platform = $this->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            return new class ($this, $platform) extends PostgreSQLSchemaManager {
                protected function determineCurrentSchemaName(): ?string
                {
                    // DBAL's createSchemaConfig needs this namespace but
                    // metadata generation must never query current_schema().
                    return 'public';
                }
            };
        }
        return parent::createSchemaManager();
    }
}
