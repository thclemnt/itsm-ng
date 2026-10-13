<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;
use Throwable;

/** Feed the existing SQL debug panel from every database access path. */
final class SqlDebugMiddleware implements Middleware
{
    private static bool $recording = false;

    /** Legacy adapters use the same boundary; nested driver calls are counted once. */
    public static function record(string $sql, callable $execute): mixed
    {
        global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST;
        if (self::$recording || empty($CFG_GLPI['debug_sql'])
            || !class_exists(\Session::class, false)
            || ($_SESSION['glpi_use_mode'] ?? null) !== \Session::DEBUG_MODE) {
            return $execute();
        }
        self::$recording = true;
        $number = ++$SQL_TOTAL_REQUEST;
        $DEBUG_SQL['queries'][$number] = $sql;
        $start = microtime(true);
        try {
            $result = $execute();
            $DEBUG_SQL['rows'][$number] = $result instanceof LegacyResult
                ? $result->num_rows
                : (is_object($result) ? (int)$result->rowCount() : (int)$result);
            return $result;
        } catch (Throwable $error) {
            $DEBUG_SQL['errors'][$number] = $error->getMessage();
            $DEBUG_SQL['rows'][$number] = -1;
            throw $error;
        } finally {
            $DEBUG_SQL['times'][$number] = microtime(true) - $start;
            self::$recording = false;
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function query(string $sql): Result
                    {
                        return SqlDebugMiddleware::record($sql, fn () => parent::query($sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return SqlDebugMiddleware::record($sql, fn () => parent::exec($sql));
                    }

                    public function prepare(string $sql): Statement
                    {
                        try {
                            $statement = parent::prepare($sql);
                        } catch (Throwable $error) {
                            return SqlDebugMiddleware::record($sql, static fn () => throw $error);
                        }
                        return new class ($statement, $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                return SqlDebugMiddleware::record($this->sql, fn () => parent::execute());
                            }
                        };
                    }
                };
            }
        };
    }
}
