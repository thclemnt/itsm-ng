<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\BooleanDomains20261008;
use itsmng\Database\Migration\Booleans20261002;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\DomainIntegration20261006;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/boolean-domains.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
/** Final public write boundary sees actual callbacks, not attempted input. */
class BooleanLateSupplierFixture extends Supplier
{
    public bool $cancelFlagWrite = false;

    public static function getTable($classname = null)
    {
        return Supplier::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields['is_recursive'] = 2;
        if ($this->cancelFlagWrite) {
            $this->updates = array_values(array_diff($this->updates, ['is_recursive']));
        }
    }
}

class BooleanLateUserFixture extends User
{
    public static function getTable($classname = null)
    {
        return User::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields['compact_mode_ui'] = 2;
    }
}

class BooleanLateProfileUserFixture extends Profile_User
{
    public bool $cancelFlagWrite = false;

    public static function getTable($classname = null)
    {
        return Profile_User::getTable();
    }

    public function pre_updateInDB()
    {
        $this->fields['is_recursive'] = 2;
        if ($this->cancelFlagWrite) {
            $this->updates = array_values(array_diff($this->updates, ['is_recursive']));
        }
    }
}

$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Exclusive disposable portability database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$mysql = $platform instanceof AbstractMySQLPlatform;
$quote = $platform->quoteIdentifier(...);
$stage = new BooleanDomains20261008();
$version = BooleanDomains20261008::VERSION;
$receipt = Ledger::state($connection, $version);
verify(($receipt['complete'] ?? false) === true && History::pendingVersions($connection) === [], 'Actually migrate the disposable fixture before ordinary bootstrap');
verify((new SchemaCheck())->differences($connection) === [], 'Current complete schema passes native boolean inspection');

