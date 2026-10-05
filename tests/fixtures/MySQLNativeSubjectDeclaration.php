<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Extract executable native declarations; never decode metadata expression literals. */
final class MySQLNativeSubjectDeclaration
{
    public static function capture(string $create, string $table, array $columns, string $constraint, bool $backslashEscapes, bool $ansiQuotes = false): array
    {
        if (!str_starts_with($create, 'CREATE TABLE ') || $columns === [] || count(array_unique($columns)) !== count($columns)) {
            throw new LogicException('An exact native CREATE TABLE and unique selected columns are required.');
        }
        $offset = strlen('CREATE TABLE ');
        if (self::identifier($create, $offset, $ansiQuotes) !== $table) {
            throw new LogicException('Native declaration belongs to another table.');
        }
        self::spaces($create, $offset);
        if (($create[$offset] ?? null) !== '(') {
            throw new LogicException('Native table declaration has no column body.');
        }
        $start = ++$offset;
        $depth = 1;
        $parts = [];
        $length = strlen($create);
        for (; $offset < $length; ++$offset) {
            $char = $create[$offset];
            if (in_array($char, ["'", '"', '`'], true)) {
                self::quoted($create, $offset, $backslashEscapes && $char !== '`' && !($ansiQuotes && $char === '"'));
            } elseif ($char === '/' && ($create[$offset + 1] ?? null) === '*') {
                $end = strpos($create, '*/', $offset + 2);
                if ($end === false) {
                    throw new LogicException('Unclosed native declaration comment.');
                }
                $offset = $end + 1;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                if (--$depth === 0) {
                    $parts[] = trim(substr($create, $start, $offset - $start));
                    break;
                }
            } elseif ($char === ',' && $depth === 1) {
                $parts[] = trim(substr($create, $start, $offset - $start));
                $start = $offset + 1;
            } elseif ($char === ';' || $char === "\0") {
                throw new LogicException('Unexpected native declaration statement boundary.');
            }
        }
        if ($depth !== 0 || in_array('', $parts, true)) {
            throw new LogicException('Incomplete native table declaration.');
        }
        $selected = [];
        $check = null;
        foreach ($parts as $part) {
            $cursor = 0;
            if (in_array($part[0], ['`', '"'], true)) {
                $name = self::identifier($part, $cursor, $ansiQuotes);
                if (!in_array($name, $columns, true)) {
                    continue;
                }
                if (isset($selected[$name])) {
                    throw new LogicException('Duplicate selected native column declaration.');
                }
                self::spaces($part, $cursor);
                $selected[$name] = substr($part, $cursor);
                if (!self::storedColumn($selected[$name], $backslashEscapes, $ansiQuotes)) {
                    throw new LogicException('Selected native subject column is not stored generated.');
                }
            } elseif (str_starts_with($part, 'CONSTRAINT ')) {
                $cursor = strlen('CONSTRAINT ');
                $name = self::identifier($part, $cursor, $ansiQuotes);
                if ($name !== $constraint) {
                    continue;
                }
                self::spaces($part, $cursor);
                if ($check !== null || preg_match('/\ACHECK\s*\(/', substr($part, $cursor)) !== 1
                    || preg_match('/(?:\bNOT ENFORCED|\/\*!\d+\s+NOT ENFORCED\s+\*\/)\s*\z/', $part) === 1) {
                    throw new LogicException('Selected native CHECK is missing or ambiguous.');
                }
                $check = $part;
            }
        }
        if (count($selected) !== count($columns) || $check === null) {
            throw new LogicException('Native CREATE TABLE does not contain every selected owner.');
        }
        return ['columns' => $selected, 'check' => $check];
    }

    private static function spaces(string $sql, int &$offset): void
    {
        while (isset($sql[$offset]) && ctype_space($sql[$offset])) {
            ++$offset;
        }
    }

    private static function identifier(string $sql, int &$offset, bool $ansiQuotes): string
    {
        self::spaces($sql, $offset);
        $quote = $sql[$offset] ?? '';
        if ($quote !== '`' && !($ansiQuotes && $quote === '"')) {
            throw new LogicException('A native quoted owner identifier is required.');
        }
        $name = '';
        for (++$offset; isset($sql[$offset]); ++$offset) {
            $char = $sql[$offset];
            if ($char === $quote) {
                if (($sql[$offset + 1] ?? null) === $quote) {
                    $name .= $quote;
                    ++$offset;
                } else {
                    ++$offset;
                    return $name;
                }
            } else {
                $name .= $char;
            }
        }
        throw new LogicException('Unclosed native owner identifier.');
    }

    private static function storedColumn(string $sql, bool $backslashEscapes, bool $ansiQuotes): bool
    {
        // Only words outside literals, identifiers and nested expressions are
        // declaration keywords. A comment/literal saying STORED cannot admit VIRTUAL.
        $words = [];
        $depth = 0;
        $word = '';
        for ($offset = 0; $offset < strlen($sql); ++$offset) {
            $char = $sql[$offset];
            if (in_array($char, ["'", '"', '`'], true)) {
                self::quoted($sql, $offset, $backslashEscapes && $char !== '`' && !($ansiQuotes && $char === '"'));
            } elseif ($char === '/' && ($sql[$offset + 1] ?? null) === '*') {
                $end = strpos($sql, '*/', $offset + 2);
                if ($end === false) {
                    return false;
                }
                $offset = $end + 1;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($depth === 0 && ctype_alpha($char)) {
                $word .= strtoupper($char);
                continue;
            }
            if ($word !== '') {
                $words[] = $word;
                $word = '';
            }
        }
        if ($word !== '') {
            $words[] = $word;
        }
        return str_contains(' ' . implode(' ', $words) . ' ', ' GENERATED ALWAYS AS STORED ');
    }

    /** Advance over one literal/identifier while retaining every original byte. */
    private static function quoted(string $sql, int &$offset, bool $backslashEscapes): void
    {
        $quote = $sql[$offset];
        for (++$offset; isset($sql[$offset]); ++$offset) {
            if ($backslashEscapes && $sql[$offset] === '\\') {
                if (!isset($sql[++$offset])) {
                    break;
                }
            } elseif ($sql[$offset] === $quote) {
                if (($sql[$offset + 1] ?? null) === $quote) {
                    ++$offset;
                } else {
                    return;
                }
            }
        }
        throw new LogicException('Unclosed native declaration literal or identifier.');
    }
}
