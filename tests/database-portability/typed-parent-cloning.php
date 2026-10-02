<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/typed-parent-cloning.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated cloning fixture required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$configuration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $user = $fixtures->create('glpi_users', ['name' => 'Clone owner ' . bin2hex(random_bytes(4))]);
    $sourceId = $fixtures->create('glpi_computers', ['name' => 'Public typed clone source', 'serial' => null, 'otherserial' => '', 'users_id' => $user]);
    $contract = $fixtures->create('glpi_contracts', ['name' => 'Cloned asset contract']);
    $limited = $fixtures->create('glpi_contracts', ['name' => 'Full contract', 'max_links_allowed' => 1]);
    foreach ([$contract, $limited] as $parent) {
        $fixtures->create('glpi_contracts_items', ['contracts_id' => $parent, 'itemtype' => 'Computer', 'items_id' => $sourceId]);
    }
    $document = $fixtures->create('glpi_documents', ['name' => 'Cloned asset attachment']);
    foreach ([0, 1] as $position) {
        $fixtures->create('glpi_documents_items', ['documents_id' => $document, 'itemtype' => 'Computer', 'items_id' => $sourceId, 'timeline_position' => $position, 'users_id' => null]);
    }
    $source = new Computer();
    verify($source->getFromDB($sourceId), 'Load source through its public model');
    $cloneId = $source->clone(['name' => "Public O'Reilly asset clone"]);
    verify(is_int($cloneId) && $cloneId > 0 && $cloneId !== $sourceId, 'Actual Computer clone succeeds');
    $clone = new Computer();
    verify($clone->getFromDB($cloneId) && $clone->fields['serial'] === null && $clone->fields['otherserial'] === '' && $clone->fields['users_id'] === $user, 'Parent clone preserves supplied NULL, empty text and unrelated ownership');
    $records = new RecordRepository(Orm::create($DB));
    $subjectRows = static fn (string $table, int $item): array => $records->matching($table, ['itemtype' => 'Computer', 'items_id' => $item], ['id ASC']);
    $verifyOwnership = static function (string $table, array $rows, int $item): void {
        $branches = EntityRegistry::discriminatedReferences($table)['items_id']['selections'];
        foreach ($rows as $row) {
            verify($row['items_id'] === $item && $row[$branches['Computer']['column']] === $item, 'Generated projection and owning clone target agree: ' . $table);
            foreach ($branches as $kind => $selection) {
                if ($kind !== 'Computer') {
                    verify($row[$selection['column']] === null, 'Unselected clone subject remains NULL: ' . $table . '/' . $kind);
                }
            }
        }
    };
    $contractRows = $subjectRows('glpi_contracts_items', $cloneId);
    verify(count($contractRows) === 1 && $contractRows[0]['contracts_id'] === $contract, 'Parent clone keeps the contract container and invokes its link-limit guard');
    $verifyOwnership('glpi_contracts_items', $contractRows, $cloneId);
    $documentRows = $subjectRows('glpi_documents_items', $cloneId);
    verify(count($documentRows) === 2 && array_column($documentRows, 'timeline_position') === [0, 1] && array_column($documentRows, 'documents_id') === [$document, $document], 'Parent clone preserves each attachment binding and timeline role');
    verify(array_column($documentRows, 'users_id') === [Session::getLoginUserID(), Session::getLoginUserID()], 'Document add lifecycle attributes cloned attachments to the current actor');
    $verifyOwnership('glpi_documents_items', $documentRows, $cloneId);
    verify(count($subjectRows('glpi_contracts_items', $sourceId)) === 2 && count($subjectRows('glpi_documents_items', $sourceId)) === 2, 'Original associations remain intact');
    verify($records->countMatching('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $cloneId]) > 0, 'Actual parent and relation lifecycle retain audit history');

    $contractModel = new Contract();
    verify($contractModel->getFromDB($contract), 'Load contract container');
    $contractClone = $contractModel->clone(['name' => 'Cloned contract container']);
    $containerRows = $records->matching('glpi_contracts_items', ['contracts_id' => $contractClone], ['id ASC']);
    verify($contractClone > 0 && array_column($containerRows, 'items_id') === [$sourceId, $cloneId], 'Container clone changes only its own end and retains both asset subjects');
    foreach ($containerRows as $row) {
        $verifyOwnership('glpi_contracts_items', [$row], $row['items_id']);
    }

    $target = $fixtures->create('glpi_computers', ['name' => 'Deprecated clone target']);
    $deprecations = 0;
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        if ($level === E_USER_DEPRECATED && $message === 'Use clone') {
            ++$deprecations;
            return true;
        }
        return false;
    });
    try {
        Contract::cloneItem('Computer', $sourceId, $target);
    } finally {
        restore_error_handler();
    }
    $deprecatedRows = $subjectRows('glpi_contracts_items', $target);
    verify($deprecations === 1 && count($deprecatedRows) === 2 && array_column($deprecatedRows, 'contracts_id') === [$contract, $contractClone], 'Deprecated public clone retains one notice, all eligible contracts and the link-limit guard');
    $verifyOwnership('glpi_contracts_items', $deprecatedRows, $target);
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'Cloning leaves no orphaned relationships');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $configuration;
}
echo $DB->getProvider() . ": public parent/container/deprecated cloning preserves typed owning subjects, nullable data, attachment multiplicity, lifecycle attribution, limits and audit history.\n";