// Actual representative mapped rows, including nullable inherited preferences
// and flags whose older supplying migrations are independent of the baseline.
$savedSession = $_SESSION;
$savedPreference = $CFG_GLPI['compact_mode_ui'];
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $membership = $fixtures->create('glpi_profiles_users', ['is_recursive' => true, 'is_dynamic' => false]);
    $beforeMembership = $connection->fetchAssociative('SELECT * FROM glpi_profiles_users WHERE id = ?', [$membership]);
    $fixed = new BooleanLateProfileUserFixture();
    verify($fixed->update(['id' => $membership, 'is_recursive' => 0, 'is_dynamic' => 1], history: false) === false, 'Fixed-owner relation delegates the final public boolean guard');
    verify($connection->fetchAssociative('SELECT * FROM glpi_profiles_users WHERE id = ?', [$membership]) === $beforeMembership && (int)$fixed->fields['is_recursive'] === 1, 'Fixed-owner late refusal restores native and callback state');
    $fixed->cancelFlagWrite = true;
    verify($fixed->update(['id' => $membership, 'is_recursive' => 0, 'is_dynamic' => 1], history: false), 'Fixed-owner cancelled invalid flag permits other valid writes');
    verify((int)$connection->fetchOne('SELECT is_dynamic FROM glpi_profiles_users WHERE id = ?', [$membership]) === 1, 'Accepted fixed-owner control actually persists the other selected flag');
    verify((int)$connection->fetchOne('SELECT is_recursive FROM glpi_profiles_users WHERE id = ?', [$membership]) === 1 && (int)$fixed->fields['is_recursive'] === 1 && (int)$fixed->input['is_recursive'] === 1, 'Fixed-owner cancellation preserves stored boolean write view');
    foreach ([['glpi_suppliers', 'is_recursive'], ['glpi_users', 'compact_mode_ui'], ['glpi_itilcategories', 'is_incident'], ['glpi_slms', 'use_ticket_calendar'], ['glpi_domains', 'is_helpdesk_visible']] as [$table, $column]) {
        $id = $fixtures->create($table, ['name' => 'Boolean domain ' . bin2hex(random_bytes(6))]);
        $em = Orm::create($DB);
        $writer = new RecordWriter($em);
        foreach ([false, '1', 0, true] as $value) {
            $writer->update($table, $id, [$column => $value]);
            verify((int)$connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$id]) === (int)(bool)$value, 'Mapped form/API zero and one persist: ' . $table . '.' . $column);
        }
        $record = $em->getRepository(EntityRegistry::tables()[$table])->findOneBy(['id' => $id]);
        $oldName = $record->name;
        foreach ([2, -1, 'false', 1.0] as $invalid) {
            try {
                $writer->update($table, $id, ['name' => 'Must not mutate managed state', $column => $invalid]);
                throw new LogicException('Invalid mapped flag accepted');
            } catch (InvalidArgumentException $error) {
                verify(str_contains($error->getMessage(), $table . '.' . $column) && $record->name === $oldName, 'Rejected input leaves the existing unit of work untouched');
            }
        }
        $em->flush();
        verify($connection->fetchOne('SELECT name FROM ' . $quote($table) . ' WHERE id = ?', [$id]) === $oldName, 'Later flush cannot leak the rejected name');
        $nullable = EntityRegistry::booleanFields($table)[$column];
        foreach ([2, -1, null] as $invalid) {
            if ($invalid === null && $nullable) {
                $writer->update($table, $id, [$column => null]);
                verify($connection->fetchOne('SELECT ' . $quote($column) . ' FROM ' . $quote($table) . ' WHERE id = ?', [$id]) === null, 'Nullable mapped preference remains SQL NULL');
                continue;
            }
            $connection->createSavepoint('boolean_native_rejection');
            $rejected = false;
            try {
                // Integer binding deliberately bypasses Doctrine BooleanType coercion.
                $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = ? WHERE id = ?', [$invalid, $id], [$invalid === null ? Types::STRING : Types::INTEGER, Types::BIGINT]);
            } catch (\Doctrine\DBAL\Exception) {
                $rejected = true;
            } finally {
                $connection->rollbackSavepoint('boolean_native_rejection');
                $connection->releaseSavepoint('boolean_native_rejection');
            }
            verify($rejected, 'Native DML cannot bypass the flag domain: ' . $table . '.' . $column);
        }
        if ($table === 'glpi_suppliers') {
            $before = $connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$id]);
            $late = new BooleanLateSupplierFixture();
            verify($late->update(['id' => $id, 'name' => 'Refused late flag', 'is_recursive' => 0], history: false) === false, 'Late actual invalid write refuses through the public lifecycle');
            verify($connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$id]) === $before && $late->fields['is_recursive'] == $before['is_recursive'], 'Refused late write restores stored row and callback view');
            $late->cancelFlagWrite = true;
            verify($late->update(['id' => $id, 'name' => 'Accepted cancelled flag', 'is_recursive' => 0], history: false), 'Cancelled invalid flag is not an actual write');
            verify((int)$connection->fetchOne('SELECT is_recursive FROM glpi_suppliers WHERE id = ?', [$id]) === 1 && (int)$late->fields['is_recursive'] === 1 && (int)$late->input['is_recursive'] === 1, 'Cancelled flag preserves native and effective callback state');
        }
        if ($table === 'glpi_users') {
            $_SESSION['glpiID'] = $id;
            $_SESSION['glpicompact_mode_ui'] = 'unchanged-session-preference';
            $user = new User();
            verify($user->update(['id' => $id, 'compact_mode_ui' => 2]) === false, 'Public User refuses before preparation');
            verify($_SESSION['glpicompact_mode_ui'] === 'unchanged-session-preference', 'Rejected preference cannot mutate SESSION');
            $CFG_GLPI['compact_mode_ui'] = 0;
            $writer->update($table, $id, ['compact_mode_ui' => false]);
            $_SESSION['glpicompact_mode_ui'] = 0;
            $lateUser = new BooleanLateUserFixture();
            verify($lateUser->update(['id' => $id, 'compact_mode_ui' => '1', '_no_message' => 1]) === false, 'Late User invalid flag refuses after valid preparation');
            verify((int)$_SESSION['glpicompact_mode_ui'] === 0 && (int)$connection->fetchOne('SELECT compact_mode_ui FROM glpi_users WHERE id = ?', [$id]) === 0, 'Late refused preference leaves stored and session state unchanged');
            $CFG_GLPI['compact_mode_ui'] = 1;
            $writer->update($table, $id, ['compact_mode_ui' => false]);
            verify($user->update(['id' => $id, 'compact_mode_ui' => 'NULL', '_no_message' => 1]), 'Legacy inherited preference sentinel remains supported');
            verify($connection->fetchOne('SELECT compact_mode_ui FROM glpi_users WHERE id = ?', [$id]) === null, 'Public false-to-inherited-NULL transition stores genuine NULL');
            verify((int)$_SESSION['glpicompact_mode_ui'] === 1, 'Current-user SESSION receives the configured effective inherited boolean');
            $_SESSION['glpicompact_mode_ui'] = 0;
            verify($user->update(['id' => $id, 'compact_mode_ui' => null, '_no_message' => 1]) && (int)$_SESSION['glpicompact_mode_ui'] === 1, 'Accepted no-change inheritance refresh uses actual stored NULL');
            $CFG_GLPI['compact_mode_ui'] = 1;
            verify($user->update(['id' => $id, 'compact_mode_ui' => '0', '_no_message' => 1]), 'Public inherited NULL-to-false transition accepted');
            verify($connection->fetchOne('SELECT compact_mode_ui FROM glpi_users WHERE id = ?', [$id]) !== null && (int)$connection->fetchOne('SELECT compact_mode_ui FROM glpi_users WHERE id = ?', [$id]) === 0, 'NULL differs from explicit false in lifecycle planning');
            $CFG_GLPI['compact_mode_ui'] = 0;
            verify($user->update(['id' => $id, 'compact_mode_ui' => '1', '_no_message' => 1]), 'Public true preference accepted');
            verify((int)$connection->fetchOne('SELECT compact_mode_ui FROM glpi_users WHERE id = ?', [$id]) === 1, 'Public callback retains zero/one representation');
        }
    }
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $CFG_GLPI['compact_mode_ui'] = $savedPreference;
}

