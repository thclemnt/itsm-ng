<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** Whole-second database clock, matching the existing UNIX_TIMESTAMP() retention boundary. */
final class CurrentEpochSeconds extends FunctionNode
{
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        return $walker->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'FLOOR(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP))'
            : 'UNIX_TIMESTAMP()';
    }
}
