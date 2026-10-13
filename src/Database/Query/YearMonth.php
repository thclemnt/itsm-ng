<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use itsmng\Database\Expressions;

/** Calendar month in the connection's application timezone. */
final class YearMonth extends FunctionNode
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
        return (new Expressions($walker->getConnection()->getDatabasePlatform()))->yearMonth($this->value->dispatch($walker));
    }
}
