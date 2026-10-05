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

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        // Dispatch each occurrence separately: SUBSTRING's bound positions must
        // register every SQL placeholder with Doctrine's parameter mapping.
        return self::expression(fn (): string => $walker->walkStringPrimary($this->value), $walker->getConnection()->getDatabasePlatform());
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
