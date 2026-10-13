<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** Numeric prefix of the application's at-most-ten-character numbering mask. */
final class AutoNameNumber extends FunctionNode
{
    private Node|string $value;
    private Node|string|null $position = null;
    private Node|string|null $width = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        if ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->position = $parser->SimpleArithmeticExpression();
            $parser->match(TokenType::T_COMMA);
            $this->width = $parser->SimpleArithmeticExpression();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        $platform = $walker->getConnection()->getDatabasePlatform();
        $value = function () use ($walker, $platform): string {
            $sql = $walker->walkStringPrimary($this->value);
            if ($this->position === null) {
                return $sql;
            }
            // Walk in emitted SQL order. Doctrine's generic SUBSTRING walks
            // width before position, reversing positional bindings in this expression.
            $position = $walker->walkSimpleArithmeticExpression($this->position);
            $width = $walker->walkSimpleArithmeticExpression($this->width);
            if ($platform instanceof PostgreSQLPlatform) {
                // Unresolved PDO parameters otherwise select substring's regex/
                // escape overload rather than its character-position overload.
                $position = 'CAST(' . $position . ' AS INTEGER)';
                $width = 'CAST(' . $width . ' AS INTEGER)';
            }
            return $platform->getSubstringExpression($sql, $position, $width);
        };
        // Every repeated PostgreSQL prefix expression registers its own bindings.
        return self::expression($value, $platform);
    }

    /** Shared only with the explicitly unmapped plugin numbering query. */
    public static function expression(string|callable $value, AbstractPlatform $platform): string
    {
        $sql = is_string($value) ? static fn (): string => $value : $value;
        if (!$platform instanceof PostgreSQLPlatform) {
            return 'CAST(' . $sql() . ' AS UNSIGNED)';
        }
        // MySQL accepts the leading ASCII integer, not decimal/exponent suffixes
        // or Unicode digits; nonnumeric input contributes zero. Negative parsing
        // saturates at signed64 minimum before unsigned conversion; positive
        // parsing saturates at unsigned64 maximum. Keep exact decimal arithmetic.
        $prefix = static fn (): string => "COALESCE(CAST(SUBSTRING(" . $sql() . " FROM E'^[ \\t\\n\\r\\f\\013]*([+-]?[0-9]+)') AS NUMERIC), 0)";
        return '(CASE WHEN ' . $sql() . ' IS NULL THEN NULL WHEN ' . $prefix() . ' < 0 THEN '
            . '18446744073709551616 + GREATEST(' . $prefix() . ', -9223372036854775808) ELSE '
            . 'LEAST(' . $prefix() . ', 18446744073709551615) END)';
    }
}
