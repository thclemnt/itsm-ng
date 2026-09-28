<?php

// SPDX-License-Identifier: GPL-2.0-or-later

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/cleanup.php /path/to/test-config\n");
    exit(2);
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    foreach (['Ticket', 'Change', 'Problem'] as $type) {
        $templateClass = $type . 'Template';
        $template = new $templateClass();
        $id = $fixtures->create($template->getTable(), ['name' => 'Cleanup template']);
        $other = $fixtures->create($template->getTable(), ['name' => 'Unrelated template']);
        $item = new $type();
        $itemtypeNum = $item->getSearchOptionIDByField('field', 'itemtype', $item->getItemsTable());
        $itemNum = $item->getSearchOptionIDByField('field', 'items_id', $item->getItemsTable());
        verify($itemtypeNum !== false && $itemNum !== false, 'Paired search options exist for ' . $type);
        foreach (['HiddenField', 'MandatoryField', 'PredefinedField'] as $suffix) {
            $class = $templateClass . $suffix;
            $field = new $class();
            $column = $class::$items_id;
            $fixtures->create($field->getTable(), [$column => $id, 'num' => $itemtypeNum]);
            $fixtures->create($field->getTable(), [$column => $id, 'num' => $itemNum]);
            $unrelated = $fixtures->create($field->getTable(), [$column => $other, 'num' => $itemNum]);
            verify($field->deleteByCriteria([$column => $id, 'num' => $itemtypeNum], true), 'Mapped criterion deletion invokes paired-field hook');
            verify($field->find([$column => $id]) === [], 'Paired item IDs are removed for ' . $class);
            verify($field->getFromDB($unrelated), 'Other template fields are preserved');
            $fixtures->create($field->getTable(), [$column => $id, 'num' => 1]);
        }
        verify($template->delete(['id' => $id], true), 'Template purge under FK enforcement');
        foreach (['HiddenField', 'MandatoryField', 'PredefinedField'] as $suffix) {
            $class = $templateClass . $suffix;
            verify((new $class())->find([$class::$items_id => $id]) === [], 'Template purge removes ' . $class);
        }
        verify($template->getFromDB($other), 'Other template is preserved');
    }

    // Snapshot IDs before model hooks remove relationships; no full-row hydration is required.
    $ids = \itsmng\Database\MappedReads::identifiers($DB, 'glpi_profiles_users', 'id', ['users_id' => 2]);
    verify($ids !== [], 'Mapped association identifier projection');
    $native = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => 2]])), 'id');
    sort($ids);
    sort($native);
    verify($ids === $native, 'Mapped association ID selection parity');
    verify(countElementsInTable('glpi_profiles_users', ['users_id' => 2]) === count($ids), 'Mapped shared count parity');
    // History cleanup must preserve another item and another type with the same numeric ID.
    $computer = $fixtures->create('glpi_computers');
    $fixtures->create('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $computer]);
    $keep = $fixtures->create('glpi_logs', ['itemtype' => 'Monitor', 'items_id' => $computer]);
    $keepOther = $fixtures->create('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $computer + 1]);
    $item = new Computer();
    verify($item->getFromDB($computer), 'History owner');
    $item->cleanHistory();
    verify(countElementsInTable('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $computer]) === 0, 'Mapped history purge');
    verify(countElementsInTable('glpi_logs', ['id' => [$keep, $keepOther]]) === 2, 'History purge preserves unrelated owners');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    countElementsInTable('glpi_profiles_users', ['users_id' => 2]);
    \itsmng\Database\MappedReads::identifiers($DB, 'glpi_profiles_users', 'id', ['users_id' => 2]);
    $item->cleanHistory();
    verify($SQL_TOTAL_REQUEST === 0, 'Mapped selectors, counts and history purge bypass legacy SQL execution');
    verify((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()) === [], 'No orphaned relationships');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped cleanup selectors, paired template fields and template purges passed.\n";
