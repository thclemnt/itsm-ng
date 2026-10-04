<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Actual configured transport and lexical regions; no application bootstrap or DDL. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php postgresql-parameter-lexing.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/based_config.php';
require GLPI_ROOT . '/inc/db.function.php';
require GLPI_CONFIG_DIR . '/config_db.php';
$DB = null;
$connection = null;
$scope = null;
$primary = null;
$cleanup = [];
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
try {
    $DB = new DB();
    $DB->assertManagedTransaction();
    $connection = $DB->getDoctrineConnection();
    verify($connection->getTransactionNestingLevel() === 0, 'New configured transport owner starts idle');
    $postgres = $DB->getProvider() === 'pgsql';
    if (!$postgres) {
        $connection->executeStatement('SET TRANSACTION READ ONLY');
    }
    $connection->beginTransaction();
    $scope = $DB->captureManagedTransactionScope();
    if ($postgres) {
        $connection->executeStatement('SET TRANSACTION READ ONLY');
    }
    $ledger = \itsmng\Database\Migration\Ledger::states($connection);
    verify($connection->fetchOne('SELECT ? AS bound', ['actual bound value']) === 'actual bound value', 'Normal supplied DBAL binding succeeds');
    if ($postgres) {
        verify($connection->fetchOne("SELECT current_setting('standard_conforming_strings')") === 'on', 'Actual owned PostgreSQL standard string mode is explicit');
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT ?::text AS bound
/* outer ? :ignored $1
   /* second :ignored ? /* third $2 ? :ignored */ still second */
   still outer ? :ignored */
SQL, ['three levels']);
        verify($row === ['bound' => 'three levels'], 'Ordinary DBAL binds across actual three-level PostgreSQL comments');
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT :actual::text AS bound /* outer /* inner ? */ ? :ignored */
SQL, ['actual' => 'named value']);
        verify($row === ['bound' => 'named value'], 'Real named PDO binding ignores positional bytes inside nested comments');
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT ?::jsonb ?? 'present' AS value /* outer /* inner */ ? :ignored */
SQL, ['{"present":false}']);
        verify($row === ['value' => true], 'Real PostgreSQL question-mark operator survives nested-comment preparation');
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT 'path\tail'::text AS plain, E'path\\tail'::text AS escaped,
       N'hello'::text AS national, B'101'::text AS bits,
       X'FA'::text AS hexadecimal, U&'d\0061t'::text AS unicode,
       ?::text AS "quoted ? :ignored" /* outer /* inner */ ? :ignored */
SQL, ['after prefixes']);
        verify($row === ['plain' => 'path\\tail', 'escaped' => 'path\\tail',
            'national' => 'hello', 'bits' => '101', 'hexadecimal' => '11111010',
            'unicode' => 'dat', 'quoted ? :ignored' => 'after prefixes'], 'Actual prefixed literals and quoted identifiers retain their distinct PostgreSQL semantics');
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT 'left'
/* outer /* inner */
   ? :ignored */
'right'::text AS combined, ?::text AS bound
SQL, ['after concatenation']);
        verify($row === ['combined' => 'leftright', 'bound' => 'after concatenation'], 'Comment newlines preserve PostgreSQL adjacent-literal concatenation');
        $result = $DB->queryParams(<<<'SQL'
SELECT $2::text AS second, $1::text AS first, $2::text AS repeated,
       $body$ /* nested-looking */ ? :ignored ' \ $3 $body$::text AS body
       /* outer /* inner */ ? :ignored $4 */
SQL, ['first', 'second']);
        verify($result instanceof \itsmng\Database\LegacyResult, 'Actual numbered adapter returns a rowset');
        verify($DB->fetchAssoc($result) === ['second' => 'second', 'first' => 'first', 'repeated' => 'second',
            'body' => " /* nested-looking */ ? :ignored ' \\ $3 "], 'Numbered repetitions and dollar-body comment bytes survive exactly once');
        $DB->freeResult($result);
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT $body$ /* preserve /* literal */ ? :ignored */ $body$::text AS body,
       ?::text AS bound /* outer /* inner */ ? :ignored */
SQL, ['ordinary dollar']);
        verify($row === ['body' => ' /* preserve /* literal */ ? :ignored */ ', 'bound' => 'ordinary dollar'], 'Ordinary DBAL dollar body remains opaque to comment adaptation');
    }
    verify(\itsmng\Database\Migration\Ledger::states($connection) === $ledger, 'Read-only lexical contracts leave the exact migration ledger unchanged');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($scope !== null) {
        try {
            $scope->assertActive();
            verify($connection->getTransactionNestingLevel() === 1, 'Only exact owned read-only frame is rolled back');
            $connection->rollBack();
        } catch (Throwable $error) {
            if ($primary === null) {
                $primary = $error;
            } else {
                $cleanup[] = $error;
            }
        }
    }
    try {
        $DB?->close();
    } catch (Throwable $error) {
        if ($primary === null) {
            $primary = $error;
        } else {
            $cleanup[] = $error;
        }
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Secondary lexical owner cleanup: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
echo $assertions . " PostgreSQL parameter lexical assertions passed\n";
