<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Query;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** KB_MATCH/KB_SCORE use the article and translation indexes without loading either model. */
final class KnowledgeBaseFullText extends FunctionNode
{
    /** @var list<PathExpression> */
    private array $fields = [];
    private InputParameter $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->fields[] = $parser->StateFieldPathExpression();
        $parser->match(TokenType::T_COMMA);
        if (!$parser->getLexer()->isNextToken(TokenType::T_INPUT_PARAMETER)) {
            $this->fields[] = $parser->StateFieldPathExpression();
            $parser->match(TokenType::T_COMMA);
        }
        $this->query = $parser->InputParameter();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $walker): string
    {
        // Dispatch each placeholder exactly where it occurs in the emitted SQL.
        $columns = array_map(static fn (PathExpression $field): string => $field->dispatch($walker), $this->fields);
        $query = $this->query->dispatch($walker);
        return self::sql($walker->getConnection()->getDatabasePlatform(), $columns, $query, $this->isScore());
    }

    private function isScore(): bool
    {
        return match (strtoupper($this->name)) {
            'KB_MATCH' => false,
            'KB_SCORE' => true,
            default => throw new \LogicException('Unknown knowledge-base full-text operation.'),
        };
    }

    /**
     * Shared with the public legacy criteria API. Columns/query MUST already be
     * trusted SQL expressions from the ORM walker or connection quoting; never request text.
     * The caller normalizes tokens and binds/quotes the provider's prefix-query syntax.
     *
     * @param list<string> $columns One indexed field, or name and answer in that order.
     */
    public static function sql(AbstractPlatform $platform, array $columns, string $query, bool $score = false): string
    {
        if (count($columns) < 1 || count($columns) > 2) {
            throw new \InvalidArgumentException('Knowledge-base full-text search requires one or two fields.');
        }
        if ($platform instanceof PostgreSQLPlatform) {
            // This expression matches the frozen article/translation GIN index definitions.
            $text = implode(" || ' ' || ", array_map(static fn (string $column): string => 'COALESCE(' . $column . ", '')", $columns));
            $vector = "to_tsvector('simple', " . $text . ')';
            $terms = "to_tsquery('simple', " . $query . ')';
            return $score ? 'ts_rank(' . $vector . ', ' . $terms . ')' : '(' . $vector . ' @@ ' . $terms . ')';
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $match = 'MATCH(' . implode(', ', $columns) . ') AGAINST(' . $query . ' IN BOOLEAN MODE)';
            return $score ? $match : '(' . $match . ' > 0)';
        }
        throw new \LogicException('Unsupported knowledge-base full-text platform.');
    }
}
