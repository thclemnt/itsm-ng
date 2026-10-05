<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Driver\PDO\Exception as DbalPdoException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\PostgresConnection;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php postgresql-restrict-exceptions.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/based_config.php';
require GLPI_ROOT . '/inc/db.function.php';
require GLPI_CONFIG_DIR . '/config_db.php';
require __DIR__ . '/fixtures/ComponentNativeAdmission.php';
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$DB = new DB();
$connection = $DB->getDoctrineConnection();
verify(str_starts_with($DB->dbdefault, 'itsm_port_') && !$connection->isTransactionActive(), 'Actual disposable configured owner starts idle');
if ($DB->getProvider() !== 'pgsql') {
    verify(!$connection instanceof PostgresConnection, 'Other providers retain their original connection and converter boundary');
    echo "$assertions other-provider boundary assertions; PostgreSQL-specific native cases are inapplicable.\n";
    exit(0);
}
$ledgerBefore = Ledger::states($connection);
$probe = PostgresConnection::create($connection->getParams(), $connection->getConfiguration());
$frame = null;
$primary = null;
$cleanup = [];
try {
    verify(!$probe->isConnected(), 'Complete supplied transport parameters create a genuinely new lazy owner');
    $frame = OwnedMutationFrame::begin($probe);
    $suffix = bin2hex(random_bytes(8));
    $parentName = 'itsm_restrict_parent_' . $suffix;
    $childName = 'itsm_restrict_child_' . $suffix;
    $noActionName = 'itsm_no_action_child_' . $suffix;
    foreach ([$parentName, $childName, $noActionName] as $name) {
        verify($probe->fetchOne('SELECT to_regclass(?)', [$name]) === null, 'Private temporary relation name has no previous owner');
    }
    $parent = $probe->quoteIdentifier($parentName);
    $child = $probe->quoteIdentifier($childName);
    $noAction = $probe->quoteIdentifier($noActionName);
    $restrictName = 'owned_restrict_' . $suffix;
    $noActionConstraint = 'owned_no_action_' . $suffix;
    $probe->executeStatement('CREATE TEMPORARY TABLE ' . $parent . ' (id BIGINT PRIMARY KEY) ON COMMIT DROP');
    $probe->executeStatement('CREATE TEMPORARY TABLE ' . $child . ' (id BIGINT PRIMARY KEY, parent_id BIGINT NOT NULL, CONSTRAINT '
        . $probe->quoteIdentifier($restrictName) . ' FOREIGN KEY (parent_id) REFERENCES ' . $parent . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT) ON COMMIT DROP');
    $probe->executeStatement('CREATE TEMPORARY TABLE ' . $noAction . ' (id BIGINT PRIMARY KEY, parent_id BIGINT NOT NULL, CONSTRAINT '
        . $probe->quoteIdentifier($noActionConstraint) . ' FOREIGN KEY (parent_id) REFERENCES ' . $parent . ' (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE) ON COMMIT DROP');
    $probe->insert($parent, ['id' => 1]);
    $probe->insert($child, ['id' => 1, 'parent_id' => 1]);
    $read = static fn (): array => [
        $probe->fetchAllAssociative('SELECT * FROM ' . $parent . ' ORDER BY id'),
        $probe->fetchAllAssociative('SELECT * FROM ' . $child . ' ORDER BY id'),
        $probe->fetchAllAssociative('SELECT * FROM ' . $noAction . ' ORDER BY id'),
    ];
    $before = $read();
    $restrictState = (int)$probe->fetchOne("SELECT current_setting('server_version_num')") >= 180000 ? '23001' : '23503';
    foreach ([
        ['DELETE FROM ' . $parent . ' WHERE id = ?', [1]],
        ['UPDATE ' . $parent . ' SET id = ? WHERE id = ?', [2, 1]],
    ] as [$sql, $params]) {
        foreach ([false, true] as $legacy) {
            $error = null;
            $nested = OwnedMutationFrame::begin($probe);
            $refusalPrimary = $refusalCleanup = null;
            try {
                try {
                    if ($legacy) {
                        $probe->executeLegacyQuery($sql, $params)->free();
                    } else {
                        $probe->executeStatement($sql, $params);
                    }
                } catch (ForeignKeyConstraintViolationException $caught) {
                    $error = $caught;
                }
                verify($error !== null && $error->getSQLState() === $restrictState && $error->getCode() === 7,
                    'Actual DBAL and legacy bound RESTRICT operations retain server-native state, code and FK abstraction');
                $driver = $error->getPrevious();
                $pdo = $driver instanceof DbalPdoException ? $driver->getPrevious() : null;
                $information = $pdo instanceof \PDOException ? $pdo->errorInfo : null;
                verify(is_array($information) && count($information) === 3
                    && $information[0] === $restrictState && $information[1] === 7 && is_string($information[2]),
                    'Converted refusal retains the actual PDO diagnostic chain');
                $native = preg_replace('/^ERROR:\\s+/', '', explode("\n", $information[2], 2)[0]);
                verify(ComponentNativeAdmission::matchesPostgresParentForeign($error, $native, $childName, $restrictName, $parentName),
                    'Native refusal names the selected private parent, child and RESTRICT constraint');
            } catch (Throwable $error) {
                $refusalPrimary = $error;
            } finally {
                try {
                    $nested->rollBack();
                } catch (Throwable $error) {
                    $refusalCleanup = $error;
                }
            }
            if ($refusalPrimary !== null) {
                throw $refusalCleanup === null ? $refusalPrimary
                    : new itsmng\Database\MutationCleanupFailure($refusalPrimary, $refusalCleanup, true);
            }
            if ($refusalCleanup !== null) {
                throw $refusalCleanup;
            }
            verify($read() === $before, 'Refused native parent write leaves every private row unchanged after owned savepoint rollback');
        }
    }
    ComponentNativeAdmission::reject($probe, fn () => $probe->insert($child, ['id' => 2, 'parent_id' => 2]), $childName, 'foreign', $restrictName);
    ComponentNativeAdmission::reject($probe, fn () => $probe->update($child, ['parent_id' => 2], ['id' => 1]), $childName, 'foreign', $restrictName);
    verify($read() === $before, 'Child-side missing-target insert/update retain their existing 23503 cause and all rows');
    OwnedMutationFrame::run($probe, static function () use ($probe, $child, $noAction, $parentName, $noActionName, $noActionConstraint): void {
        $probe->delete($child, ['id' => 1]);
        $probe->insert($noAction, ['id' => 1, 'parent_id' => 1]);
        $noActionError = null;
        ComponentNativeAdmission::reject($probe, static function () use ($probe, $parentName, &$noActionError): void {
            try {
                $probe->delete($parentName, ['id' => 1]);
            } catch (ForeignKeyConstraintViolationException $error) {
                $noActionError = $error;
                throw $error;
            }
        }, $noActionName, 'parent-foreign', $noActionConstraint, $parentName);
        verify($noActionError?->getSQLState() === '23503', 'Actual NO ACTION remains 23503 on both PostgreSQL generations');
    });
    $probe->delete($noAction, ['id' => 1]);
    $probe->insert($child, ['id' => 1, 'parent_id' => 1]);
    verify($read() === $before, 'NO ACTION positive fixture and refusal preserve the original data after explicit private reset');
    $positive = OwnedMutationFrame::begin($probe);
    $positivePrimary = $positiveCleanup = null;
    try {
        verify($probe->delete($child, ['id' => 1]) === 1 && $probe->delete($parent, ['id' => 1]) === 1,
            'Deleting the owning child allows the real parent deletion');
    } catch (Throwable $error) {
        $positivePrimary = $error;
    } finally {
        try {
            $positive->rollBack();
        } catch (Throwable $error) {
            $positiveCleanup = $error;
        }
    }
    if ($positivePrimary !== null) {
        throw $positiveCleanup === null ? $positivePrimary
            : new itsmng\Database\MutationCleanupFailure($positivePrimary, $positiveCleanup, true);
    }
    if ($positiveCleanup !== null) {
        throw $positiveCleanup;
    }
    verify($read() === $before, 'Successful operations still respect the caller-owned savepoint rollback');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($frame !== null) {
        try {
            $frame->rollBack();
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
    }
    try {
        $probe->close();
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
    try {
        verify(!$connection->isTransactionActive() && Ledger::states($connection) === $ledgerBefore,
            'Original configured owner remains idle and every canonical receipt is unchanged');
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    throw $cleanup === [] ? $primary : new itsmng\Database\MutationCleanupFailure($primary, $cleanup[0], true);
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo "PostgreSQL native FK exception boundary: $assertions assertions passed.\n";
