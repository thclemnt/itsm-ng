<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\API\ExceptionConverter as Converter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Query;

/** PostgreSQL 18 reports immediate FK RESTRICT failures separately from NO ACTION. */
final class ExceptionConverter implements Converter
{
    public function __construct(private readonly Converter $wrapped)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if ($exception->getSQLState() === '23001') {
            return new ForeignKeyConstraintViolationException($exception, $query);
        }
        return $this->wrapped->convert($exception, $query);
    }
}
