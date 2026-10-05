<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use itsmng\Database\JsonCheckExpression as Expression;

/** Exact MariaDB JSON alias grammar needs neither application bootstrap nor a database. */
class JsonCheckExpression extends \atoum\atoum\test
{
    public function testQuotedNativeColumnGrammar(): void
    {
        foreach ([false, true] as $ansi) {
            foreach (['json_valid(`payload`)', 'JSON_VALID(`payload`)', '(json_valid(`payload`))', ' ( ( json_valid( `payload` ) ) ) '] as $clause) {
                $this->string(Expression::column($clause, $ansi))->isIdenticalTo('payload');
            }
        }
    }

    public function testLiteralsCompoundsAndMalformedChecksAreRefused(): void
    {
        foreach ([false, true] as $ansi) {
            foreach (["json_valid('payload')", "json_valid('\"payload\"')", 'json_valid(0)', 'json_valid(payload) OR 1=1', 'NOT json_valid(payload)',
                'json_valid(payload, other)', 'json_valid(table.payload)', 'json_valid(payload) /* comment */', '(json_valid(payload)', 'json_valid(payload))',
                'json_valid((payload))', 'json_valid()', 'json_valid("")', 'json_valid(``)'] as $clause) {
                $this->variable(Expression::column($clause, $ansi))->isNull($clause);
            }
        }
    }

    public function testBareArgumentsCannotImpersonateQuotedColumns(): void
    {
        foreach ([false, true] as $ansi) {
            foreach (['payload', 'true', 'false', 'null', 'current_date', 'current_time', 'current_timestamp', 'current_user', 'localtime', 'localtimestamp', 'utc_date', 'utc_time', 'utc_timestamp', 'session_user', 'system_user'] as $argument) {
                $this->variable(Expression::column('json_valid(' . $argument . ')', $ansi))->isNull();
                $this->string(Expression::column('json_valid(`' . $argument . '`)', $ansi))->isIdenticalTo($argument);
            }
        }
    }

    public function testAnsiQuotesRequireTheObservedMode(): void
    {
        foreach (['json_valid("payload")' => 'payload', 'json_valid("pay""load")' => 'pay"load'] as $clause => $expected) {
            $this->string(Expression::column($clause, true))->isIdenticalTo($expected);
            $this->variable(Expression::column($clause, false))->isNull();
        }
    }

    public function testEscapedBackticksAndBoundedGrammar(): void
    {
        $this->string(Expression::column('json_valid(`pay``load`)', false))->isIdenticalTo('pay`load');
        $this->variable(Expression::column(str_repeat(' ', 4096) . 'json_valid(payload)', true))->isNull();
    }
}
