<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/stock-concurrency.php /path/to/test-config\n");
    exit(2);
}
if (($argv[2] ?? '') === 'claim' && !defined('GLPI_CACHE_DIR')) {
    define('GLPI_CACHE_DIR', $argv[5]);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(2);
});
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Dedicated test database required');
}
if (($argv[2] ?? '') === 'claim') {
    fwrite(STDOUT, "READY\n");
    if (trim((string)fgets(STDIN)) !== 'GO') {
        exit(2);
    }
    exit((new Cartridge())->install((int)$argv[3], (int)$argv[4]) ? 0 : 1);
}

// Separate processes need committed fixtures; remove only this run's records.
$fixtures = new FixtureRecords($DB);
$storage = new \itsmng\Database\MappedStorage($DB);
$printers = [];
$model = $cartridge = null;
$workers = [];
try {
    $model = $fixtures->create('glpi_cartridgeitems', ['name' => 'Concurrent stock ' . bin2hex(random_bytes(8))]);
    $cartridge = $fixtures->create('glpi_cartridges', ['cartridgeitems_id' => $model]);
    for ($i = 0; $i < 2; ++$i) {
        $printer = $fixtures->create('glpi_printers', ['name' => 'Concurrent stock claimant']);
        $printers[] = $printer;
        $process = proc_open([PHP_BINARY, __FILE__, $directory, 'claim', (string)$printer, (string)$model, GLPI_CACHE_DIR], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start stock claimant');
        }
        $workers[] = ['process' => $process, 'pipes' => $pipes];
        stream_set_timeout($pipes[1], 15);
        if (trim((string)fgets($pipes[1])) !== 'READY') {
            throw new RuntimeException('Stock claimant failed to initialize');
        }
    }
    foreach ($workers as &$worker) {
        fwrite($worker['pipes'][0], "GO\n");
        fclose($worker['pipes'][0]);
        unset($worker['pipes'][0]);
    }
    unset($worker);
    $statuses = [];
    foreach ($workers as &$worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $errors = stream_get_contents($worker['pipes'][2]);
        foreach ($worker['pipes'] as $pipe) {
            fclose($pipe);
        }
        $worker['pipes'] = [];
        $statuses[] = proc_close($worker['process']);
        $worker['process'] = null;
        if ($output !== '' || $errors !== '') {
            throw new RuntimeException('Unexpected claimant output: ' . $output . $errors);
        }
    }
    unset($worker);
    sort($statuses);
    if ($statuses !== [0, 1]) {
        throw new RuntimeException('Exactly one concurrent stock request must succeed: ' . json_encode($statuses));
    }
    $record = new Cartridge();
    if (!$record->getFromDB($cartridge) || !in_array((int)$record->fields['printers_id'], $printers, true) || !$record->fields['date_use'] || Cartridge::getUnusedNumber($model) !== 0) {
        throw new RuntimeException('Concurrent claim did not produce exactly one installed cartridge');
    }
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            foreach ($worker['pipes'] as $pipe) {
                fclose($pipe);
            }
            proc_close($worker['process']);
        }
    }
    if ($cartridge !== null) {
        $storage->delete('glpi_cartridges', $cartridge);
    }
    if ($model !== null) {
        $storage->delete('glpi_cartridgeitems', $model);
    }
    if ($printers) {
        foreach (\itsmng\Database\MappedReads::identifiers($DB, 'glpi_logs', 'id', ['itemtype' => 'Printer', 'items_id' => $printers]) as $log) {
            $storage->delete('glpi_logs', $log);
        }
    }
    foreach ($printers as $printer) {
        $storage->delete('glpi_printers', $printer);
    }
}
echo $DB->getProvider() . ": two concurrent stock claims allocate one cartridge exactly once.\n";
