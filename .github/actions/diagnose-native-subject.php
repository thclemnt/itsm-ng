<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Failed CI only: native metadata, never application rows or schema repairs.
$stage = 'autoload';
$failed = false;
$result = ['connection_scope' => 'fresh diagnostic connections, not the earlier failing connection'];
try {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    // Actual failed CHECK owners, plus the previous unaffected control table.
    $tables = ['glpi_domains', 'glpi_reservationitems', 'glpi_items_devicebatteries', 'glpi_certificates_items'];
    foreach (['itsm_test_application', 'itsm_test_ci_migration'] as $database) {
        $stage = $database . ': connection';
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1',
            'port' => (int)getenv('ITSM_DIAGNOSTIC_DB_PORT'), 'dbname' => $database,
            'user' => 'test', 'password' => 'test', 'charset' => 'utf8mb4']);
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->beginTransaction();
            $version = $connection->fetchOne('SELECT VERSION()');
            $maria = str_contains(strtolower($version), 'mariadb');
            $entry = ['server_version' => $version, 'session' => $connection->fetchAssociative(
                'SELECT CONNECTION_ID() AS connection_id, CURRENT_USER() AS authenticated_account, DATABASE() AS current_database, '
                . '@@SESSION.sql_mode AS sql_mode, @@GLOBAL.table_definition_cache AS table_definition_cache'
            )];
            // Keep partial diagnostics when one native read fails, without exposing connection details.
            $capture = static function (callable $read) use (&$failed): mixed {
                try {
                    return $read();
                } catch (Throwable $error) {
                    $failed = true;
                    return ['error_type' => get_class($error), 'error_code' => $error->getCode()];
                }
            };
            $entry['bulk_checks_before'] = $capture(static fn (): array => \itsmng\Database\BooleanDomainSchema::catalog($connection)['checks']);
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
            $owner = new \itsmng\Database\BaselineSchema();
            $owner->build($connection->getDatabasePlatform());
            $policies = $owner->subjectPolicies();
            foreach ($tables as $table) {
                $flags = [];
                foreach (\itsmng\Database\EntityRegistry::booleanFields($table) as $column => $nullable) {
                    $flags[$column] = ['constraint' => \itsmng\Database\BooleanDomainSchema::name($table, $column), 'nullable' => $nullable];
                }
                $query = $maria
                    ? 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME'
                    : 'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
                        . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                        . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK' ORDER BY tc.CONSTRAINT_NAME";
                $entry['tables'][$table] = ['expected_subjects' => $policies[$table] ?? [], 'expected_booleans' => $flags,
                    'show_create' => $capture(static fn (): array|false => $connection->fetchAssociative('SHOW CREATE TABLE ' . $connection->getDatabasePlatform()->quoteIdentifier($table))),
                    'selected_checks' => $capture(static fn (): array => $connection->fetchAllAssociative($query, [$table])),
                    'columns' => $capture(static fn (): array => $connection->fetchAllAssociative(
                        'SELECT COLUMN_NAME, DATA_TYPE, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME '
                        . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('items_id', 'itemtype') ORDER BY COLUMN_NAME",
                        [$table]
                    ))];
            }
            $entry['bulk_checks_after'] = $capture(static fn (): array => \itsmng\Database\BooleanDomainSchema::catalog($connection)['checks']);
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
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (strlen($json) > 2097152) {
        throw new LengthException('Native CHECK diagnostic exceeds its metadata bound');
    }
    echo $json, "\n";
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    echo json_encode(['stage' => $stage, 'error_type' => get_class($error)], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
