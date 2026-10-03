<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Entity as Record;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/domain-commercial-supplier.php /path/to/test-config\n");
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
final class CommercialSupplierApiResponse extends RuntimeException
{
    public function __construct(public readonly array $response, int $status)
    {
        parent::__construct(json_encode($response, JSON_THROW_ON_ERROR), $status);
    }
}
final class CommercialSupplierApiProbe extends \Glpi\Api\APIRest
{
    public function configure(int $client): void
    {
        $this->session_write = true;
        $this->app_tokens = [$client => 'commercial-supplier-fixture'];
        $this->parameters = ['app_token' => 'commercial-supplier-fixture', 'session_token' => session_id()];
    }
    public function updateDomain(array $input): array
    {
        return $this->updateItems(Domain::class, ['input' => [(object)$input]]);
    }
    public function refusedUpdate(array $input): bool
    {
        try {
            $this->updateDomain($input);
        } catch (CommercialSupplierApiResponse $response) {
            verify($response->getCode() === 400 && $response->response[0] === 'ERROR_GLPI_UPDATE', 'REST refuses with the actual update error and HTTP status');
            verify(count($response->response[1]) === 1 && $response->response[1][0][$input['id']] === false
                && str_contains($response->response[1][0]['message'], 'commercial supplier'), 'REST refuses this Domain with its commercial ownership diagnostic');
            return false;
        }
        throw new RuntimeException('Expected REST commercial supplier refusal');
    }
    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new CommercialSupplierApiResponse($response, $httpcode);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$session = $_SESSION;
$configuration = $CFG_GLPI;
$hooks = $PLUGIN_HOOKS;
$plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
$activePlugins = $plugins->getValue();
$plugins->setValue(null, [...$activePlugins, 'commercial_supplier_fixture']);
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$records = static fn (): RecordRepository => new RecordRepository(Orm::create($DB));
$read = static fn (string $table, int $id): ?array => $records()->find($table, 'id', $id);
$counts = static fn (): array => array_map(static fn (string $table): int => $records()->countMatching($table, []), ['glpi_domains', 'glpi_logs', 'glpi_queuednotifications']);
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $prefix = 'Commercial supplier ' . bin2hex(random_bytes(5));
    $owner = (int)(new Entity())->add(['name' => $prefix . ' owner', 'entities_id' => 0]);
    $sibling = (int)(new Entity())->add(['name' => $prefix . ' sibling', 'entities_id' => 0]);
    $child = (int)(new Entity())->add(['name' => $prefix . ' child', 'entities_id' => $owner]);
    verify($owner > 0 && $sibling > 0 && $child > 0, 'Actual owner hierarchy includes root zero');
    $same = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' local', 'entities_id' => $owner]);
    $other = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' other', 'entities_id' => $owner]);
    $outside = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' sibling', 'entities_id' => $sibling, 'is_recursive' => true]);
    $descendant = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' descendant', 'entities_id' => $child, 'is_recursive' => true]);
    $ancestor = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' ancestor', 'entities_id' => 0, 'is_recursive' => true]);
    $nonrecursiveAncestor = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' nonrecursive ancestor', 'entities_id' => 0]);
    $financialSupplier = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' financial', 'entities_id' => $owner]);
    $_SESSION['glpiactiveentities'] = [$owner, $child];
    $_SESSION['glpiactiveentities_string'] = $owner . ',' . $child;
    $_SESSION['glpiactive_entity'] = $owner;
    $_SESSION['glpiparententities'] = [0];
    $_SESSION['glpishowallentities'] = false;
    // Selection uses Domain authority; unrelated Supplier CRUD rights are not added.
    $_SESSION['glpiactiveprofile'][Supplier::$rightname] = 0;
    verify(!Supplier::canView() && !Supplier::canUpdate(), 'Actual Supplier READ and UPDATE rights are absent');
    $input = ['name' => $prefix . ' domain', 'entities_id' => $owner, 'suppliers_id' => $same];
    verify((new Domain())->can(-1, CREATE, $input), 'Actual Domain create authority without Supplier CRUD');
    $foreignInput = $input;
    $foreignInput['entities_id'] = $sibling;
    verify(!(new Domain())->can(-1, CREATE, $foreignInput), 'Actual Domain create still rejects actor-outside owner');
    $choices = Dropdown::getDropdownValue(['itemtype' => Supplier::class, 'entity_restrict' => $owner, 'display_emptychoice' => false], false);
    $choiceIds = [];
    foreach ($choices['results'] as $group) {
        foreach ($group['children'] ?? [$group] as $choice) {
            $choiceIds[] = (int)$choice['id'];
        }
    }
    verify(in_array($same, $choiceIds, true) && in_array($ancestor, $choiceIds, true)
        && !in_array($outside, $choiceIds, true) && !in_array($nonrecursiveAncestor, $choiceIds, true), 'Actual Supplier choices establish local/recursive ancestor policy under actor scope');
    $domain = new Domain();
    $id = (int)$domain->add($input);
    verify($id > 0 && $read('glpi_domains', $id)['suppliers_id'] === $same, 'Public Domain add selects its local commercial Supplier');
    $financial = $fixtures->create('glpi_infocoms', ['itemtype' => Domain::class, 'items_id' => $id, 'entities_id' => $owner, 'suppliers_id' => $financialSupplier]);
    $client = $fixtures->create('glpi_apiclients', ['name' => $prefix . ' API', 'dolog_method' => 0]);
    $api = new CommercialSupplierApiProbe();
    $api->configure($client);
    $refused = static function (callable $operation, string $message) use ($read, $counts, $id, $financial): void {
        $before = [$read('glpi_domains', $id), $read('glpi_infocoms', $financial), $counts()];
        verify($operation() === false, $message);
        verify([$read('glpi_domains', $id), $read('glpi_infocoms', $financial), $counts()] === $before, 'Refusal preserves Domain, financial supplier, history and notifications: ' . $message);
    };
    foreach ([$outside => 'Sibling', $nonrecursiveAncestor => 'Nonrecursive ancestor', $descendant => 'Descendant'] as $supplier => $label) {
        $before = $counts();
        verify((new Domain())->add(['name' => $prefix . ' refused ' . $label, 'entities_id' => $owner, 'suppliers_id' => $supplier, 'is_recursive' => true]) === false, 'Public add refuses ' . $label . ' commercial ownership');
        verify($counts() === $before, 'Refused add changes no Domain/history/notification rows');
        $refused(static fn (): bool => (new Domain())->update(['id' => $id, 'suppliers_id' => $supplier, 'comment' => 'Must not persist']), 'Public update refuses ' . $label);
        $refused(static fn (): bool => $api->refusedUpdate(['id' => $id, 'suppliers_id' => $supplier, 'comment' => 'Must not persist']), 'Actual REST update refuses ' . $label);
    }
    verify($domain->getFromDB($id), 'Load Domain for pure transfer coherence preflight');
    $before = [$read('glpi_domains', $id), $read('glpi_infocoms', $financial), $counts()];
    $transferRefused = false;
    try {
        $domain->validateEntityTransfer($child);
    } catch (InvalidArgumentException $error) {
        $transferRefused = str_contains($error->getMessage(), 'commercial supplier');
    }
    verify($transferRefused && [$read('glpi_domains', $id), $read('glpi_infocoms', $financial), $counts()] === $before, 'Pure Domain transfer hook rejects incompatible owner before auxiliary effects');
    $domain->validateEntityTransfer($owner);
    verify([$read('glpi_domains', $id), $read('glpi_infocoms', $financial), $counts()] === $before, 'Pure allowed transfer hook has no writes');
    verify((new Domain())->update(['id' => $id, 'suppliers_id' => $ancestor]), 'Recursive root Supplier ancestor accepted');
    verify((new Domain())->update(['id' => $id, 'entities_id' => $child]), 'Owner-only move remains coherent with recursive ancestor');
    verify((new Domain())->update(['id' => $id, 'entities_id' => $owner, 'suppliers_id' => $same]), 'Owner and commercial Supplier move together coherently');
    $refused(static fn (): bool => (new Domain())->update(['id' => $id, 'entities_id' => $child]), 'Owner-only move refuses retained nonrecursive Supplier');
    verify((new Domain())->update(['id' => $id, 'comment' => 'Absent selection retained']), 'Unrelated update succeeds');
    verify($read('glpi_domains', $id)['suppliers_id'] === $same, 'Absent supplier key retains owning association');
    foreach ([null, 0, '0', 'NULL', 'null'] as $clear) {
        verify((new Domain())->update(['id' => $id, 'suppliers_id' => $clear]), 'Explicit nullable/legacy empty selection clears commercial Supplier');
        verify($read('glpi_domains', $id)['suppliers_id'] === null && $read('glpi_infocoms', $financial)['suppliers_id'] === $financialSupplier, 'Clear preserves separate financial role');
        verify((new Domain())->update(['id' => $id, 'suppliers_id' => $other]), 'Restore authorized local selection');
    }
    $refused(static fn (): bool => (new Domain())->update(['id' => $id, 'suppliers_id' => 'N\\ULL']), 'Escaped literal NULL is an invalid Supplier identifier');
    verify((new Domain())->update(['id' => $id, 'entities_id' => $child, 'suppliers_id' => null]), 'Explicit clear permits coherent owner move');
    verify((new Domain())->update(['id' => $id, 'entities_id' => $owner, 'suppliers_id' => $same]), 'Restore owner and commercial role');
    verify($domain->getFromDB($id), 'Load actual clone source');
    $clone = (int)$domain->clone(['name' => $prefix . ' copied']);
    verify($clone > 0 && $read('glpi_domains', $clone)['suppliers_id'] === $same, 'Legitimate clone retains commercial Supplier');
    $before = $counts();
    verify($domain->clone(['entities_id' => $child]) === false, 'Clone to child refuses retained nonrecursive Supplier');
    verify($counts() === $before, 'Refused clone preserves source and history');
    $clearClone = (int)$domain->clone(['name' => $prefix . ' cleared clone', 'entities_id' => $child, 'suppliers_id' => null]);
    verify($clearClone > 0 && $read('glpi_domains', $clearClone)['suppliers_id'] === null, 'Explicit null clone override clears Supplier before owner change');

    foreach (['NULL', 'null'] as $clear) {
        $cleared = (int)$domain->clone(['name' => $prefix . ' sentinel clone ' . $clear, 'entities_id' => $child, 'suppliers_id' => $clear]);
        verify($cleared > 0 && $read('glpi_domains', $cleared)['suppliers_id'] === null, 'Legacy NULL clone override agrees with persistence decoding');
    }
    $added = 0;
    $PLUGIN_HOOKS['item_add']['commercial_supplier_fixture'][Domain::class] = static function (Domain $item) use (&$added): void {
        ++$added;
    };
    $assigned = (int)(new Domain())->addWithAssignedIdentifier(4294971801, ['name' => $prefix . ' assigned', 'entities_id' => $owner, 'suppliers_id' => $ancestor]);
    unset($PLUGIN_HOOKS['item_add']['commercial_supplier_fixture']);
    verify($assigned === 4294971801 && $added === 1, 'Assigned-ID importer lifecycle validates scope and runs actual public item_add hook once');

    // Native ORM paths enforce exactly the same association predicate.
    $ormReject = static function (callable $operation, string $message) use ($counts, $connection): void {
        $before = $counts();
        $connection->beginTransaction();
        $em = Orm::create($GLOBALS['DB']);
        try {
            $rejected = false;
            try {
                $operation($em);
            } catch (InvalidArgumentException $error) {
                $rejected = str_contains($error->getMessage(), 'commercial supplier');
            }
            verify($rejected, $message);
        } finally {
            $em->close();
            $connection->rollBack();
        }
        verify($counts() === $before, 'Native ORM refusal writes no Domain/history rows');
    };
    $ormReject(static function ($em) use ($owner, $outside): void {
        $record = new Record\Domain();
        $record->entities = $em->find(Record\Entity::class, $owner);
        $record->suppliers = $em->find(Record\Supplier::class, $outside);
        $em->persist($record);
        $em->flush();
    }, 'Native ORM persist refuses sibling commercial Supplier');
    $ormReject(static function ($em) use ($owner, $same, $outside): void {
        $record = new Record\Domain();
        $record->entities = $em->find(Record\Entity::class, $owner);
        $record->suppliers = $em->find(Record\Supplier::class, $same);
        $em->persist($record);
        $record->suppliers = $em->find(Record\Supplier::class, $outside);
        $em->flush();
    }, 'Native ORM persist-then-mutate cannot bypass supplier validation at flush');
    $ormReject(static function ($em) use ($id, $child): void {
        $record = $em->find(Record\Domain::class, $id);
        $record->entities = $em->find(Record\Entity::class, $child);
        $em->flush();
    }, 'Native ORM owner-only update refuses retained nonrecursive Supplier');
    $em = Orm::create($DB);
    $record = new Record\Domain();
    $record->name = $prefix . ' native';
    $record->entities = $em->find(Record\Entity::class, $child);
    $record->suppliers = $em->find(Record\Supplier::class, $ancestor);
    $em->persist($record);
    $em->flush();
    verify($record->id > 0, 'Native ORM persist accepts recursive root ancestor');
    $record->suppliers = null;
    $em->flush();
    verify($read('glpi_domains', $record->id)['suppliers_id'] === null, 'Native ORM nullable clear succeeds');
    $em->close();
    $em = Orm::create($DB);
    $supplierRecord = new Record\Supplier();
    $supplierRecord->name = $prefix . ' pending native supplier';
    $supplierRecord->entities = $em->find(Record\Entity::class, $owner);
    $em->persist($supplierRecord);
    $pendingDomain = new Record\Domain();
    $pendingDomain->entities = $supplierRecord->entities;
    $pendingDomain->suppliers = $supplierRecord;
    $em->persist($pendingDomain);
    $em->flush();
    verify($pendingDomain->id > 0 && $supplierRecord->id > 0, 'Native ORM accepts a newly persisted local Supplier in the same unit of work');
    $em->close();

    if ($DB->getProvider() !== 'pgsql') {
        $malformed = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' malformed', 'entities_id' => 0]);
        $connection->update('glpi_suppliers', ['is_recursive' => 2], ['id' => $malformed]);
        $refused(static fn (): bool => (new Domain())->update(['id' => $id, 'suppliers_id' => $malformed]), 'Legacy MySQL flag2 cannot grant recursive supplier ownership');
        $ormReject(static function ($em) use ($id, $malformed): void {
            $record = $em->find(Record\Domain::class, $id);
            $record->suppliers = $em->find(Record\Supplier::class, $malformed);
            $em->flush();
        }, 'Native ORM rejects hydrated truthy legacy flag2');
    }
    // A post-prepare extension cannot bypass the persistence backstop.
    $PLUGIN_HOOKS['post_prepareadd']['commercial_supplier_fixture'][Domain::class] = static function (Domain $item) use ($outside): void {
        $item->input['suppliers_id'] = $outside;
    };
    $before = $counts();
    $rejected = false;
    try {
        (new Domain())->add(['name' => $prefix . ' changed by hook', 'entities_id' => $owner, 'suppliers_id' => $same]);
    } catch (InvalidArgumentException $error) {
        $rejected = str_contains($error->getMessage(), 'commercial supplier');
    }
    unset($PLUGIN_HOOKS['post_prepareadd']['commercial_supplier_fixture']);
    verify($rejected && $counts() === $before, 'Post-prepare supplier mutation is rejected without Domain/history side effects');
    $_SESSION['glpiactiveprofile'][Supplier::$rightname] = $session['glpiactiveprofile'][Supplier::$rightname];
    verify((new Supplier())->can($same, PURGE), 'Restore authorized Supplier purge actor');
    verify((new Supplier())->delete(['id' => $same], true), 'Actual Supplier purge clears optional commercial association');
    verify($read('glpi_domains', $id)['suppliers_id'] === null && $read('glpi_domains', $clone)['suppliers_id'] === null
        && $read('glpi_infocoms', $financial)['suppliers_id'] === $financialSupplier, 'Supplier purge preserves Domains and distinct financial supplier');
} finally {
    $DB->rollBack();
    $_SESSION = $session;
    $CFG_GLPI = $configuration;
    $PLUGIN_HOOKS = $hooks;
    $plugins->setValue(null, $activePlugins);
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after tests');
echo $DB->getProvider() . ": authoritative commercial supplier scope, public/REST/native ORM, NULL/absence, clone, retarget and purge passed.\n";
