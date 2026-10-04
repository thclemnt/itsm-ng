<?php

// SPDX-License-Identifier: GPL-2.0-or-later

require dirname(__DIR__, 2) . '/tools/database/SqlCallInventory.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Declaration probes are inspected, never constructed or invoked.
final class InventoryUnownedPdoConnectionProbe
{
    private PDO $pdo;

    public function query(string $sql): mixed
    {
        return $this->pdo->query($sql);
    }
}

final class InventoryUntypedNativeDriverProbe extends Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware
{
    private $pdo;

    public function query(string $sql): Doctrine\DBAL\Driver\Result
    {
        $this->pdo->query($sql);
        throw new LogicException('Declaration probe is never invoked');
    }
}

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
$driverFile = $root . '/src/Database/Driver/Postgres/Connection.php';
$driverSource = file_get_contents($driverFile);
$driverCalls = SqlCallInventory::scan($driverSource, 'src/Database/Driver/Postgres/Connection.php');
$ownedCalls = SqlCallInventory::classifyOwnedDriverBoundaries($driverCalls, $driverSource, $driverFile);
$nativeCalls = array_values(array_filter($driverCalls, static fn (array $call): bool => $call['category'] === 'direct_driver'));
$boundaries = array_values(array_filter($ownedCalls, static fn (array $call): bool => $call['category'] === 'owned_driver_boundary'));
verify(count($nativeCalls) === 2 && count($boundaries) === 2, 'Actual native PDO calls retain lexical evidence and resolve to the two real driver methods');
foreach ($boundaries as $boundary) {
    $owner = new ReflectionClass($boundary['ownership']['class']);
    verify($owner->implementsInterface(Doctrine\DBAL\Driver\Connection::class)
        && realpath($owner->getFileName()) === realpath($driverFile)
        && $boundary['ownership']['native_type'] === PDO::class,
        'Actual interface, declaration file and native PDO ownership establish the driver boundary');
}
verify(SqlCallInventory::classifyOwnedDriverBoundaries($driverCalls, $driverSource, __FILE__) === $driverCalls,
    'A real driver declaration supplied under a different physical source is not ownership evidence');
verify(SqlCallInventory::classifyOwnedDriverBoundaries($driverCalls, $driverSource . "\n// forged source\n", $driverFile) === $driverCalls,
    'A modified supplied declaration cannot borrow ownership from the loaded actual source');
$unownedSource = '<?php $this->pdo->query("SELECT 1"); PDO::query("SELECT 2"); mysqli_query($link, "SELECT 3"); pg_query($link, "SELECT 4");';
$unowned = SqlCallInventory::scan($unownedSource, 'src/Database/Driver/Forged.php');
verify(array_column($unowned, 'category') === ['direct_driver', 'direct_driver', 'direct_driver', 'direct_driver']
    && SqlCallInventory::classifyOwnedDriverBoundaries($unowned, $unownedSource, $driverFile) === $unowned,
    'A driver-like path, PDO spelling, static native calls and native free functions confer no exception');
$probeSource = file_get_contents(__FILE__);
$probeCalls = SqlCallInventory::scan($probeSource, 'tests/database-portability/sql-inventory.php');
$probeSemantic = SqlCallInventory::classifyOwnedDriverBoundaries($probeCalls, $probeSource, __FILE__);
verify(count(array_filter($probeCalls, static fn (array $call): bool => $call['category'] === 'direct_driver')) === 2
    && $probeCalls === $probeSemantic, 'Actual PDO property without the owning interface and actual driver interface without a PDO property both refuse classification');
$compactFile = __DIR__ . '/fixtures/compact-native-driver-declaration.txt';
require $compactFile; // Declaration fixture only; no instance or native method invocation.
$compactSource = file_get_contents($compactFile);
$compactOwner = new ReflectionClass(InventoryCompactNativeDriverProbe::class);
verify($compactOwner->implementsInterface(Doctrine\DBAL\Driver\Connection::class)
    && $compactOwner->getProperty('pdo')->getType()->getName() === PDO::class
    && $compactOwner->getMethod('query')->getStartLine() === $compactOwner->getMethod('applicationSql')->getStartLine(),
    'Actual typed native driver declaration has genuinely overlapping reflection line ranges');
$compactCalls = SqlCallInventory::scan($compactSource, 'fixtures/compact-native-driver-declaration.txt');
verify(count($compactCalls) === 1 && $compactCalls[0]['category'] === 'direct_driver'
    && SqlCallInventory::classifyOwnedDriverBoundaries($compactCalls, $compactSource, $compactFile) === $compactCalls,
    'A same-line unrelated method cannot borrow the real owning driver query method through line ranges');
$all = SqlCallInventory::discover($root);
verify($all === SqlCallInventory::discover($root), 'Repository inventory order is deterministic');
verify(!array_filter($all, static fn (array $call): bool => $call['path'] === 'install/install.php' && $call['category'] === 'direct_driver'), 'Web installer no longer opens native driver connections');
verify(!array_filter($all, static fn (array $call): bool => $call['category'] === 'direct_driver'), 'Application native PDO, mysqli and PostgreSQL calls are absent outside verified owning Doctrine driver methods');
verify(!array_filter($all, static fn (array $call): bool => in_array($call['path'], ['inc/auth.class.php', 'inc/authmail.class.php'], true)
    && in_array($call['category'], ['legacy_adapter', 'legacy_dynamic', 'direct_driver'], true)), 'Authentication services no longer issue adapter or native-driver SQL');
verify(!array_filter($all, static fn (array $call): bool => $call['path'] === 'inc/lock.class.php'
    && in_array($call['category'], ['legacy_adapter', 'legacy_dynamic', 'direct_driver'], true)), 'Inventory locks no longer issue adapter or native-driver SQL');
verify(!array_filter($all, static fn (array $call): bool => $call['path'] === 'inc/reservationitem.class.php'
    && in_array($call['category'], ['legacy_adapter', 'legacy_dynamic', 'direct_driver'], true)), 'Reservable items no longer issue adapter or native-driver SQL');
verify(!array_filter($all, static fn (array $call): bool => in_array($call['path'], [
    'inc/caldav/traits/caldavuriutiltrait.class.php', 'inc/reminder_user.class.php', 'inc/entity_reminder.class.php', 'inc/alert.class.php',
    'inc/infocom.class.php',
], true) && in_array($call['category'], ['legacy_adapter', 'legacy_dynamic', 'direct_driver'], true)), 'Calendar UID, reminder audience and alert loaders no longer issue adapter or native-driver SQL');
verify((bool)array_filter($all, static fn (array $call): bool => $call['path'] === 'install/update_0723_078.php' && $call['method'] === 'queryOrDie'), 'Previously omitted historical migration wrappers are discovered');
echo "SQL inventory: token discovery, false-positive boundaries, source locations and remaining driver/migration evidence passed.\n";
