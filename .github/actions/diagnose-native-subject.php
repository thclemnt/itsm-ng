<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Failed CI only: declarations and selected native catalog metadata, never application rows.
$stage = 'autoload';
try {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $stage = 'connection';
    $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1',
        'port' => (int)getenv('ITSM_DIAGNOSTIC_DB_PORT'), 'dbname' => 'itsm_test_application',
        'user' => 'test', 'password' => 'test', 'charset' => 'utf8mb4']);
    try {
        $connection->executeStatement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();
        $version = $connection->fetchOne('SELECT VERSION()');
        $maria = str_contains(strtolower($version), 'mariadb');
        $stage = 'expected declaration';
        $owner = new \itsmng\Database\BaselineSchema();
        $owner->build($connection->getDatabasePlatform());
        $table = 'glpi_certificates_items';
        $policy = $owner->subjectPolicies()[$table]['items_id'];
        $stage = 'native catalog';
        $columns = $connection->fetchAllAssociative('SELECT COLUMN_NAME, DATA_TYPE, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME '
            . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('items_id', 'itemtype') ORDER BY COLUMN_NAME", [$table]);
        $query = $maria
            ? 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
            : 'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
                . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.CONSTRAINT_NAME = ?";
        $checks = $connection->fetchAllAssociative($query, [$table, $policy['constraint']]);
        $result = ['server_version' => $version, 'sql_mode' => $connection->fetchOne('SELECT @@SESSION.sql_mode'),
            'check_constraint_checks' => $maria ? $connection->fetchOne('SELECT @@SESSION.check_constraint_checks') : null,
            'table' => $table, 'expected' => $policy, 'columns' => $columns,
            'checks' => $checks];
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) {
            throw new LengthException('Native subject diagnostic exceeds its bound');
        }
    } finally {
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } finally {
            $connection->close();
        }
    }
    echo $json, "\n";
} catch (Throwable $error) {
    echo json_encode(['stage' => $stage, 'error_type' => get_class($error)], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
