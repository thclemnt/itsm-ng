<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Lexical call discovery; uncertain receivers require semantic review, not automatic conversion. */
final class SqlCallInventory
{
    private const METHODS = [
        'request', 'query', 'queryordie', 'insert', 'insertordie', 'update', 'updateordie',
        'delete', 'deleteordie', 'prepare', 'executeprepared', 'runfile', 'buildinsert',
        'buildupdate', 'builddelete', 'doquery', 'executequery', 'executestatement',
    ];

    public static function scan(string $source, string $path): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool =>
            !$token->isIgnorable()));
        $calls = [];
        $compatibility = in_array($path, [
            'inc/dbadapter.class.php', 'inc/dbmysql.class.php', 'inc/dbpgsql.class.php',
            'src/Database/LegacyStatement.php', 'src/Database/PostgresStatement.php',
        ], true);
        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            if (($tokens[$index + 1]->text ?? '') !== '(' && $previous?->id !== T_NEW) {
                continue;
            }
            $name = strtolower(ltrim($token->text, '\\'));
            $category = null;
            $receiver = '';
            if ($previous?->id === T_NEW) {
                if (in_array($name, ['mysqli', 'pdo', 'sqlite3'], true)) {
                    $category = 'direct_driver';
                } elseif (in_array($name, ['db', 'dbmysql', 'dbpgsql', 'dbadapter'], true)) {
                    $category = 'adapter_construction';
                }
            } elseif ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $receiverToken = $tokens[$index - 2] ?? null;
                $receiver = $receiverToken?->text ?? '';
                if ($token->id === T_VARIABLE) {
                    $category = $receiver === '$DB' ? 'legacy_dynamic' : 'dynamic_candidate';
                } elseif (in_array($name, self::METHODS, true)) {
                    if (in_array(strtolower(ltrim($receiver, '\\')), ['mysqli', 'pdo', 'sqlite3'], true)) {
                        $category = 'direct_driver';
                    } elseif ($receiver === '$DB' || in_array(strtolower(ltrim($receiver, '\\')), ['dbmysql', 'dbpgsql', 'dbadapter'], true)) {
                        $category = 'legacy_adapter';
                    } elseif ($compatibility && ($receiver === '$this' || $receiver === 'db')) {
                        $category = 'adapter_internal';
                    } else {
                        // Includes alternate DB variables, Doctrine calls and model CRUD.
                        $category = 'method_candidate';
                    }
                }
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
                && !$previous?->is([T_FUNCTION, T_FN])
                && !(($previous?->text === '&') && ($tokens[$index - 2]->id ?? null) === T_FUNCTION)
                && preg_match('/^(?:mysqli|mysql|pg|sqlite)_[a-z0-9_]+$/D', $name)) {
                $category = 'direct_driver';
            }
            if ($category !== null) {
                $calls[] = [
                    'path' => $path, 'line' => $token->line, 'offset' => $token->pos,
                    'category' => $category, 'receiver' => $receiver, 'method' => $token->text,
                ];
            }
        }
        return $calls;
    }

    public static function discover(string $root): array
    {
        $calls = [];
        foreach (['inc', 'src', 'front', 'ajax', 'install'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    array_push($calls, ...self::scan(file_get_contents($file->getPathname()), substr($file->getPathname(), strlen($root) + 1)));
                }
            }
        }
        usort($calls, static fn (array $a, array $b): int => [$a['path'], $a['offset']] <=> [$b['path'], $b['offset']]);
        return $calls;
    }
}
