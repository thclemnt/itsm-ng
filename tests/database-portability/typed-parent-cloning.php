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
    // Direct callers supply partial legacy or canonical proposals, unlike Clonable's
    // already-normalized relation overrides. Exercise the common public boundary.
    $direct = new Document_Item();
    $directSource = $subjectRows('glpi_documents_items', $sourceId)[0];
    verify($direct->getFromDB($directSource['id']), 'Load direct converted relation clone source');
    $legacyClone = $direct->clone(\itsmng\Database\Entity\DocumentItem::withReference([], 'Computer', $target));
    $legacyRows = $subjectRows('glpi_documents_items', $target);
    verify(is_int($legacyClone) && $legacyClone > 0 && count($legacyRows) === 1, 'Partial legacy clone replaces the copied owning identity');
    $verifyOwnership('glpi_documents_items', $legacyRows, $target);
    $typedTarget = $fixtures->create('glpi_computers', ['name' => 'Canonical direct clone target']);
    $canonicalClone = $direct->clone(['computers_id' => $typedTarget, 'timeline_position' => 7]);
    $canonicalRows = $subjectRows('glpi_documents_items', $typedTarget);
    verify(is_int($canonicalClone) && $canonicalClone > 0 && count($canonicalRows) === 1 && $canonicalRows[0]['timeline_position'] === 7, 'Canonical-only override retains the current kind, replaces its identity and preserves scalar overrides');
    $verifyOwnership('glpi_documents_items', $canonicalRows, $typedTarget);
    $crossTarget = $fixtures->create('glpi_monitors', ['name' => 'Cross-kind direct clone target']);
    $crossClone = $direct->clone(\itsmng\Database\Entity\DocumentItem::withReference([], 'Monitor', $crossTarget));
    $crossRow = $records->matching('glpi_documents_items', ['id' => $crossClone])[0] ?? null;
    $crossColumn = EntityRegistry::discriminatedReferences('glpi_documents_items')['items_id']['selections']['Monitor']['column'];
    verify(is_int($crossClone) && $crossClone > 0 && $crossRow[$crossColumn] === $crossTarget && $crossRow['items_id'] === $crossTarget && $crossRow['computers_id'] === null, 'Legacy cross-kind clone replaces and clears the copied subject association');
    $scalarClone = $direct->clone(['timeline_position' => 8]);
    $scalarRow = $records->matching('glpi_documents_items', ['id' => $scalarClone])[0] ?? null;
    verify(is_int($scalarClone) && $scalarClone > 0 && $scalarRow['computers_id'] === $sourceId && $scalarRow['items_id'] === $sourceId && $scalarRow['timeline_position'] === 8, 'Scalar-only direct clone retains its canonical relationship and regenerates the legacy identity');
    $beforeRefusal = $records->countMatching('glpi_documents_items', ['documents_id' => $document]);
    verify($direct->clone(['itemtype' => 'Computer', 'items_id' => $target, 'computers_id' => $sourceId]) === false, 'Conflicting caller-supplied legacy and canonical clone identities are refused');
    verify($direct->clone(['computers_id' => null]) === false, 'A required selected clone identity cannot be explicitly NULL');
    verify($records->countMatching('glpi_documents_items', ['documents_id' => $document]) === $beforeRefusal, 'Rejected partial clone proposals insert no relation');
    $unchangedSource = new Document_Item();
    verify($unchangedSource->getFromDB($directSource['id']) && $unchangedSource->fields === $direct->fields, 'Direct cloning and rejection preserve the source model and database tuple');

    $nullableSource = $fixtures->create('glpi_computers', ['name' => 'Clone nullable source', 'serial' => 'Retained source serial']);
    $nullable = new Computer();
    verify($nullable->getFromDB($nullableSource), 'Load nonnull source for nullable override');
    $nullClone = $nullable->clone(['serial' => null]);
    $absentClone = $nullable->clone(['name' => 'Absent serial clone']);
    $nullRow = $records->matching('glpi_computers', ['id' => $nullClone])[0] ?? null;
    $absentRow = $records->matching('glpi_computers', ['id' => $absentClone])[0] ?? null;
    verify(is_int($nullClone) && $nullClone > 0 && $nullRow['serial'] === null, 'Supplied nullable scalar clone override remains NULL');
    verify(is_int($absentClone) && $absentClone > 0 && $absentRow['serial'] === 'Retained source serial', 'Absent nullable scalar override retains the source value');

    // Other converted identities use entity-owned fallback and empty policies too.
    $authCopy = \itsmng\Database\CloneInput::merge('glpi_users', [
        'authtype' => Auth::LDAP, 'auths_id' => 9, 'authldaps_id' => 9,
        'authmails_id' => null, 'auth_source_code' => null, 'comment' => 'Original',
    ], ['authldaps_id' => 10, 'comment' => null]);
    verify($authCopy['auths_id'] === 10 && $authCopy['authldaps_id'] === 10 && $authCopy['authmails_id'] === null && $authCopy['comment'] === null, 'Canonical authentication clone override uses its current discriminator and preserves explicit scalar NULL');
    $nullServer = \itsmng\Database\CloneInput::merge('glpi_users', $authCopy, ['authldaps_id' => null]);
    $absentServer = \itsmng\Database\CloneInput::merge('glpi_users', $authCopy, ['comment' => 'Scalar only']);
    verify($nullServer['authldaps_id'] === null && $nullServer['auths_id'] === 0 && $absentServer['authldaps_id'] === 10 && $absentServer['auths_id'] === 10, 'Nullable canonical override distinguishes supplied NULL from an absent relationship key');
    $localCopy = \itsmng\Database\CloneInput::merge('glpi_users', $authCopy, ['authtype' => Auth::DB_GLPI, 'auth_source_code' => 0]);
    verify($localCopy['auths_id'] === 0 && $localCopy['authldaps_id'] === null && $localCopy['authmails_id'] === null, 'Fallback authentication clone clears copied server ownership and projects the actual local code');
    $stockCopy = \itsmng\Database\CloneInput::merge('glpi_items_deviceprocessors', [
        'itemtype' => 'Computer', 'items_id' => 9, 'computers_id' => 9,
        'entities_id' => 0, 'deviceprocessors_id' => 1,
    ], ['itemtype' => null, 'items_id' => 0]);
    verify($stockCopy['itemtype'] === null && $stockCopy['computers_id'] === null && $stockCopy['items_id'] === 0 && $stockCopy['entities_id'] === 0, 'Entity-owned optional stock clone normalizes explicit NULL kind and owning columns');
    verify((new ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'Cloning leaves no orphaned relationships');
} finally {
    $DB->rollBack();
    $CFG_GLPI = $configuration;
}
echo $DB->getProvider() . ": public parent/container/deprecated cloning preserves typed owning subjects, nullable data, attachment multiplicity, lifecycle attribution, limits and audit history.\n";
