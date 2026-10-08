<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Failed CI only: native metadata, never application rows or schema repairs.

use Doctrine\DBAL\DriverManager;
use itsmng\Database\BaselineSchema;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\EntityRegistry;

$stage = 'autoload';
$failed = false;
$result = ['connection_scope' => 'fresh diagnostic connections, not the earlier failing connection'];
// Archive oversized metadata without changing its native reads or discarding observations.
$render = static function (array $result) use (&$failed): string {
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (strlen($json) <= 2097152) {
        return $json;
    }
    $archive = [
        'file' => 'native-subject-details.json.gz',
        'encoding' => 'gzip-compressed UTF-8 JSON; complete unabridged metadata',
        'uncompressed_bytes' => strlen($json),
        'uncompressed_sha256' => hash('sha256', $json),
        'complete' => false,
    ];
    try {
        $compressed = gzencode($json);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress diagnostic metadata');
        }
        $archive['compressed_bytes'] = strlen($compressed);
        $archive['compressed_sha256'] = hash('sha256', $compressed);
        $directory = getenv('RUNNER_TEMP') ?: sys_get_temp_dir();
        $handle = fopen($directory . '/' . $archive['file'], 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create diagnostic artifact');
        }
        try {
            if (fwrite($handle, $compressed) !== strlen($compressed)) {
                throw new RuntimeException('Incomplete diagnostic artifact');
            }
        } finally {
            if (!fclose($handle)) {
                throw new RuntimeException('Diagnostic artifact close failed');
            }
        }
        $archive['complete'] = true;
    } catch (Throwable $error) {
        $failed = true;
        $archive['error_type'] = get_class($error);
        $archive['error_code'] = $error->getCode();
    }
    return json_encode(['connection_scope' => $result['connection_scope'], 'archive' => $archive], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
};

try {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    // Actual failed CHECK owners, plus the previous unaffected control table.
    $tables = ['glpi_domains', 'glpi_reservationitems', 'glpi_items_devicebatteries', 'glpi_certificates_items'];
    foreach (['itsm_test_application', 'itsm_test_ci_migration'] as $database) {
        $stage = $database . ': connection';
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => (int)getenv('ITSM_DIAGNOSTIC_DB_PORT'),
            'dbname' => $database,
            'user' => 'test',
            'password' => 'test',
            'charset' => 'utf8mb4',
        ]);
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->beginTransaction();
            $version = $connection->fetchOne('SELECT VERSION()');
            $maria = str_contains(strtolower($version), 'mariadb');
            $entry = [
                'server_version' => $version,
                'session' => $connection->fetchAssociative(
                    'SELECT CONNECTION_ID() AS connection_id, CURRENT_USER() AS authenticated_account, DATABASE() AS current_database, '
                    . '@@SESSION.sql_mode AS sql_mode, @@GLOBAL.table_definition_cache AS table_definition_cache'
                ),
            ];
            // Keep partial diagnostics when one native read fails, without exposing connection details.
            $capture = static function (callable $read) use (&$failed): mixed {
                try {
                    return $read();
                } catch (Throwable $error) {
                    $failed = true;
                    return ['error_type' => get_class($error), 'error_code' => $error->getCode()];
                }
            };
            $entry['bulk_checks_before'] = $capture(static fn (): array => BooleanDomainSchema::catalog($connection)['checks']);
            // These independent native reads do not share the application's catalog JOIN.
            $entry['constraint_owners'] = $capture(static fn (): array => $connection->fetchAllAssociative(
                'SELECT TABLE_NAME, CONSTRAINT_NAME' . ($maria ? '' : ', ENFORCED')
                . " FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK' ORDER BY TABLE_NAME, CONSTRAINT_NAME"
            ));
            $entry['constraint_clauses'] = $capture(static fn (): array => $connection->fetchAllAssociative(
                'SELECT CONSTRAINT_NAME, CHECK_CLAUSE' . ($maria ? ', TABLE_NAME' : '')
                . ' FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME'
            ));
            $stage = $database . ': declarations and selected owners';
            $owner = new BaselineSchema();
            $owner->build($connection->getDatabasePlatform());
            $policies = $owner->subjectPolicies();
            foreach ($tables as $table) {
                $flags = [];
                foreach (EntityRegistry::booleanFields($table) as $column => $nullable) {
                    $flags[$column] = ['constraint' => BooleanDomainSchema::name($table, $column), 'nullable' => $nullable];
                }
                $query = $maria
                    ? 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME'
                    : 'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
                        . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                        . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK' ORDER BY tc.CONSTRAINT_NAME";
                $entry['tables'][$table] = [
                    'expected_subjects' => $policies[$table] ?? [],
                    'expected_booleans' => $flags,
                    'show_create' => $capture(static fn (): array|false => $connection->fetchAssociative('SHOW CREATE TABLE ' . $connection->getDatabasePlatform()->quoteIdentifier($table))),
                    'selected_checks' => $capture(static fn (): array => $connection->fetchAllAssociative($query, [$table])),
                    'columns' => $capture(static fn (): array => $connection->fetchAllAssociative(
                        'SELECT COLUMN_NAME, DATA_TYPE, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME '
                        . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('items_id', 'itemtype') ORDER BY COLUMN_NAME",
                        [$table]
                    )),
                ];
            }
            $entry['bulk_checks_after'] = $capture(static fn (): array => BooleanDomainSchema::catalog($connection)['checks']);
            $result['databases'][$database] = $entry;
        } catch (Throwable $error) {
            $failed = true;
            $result['databases'][$database] = ($entry ?? []) + ['stage' => $stage, 'error_type' => get_class($error), 'error_code' => $error->getCode()];
        } finally {
            try {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } finally {
                $connection->close();
                unset($entry);
            }
        }
    }
    echo $render($result), "\n";
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    echo json_encode(['stage' => $stage, 'error_type' => get_class($error)], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
