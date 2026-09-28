<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Compile every displayed/sorted core column on the active provider. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/search-columns.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
class GlpitestSQLError extends RuntimeException
{
}
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Use a dedicated itsm_port_* database.');
$_SESSION['glpiextauth'] = 0;
$auth = new Auth();
verify($auth->login('itsm', 'itsm', true), 'Seeded administrator login.');

$connection = $DB->getDoctrineConnection();
$count = 0;
foreach (['Ticket', 'Computer', 'User', 'Group', 'Software', 'SoftwareLicense', 'Change', 'Problem', 'Entity'] as $type) {
    foreach (Search::getCleanedOptions($type) as $id => $option) {
        if (!is_array($option) || empty($option['field']) || str_starts_with($option['field'], '_virtual')) {
            continue;
        }
        $data = Search::prepareDatasForSearch($type, ['criteria' => [], 'sort' => $id, 'list_limit' => 1], [$id]);
        Search::constructSQL($data);
        $sql = $data['sql']['search'];
        if ($DB->getProvider() === 'pgsql') {
            $sql = \itsmng\Database\LegacySql::postgres($sql);
        }
        try {
            $connection->executeQuery('EXPLAIN ' . $sql);
        } catch (Throwable $e) {
            throw new RuntimeException("$type column $id: " . $e->getMessage(), 0, $e);
        }
        $count++;
    }
}
echo $DB->getProvider() . ": $count core search columns planned successfully.\n";
