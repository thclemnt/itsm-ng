<?php

// SPDX-License-Identifier: GPL-2.0-or-later

require dirname(__DIR__, 2) . '/src/Database/JsonCheckExpression.php';

use itsmng\Database\JsonCheckExpression;

$assertions = 0;
foreach ([false, true] as $ansi) {
    foreach (['json_valid(`payload`)', 'JSON_VALID(`payload`)', '(json_valid(`payload`))', ' ( ( json_valid( `payload` ) ) ) '] as $clause) {
        if (JsonCheckExpression::column($clause, $ansi) !== 'payload') {
            throw new RuntimeException('Exact native identifier grammar refused');
        }
        ++$assertions;
    }
    foreach (["json_valid('payload')", "json_valid('\"payload\"')", 'json_valid(0)', 'json_valid(payload) OR 1=1', 'NOT json_valid(payload)',
        'json_valid(payload, other)', 'json_valid(table.payload)', 'json_valid(payload) /* comment */', '(json_valid(payload)', 'json_valid(payload))',
        'json_valid((payload))', 'json_valid()', 'json_valid("")', 'json_valid(``)'] as $clause) {
        if (JsonCheckExpression::column($clause, $ansi) !== null) {
            throw new RuntimeException('Literal, compound or malformed CHECK accepted as a JSON declaration');
        }
        ++$assertions;
    }
    foreach (['payload', 'true', 'false', 'null', 'current_date', 'current_time', 'current_timestamp', 'current_user', 'localtime', 'localtimestamp', 'utc_date', 'utc_time', 'utc_timestamp', 'session_user', 'system_user'] as $argument) {
        if (JsonCheckExpression::column('json_valid(' . $argument . ')', $ansi) !== null
            || JsonCheckExpression::column('json_valid(`' . $argument . '`)', $ansi) !== $argument) {
            throw new RuntimeException('Bare argument accepted or actual quoted column name lost');
        }
        $assertions += 2;
    }
}
foreach (['json_valid("payload")' => 'payload', 'json_valid("pay""load")' => 'pay"load'] as $clause => $expected) {
    if (JsonCheckExpression::column($clause, true) !== $expected || JsonCheckExpression::column($clause, false) !== null) {
        throw new RuntimeException('ANSI quote semantics not preserved');
    }
    $assertions += 2;
}
if (JsonCheckExpression::column('json_valid(`pay``load`)', false) !== 'pay`load' || JsonCheckExpression::column(str_repeat(' ', 4096) . 'json_valid(payload)', true) !== null) {
    throw new RuntimeException('Escaped delimiter or bounded grammar failed');
}
$assertions += 2;
echo "pure: $assertions MariaDB JSON alias grammar assertions passed without connections\n";
