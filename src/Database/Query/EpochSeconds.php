<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** Seconds since the epoch: elapsed durations must not use wall-clock arithmetic across DST. */
final class EpochSeconds extends FunctionNode
{
    private Node $value;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        $value = $this->value->dispatch($walker);
        return $walker->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'EXTRACT(EPOCH FROM ' . $value . ')'
            : 'UNIX_TIMESTAMP(' . $value . ')';
    }
}