// Emulate an explicitly older history in this exclusive fixture. Current schema
// always rejects raw 2 above; removing its new CHECK is confined to this test's
// historical regression, not a production compatibility escape hatch.
$table = 'glpi_suppliers';
$column = 'is_recursive';
$name = BooleanDomainSchema::name($table, $column);
$drop = 'ALTER TABLE ' . $quote($table) . ($platform instanceof MySQLPlatform ? ' DROP CHECK ' : ' DROP CONSTRAINT ') . $quote($name);
$add = 'ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT ' . $quote($name) . ' CHECK (' . BooleanDomainSchema::expression($platform, $column, false) . ')';
$id = (new FixtureRecords($DB))->create($table, ['name' => 'Historical invalid boolean ' . bin2hex(random_bytes(6)), $column => true]);
$priorStates = Ledger::states($connection);
try {
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    if ($mysql) {
        $connection->executeStatement($drop);
        $connection->executeStatement('UPDATE glpi_suppliers SET is_recursive = 2 WHERE id = ?', [$id]);
        $before = BooleanDomainSchema::catalog($connection);
        foreach ([fn () => $stage->plan($connection), fn () => (new History())->upgrade($connection)] as $attempt) {
            try {
                $attempt();
                throw new LogicException('Historical bad flag accepted');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'glpi_suppliers.is_recursive') && str_contains($error->getMessage(), '1 rows'), 'Historical bad-data diagnostic includes count and owning property');
            }
            verify(BooleanDomainSchema::catalog($connection) === $before && Ledger::state($connection, $version) === null, 'Complete preflight refuses before native DDL or receipt');
        }
        $connection->executeStatement('UPDATE glpi_suppliers SET is_recursive = 1 WHERE id = ?', [$id]);
        try {
            $stage->apply($connection, static fn () => throw new RuntimeException('Injected interruption after committed CHECK DDL'));
            throw new LogicException('Fault injection did not run');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'Injected interruption') && (Ledger::state($connection, $version)['complete'] ?? true) === false, 'Committed DDL retains an incomplete retry journal');
        }
        verify($stage->plan($connection)['sql'] === [], 'Retry re-inspects already committed native CHECK');
        (new History())->upgrade($connection);
        verify(Ledger::state($connection, $version)['complete'] && (new SchemaCheck())->differences($connection) === [], 'Older completed history actually adopts appended boolean domains');
        $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
        $connection->executeStatement($drop);
        $connection->executeStatement('ALTER TABLE ' . $quote($table) . ' ADD CONSTRAINT ' . $quote($name) . ' CHECK (is_recursive IS NOT NULL AND (is_recursive IN (0,1) OR id > 0))');
        verify(in_array('Changed boolean domain CHECK: ' . $table . '.' . $name, (new SchemaCheck())->differences($connection), true), 'Read-only schema checking rejects a permissive lookalike with the right name');
        try {
            $stage->plan($connection);
            throw new LogicException('Permissive historical CHECK accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'Conflicting boolean CHECK'), 'Migration refuses an incompatible named CHECK');
        }
        $connection->executeStatement($drop);
        $connection->executeStatement($add);
    } else {
        // The earlier conversion is complete: its receipt must not excuse a
        // later integer regression merely because another history is pending.
        verify(($priorStates[Booleans20261002::VERSION]['complete'] ?? false) === true, 'Old PG conversion genuinely completed');
        $connection->executeStatement('ALTER TABLE glpi_suppliers ALTER COLUMN is_recursive DROP DEFAULT, ALTER COLUMN is_recursive TYPE SMALLINT USING CASE WHEN is_recursive THEN 1 ELSE 0 END, ALTER COLUMN is_recursive SET DEFAULT 0');
        $before = BooleanDomainSchema::catalog($connection);
        try {
            (new History())->upgrade($connection);
            throw new LogicException('Completed-converter storage drift accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'Unsupported boolean storage: glpi_suppliers.is_recursive'), 'Completed converter cannot defer drift to a skipped phase');
        }
        verify(BooleanDomainSchema::catalog($connection) === $before && Ledger::state($connection, $version) === null, 'PG drift refuses before any migration DDL or new receipt');
        $connection->executeStatement('ALTER TABLE glpi_suppliers ALTER COLUMN is_recursive DROP DEFAULT, ALTER COLUMN is_recursive TYPE BOOLEAN USING (is_recursive = 1), ALTER COLUMN is_recursive SET DEFAULT FALSE');
        (new History())->upgrade($connection);
        verify(Ledger::state($connection, $version)['complete'], 'Native PG flags receive the appended no-op receipt');
    }
    // A later flag's completed supplying receipt cannot excuse a missing
    // column. Preserve any preceding suite rows while inspecting this drift.
    verify(($priorStates[DomainIntegration20261006::VERSION]['complete'] ?? false) === true, 'Domain supplying migration actually completed');
    $domainValues = $connection->fetchAllAssociative('SELECT id, is_helpdesk_visible FROM glpi_domains ORDER BY id');
    $domainCheck = BooleanDomainSchema::name('glpi_domains', 'is_helpdesk_visible');
    $domainDropCheck = 'ALTER TABLE glpi_domains' . ($platform instanceof MySQLPlatform ? ' DROP CHECK ' : ' DROP CONSTRAINT ') . $quote($domainCheck);
    $connection->delete(LegacyToOrm::LEDGER, ['version' => $version]);
    try {
        if ($mysql) {
            $connection->executeStatement($domainDropCheck);
        }
        $connection->executeStatement('ALTER TABLE glpi_domains DROP COLUMN is_helpdesk_visible');
        $before = BooleanDomainSchema::catalog($connection);
        try {
            (new History())->upgrade($connection);
            throw new LogicException('Completed producer allowed a missing boolean field');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'Missing boolean column: glpi_domains.is_helpdesk_visible'), 'Completed producer drift is actionable before DDL');
        }
        verify(BooleanDomainSchema::catalog($connection) === $before && Ledger::state($connection, $version) === null, 'Missing completed producer refuses without native or ledger writes');
    } finally {
        if (!isset(BooleanDomainSchema::catalog($connection)['columns']['glpi_domains']['is_helpdesk_visible'])) {
            $connection->executeStatement('ALTER TABLE glpi_domains ADD COLUMN is_helpdesk_visible BOOLEAN NOT NULL DEFAULT TRUE');
            foreach ($domainValues as $domain) {
                $connection->update('glpi_domains', ['is_helpdesk_visible' => $domain['is_helpdesk_visible']], ['id' => $domain['id']], ['is_helpdesk_visible' => Types::BOOLEAN, 'id' => Types::BIGINT]);
            }
        }
        if ($mysql && !isset(BooleanDomainSchema::catalog($connection)['checks']['glpi_domains'][$domainCheck])) {
            $connection->executeStatement('ALTER TABLE glpi_domains ADD CONSTRAINT ' . $quote($domainCheck) . ' CHECK (' . BooleanDomainSchema::expression($platform, 'is_helpdesk_visible', false) . ')');
        }
    }
    verify($connection->fetchAllAssociative('SELECT id, is_helpdesk_visible FROM glpi_domains ORDER BY id') === $domainValues, 'Missing-column fixture retains every pre-existing Domain visibility');
    (new History())->upgrade($connection);
    foreach ($priorStates as $oldVersion => $state) {
        if ($oldVersion !== $version) {
            verify(Ledger::state($connection, $oldVersion) === $state, 'Earlier historical receipts remain unchanged: ' . $oldVersion);
        }
    }
} finally {
    if ($mysql) {
        $connection->executeStatement('UPDATE glpi_suppliers SET is_recursive = 1 WHERE id = ?', [$id]);
        $checks = BooleanDomainSchema::catalog($connection)['checks'];
        if (isset($checks[$table][$name])) {
            $connection->executeStatement($drop);
        }
        $connection->executeStatement($add);
    } else {
        $type = BooleanDomainSchema::catalog($connection)['columns'][$table][$column]['data_type'];
        if ($type !== 'boolean') {
            $connection->executeStatement('ALTER TABLE glpi_suppliers ALTER COLUMN is_recursive DROP DEFAULT, ALTER COLUMN is_recursive TYPE BOOLEAN USING (is_recursive = 1), ALTER COLUMN is_recursive SET DEFAULT FALSE');
        }
    }
    $connection->delete($table, ['id' => $id]);
    Ledger::save($connection, $version, $receipt);
}
verify((new SchemaCheck())->differences($connection) === [], 'Historical fixture restores the full current schema');
echo $DB->getProvider() . ': boolean domains, public/ORM inputs, historical adoption, pre-DDL diagnostics and retry: ' . $assertions . " assertions passed.\n";
