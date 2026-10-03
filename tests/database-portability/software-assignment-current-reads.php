<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\Repository\SoftwareAssignmentRepository;
use itsmng\Database\Repository\SoftwareInstallationRepository;
use itsmng\Database\Repository\SoftwareRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/software-assignment-current-reads.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** Observe the actual prepared persistence boundary while keeping the real write. */
class CurrentReadSoftwareLicense extends SoftwareLicense
{
    public static int $prepared = 0;
    public static int $writes = 0;

    public static function getTable($classname = null)
    {
        return SoftwareLicense::getTable();
    }

    public static function getType()
    {
        return SoftwareLicense::getType();
    }

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        ++self::$prepared;
        return parent::executePreparedUpdate($operation, $storedFields);
    }

    public function updateInDB($updates, $oldvalues = [])
    {
        ++self::$writes;
        return parent::updateInDB($updates, $oldvalues);
    }
}

/** Prove stale endpoint preparation reaches the real installation boundary. */
class CurrentReadSoftwareInstallation extends Item_SoftwareVersion
{
    public static int $prepared = 0;
    public static int $writes = 0;

    public static function getTable($classname = null)
    {
        return Item_SoftwareVersion::getTable();
    }

    public static function getType()
    {
        return Item_SoftwareVersion::getType();
    }

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        ++self::$prepared;
        return parent::executePreparedUpdate($operation, $storedFields);
    }

    public function updateInDB($updates, $oldvalues = [])
    {
        ++self::$writes;
        return parent::updateInDB($updates, $oldvalues);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Administrator login');
$connection = $DB->getDoctrineConnection();
verify($connection->getTransactionNestingLevel() === 0, 'Contract starts outside a caller transaction');
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema before tests');
$savedSession = $_SESSION;
$savedConfig = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$originalIsolation = $connection->getTransactionIsolation();
$secondaryAdapter = null;
$secondary = null;
$created = [];
$fixtures = new FixtureRecords($DB);
$prefix = 'Allocation current read ' . bin2hex(random_bytes(5));
$record = static function (string $table, array $values = []) use ($fixtures, &$created): int {
    $id = $fixtures->create($table, $values);
    $created[] = [$table, $id];
    return $id;
};
$manager = static fn ($writer): EntityManager => new EntityManager($writer, Orm::configuration($writer->getDatabasePlatform()));
$read = static fn ($writer, string $table, int $id): ?array => (new RecordRepository($manager($writer)))->find($table, 'id', $id);
$rows = static fn ($writer, string $table, array $criteria = []): array => (new RecordRepository($manager($writer)))->matching($table, $criteria, 'id ASC');
$graph = static function (string $label, int $entity) use ($record, $prefix): array {
    $software = $record('glpi_softwares', ['name' => $prefix . ' ' . $label, 'entities_id' => $entity]);
    $otherSoftware = $record('glpi_softwares', ['name' => $prefix . ' peer ' . $label, 'entities_id' => $entity]);
    $license = $record('glpi_softwarelicenses', ['softwares_id' => $software, 'entities_id' => $entity, 'number' => 5]);
    $otherLicense = $record('glpi_softwarelicenses', ['softwares_id' => $otherSoftware, 'entities_id' => $entity, 'number' => 5]);
    $monitor = $record('glpi_monitors', ['name' => $prefix, 'entities_id' => $entity]);
    $computer = $record('glpi_computers', ['name' => $prefix, 'entities_id' => $entity]);
    $phone = $record('glpi_phones', ['name' => $prefix, 'entities_id' => $entity]);
    $allocation = $record('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $monitor, 'softwarelicenses_id' => $license]);
    return compact('entity', 'software', 'otherSoftware', 'license', 'otherLicense', 'monitor', 'computer', 'phone', 'allocation');
};
$snapshot = static function ($writer, array $g) use ($read, $rows): array {
    return [
        $read($writer, 'glpi_softwares', $g['software']), $read($writer, 'glpi_softwares', $g['otherSoftware']),
        $read($writer, 'glpi_softwarelicenses', $g['license']), $read($writer, 'glpi_softwarelicenses', $g['otherLicense']),
        $read($writer, 'glpi_monitors', $g['monitor']), $read($writer, 'glpi_computers', $g['computer']), $read($writer, 'glpi_phones', $g['phone']),
        $rows($writer, 'glpi_items_softwarelicenses', ['items_id' => [$g['monitor'], $g['computer'], $g['phone']], 'itemtype' => ['Monitor', 'Computer', 'Phone']]),
        $rows($writer, 'glpi_logs'), $rows($writer, 'glpi_queuednotifications'),
        $rows($writer, 'glpi_softwarelicenses', ['softwares_id' => [$g['software'], $g['otherSoftware']]]),
    ];
};

try {
    if ($DB->getProvider() === 'pgsql') {
        // NativeDriver is bound to A's one handle and A's params contain only
        // dbname. Never reuse that bridge/middleware or clone its live handle.
        // This is the existing PostgreSQL transaction contract's fresh adapter
        // pattern; normal connect applies the configured schema and timezone.
        $secondaryAdapter = (new ReflectionClass($DB))->newInstanceWithoutConstructor();
        verify($secondaryAdapter->connect() === true, 'Independent configured PostgreSQL writer opens');
        $secondary = $secondaryAdapter->getDoctrineConnection();
        $physicalId = 'SELECT pg_backend_pid()';
        verify($secondary->fetchOne("SELECT current_setting('search_path')") === $connection->fetchOne("SELECT current_setting('search_path')")
            && $secondary->fetchOne("SELECT current_setting('TimeZone')") === $connection->fetchOne("SELECT current_setting('TimeZone')"), 'Independent PostgreSQL writer preserves configured schema and timezone');
    } else {
        $secondary = DriverManager::getConnection($connection->getParams());
        $physicalId = 'SELECT CONNECTION_ID()';
    }
    verify($secondary !== $connection && $secondary->fetchOne($physicalId) !== $connection->fetchOne($physicalId), 'A and B use distinct physical writers');
    $entity = $record('glpi_entities', ['name' => $prefix . ' visible']);
    $hidden = $record('glpi_entities', ['name' => $prefix . ' hidden']);
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpiactiveentities'] = [0, $entity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $entity;
    $_SESSION['glpishowallentities'] = false;

    $publish = static function (callable $operation) use ($secondary, $manager): mixed {
        $secondary->beginTransaction();
        try {
            $result = $operation(new RecordWriter($manager($secondary)));
            $secondary->commit();
            verify($secondary->getTransactionNestingLevel() === 0, 'B finishes its physical commit before A takes aggregate locks');
            return $result;
        } catch (Throwable $error) {
            while ($secondary->getTransactionNestingLevel() > 0) {
                $secondary->rollBack();
            }
            throw $error;
        }
    };
    $publishPhantoms = static function (array $g, bool $removeOriginal = false) use ($publish, &$created): int {
        return $publish(static function (RecordWriter $writer) use ($g, $removeOriginal, &$created): int {
            if ($removeOriginal) {
                $writer->delete('glpi_items_softwarelicenses', $g['allocation']);
            }
            foreach ([['Computer', $g['computer']], ['Phone', $g['phone']]] as [$kind, $subject]) {
                $id = $writer->insert('glpi_items_softwarelicenses', ['itemtype' => $kind, 'items_id' => $subject, 'softwarelicenses_id' => $g['license']]);
                $created[] = ['glpi_items_softwarelicenses', $id];
            }
            $invalid = $writer->insert('glpi_softwarelicenses', ['softwares_id' => $g['software'], 'entities_id' => $g['entity'], 'number' => 0, 'is_valid' => false]);
            $created[] = ['glpi_softwarelicenses', $invalid];
            $id = $writer->insert('glpi_items_softwarelicenses', ['itemtype' => 'Monitor', 'items_id' => $g['monitor'], 'softwarelicenses_id' => $invalid]);
            $created[] = ['glpi_items_softwarelicenses', $id];
            return $invalid;
        });
    };

    // PostgreSQL READ COMMITTED and MySQL/MariaDB RR are supported mutation
    // boundaries. MySQL's nonlocking view stays old; locked projections must not.
    $supported = $DB->getProvider() === 'pgsql' ? TransactionIsolationLevel::READ_COMMITTED : TransactionIsolationLevel::REPEATABLE_READ;
    $connection->setTransactionIsolation($supported);
    foreach ([false, true] as $removeOriginal) {
        $g = $graph($removeOriginal ? 'supported deletion and phantoms' : 'supported phantoms', $entity);
        verify($connection->getTransactionNestingLevel() === 0, 'All fixtures commit before A establishes its caller snapshot');
        $connection->beginTransaction();
        try {
            $plain = new SoftwareInstallationRepository($manager($connection));
            verify($plain->count(true, $g['license'], false, 'Monitor', Monitor::getTable(), []) === 1
                && !(new SoftwareRepository($manager($connection)))->hasInvalidLicense($g['software']), 'A establishes a real data snapshot before B writes');
            $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' supported caller marker']);
            $invalid = $publishPhantoms($g, $removeOriginal);
            $expectedCount = $removeOriginal ? 2 : 3;
            $quantity = $expectedCount - 1;
            $before = $snapshot($secondary, $g);
            verify(count($rows($secondary, 'glpi_items_softwarelicenses', ['softwarelicenses_id' => $g['license']])) === $expectedCount, 'B physically commits the exact insertion/deletion allocation count');
            if ($DB->getProvider() !== 'pgsql') {
                verify($plain->itemTypes(true, $g['license']) === ['Monitor']
                    && !(new SoftwareRepository($manager($connection)))->hasInvalidLicense($g['software']), 'Actual MySQL RR nonlocking snapshot still misses B new kinds and invalid licence');
            }
            $current = new SoftwareAssignmentRepository($manager($connection));
            $current->lockSubjects([['Monitor', $g['monitor']], ['Computer', $g['computer']], ['Phone', $g['phone']]]);
            $current->lockSoftware([$g['software']]);
            $current->lockLicenses([$g['license'], $invalid]);
            verify($current->eligibleAllocationCount($g['license']) === $expectedCount, 'Locked eligible count sees B new kinds after the caller snapshot');
            $references = $current->licensesForSubject('Monitor', $g['monitor']);
            $expectedReferences = $removeOriginal ? [$invalid] : [$g['license'], $invalid];
            sort($expectedReferences);
            verify($references === $expectedReferences && (new SoftwareRepository($manager($connection)))->hasInvalidLicense($g['software'], currentRead: true), 'Locked selected references and invalid-licence projection see B committed rows');
            CurrentReadSoftwareLicense::$writes = 0;
            verify((new CurrentReadSoftwareLicense())->update(['id' => $g['license'], 'number' => $quantity]) === true
                && CurrentReadSoftwareLicense::$writes === 1, 'Actual public quantity command persists once using current count under the supported caller isolation');
            $stored = $read($connection, 'glpi_softwarelicenses', $g['license']);
            verify($stored['number'] === $quantity && !$stored['is_valid'] && !$read($connection, 'glpi_softwares', $g['software'])['is_valid'], 'Finite quantity below the current count produces required licence and Software invalidity');
            verify($connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1 && $read($connection, 'glpi_suppliers', $marker) !== null, 'Accepted command retains a usable caller frame and marker');
        } finally {
            $connection->rollBack();
        }
        verify($snapshot($secondary, $g) === $before && $read($secondary, 'glpi_suppliers', $marker) === null, 'Outer rollback preserves B commits and reverses only A quantity, aggregate, history and marker work');

    }

    // Existing selected owners/scopes changed by B must fail rather than use A
    // older prepared source identity or an actor's older visible entity scope.
    foreach (['allocation owner', 'subject scope', 'licence owner', 'licence quantity'] as $case) {
        $g = $graph($case, $entity);
        $connection->beginTransaction();
        try {
            $old = $snapshot($connection, $g);
            $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' changed source marker']);
            $publish(static function (RecordWriter $writer) use ($case, $g, $hidden): void {
                match ($case) {
                    'allocation owner' => $writer->update('glpi_items_softwarelicenses', $g['allocation'], ['softwarelicenses_id' => $g['otherLicense']]),
                    'subject scope' => $writer->update('glpi_monitors', $g['monitor'], ['entities_id' => $hidden]),
                    'licence owner' => $writer->update('glpi_softwarelicenses', $g['license'], ['softwares_id' => $g['otherSoftware']]),
                    'licence quantity' => $writer->update('glpi_softwarelicenses', $g['license'], ['number' => 4]),
                };
            });
            $before = $snapshot($secondary, $g);
            $model = in_array($case, ['licence owner', 'licence quantity'], true) ? new SoftwareLicense() : new Item_SoftwareLicense();
            $result = $model instanceof SoftwareLicense
                ? $model->update(['id' => $g['license'], 'number' => 2])
                : $model->update(['id' => $g['allocation'], 'is_dynamic' => 1]);
            if ($DB->getProvider() !== 'pgsql' || $case === 'subject scope') {
                verify($result === false, 'Actual public command refuses stale or incoherent B changed ' . $case);
                verify($snapshot($secondary, $g) === $before && $snapshot($connection, $g)[8] === $old[8], 'Changed-source refusal performs no row/history/queue writes and preserves B commits');
            } else {
                verify($result === true, 'PostgreSQL READ COMMITTED public command loads and accepts the current coherent source after B commit');
                verify($model instanceof SoftwareLicense ? $model->fields['number'] === 2 && $model->fields['softwares_id'] === $before[2]['softwares_id']
                    : $model->fields['softwarelicenses_id'] === $g['otherLicense'], 'Supported PostgreSQL command retains B current licence owner/identity');
                verify($snapshot($secondary, $g) === $before, 'Supported caller command does not physically commit over B state');
            }
            verify($connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1 && $read($connection, 'glpi_suppliers', $marker) !== null, 'Changed-source command retains a usable caller transaction and marker');
        } finally {
            $connection->rollBack();
        }
    }

    if ($DB->getProvider() !== 'pgsql') {
        foreach (['entities_id', 'is_recursive'] as $scopeColumn) {
            $g = $graph('direct licence changed ' . $scopeColumn, $entity);
            $original = $snapshot($secondary, $g);
            $connection->beginTransaction();
            try {
                CurrentReadSoftwareLicense::$prepared = CurrentReadSoftwareLicense::$writes = 0;
                verify((new CurrentReadSoftwareLicense())->update(['id' => $g['license'], 'number' => 2]) === true
                    && CurrentReadSoftwareLicense::$prepared === 1 && CurrentReadSoftwareLicense::$writes === 1, 'Actual unchanged licence scope permits the authorized quantity control: ' . $scopeColumn);
                $stored = $read($connection, 'glpi_softwarelicenses', $g['license']);
                verify($stored['number'] === 2 && $stored['is_valid'] && $read($connection, 'glpi_softwares', $g['software'])['is_valid'], 'Accepted unchanged-scope control retains the correct licence and Software flags');
            } finally {
                $connection->rollBack();
            }
            verify($snapshot($secondary, $g) === $original, 'Control rollback restores only caller quantity/history/queue work before the scope race');

            $connection->beginTransaction();
            try {
                verify($snapshot($connection, $g) === $original, 'Actual MySQL RR caller snapshot retains the committed original licence scope');
                $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' direct licence scope marker']);
                $changedScope = $scopeColumn === 'entities_id' ? $hidden : true;
                $publish(static function (RecordWriter $writer) use ($g, $scopeColumn, $changedScope): void {
                    $writer->update('glpi_softwarelicenses', $g['license'], [$scopeColumn => $changedScope]);
                });
                $before = $snapshot($secondary, $g);
                verify($before[2][$scopeColumn] != $original[2][$scopeColumn] && $before[2]['softwares_id'] === $original[2]['softwares_id']
                    && $read($connection, 'glpi_softwarelicenses', $g['license'])[$scopeColumn] === $original[2][$scopeColumn], 'B physically commits the changed licence scope with the same Software owner while A still reads its old scope');
                CurrentReadSoftwareLicense::$prepared = CurrentReadSoftwareLicense::$writes = 0;
                $model = new CurrentReadSoftwareLicense();
                verify($model->update(['id' => $g['license'], 'number' => 2]) === false
                    && CurrentReadSoftwareLicense::$prepared === 1 && CurrentReadSoftwareLicense::$writes === 0, 'Direct public licence quantity command rejects changed persisted ' . $scopeColumn . ' after preparation and before its writer');
                verify($snapshot($secondary, $g) === $before && $snapshot($connection, $g) === $original
                    && $model->fields === $original[2] && $model->updates === [] && $model->oldvalues === [] && $model->input['number'] === 2, 'Changed licence scope refusal preserves original model/flags/history/queue and attempted input while leaving B committed scope untouched');
                verify($connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1
                    && $read($connection, 'glpi_suppliers', $marker) !== null, 'Licence persisted-scope refusal keeps the native caller frame and marker usable');
            } finally {
                $connection->rollBack();
            }
            verify($snapshot($secondary, $g) === $before && $read($secondary, 'glpi_suppliers', $marker) === null, 'Caller rollback preserves B committed licence scope and removes only the caller marker');
            // Re-establish the original authorized ownership before retrying;
            // an entity outside the actor's scope is not silently authorized.
            $publish(static function (RecordWriter $writer) use ($g, $scopeColumn, $original): void {
                $writer->update('glpi_softwarelicenses', $g['license'], [$scopeColumn => $original[2][$scopeColumn]]);
            });
            verify((new SoftwareLicense())->update(['id' => $g['license'], 'number' => 2]) === true
                && $read($secondary, 'glpi_softwarelicenses', $g['license'])['number'] === 2
                && $read($secondary, 'glpi_softwarelicenses', $g['license'])[$scopeColumn] === $original[2][$scopeColumn], 'Standalone authorized retry succeeds after B restores the unchanged coherent licence scope');
        }

        // The version retains its duplicated recursive flag, so endpoint
        // preparation alone permits this retarget. The current owning Software
        // scope, not that older cache, must govern the mutation boundary.
        $scopeSoftware = $record('glpi_softwares', ['name' => $prefix . ' recursive root scope', 'entities_id' => 0, 'is_recursive' => true]);
        $scopeVersion = $record('glpi_softwareversions', ['name' => $prefix . ' recursive version', 'softwares_id' => $scopeSoftware, 'entities_id' => 0, 'is_recursive' => true]);
        $scopeSource = $record('glpi_monitors', ['name' => $prefix . ' root installation subject', 'entities_id' => 0]);
        $scopeTarget = $record('glpi_monitors', ['name' => $prefix . ' child installation subject', 'entities_id' => $entity]);
        $scopeInstallation = $record('glpi_items_softwareversions', ['softwareversions_id' => $scopeVersion, 'itemtype' => 'Monitor', 'items_id' => $scopeSource, 'entities_id' => 0, 'is_dynamic' => true, 'date_install' => '2020-01-02']);
        $scopeSnapshot = static function ($writer) use ($read, $rows, $scopeSoftware, $scopeVersion, $scopeSource, $scopeTarget, $scopeInstallation): array {
            return [
                $read($writer, 'glpi_softwares', $scopeSoftware), $read($writer, 'glpi_softwareversions', $scopeVersion),
                $read($writer, 'glpi_monitors', $scopeSource), $read($writer, 'glpi_monitors', $scopeTarget),
                $read($writer, 'glpi_items_softwareversions', $scopeInstallation),
                $rows($writer, 'glpi_logs'), $rows($writer, 'glpi_queuednotifications'),
            ];
        };
        $scopeOriginal = $scopeSnapshot($secondary);
        $connection->beginTransaction();
        try {
            CurrentReadSoftwareInstallation::$prepared = CurrentReadSoftwareInstallation::$writes = 0;
            verify((new CurrentReadSoftwareInstallation())->update(['id' => $scopeInstallation, 'itemtype' => 'Monitor', 'items_id' => $scopeTarget]) === true
                && CurrentReadSoftwareInstallation::$prepared === 1 && CurrentReadSoftwareInstallation::$writes === 1, 'Actual recursive root Software permits the child installation retarget before B changes its scope');
            $retargeted = $read($connection, 'glpi_items_softwareversions', $scopeInstallation);
            verify($retargeted['items_id'] === $scopeTarget && $retargeted['entities_id'] === $entity
                && $retargeted['is_dynamic'] === $scopeOriginal[4]['is_dynamic'] && $retargeted['date_install'] === $scopeOriginal[4]['date_install'], 'Accepted installation control preserves inventory state and install date while deriving child ownership');
        } finally {
            $connection->rollBack();
        }
        verify($scopeSnapshot($secondary) === $scopeOriginal, 'Accepted control rollback restores installation, parent scope, history and queue');

        $connection->beginTransaction();
        try {
            verify($scopeSnapshot($connection) === $scopeOriginal, 'Actual MySQL RR caller snapshot starts with recursive Software and original installation');
            $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' installation scope marker']);
            $publish(static function (RecordWriter $writer) use ($scopeSoftware): void {
                $writer->update('glpi_softwares', $scopeSoftware, ['is_recursive' => false]);
            });
            $before = $scopeSnapshot($secondary);
            verify(!$before[0]['is_recursive'] && $read($connection, 'glpi_softwares', $scopeSoftware)['is_recursive'], 'B commits removed owning Software recursion while A nonlocking snapshot still permits the child');
            CurrentReadSoftwareInstallation::$prepared = CurrentReadSoftwareInstallation::$writes = 0;
            $model = new CurrentReadSoftwareInstallation();
            verify($model->update(['id' => $scopeInstallation, 'itemtype' => 'Monitor', 'items_id' => $scopeTarget]) === false
                && CurrentReadSoftwareInstallation::$prepared === 1 && CurrentReadSoftwareInstallation::$writes === 0, 'Current owning Software scope refuses the actually prepared retarget before installation persistence');
            verify($scopeSnapshot($secondary) === $before && $scopeSnapshot($connection)[4] === $scopeOriginal[4]
                && $scopeSnapshot($connection)[5] === $scopeOriginal[5] && $scopeSnapshot($connection)[6] === $scopeOriginal[6]
                && $model->fields === $scopeOriginal[4] && $model->updates === [] && $model->oldvalues === []
                && $model->input['items_id'] === $scopeTarget, 'Scope refusal retains B commit, original installation/model/history/queue and attempted input');
            verify($connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1
                && $read($connection, 'glpi_suppliers', $marker) !== null, 'Installation scope refusal leaves the native caller frame and marker usable');
        } finally {
            $connection->rollBack();
        }
        verify($scopeSnapshot($secondary) === $before && $read($secondary, 'glpi_suppliers', $marker) === null, 'Caller rollback preserves B removed recursion and removes only the caller marker');
        $publish(static function (RecordWriter $writer) use ($scopeSoftware): void {
            $writer->update('glpi_softwares', $scopeSoftware, ['is_recursive' => true]);
        });
        verify((new Item_SoftwareVersion())->update(['id' => $scopeInstallation, 'itemtype' => 'Monitor', 'items_id' => $scopeTarget]) === true
            && $read($secondary, 'glpi_items_softwareversions', $scopeInstallation)['items_id'] === $scopeTarget, 'Standalone retry accepts the child retarget after B actually restores recursive Software scope');
    }

    // A stale public model can report a no-op even when B's current required
    // flag differs. The service must verify that physical flag after the hook.
    $g = $graph('current required validity flag', $entity);
    $connection->beginTransaction();
    try {
        $old = $snapshot($connection, $g);
        $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' required flag marker']);
        $publish(static function (RecordWriter $writer) use ($g): void {
            $writer->update('glpi_softwarelicenses', $g['license'], ['is_valid' => false]);
        });
        $before = $snapshot($secondary, $g);
        $accepted = SoftwareLicense::updateValidityIndicator($g['license']);
        if ($DB->getProvider() === 'pgsql') {
            verify($accepted === true && $read($connection, 'glpi_softwarelicenses', $g['license'])['is_valid'], 'READ COMMITTED public validity refresh sees B current false flag and performs the required true write');
        } else {
            verify($accepted === false && $snapshot($connection, $g)[8] === $old[8], 'MySQL RR stale-model no-op cannot falsely accept the physically uncorrected required flag');
        }
        verify($snapshot($secondary, $g) === $before && $connection->getTransactionNestingLevel() === 1
            && (int)$connection->fetchOne('SELECT 1') === 1 && $read($connection, 'glpi_suppliers', $marker) !== null, 'Required-flag command preserves B commit, caller ownership, audit/queue and marker');
    } finally {
        $connection->rollBack();
    }

    if ($DB->getProvider() === 'pgsql') {
        foreach (['repeatable read', 'serializable'] as $physicalIsolation) {
            $g = $graph('strong ' . $physicalIsolation, $entity);
            $connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            // A caller may set the physical session without updating DBAL's
            // cached value. The service must inspect this same physical writer.
            $connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ' . strtoupper($physicalIsolation));
            verify($connection->getTransactionIsolation() === TransactionIsolationLevel::READ_COMMITTED, 'Control retains an intentionally stale DBAL isolation cache');
            $connection->beginTransaction();
            try {
                $plain = new SoftwareInstallationRepository($manager($connection));
                verify($plain->count(true, $g['license'], false, 'Monitor', Monitor::getTable(), []) === 1, 'PostgreSQL strong caller snapshot predates B allocation inserts');
                $marker = $fixtures->create('glpi_suppliers', ['name' => $prefix . ' strong caller marker']);
                $publishPhantoms($g);
                $before = $snapshot($secondary, $g);
                $current = new SoftwareAssignmentRepository($manager($connection));
                $current->lockSoftware([$g['software']]);
                $current->lockLicenses([$g['license']]);
                verify($current->eligibleAllocationCount($g['license']) === 1
                    && !(new SoftwareRepository($manager($connection)))->hasInvalidLicense($g['software'], currentRead: true), 'Factual PostgreSQL strong snapshot control: row locks cannot expose B allocation/invalid-licence phantoms');
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Existing caller feedback']];
                $model = new CurrentReadSoftwareLicense();
                CurrentReadSoftwareLicense::$writes = 0;
                verify($model->update(['id' => $g['license'], 'number' => 2]) === false && CurrentReadSoftwareLicense::$writes === 0, 'PostgreSQL strong caller isolation refuses before the actual public persistence boundary');
                $dictionary = new \itsmng\Database\Repository\SoftwareDictionaryRepository($manager($connection));
                verify($dictionary->moveLicenses($g['software'], $g['otherSoftware']) === false, 'Direct bounded dictionary command also refuses the actual strong physical isolation');
                verify($snapshot($secondary, $g) === $before && $snapshot($connection, $g)[8] === $before[8]
                    && $snapshot($connection, $g)[9] === $before[9] && $model->fields === $before[2] && $model->updates === [] && $model->oldvalues === [], 'Early isolation refusal preserves B committed graph, audit, queue and original public model');
                verify(($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? []) === ['Existing caller feedback']
                    && in_array(__('Finish the current operation, then retry this software change.'), $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [], true), 'Isolation refusal retains prior feedback and supplies a useful retry instruction');
                foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [] as $message) {
                    verify(!preg_match('/PostgreSQL|READ COMMITTED|transaction_isolation|serializable|repeatable read/i', $message), 'Ordinary retry feedback keeps physical database details out of the user flow');
                }
                verify($connection->fetchOne("SELECT current_setting('transaction_isolation')") === $physicalIsolation
                    && $connection->getTransactionNestingLevel() === 1 && (int)$connection->fetchOne('SELECT 1') === 1
                    && $read($connection, 'glpi_suppliers', $marker) !== null, 'Refusal preserves actual caller isolation, usable native frame and marker');
            } finally {
                $connection->rollBack();
                $connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            }
            verify($snapshot($secondary, $g) === $before && $read($secondary, 'glpi_suppliers', $marker) === null, 'Caller rollback preserves B phantoms and removes only its own marker');
            verify((new SoftwareLicense())->update(['id' => $g['license'], 'number' => 2]) === true
                && !$read($secondary, 'glpi_softwarelicenses', $g['license'])['is_valid'], 'Actual PostgreSQL standalone READ COMMITTED retry sees B current allocations and commits correct validity');
        }
    }
} finally {
    while ($connection->getTransactionNestingLevel() > 0) {
        $connection->rollBack();
    }
    if ($secondary !== null) {
        while ($secondary->getTransactionNestingLevel() > 0) {
            $secondary->rollBack();
        }
    }
    $connection->setTransactionIsolation($supported ?? TransactionIsolationLevel::READ_COMMITTED);
    // Fixture cleanup is bounded to rows created by this contract. Restore the
    // public policy state first, then use each actual model's purge lifecycle.
    $CFG_GLPI['use_notifications'] = false;
    try {
        foreach (array_reverse($created) as [$table, $id]) {
            $kind = getItemTypeForTable($table);
            $model = new $kind();
            if ($model->getFromDB($id)) {
                verify((bool)$model->delete(['id' => $id, '_no_history' => true, '_disablenotif' => true], true), 'Owned fixture purge: ' . $table);
            }
        }
    } finally {
        if ($secondaryAdapter !== null) {
            // Normal adapter close already closes its transferred native driver;
            // do not also close the same handle through the facade.
            $secondaryAdapter->close();
        } elseif ($secondary !== null) {
            $secondary->close();
        }
        $connection->setTransactionIsolation($originalIsolation);
        $_SESSION = $savedSession;
        $CFG_GLPI = $savedConfig;
    }
}
verify((new SchemaCheck())->differences($connection) === [], 'Canonical schema after current-read fixtures');
echo $DB->getProvider() . ": $assertions two-writer current allocation, caller snapshot and isolation assertions passed.\n";
