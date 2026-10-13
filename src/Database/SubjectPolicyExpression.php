<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use UnexpectedValueException;

/** Recognize the finite native subject-policy grammar, without executing catalog SQL. */
final class SubjectPolicyExpression
{
    private int $position = 0;
    private int $depth = 0;

    private function __construct(private array $tokens, private bool $postgres, private array $integerDiscriminators, private array $integerTypes, private array $stringSelections, private array $integerPairs, private array $booleanColumns)
    {
    }

    /** $verifiedCheck must independently match an enforced, validated native CHECK. */
    public static function equivalent(string $expected, string $actual, bool $postgres, bool $ansiQuotes = false, ?string $verifiedCheck = null, array $integerDiscriminators = [], array $integerTypes = [], array $stringSelections = [], array $integerPairs = [], array $booleanColumns = []): bool
    {
        try {
            $left = self::parse($expected, $postgres, $ansiQuotes, integerDiscriminators: $integerDiscriminators, integerTypes: $integerTypes, stringSelections: $stringSelections, integerPairs: $integerPairs, booleanColumns: $booleanColumns);
            $right = self::parse($actual, $postgres, $ansiQuotes, true, $integerDiscriminators, $integerTypes, $stringSelections, $integerPairs, $booleanColumns);
            if ($verifiedCheck !== null) {
                $check = self::parse($verifiedCheck, $postgres, $ansiQuotes, integerDiscriminators: $integerDiscriminators, integerTypes: $integerTypes, stringSelections: $stringSelections, integerPairs: $integerPairs, booleanColumns: $booleanColumns);
                $left = self::guardedCoalesce($left, $check, $postgres);
                $right = self::guardedCoalesce($right, $check, $postgres);
            }
            return $left === $right;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    private static function parse(string $sql, bool $postgres, bool $ansiQuotes, bool $nativeCatalog = false, array $integerDiscriminators = [], array $integerTypes = [], array $stringSelections = [], array $integerPairs = [], array $booleanColumns = []): array
    {
        if (strlen($sql) > 262144) {
            throw new UnexpectedValueException();
        }
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($sql)) {
            if (preg_match('/\G\s+/', $sql, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                continue;
            }
            // MySQL INFORMATION_SCHEMA escapes the outer literal delimiters
            // (MySQL bugs #104294/#100607). Decode only that catalog token;
            // embedded quotes/backslashes remain outside this finite grammar.
            if ($nativeCatalog && !$postgres && substr($sql, $offset, 2) === "\\'") {
                $end = strpos($sql, "\\'", $offset + 2);
                $literal = $end === false ? null : substr($sql, $offset + 2, $end - $offset - 2);
                if ($literal === null || !preg_match('/\A[\x20-\x26\x28-\x5b\x5d-\x7e]*\z/D', $literal)) {
                    throw new UnexpectedValueException();
                }
                $match = ["'" . $literal . "'"];
                $offset += 2; // The two catalog-only backslashes.
            } elseif (!preg_match('/\G(?:\'(?:[^\'\\\\]|\'\')*\'|`(?:[^`]|``)+`|"(?:[^"]|"")+"|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|::|>=|<>|[=>(),\[\]])/A', $sql, $match, 0, $offset)) {
                throw new UnexpectedValueException();
            }
            $token = $match[0];
            $offset += strlen($token);
            if ($token[0] === "'") {
                $tokens[] = ['string', str_replace("''", "'", substr($token, 1, -1))];
            } elseif ($token[0] === '`' || $token[0] === '"') {
                if ($token[0] === '"' && !$postgres && !$ansiQuotes) {
                    throw new UnexpectedValueException();
                }
                $tokens[] = ['identifier', str_replace($token[0] . $token[0], $token[0], substr($token, 1, -1))];
            } else {
                $tokens[] = strtolower($token);
            }
            if (count($tokens) > 32768) {
                throw new UnexpectedValueException();
            }
        }
        $parser = new self($tokens, $postgres, $integerDiscriminators, $integerTypes, $stringSelections, $integerPairs, $booleanColumns);
        $result = $parser->expression();
        if ($parser->position !== count($tokens)) {
            throw new UnexpectedValueException();
        }
        return self::canonical($result, $postgres);
    }

    /** Distribute the finite subject branches and absorb redundant native null guards. */
    private static function canonical(array $node, bool $postgres): array
    {
        if ($node[0] === 'coalesce') {
            return ['coalesce', self::canonical($node[1], $postgres), self::canonical($node[2], $postgres)];
        }
        if ($node[0] === 'case') {
            return self::canonicalCase($node, $postgres);
        }
        if (!in_array($node[0], ['and', 'or'], true)) {
            return $node;
        }
        $terms = $node[0] === 'and' ? [[]] : [];
        foreach ($node[1] as $child) {
            $child = self::canonical($child, $postgres);
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
                            throw new UnexpectedValueException();
                        }
                    }
                }
            }
            $terms = $node[0] === 'or' ? [...$terms, ...$next] : $next;
            if (count($terms) > 256) {
                throw new UnexpectedValueException();
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

    /** Reorder only disjoint literal choices of one exact discriminator. */
    private static function canonicalCase(array $node, bool $postgres): array
    {
        $branches = [];
        $discriminator = null;
        $seen = [];
        foreach ($node[1] as [$condition, $value]) {
            $choices = $condition[0] === 'or' ? $condition[1] : [$condition];
            foreach ($choices as $choice) {
                if ($choice[0] !== '=' || $choice[2][0] !== 'string'
                    || !(($postgres && $choice[1][0] === 'identifier')
                        || ($choice[1][0] === 'binary' && $choice[1][1][0] === 'identifier'))) {
                    return $node;
                }
                $key = json_encode($choice[2], JSON_THROW_ON_ERROR);
                if (($discriminator !== null && $discriminator !== $choice[1]) || isset($seen[$key])) {
                    // Overlapping branches retain their first-match ordering.
                    return $node;
                }
                $discriminator = $choice[1];
                $seen[$key] = true;
                $branches[$key] = [$choice, self::canonical($value, $postgres)];
            }
        }
        ksort($branches);
        return ['case', array_values($branches), self::canonical($node[2], $postgres)];
    }

    /** Native stock COALESCE is equivalent only on rows admitted by its verified CHECK. */
    private static function guardedCoalesce(array $node, array $check, bool $postgres): array
    {
        if ($node[0] !== 'coalesce' || $node[1][0] !== 'case'
            || $node[1][2][0] !== 'literal_null' || $node[2][0] !== 'integer') {
            return $node;
        }
        $terms = $check[0] === 'or' ? $check[1] : [$check];
        foreach ($node[1][1] as [$condition, $value]) {
            if ($condition[0] !== '=' || $condition[2][0] !== 'string' || $value[0] !== 'identifier'
                || !(($postgres && $condition[1][0] === 'identifier')
                    || ($condition[1][0] === 'binary' && $condition[1][1][0] === 'identifier'))) {
                return $node;
            }
            $subject = $condition[1][0] === 'binary' ? $condition[1][1] : $condition[1];
            foreach ($terms as $term) {
                $proved = false;
                foreach ($term[0] === 'and' ? $term[1] : [$term] as $atom) {
                    if ($atom === ['not_null', $value]
                        || $atom === ['null', $subject]
                        || ($atom[0] === '=' && $atom[1] === $condition[1]
                            && $atom[2][0] === 'string' && $atom[2] !== $condition[2])) {
                        // Either this CHECK alternative requires the result nonnull,
                        // or it is false for this selected discriminator literal.
                        $proved = true;
                        break;
                    }
                }
                if (!$proved) {
                    return $node;
                }
            }
        }
        return ['case', $node[1][1], $node[2]];
    }

    private function expression(): array
    {
        if (++$this->depth > 128) {
            throw new UnexpectedValueException();
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
        // MySQL may deparse numeric NOT IN as NOT (IN). Negation remains limited
        // to a finite declared integer discriminator selection, never arbitrary SQL.
        if ($this->take('not')) {
            $selection = $this->value();
            if ($this->isDeclaredBoolean($selection)) {
                return ['=', $selection, ['integer', '0']];
            }
            $choices = $selection[0] === 'or' ? $selection[1] : [$selection];
            $negative = [];
            foreach ($choices as $choice) {
                if ($choice[0] !== '=' || !(($this->isIntegerDiscriminator($choice[1]) && $choice[2][0] === 'integer')
                    || $this->isStringChoice($choice[1], $choice[2]))) {
                    throw new UnexpectedValueException();
                }
                $negative[] = ['<>', $choice[1], $choice[2]];
            }
            return $this->junction('and', $negative);
        }
        $left = $this->value();
        if ($this->take('is')) {
            $not = $this->take('not');
            $this->expect('null');
            return [$not ? 'not_null' : 'null', $left[0] === 'binary' ? $left[1] : $left];
        }
        $not = $this->take('not');
        if ($this->take('in')) {
            if ($not && !$this->isIntegerDiscriminator($left) && !$this->isStringSelection($left)) {
                throw new UnexpectedValueException();
            }
            $this->expect('(');
            $choices = [];
            do {
                $right = $this->value();
                if ($not && !(($this->isIntegerDiscriminator($left) && $right[0] === 'integer') || $this->isStringChoice($left, $right))) {
                    throw new UnexpectedValueException();
                }
                $choices[] = [$not ? '<>' : '=', $left, $right];
            } while ($this->take(','));
            $this->expect(')');
            return $this->junction($not ? 'and' : 'or', $choices);
        }
        if ($not) {
            throw new UnexpectedValueException();
        }
        foreach (['=', '>=', '>', '<>'] as $operator) {
            if (!$this->take($operator)) {
                continue;
            }
            if (($operator === '=' && $this->take('any')) || ($operator === '<>' && $this->take('all'))) {
                if (!$this->postgres || (!$this->isIntegerDiscriminator($left) && !$this->isStringSelection($left))) {
                    throw new UnexpectedValueException();
                }
                if ($this->isStringSelection($left)) {
                    return $this->stringArrayComparison($left, $operator);
                }
                $this->expect('(');
                $this->expect('array');
                $this->expect('[');
                $choices = [];
                do {
                    $right = $this->value();
                    if (!(($this->isIntegerDiscriminator($left) && $right[0] === 'integer') || $this->isStringChoice($left, $right))
                        || count($choices) >= 256) {
                        throw new UnexpectedValueException();
                    }
                    $choices[] = [$operator, $left, $right];
                } while ($this->take(','));
                $this->expect(']');
                if ($this->take('::')) {
                    if (!$this->isStringSelection($left)) {
                        throw new UnexpectedValueException();
                    }
                    $this->expect('text');
                    $this->expect('[');
                    $this->expect(']');
                }
                $this->expect(')');
                return $this->junction($operator === '=' ? 'or' : 'and', $choices);
            }
            $right = $this->value();
            // MySQL 8.4 deparses NOT flag as literal zero on the left.
            // Reorder only false equality for this policy's declared boolean.
            if ($operator === '=' && $this->isDeclaredBoolean($right)
                && !$this->postgres && $left === ['integer', '0']) {
                return ['=', $right, ['integer', '0']];
            }
            if ($operator === '=' && $this->isDeclaredBoolean($left)) {
                if ($right[0] === 'boolean') {
                    $right = ['integer', $right[1] ? '1' : '0'];
                } elseif ($this->postgres || $right[0] !== 'integer' || !in_array($right[1], ['0', '1'], true)) {
                    throw new UnexpectedValueException();
                }
                return ['=', $left, $right];
            }
            if ($operator === '<>' && $this->isDeclaredIntegerPair($left, $right)) {
                $pair = [$left, $right];
                usort($pair, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
                return ['<>', ...$pair];
            }
            if ($operator === '<>' && !(($this->isIntegerDiscriminator($left) && $right[0] === 'integer') || $this->isStringChoice($left, $right))) {
                throw new UnexpectedValueException();
            }
            return [$operator, $left, $right];
        }
        return $left;
    }

    /** Only this policy's explicit nonnullable BOOL may use finite false forms. */
    private function isDeclaredBoolean(array $value): bool
    {
        return $value[0] === 'identifier' && in_array($value[1], $this->booleanColumns, true);
    }

    /** Identifier inequality is authorized only for the owning root/parent pair. */
    private function isDeclaredIntegerPair(array $left, array $right): bool
    {
        if ($left[0] !== 'identifier' || $right[0] !== 'identifier' || $left[1] === $right[1]) {
            return false;
        }
        foreach ($this->integerPairs as $pair) {
            if (count($pair) === 2 && in_array($left[1], $pair, true) && in_array($right[1], $pair, true)
                && isset($this->integerTypes[$left[1]], $this->integerTypes[$right[1]])) {
                return true;
            }
        }
        return false;
    }

    private function isIntegerDiscriminator(array $value): bool
    {
        return $value[0] === 'identifier' && in_array($value[1], $this->integerDiscriminators, true);
    }

    /** Only current property policy can authorize string-mode operators/array choices. */
    private function isStringSelection(array $value): bool
    {
        // MySQL exact discriminator comparison wraps only this declared field in BINARY.
        $selected = $value[0] === 'binary' ? $value[1] : $value;
        return $selected[0] === 'identifier' && isset($this->stringSelections[$selected[1]]);
    }

    private function isStringChoice(array $left, array $right): bool
    {
        return $this->isStringSelection($left) && $right[0] === 'string'
            && in_array($right[1], $this->stringSelections[($left[0] === 'binary' ? $left[1] : $left)[1]], true);
    }

    /** The varchar-array renderer is permitted only for declared mode literal choices. */
    private function stringArrayComparison(array $left, string $operator): array
    {
        $this->expect('(');
        $wrapped = $this->take('(');
        $this->expect('array');
        $this->expect('[');
        $choices = [];
        $varchar = false;
        do {
            $literal = $this->tokens[$this->position] ?? null;
            $cast = $this->tokens[$this->position + 2] ?? null;
            if ($literal === '(' && is_array($this->tokens[$this->position + 1] ?? null)
                && $this->tokens[$this->position + 1][0] === 'string'
                && ($this->tokens[$this->position + 2] ?? null) === '::'
                && in_array($this->tokens[$this->position + 3] ?? null, ['character', 'varchar'], true)) {
                // ALTER TYPE reparses the original binary array coercion as
                // individually relabeled literals. Admit only this complete
                // unnarrowed varchar literal -> text shape in ARRAY context.
                $this->expect('(');
                $right = $this->tokens[$this->position++];
                $this->expect('::');
                if ($this->take('character')) {
                    $this->expect('varying');
                } else {
                    $this->expect('varchar');
                }
                $this->expect(')');
                $this->expect('::');
                $this->expect('text');
            } elseif (is_array($literal) && $literal[0] === 'string'
                && ($this->tokens[$this->position + 1] ?? null) === '::'
                && in_array($cast, ['character', 'varchar'], true)) {
                $right = $literal;
                $this->position += 3;
                if ($cast === 'character') {
                    $this->expect('varying');
                }
                $varchar = true;
                // A following typmod, function, operator or additional cast is
                // not a delimiter and therefore cannot be silently discarded.
            } else {
                // Retain the existing harmless text-literal forms unchanged.
                $right = $this->value();
            }
            if (!$this->isStringChoice($left, $right) || count($choices) >= 256) {
                throw new UnexpectedValueException();
            }
            $choices[] = [$operator, $left, $right];
        } while ($this->take(','));
        $this->expect(']');
        if ($wrapped) {
            $this->expect(')');
        }
        $textArray = $this->take('::');
        if ($textArray) {
            $this->expect('text');
            $this->expect('[');
            $this->expect(']');
        }
        if ($varchar && (!$wrapped || !$textArray)) {
            throw new UnexpectedValueException();
        }
        $this->expect(')');
        return $this->junction($operator === '=' ? 'or' : 'and', $choices);
    }

    private function value(): array
    {
        if (++$this->depth > 128) {
            throw new UnexpectedValueException();
        }
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
                throw new UnexpectedValueException();
            }
            $this->expect('else');
            $fallback = $this->value();
            $this->expect('end');
            $value = ['case', $branches, $fallback];
        } elseif ($this->take('coalesce')) {
            $this->expect('(');
            $source = $this->value();
            $this->expect(',');
            $fallback = $this->value();
            $this->expect(')');
            $value = ['coalesce', $source, $fallback];
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
            $token = $this->tokens[$this->position++] ?? throw new UnexpectedValueException();
            if (is_array($token)) {
                $value = $token;
            } elseif ($token === 'null') {
                $value = ['literal_null'];
            } elseif (in_array($token, ['false', 'true'], true)) {
                // Only unquoted SQL keywords are literals; quoted names remain identifiers.
                $value = ['boolean', $token === 'true'];
            } elseif (ctype_digit($token)) {
                $value = ['integer', $token];
            } elseif (preg_match('/^_(?:utf8|utf8mb3|utf8mb4|ascii)$/D', $token)) {
                $value = $this->tokens[$this->position++] ?? throw new UnexpectedValueException();
                if (!is_array($value) || $value[0] !== 'string' || preg_match('/[^\x20-\x7e]/', $value[1])) {
                    throw new UnexpectedValueException();
                }
            } elseif (preg_match('/^[a-z_][a-z_0-9]*$/D', $token)) {
                $value = ['identifier', $token];
            } else {
                throw new UnexpectedValueException();
            }
        }
        while ($this->take('::')) {
            if (!$this->postgres) {
                throw new UnexpectedValueException();
            }
            // The deparser adds widening casts to native varchar/text and bigint
            // constants. Length-limited casts, functions and collations are refused.
            if ($this->take('text')) {
                if (!in_array($value[0], ['identifier', 'string'], true)) {
                    throw new UnexpectedValueException();
                }
            } elseif (($cast = $this->take('bigint') ? 'bigint' : ($this->take('integer') ? 'integer' : null)) !== null) {
                if (!in_array($value[0], ['literal_null', 'integer'], true)) {
                    $source = $value[0] === 'identifier' ? ($this->integerTypes[$value[1]] ?? null) : null;
                    if (!in_array($source, ['smallint', 'integer', 'bigint'], true)
                        || ($cast === 'integer' && $source === 'bigint')) {
                        throw new UnexpectedValueException();
                    }
                }
            } else {
                throw new UnexpectedValueException();
            }
        }
        --$this->depth;
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
            throw new UnexpectedValueException();
        }
    }
}
