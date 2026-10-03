<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Recognize only the generated boolean-domain grammar, retaining SQL precedence. */
final class BooleanCheckExpression
{
    private int $position = 0;

    private function __construct(private array $tokens, private bool $ansiQuotes)
    {
    }

    public static function matches(string $clause, string $column, bool $nullable, bool $ansiQuotes = false): bool
    {
        // Native catalogues may add parentheses/identifier quoting. Neither
        // permits another predicate, function, comparison, comment or literal.
        if (strlen($clause) > 4096) {
            return false;
        }
        $tokens = [];
        $identifier = self::identifierPattern($ansiQuotes);
        $offset = 0;
        while ($offset < strlen($clause)) {
            if (!preg_match('/\G\s*(' . $identifier . '|[01]|[(),])\s*/A', $clause, $match, 0, $offset)) {
                return false;
            }
            $tokens[] = strtolower($match[1]);
            $offset += strlen($match[0]);
            if (count($tokens) > 256) {
                return false;
            }
        }
        try {
            $parser = new self($tokens, $ansiQuotes);
            $actual = $parser->expression();
            if ($parser->position !== count($tokens)) {
                return false;
            }
        } catch (\UnexpectedValueException) {
            return false;
        }
        $column = strtolower($column);
        return $actual === [$nullable ? 'or' : 'and', [$nullable ? 'null' : 'not_null', $column], ['in', $column]];
    }

    /** Double quotes identify columns only in the observed native catalogue mode. */
    private static function identifierPattern(bool $ansiQuotes): string
    {
        return '(?:`(?:[^`]|``)+`|[a-zA-Z_][a-zA-Z_0-9]*' . ($ansiQuotes ? '|"(?:[^"]|"")+"' : '') . ')';
    }

    private function expression(): array
    {
        $value = $this->conjunction();
        while ($this->take('or')) {
            $value = ['or', $value, $this->conjunction()];
        }
        return $value;
    }

    private function conjunction(): array
    {
        $value = $this->atom();
        while ($this->take('and')) {
            $value = ['and', $value, $this->atom()];
        }
        return $value;
    }

    private function atom(): array
    {
        if ($this->take('(')) {
            $value = $this->expression();
            $this->expect(')');
            return $value;
        }
        $column = $this->tokens[$this->position++] ?? throw new \UnexpectedValueException();
        if (!preg_match('/^' . self::identifierPattern($this->ansiQuotes) . '$/D', $column)) {
            throw new \UnexpectedValueException();
        }
        if ($column[0] === '`' || ($this->ansiQuotes && $column[0] === '"')) {
            $quote = $column[0];
            $column = str_replace($quote . $quote, $quote, substr($column, 1, -1));
        }
        if ($this->take('is')) {
            $not = $this->take('not');
            $this->expect('null');
            return [$not ? 'not_null' : 'null', $column];
        }
        $this->expect('in');
        $this->expect('(');
        $this->expect('0');
        $this->expect(',');
        $this->expect('1');
        $this->expect(')');
        return ['in', $column];
    }

    private function take(string $token): bool
    {
        if (($this->tokens[$this->position] ?? null) !== $token) {
            return false;
        }
        ++$this->position;
        return true;
    }

    private function expect(string $token): void
    {
        if (!$this->take($token)) {
            throw new \UnexpectedValueException();
        }
    }
}
