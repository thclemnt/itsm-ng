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
                    $source = file_get_contents($file->getPathname());
                    $found = self::scan($source, substr($file->getPathname(), strlen($root) + 1));
                    array_push($calls, ...self::classifyOwnedDriverBoundaries($found, $source, $file->getPathname()));
                }
            }
        }
        usort($calls, static fn (array $a, array $b): int => [$a['path'], $a['offset']] <=> [$b['path'], $b['offset']]);
        return $calls;
    }

    /** Resolve only native PDO query/prepare owned by an actual DBAL driver declaration. */
    public static function classifyOwnedDriverBoundaries(array $calls, string $source, string $file): array
    {
        if (!array_filter($calls, static fn (array $call): bool => $call['category'] === 'direct_driver'
            && in_array(strtolower($call['method']), ['prepare', 'query'], true))) {
            return $calls;
        }
        if (!is_file($file) || is_link($file) || file_get_contents($file) !== $source) {
            return $calls; // A path or supplied declaration is not ownership evidence.
        }
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool =>
            !$token->isIgnorable()));
        $native = [];
        foreach ($calls as $index => $call) {
            if ($call['category'] !== 'direct_driver' || !in_array(strtolower($call['method']), ['prepare', 'query'], true)) {
                continue;
            }
            foreach ($tokens as $position => $token) {
                if ($token->pos === $call['offset'] && ($tokens[$position - 4]->text ?? '') === '$this'
                    && ($tokens[$position - 3]->id ?? null) === T_OBJECT_OPERATOR
                    && ($tokens[$position - 2]->id ?? null) === T_STRING
                    && ($tokens[$position - 1]->id ?? null) === T_OBJECT_OPERATOR) {
                    $native[$index] = $tokens[$position - 2]->text;
                    break;
                }
            }
        }
        if (!$native) {
            return $calls; // Constructors, static native calls and free functions remain direct.
        }
        $namespace = '';
        foreach ($tokens as $position => $token) {
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($cursor = $position + 1; isset($tokens[$cursor]) && !in_array($tokens[$cursor]->text, [';', '{'], true); ++$cursor) {
                    $namespace .= $tokens[$cursor]->text;
                }
            }
            if ($token->id !== T_CLASS || ($tokens[$position - 1]->id ?? null) === T_DOUBLE_COLON
                || ($tokens[$position + 1]->id ?? null) !== T_STRING) {
                continue;
            }
            $name = ltrim($namespace . '\\' . $tokens[$position + 1]->text, '\\');
            if (!class_exists($name)) {
                continue;
            }
            $class = new ReflectionClass($name); // Declaration only; no driver/model construction.
            if (realpath((string) $class->getFileName()) !== realpath($file)
                || !$class->implementsInterface(\Doctrine\DBAL\Driver\Connection::class)) {
                continue;
            }
            $bodies = self::declaredMethodBodies($tokens, $position);
            foreach ($native as $index => $propertyName) {
                $methodName = strtolower($calls[$index]['method']);
                if (!$class->hasProperty($propertyName) || !$class->hasMethod($methodName)) {
                    continue;
                }
                $property = $class->getProperty($propertyName);
                $type = $property->getType();
                $method = $class->getMethod($methodName);
                if (!$type instanceof ReflectionNamedType || $type->getName() !== PDO::class
                    || $property->getDeclaringClass()->getName() !== $class->getName()
                    || $method->getDeclaringClass()->getName() !== $class->getName()
                    || realpath((string) $method->getFileName()) !== realpath($file)
                    || !isset($bodies[$methodName])
                    || $calls[$index]['offset'] <= $bodies[$methodName][0]
                    || $calls[$index]['offset'] >= $bodies[$methodName][1]) {
                    continue;
                }
                $calls[$index]['category'] = 'owned_driver_boundary';
                $calls[$index]['ownership'] = ['class' => $class->getName(), 'interface' => \Doctrine\DBAL\Driver\Connection::class,
                    'method' => $method->getName(), 'property' => $propertyName, 'native_type' => $type->getName()];
            }
        }
        return $calls;
    }

    /** Exact method body offsets avoid borrowing ownership from same-line declarations. */
    private static function declaredMethodBodies(array $tokens, int $class): array
    {
        $open = $class + 1;
        while (isset($tokens[$open]) && $tokens[$open]->id !== ord('{')) {
            ++$open;
        }
        $depth = 1;
        $bodies = [];
        for ($cursor = $open + 1; isset($tokens[$cursor]) && $depth > 0; ++$cursor) {
            if ($depth === 1 && $tokens[$cursor]->id === T_FUNCTION) {
                $name = $cursor + 1;
                if (($tokens[$name]->text ?? '') === '&') {
                    ++$name;
                }
                if (($tokens[$name]->id ?? null) === T_STRING) {
                    $body = $name + 1;
                    while (isset($tokens[$body]) && $tokens[$body]->id !== ord('{') && $tokens[$body]->id !== ord(';')) {
                        ++$body;
                    }
                    if (($tokens[$body]->id ?? null) === ord('{')) {
                        $end = $body + 1;
                        $bodyDepth = 1;
                        $nestedScope = false;
                        while (isset($tokens[$end]) && $bodyDepth > 0) {
                            if ($tokens[$end]->is([T_FUNCTION, T_FN])
                                || ($tokens[$end]->id === T_CLASS && ($tokens[$end - 1]->id ?? null) !== T_DOUBLE_COLON)) {
                                $nestedScope = true; // Nested declarations/closures require separate receiver analysis.
                            }
                            $bodyDepth += self::braceDelta($tokens[$end]);
                            ++$end;
                        }
                        if ($bodyDepth === 0) {
                            if (!$nestedScope) {
                                $bodies[strtolower($tokens[$name]->text)] = [$tokens[$body]->pos, $tokens[$end - 1]->pos];
                            }
                            $cursor = $end - 1;
                            continue;
                        }
                    }
                }
            }
            $depth += self::braceDelta($tokens[$cursor]);
        }
        return $bodies;
    }

    private static function braceDelta(PhpToken $token): int
    {
        if ($token->id === ord('{') || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            return 1;
        }
        return $token->id === ord('}') ? -1 : 0;
    }
}
