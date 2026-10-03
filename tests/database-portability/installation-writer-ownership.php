<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Glpi\Console\Database\InstallCommand;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\SchemaCheck;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/installation-writer-ownership.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}

/** Exercise the real execution boundary, without a second console bootstrap. */
final class InstallationWriterOwnershipCommand extends InstallCommand
{
    public bool $abortConfirmation = false;

    public function invoke(array $options = []): array
    {
        $definition = clone $this->getDefinition();
        $definition->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE));
        $input = new ArrayInput($options + ['--no-interaction' => true], $definition);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        return [parent::execute($input, $output), $output->fetch()];
    }

    protected function askForDbConfigConfirmation(InputInterface $input, OutputInterface $output, $host, $name, $user)
    {
        return $this->abortConfirmation ? false : parent::askForDbConfigConfirmation($input, $output, $host, $name, $user);
    }
}

$writer = $DB;
verify($writer instanceof DBAdapter && $writer->connected && !$writer->isSlave()
    && str_starts_with($writer->dbdefault, 'itsm_port_'), 'Dedicated connected configured writer required');
$connection = $writer->getDoctrineConnection();
verify(!$connection->isTransactionActive() && $connection->getTransactionNestingLevel() === 0, 'Own an idle complete installation');
verify(History::pendingVersions($connection) === [] && !History::isInstalling($connection)
    && (new SchemaCheck())->differences($connection) === [], 'Complete canonical schema required; never install or force-reset shared fixture');
$configurationBefore = file_get_contents(GLPI_CONFIG_DIR . '/config_db.php');
$ledgerBefore = Ledger::states($connection);
$quote = $connection->quoteIdentifier(...);
$configRowsBefore = $connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_configs') . ' ORDER BY ' . $quote('id'));
$physicalId = static fn (): int => (int)$connection->fetchOne($writer->getProvider() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
$physicalBefore = $physicalId();
$nativeSessionBefore = $writer->getProvider() === 'mysql'
    ? $connection->fetchAssociative('SELECT @@SESSION.character_set_connection AS charset, @@SESSION.time_zone AS timezone, @@SESSION.sql_mode AS modes') : null;
$hostBefore = $writer->dbhost;
$passwordBefore = $writer->dbpassword;
$sessionBefore = $_SESSION;
$configBefore = $CFG_GLPI;
$command = new InstallationWriterOwnershipCommand();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
try {
    [$status] = $command->invoke();
    verify($status === InstallCommand::ERROR_DB_ALREADY_CONTAINS_TABLES, 'Actual command preserves completed-install refusal');

    // These public settings are only for a later explicit reconnect. The already
    // connected writer still owns its original physical endpoint and credentials.
    // A reconstructed factory would try the forbidden endpoint rather than use it.
    $writer->dbpassword = rawurlencode("not a reconnect password %2F + ' \\ Ω");
    foreach (['127.0.0.1:1', ['127.0.0.1:1']] as $unusableEndpoint) {
        $writer->dbhost = $unusableEndpoint;
        [$status] = $command->invoke();
        verify($status === InstallCommand::ERROR_DB_ALREADY_CONTAINS_TABLES, 'Existing-config installation uses supplied writer rather than reconstructed transport');
        verify(
            $DB === $writer && $writer->getDoctrineConnection() === $connection && $physicalId() === $physicalBefore,
            'Same adapter and physical connection remain owned and open'
        );
    }
    $writer->dbhost = $hostBefore;
    $writer->dbpassword = $passwordBefore;

    $disconnected = clone $writer;
    $disconnected->connected = false;
    $replica = clone $writer;
    $replica->slave = true;
    foreach ([null, $disconnected, $replica] as $unusableWriter) {
        $DB = $unusableWriter;
        [$status, $output] = $command->invoke();
        verify($status === InstallCommand::ERROR_DB_CONNECTION_FAILED
            && str_contains($output, 'connected configured write adapter'), 'Absent, disconnected or read adapter refuses before catalogue and installation');
        verify($writer->getDoctrineConnection() === $connection && $physicalId() === $physicalBefore, 'Refusal never closes the supplied physical connection');
    }
    $DB = $writer;
    [$status] = $command->invoke(['--db-name' => $writer->dbdefault]);
    verify($status === InstallCommand::ERROR_DB_CONFIG_ALREADY_SET, 'Explicit input still requires reconfigure before overriding existing configuration');
    $command->abortConfirmation = true;
    [$status] = $command->invoke();
    verify($status === 0, 'Existing-config confirmation abort retains successful abort status');
} finally {
    restore_error_handler();
    $writer->dbhost = $hostBefore;
    $writer->dbpassword = $passwordBefore;
    $DB = $writer;
    $_SESSION = $sessionBefore;
    $CFG_GLPI = $configBefore;
}
verify(file_get_contents(GLPI_CONFIG_DIR . '/config_db.php') === $configurationBefore, 'Configuration serialization is untouched');
verify(
    Ledger::states($connection) === $ledgerBefore && $connection->fetchAllAssociative('SELECT * FROM ' . $quote('glpi_configs') . ' ORDER BY ' . $quote('id')) === $configRowsBefore,
    'All real refusals and abort preserve history and stored release/configuration rows'
);
verify($physicalId() === $physicalBefore && $writer->getDoctrineConnection() === $connection
    && !$connection->isTransactionActive() && $connection->getTransactionNestingLevel() === 0, 'Connection ownership and caller idleness retained');
verify(
    $nativeSessionBefore === null || $connection->fetchAssociative('SELECT @@SESSION.character_set_connection AS charset, @@SESSION.time_zone AS timezone, @@SESSION.sql_mode AS modes') === $nativeSessionBefore,
    'Configured MySQL charset, timezone and strict-session modes survive all command checks'
);
verify((new SchemaCheck())->differences($connection) === [], 'Read-only command checks preserve canonical schema');
echo $writer->getProvider() . ': ' . $assertions . " configured installation writer ownership assertions passed.\n";
