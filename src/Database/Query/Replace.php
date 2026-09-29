<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** Literal string replacement supported by both application database platforms. */
final class Replace extends FunctionNode
{
    private array $arguments = [];

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        for ($i = 0; $i < 3; ++$i) {
            if ($i > 0) {
                $parser->match(TokenType::T_COMMA);
            }
            $this->arguments[] = $parser->StringPrimary();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        return 'REPLACE(' . implode(', ', array_map($walker->walkStringPrimary(...), $this->arguments)) . ')';
    }
}
