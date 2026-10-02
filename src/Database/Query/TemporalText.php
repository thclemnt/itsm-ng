<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

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
        [$postgres, $mysql] = match ($this->kind) {
            'date' => ['YYYY-MM-DD', '%Y-%m-%d'],
            'datetime' => ['YYYY-MM-DD HH24:MI:SS', '%Y-%m-%d %H:%i:%s'],
            default => throw new \InvalidArgumentException('Unknown temporal text representation.'),
        };
        if ($walker->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return 'TO_CHAR(' . $value . ", '" . $postgres . "')";
        }
        return 'DATE_FORMAT(' . $value . ", '" . $mysql . "')";
    }
}
