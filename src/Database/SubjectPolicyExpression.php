<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Recognize the finite native subject-policy grammar, without executing catalog SQL. */
final class SubjectPolicyExpression
{
    private int $position = 0;
    private int $depth = 0;

    private function __construct(private array $tokens, private bool $postgres)
    {
    }

    public static function equivalent(string $expected, string $actual, bool $postgres, bool $ansiQuotes = false): bool
    {
        try {
            return self::parse($expected, $postgres, $ansiQuotes) === self::parse($actual, $postgres, $ansiQuotes);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    private static function parse(string $sql, bool $postgres, bool $ansiQuotes): array
    {
        if (strlen($sql) > 262144) {
            throw new \UnexpectedValueException();
        }
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($sql)) {
            if (preg_match('/\G\s+/', $sql, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                continue;
            }
            if (!preg_match('/\G(?:\'(?:[^\'\\\\]|\'\')*\'|`(?:[^`]|``)+`|"(?:[^"]|"")+"|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|::|>=|[=(),])/A', $sql, $match, 0, $offset)) {
                throw new \UnexpectedValueException();
            }
            $token = $match[0];
            $offset += strlen($token);
            if ($token[0] === "'") {
                $tokens[] = ['string', str_replace("''", "'", substr($token, 1, -1))];
            } elseif ($token[0] === '`' || $token[0] === '"') {
                if ($token[0] === '"' && !$postgres && !$ansiQuotes) {
                    throw new \UnexpectedValueException();
                }
                $tokens[] = ['identifier', str_replace($token[0] . $token[0], $token[0], substr($token, 1, -1))];
            } else {
                $tokens[] = strtolower($token);
            }
            if (count($tokens) > 32768) {
                throw new \UnexpectedValueException();
            }
        }
        $parser = new self($tokens, $postgres);
        $result = $parser->expression();
        if ($parser->position !== count($tokens)) {
            throw new \UnexpectedValueException();
        }
        return self::canonical($result);
    }

    /** Distribute the finite subject branches and absorb redundant native null guards. */
    private static function canonical(array $node): array
    {
        if (!in_array($node[0], ['and', 'or'], true)) {
            return $node;
        }
        $terms = $node[0] === 'and' ? [[]] : [];
        foreach ($node[1] as $child) {
            $child = self::canonical($child);
            $alternatives = $child[0] === 'or' ? $child[1] : [$child];
            $next = [];
            foreach ($alternatives as $alternative) {
                $atoms = $alternative[0] === 'and' ? $alternative[1] : [$alternative];
                if ($node[0] === 'or') {
                    $next[] = $atoms;
                } else {
                    foreach ($terms as $term) {
                        $next[] = [...$term, ...$atoms];
                        if (count($next) > 256) {
                            throw new \UnexpectedValueException();
                        }
                    }
                }
            }
            $terms = $node[0] === 'or' ? [...$terms, ...$next] : $next;
            if (count($terms) > 256) {
                throw new \UnexpectedValueException();
            }
        }
        $sets = [];
        foreach ($terms as $term) {
            $set = [];
            foreach ($term as $atom) {
                $set[json_encode($atom, JSON_THROW_ON_ERROR)] = $atom;
            }
            ksort($set);
            $sets[json_encode(array_keys($set), JSON_THROW_ON_ERROR)] = $set;
        }
        foreach ($sets as $key => $set) {
            foreach ($sets as $otherKey => $other) {
                if ($key !== $otherKey && count($other) < count($set) && !array_diff_key($other, $set)) {
                    unset($sets[$key]);
                    break;
                }
            }
        }
        ksort($sets);
        $values = array_map(static function (array $set): array {
            $atoms = array_values($set);
            return count($atoms) === 1 ? $atoms[0] : ['and', $atoms];
        }, array_values($sets));
        return count($values) === 1 ? $values[0] : ['or', $values];
    }

    private function expression(): array
    {
        if (++$this->depth > 128) {
            throw new \UnexpectedValueException();
        }
        $values = [$this->conjunction()];
        while ($this->take('or')) {
            $values[] = $this->conjunction();
        }
        --$this->depth;
        return $this->junction('or', $values);
    }

    private function conjunction(): array
    {
        $values = [$this->comparison()];
        while ($this->take('and')) {
            $values[] = $this->comparison();
        }
        return $this->junction('and', $values);
    }

    private function junction(string $kind, array $values): array
    {
        $flat = [];
        foreach ($values as $value) {
            array_push($flat, ...($value[0] === $kind ? $value[1] : [$value]));
        }
        if (count($flat) === 1) {
            return $flat[0];
        }
        usort($flat, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        return [$kind, $flat];
    }

    private function comparison(): array
    {
        $left = $this->value();
        if ($this->take('is')) {
            $not = $this->take('not');
            $this->expect('null');
            return [$not ? 'not_null' : 'null', $left[0] === 'binary' ? $left[1] : $left];
        }
        if ($this->take('in')) {
            $this->expect('(');
            $choices = [];
            do {
                $choices[] = ['=', $left, $this->value()];
            } while ($this->take(','));
            $this->expect(')');
            return $this->junction('or', $choices);
        }
        foreach (['=', '>='] as $operator) {
            if ($this->take($operator)) {
                return [$operator, $left, $this->value()];
            }
        }
        return $left;
    }

    private function value(): array
    {
        if ($this->take('(')) {
            $value = $this->expression();
            $this->expect(')');
        } elseif ($this->take('case')) {
            $subject = ($this->tokens[$this->position] ?? null) === 'when' ? null : $this->value();
            $branches = [];
            while ($this->take('when')) {
                $condition = $this->expression();
                $this->expect('then');
                $branches[] = [$subject === null ? $condition : ['=', $subject, $condition], $this->value()];
            }
            if (!$branches) {
                throw new \UnexpectedValueException();
            }
            $this->expect('else');
            $fallback = $this->value();
            $this->expect('end');
            $value = ['case', $branches, $fallback];
        } elseif ($this->take('cast')) {
            $this->expect('(');
            $source = $this->value();
            $this->expect('as');
            if ($this->take('binary')) {
                $value = ['binary', $source];
            } else {
                $this->expect('char');
                $this->expect('charset');
                $this->expect('binary');
                $value = ['binary', $source];
            }
            $this->expect(')');
        } else {
            $token = $this->tokens[$this->position++] ?? throw new \UnexpectedValueException();
            if (is_array($token)) {
                $value = $token;
            } elseif ($token === 'null') {
                $value = ['literal_null'];
            } elseif (ctype_digit($token)) {
                $value = ['integer', $token];
            } elseif (preg_match('/^_(?:utf8|utf8mb3|utf8mb4|ascii)$/D', $token)) {
                $value = $this->tokens[$this->position++] ?? throw new \UnexpectedValueException();
                if (!is_array($value) || $value[0] !== 'string' || preg_match('/[^\x20-\x7e]/', $value[1])) {
                    throw new \UnexpectedValueException();
                }
            } elseif (preg_match('/^[a-z_][a-z_0-9]*$/D', $token)) {
                $value = ['identifier', $token];
            } else {
                throw new \UnexpectedValueException();
            }
        }
        while ($this->take('::')) {
            if (!$this->postgres) {
                throw new \UnexpectedValueException();
            }
            // The deparser adds widening casts to native varchar/text and bigint
            // constants. Length-limited casts, functions and collations are refused.
            if ($this->take('text')) {
                if (!in_array($value[0], ['identifier', 'string'], true)) {
                    throw new \UnexpectedValueException();
                }
            } elseif ($this->take('bigint') || $this->take('integer')) {
                if (!in_array($value[0], ['literal_null', 'integer'], true)) {
                    throw new \UnexpectedValueException();
                }
            } else {
                throw new \UnexpectedValueException();
            }
        }
        return $value;
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
