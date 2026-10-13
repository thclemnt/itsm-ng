<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use itsmng\Database\Expressions;

/** Calendar text in the connection's timezone, with the application's legacy precision. */
final class TemporalText extends FunctionNode
{
    private Node $value;
    private string $kind;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->kind = $parser->getLexer()->lookahead->value;
        $parser->match(TokenType::T_STRING);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        $value = $this->value->dispatch($walker);
        return (new Expressions($walker->getConnection()->getDatabasePlatform()))->temporalText(
            $value,
            $this->kind
        );
    }
}
