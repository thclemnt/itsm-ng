<?php

// SPDX-License-Identifier: GPL-2.0-or-later

require dirname(__DIR__, 2) . '/tools/database/SqlCallInventory.php';

function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$source = <<<'PHP'
<?php
// $DB->query('comment'); new mysqli(); pg_query('comment');
$text = '$DB->prepare("string"); new \mysqli();';
function pg_fixture() {}
function &pg_reference_fixture() {}
$DB
    ->prepare('bound');
$DB->queryOrDie('first'); $DB->updateOrDie('second');
$DB?->request([]);
$DB->$operation([]);
new \mysqli();
new mysqli;
\pg_query($link, 'SELECT 1');
PDO::query('SELECT 2');
$read->request([]);
$model->update([]);
$connection->executeStatement('DDL');
$object->$operation();
new DBmysql();
PHP;
$calls = SqlCallInventory::scan($source, 'fixture.php');
verify(array_column($calls, 'category') === [
    'legacy_adapter', 'legacy_adapter', 'legacy_adapter', 'legacy_adapter',
    'legacy_dynamic', 'direct_driver', 'direct_driver', 'direct_driver', 'direct_driver',
    'method_candidate', 'method_candidate', 'method_candidate', 'dynamic_candidate',
    'adapter_construction',
], 'Tokens discover wrappers, nullable/dynamic calls, constructors and alternate receivers without comments/string/declaration false positives');
verify(array_column(array_slice($calls, 0, 4), 'line') === [7, 8, 8, 9], 'Multiline calls retain method lines and same-line expressions remain distinct');
verify($calls[1]['offset'] !== $calls[2]['offset'], 'Two calls on one line have separate byte offsets');
verify($calls[9]['receiver'] === '$read' && $calls[11]['receiver'] === '$connection', 'Unknown DB and Doctrine receivers remain candidates for review');
$compatibility = SqlCallInventory::scan('<?php $this->query("SELECT 1"); $this->db->executePrepared($statement);', 'inc/dbmysql.class.php');
verify(array_column($compatibility, 'category') === ['adapter_internal', 'adapter_internal'], 'Adapter implementation calls are separated from application execution');
verify(SqlCallInventory::scan('<?php $this->query("SELECT 1");', 'inc/domain.class.php')[0]['category'] === 'method_candidate', 'Unrelated this receivers are not called adapters');
verify(SqlCallInventory::scan('<?php \DBmysql::query("SELECT 1");', 'fixture.php')[0]['category'] === 'legacy_adapter', 'Static adapter calls are discovered');

$root = dirname(__DIR__, 2);
$all = SqlCallInventory::discover($root);
verify($all === SqlCallInventory::discover($root), 'Repository inventory order is deterministic');
verify((bool)array_filter($all, static fn (array $call): bool => $call['path'] === 'install/install.php' && $call['category'] === 'direct_driver'), 'Current web installer driver dependency is discovered');
verify((bool)array_filter($all, static fn (array $call): bool => $call['path'] === 'install/update_0723_078.php' && $call['method'] === 'queryOrDie'), 'Previously omitted historical migration wrappers are discovered');
echo "SQL inventory: token discovery, false-positive boundaries, source locations and remaining driver/migration evidence passed.\n";
