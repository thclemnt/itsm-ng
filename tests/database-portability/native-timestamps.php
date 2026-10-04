<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use itsmng\Database\Entity\ObjectLock as MappedObjectLock;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\NativeTimestampSchema;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ObjectLockRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/native-timestamps.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
require __DIR__ . '/fixtures/NativeTemporalProbe.php';
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated fixture required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$mysql = $platform instanceof AbstractMySQLPlatform;
$DB->assertManagedTransaction();
verify($connection->getTransactionNestingLevel() === 0, 'Native temporal probe requires its own idle writer');
$manager = $connection->createSchemaManager();
$config = Orm::configuration($platform);
$config->setMetadataDriverImpl(new AttributeDriver([], $platform));
$probeEm = new EntityManager($connection, $config);
$metadata = $probeEm->getClassMetadata(NativeTemporalProbe::class);
$expected = (new SchemaTool($probeEm))->getSchemaFromMetadata([$metadata]);
$probe = $expected->getTable($metadata->getTableName());
$table = $probe->getName();
$policy = NativeTimestampSchema::declarations([$metadata])[$table]['touched_instant'];
$quoted = $platform->quoteIdentifier($table);
verify(!$manager->tablesExist([$table]), 'Probe table must be absent before owned creation');
if (!$mysql) {
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = ?', [$policy->touchTrigger]) === 0, 'Probe touch function must be absent before owned creation');
}
$timezone = $connection->fetchOne($mysql ? 'SELECT @@session.time_zone' : 'SHOW TIME ZONE');
$phpTimezone = date_default_timezone_get();
$setTimezone = static function (string $zone) use ($connection, $mysql): void {
    $connection->executeStatement($mysql ? 'SET SESSION time_zone = ?' : "SELECT set_config('TimeZone', ?, false)", [$zone]);
};
$ledgerSql = 'SELECT * FROM itsmng_migrations ORDER BY version';
$ledgerBefore = $connection->fetchAllAssociative($ledgerSql);
$catalogBefore = $manager->listTableNames();
sort($catalogBefore, SORT_STRING);
$coreTables = ['glpi_computers', 'glpi_users', 'glpi_objectlocks'];
$coreRows = [];
foreach ($coreTables as $name) {
    $coreRows[$name] = $connection->fetchAllAssociative('SELECT * FROM ' . $platform->quoteIdentifier($name) . ' ORDER BY id');
}
$primary = null;
$cleanup = [];
$scope = null;
$tableOwned = $functionOwned = false;
$nativeSafe = true;
try {
    date_default_timezone_set('UTC');
    $setTimezone('UTC');
    $manager->createTable($probe);
    $tableOwned = true;
    foreach ($policy->touchSql($platform, $table, 'touched_instant') as $offset => $sql) {
        $connection->executeStatement($sql);
        if ($offset === 0) {
            $functionOwned = true;
        }
    }
    verify((new SchemaCheck())->differences($connection, $expected) === [], 'Property-driven SchemaTool table round trips through native inspection');
    verify(NativeTimestampSchema::differences($connection, $expected, [$metadata]) === [], 'Property-owned automatic touch is installed on the independent table');
    $connection->beginTransaction();
    $scope = $DB->captureManagedTransactionScope();
    $connection->insert($table, ['id' => 1, 'label' => 'Database default']);
    $default = $probeEm->find(NativeTemporalProbe::class, 1);
    verify(
        $default instanceof NativeTemporalProbe && $default->touched_instant instanceof DateTimeInterface
        && $default->nullable_instant === null && $default->unmarked_wall_time === null,
        'Native default/null values hydrate through the existing ORM datetime types'
    );
    $probeEm->clear();
    foreach (['2019-03-04 10:00:00' => '2019-03-04 11:00:00', '2019-07-04 10:00:00' => '2019-07-04 12:00:00'] as $utc => $local) {
        $connection->insert($table, ['id' => 2, 'label' => 'Owned instant', 'nullable_instant' => new DateTimeImmutable($utc, new DateTimeZone('UTC')), 'unmarked_wall_time' => new DateTimeImmutable($utc, new DateTimeZone('UTC'))], ['nullable_instant' => Types::DATETIMETZ_IMMUTABLE, 'unmarked_wall_time' => Types::DATETIMETZ_IMMUTABLE]);
        $setTimezone('Europe/Paris');
        $row = $connection->fetchAssociative('SELECT nullable_instant, unmarked_wall_time FROM ' . $quoted . ' WHERE id = 2');
        verify(substr($row['nullable_instant'], 0, 19) === $local, 'Property-declared native storage follows the session timezone and DST');
        verify(substr($row['unmarked_wall_time'], 0, 19) === ($mysql ? $utc : $local), 'Unmarked datetime retains its existing provider semantics');
        $setTimezone('UTC');
        $hydrated = $probeEm->find(NativeTemporalProbe::class, 2);
        verify($hydrated->nullable_instant instanceof DateTimeInterface && $hydrated->nullable_instant->format('Y-m-d H:i:s') === $utc, 'UTC ORM read preserves the stored instant after timezone changes');
        $probeEm->clear();
        $connection->delete($table, ['id' => 2]);
    }
    $old = '2001-01-01 00:00:00';
    $explicit = '2003-01-01 00:00:00';
    $connection->update($table, ['touched_instant' => $old], ['id' => 1]);
    $connection->update($table, ['label' => 'Touch an ordinary value'], ['id' => 1]);
    verify(substr($connection->fetchOne('SELECT touched_instant FROM ' . $quoted . ' WHERE id = 1'), 0, 19) !== $old, 'An actual ordinary update invokes the property-owned clock');
    $connection->update($table, ['label' => 'Explicit instant wins', 'touched_instant' => $explicit], ['id' => 1]);
    verify(substr($connection->fetchOne('SELECT touched_instant FROM ' . $quoted . ' WHERE id = 1'), 0, 19) === $explicit, 'Automatic touch preserves an explicitly supplied distinct instant');
    $connection->update($table, ['label' => 'Explicit instant wins'], ['id' => 1]);
    verify(substr($connection->fetchOne('SELECT touched_instant FROM ' . $quoted . ' WHERE id = 1'), 0, 19) === $explicit, 'A no-op update preserves the existing clock');
    $clock = $probeEm->find(NativeTemporalProbe::class, 1);
    $clock->label = 'Explicitly supply the unchanged clock';
    $clock->touched_instant = new DateTime($explicit, new DateTimeZone('UTC'));
    $probeEm->flush();
    $storedClock = substr($connection->fetchOne('SELECT touched_instant FROM ' . $quoted . ' WHERE id = 1'), 0, 19);
    verify($mysql ? $storedClock === $explicit : $storedClock !== $explicit, 'Explicit same-clock assignment preserves the existing provider-specific native touch semantics');
    verify($clock->touched_instant->format('Y-m-d H:i:s') === $storedClock, 'Generated ownership refreshes the managed clock to the actual native outcome');
    $clock->label = 'Explicitly supply a distinct clock';
    $clock->touched_instant = new DateTime('2005-01-01 00:00:00', new DateTimeZone('UTC'));
    $probeEm->flush();
    verify(
        $clock->touched_instant->format('Y-m-d H:i:s') === '2005-01-01 00:00:00'
        && substr($connection->fetchOne('SELECT touched_instant FROM ' . $quoted . ' WHERE id = 1'), 0, 19) === '2005-01-01 00:00:00',
        'Managed refresh preserves a distinct explicit writable timestamp'
    );
    $fixtures = new FixtureRecords($DB, static function (string $name, int $id) use ($coreTables): void {
        verify(in_array($name, $coreTables, true) && $id > 0, 'Actual lock fixture remains within its owned computer/user/lock graph');
    });
    $computer = $fixtures->create('glpi_computers', ['name' => 'Temporal lock subject']);
    $first = $fixtures->create('glpi_users', ['name' => 'Temporal first locker']);
    $second = $fixtures->create('glpi_users', ['name' => 'Temporal second locker']);
    $lock = $fixtures->create('glpi_objectlocks', ['itemtype' => 'Computer', 'subject_computers_id' => $computer, 'users_id' => $first, 'date_mod' => new DateTimeImmutable($old, new DateTimeZone('UTC'))]);
    $cutoff = new DateTimeImmutable('2002-01-01 00:00:00', new DateTimeZone('UTC'));
    $expired = static fn (): array => array_map(static fn ($row): int => (int)$row['id'], (new ObjectLockRepository(Orm::create($DB)))->expired($cutoff));
    verify(in_array($lock, $expired(), true), 'Actual lock expiry repository consumes the explicitly stored old instant');
    $lockEm = Orm::create($DB);
    $managedLock = $lockEm->find(MappedObjectLock::class, $lock);
    (new RecordWriter($lockEm))->update('glpi_objectlocks', $lock, ['users_id' => $second]);
    verify(
        $managedLock->date_mod->format('Y-m-d H:i:s') !== $old
        && $managedLock->date_mod->format('Y-m-d H:i:s') === substr($connection->fetchOne('SELECT date_mod FROM glpi_objectlocks WHERE id = ?', [$lock]), 0, 19),
        'The actual lock update refreshes the retained managed timestamp after native automatic touch'
    );
    $fresh = Orm::create($DB)->find(MappedObjectLock::class, $lock);
    verify(
        $fresh->date_mod instanceof DateTimeInterface && $fresh->date_mod->format('Y-m-d H:i:s') !== $old
        && $fresh->users->id === $second && !in_array($lock, $expired(), true),
        'A real ORM lock ownership update touches the instant and changes actual expiry admission'
    );
    (new RecordWriter($lockEm))->update('glpi_objectlocks', $lock, ['users_id' => $first, 'date_mod' => new DateTimeImmutable($explicit, new DateTimeZone('UTC'))]);
    verify($managedLock->date_mod->format('Y-m-d H:i:s') === $explicit, 'The retained managed lock preserves an explicit distinct writable timestamp');
    $row = (new RecordRepository(Orm::create($DB)))->find('glpi_objectlocks', 'id', $lock);
    verify(substr($row['date_mod'], 0, 19) === $explicit && (int)$row['users_id'] === $first, 'Explicit ORM lock timestamp and ownership survive the native touch policy');
    $scope->assertActive();
    verify($connection->getTransactionNestingLevel() === 1, 'Only the original owned temporal frame may roll back');
    $connection->rollBack();
    $scope = null;
    verify((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quoted) === 0, 'Native/ORM probe row mutations roll back');

    // DDL drift is confined to the new probe, outside any application transaction.
    if ($mysql) {
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' MODIFY ' . $platform->getColumnDeclarationSQL($platform->quoteIdentifier('nullable_instant'), ['typeName' => Types::DATETIME_MUTABLE, 'notnull' => false, 'comment' => "Property's instant"]));
        $beforeCheck = $manager->introspectTable($table)->getColumn('nullable_instant')->toArray(true);
        verify(in_array('Expected native TIMESTAMP: ' . $table . '.nullable_instant', (new SchemaCheck())->differences($connection, $expected), true), 'Property-declared native timestamp drift is reported despite DBAL aliasing');
        verify($manager->introspectTable($table)->getColumn('nullable_instant')->toArray(true) === $beforeCheck, 'Schema inspection leaves timestamp drift untouched');
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' MODIFY ' . $platform->getColumnDeclarationSQL($platform->quoteIdentifier('nullable_instant'), ['columnDefinition' => $probe->getColumn('nullable_instant')->getColumnDefinition()]));
        $untouched = clone $metadata->getFieldMapping('touched_instant');
        $untouchedDefinition = (new itsmng\Database\Mapping\NativeTimestamp())->declaration($platform, $untouched);
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' MODIFY ' . $platform->getColumnDeclarationSQL($platform->quoteIdentifier('touched_instant'), ['columnDefinition' => $untouchedDefinition]));
    } else {
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' ALTER COLUMN nullable_instant TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $beforeCheck = $manager->introspectTable($table)->getColumn('nullable_instant')->toArray(true);
        verify(in_array('Changed column: ' . $table . '.nullable_instant', (new SchemaCheck())->differences($connection, $expected), true), 'PostgreSQL timezone storage drift is reported');
        verify($manager->introspectTable($table)->getColumn('nullable_instant')->toArray(true) === $beforeCheck, 'Schema inspection leaves PostgreSQL timezone drift untouched');
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' ALTER COLUMN nullable_instant TYPE TIMESTAMP(0) WITH TIME ZONE');
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' DISABLE TRIGGER ' . $platform->quoteIdentifier($policy->touchTrigger));
    }
    $touchDrift = NativeTimestampSchema::differences($connection, $expected, [$metadata]);
    verify($touchDrift === ['Expected automatic timestamp touch: ' . $table . '.touched_instant'], 'Native automatic-touch drift has a precise property-owned diagnostic');
    verify(NativeTimestampSchema::differences($connection, $expected, [$metadata]) === $touchDrift, 'Read-only touch inspection does not enable or repair the clock');
    if ($mysql) {
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' MODIFY ' . $platform->getColumnDeclarationSQL($platform->quoteIdentifier('touched_instant'), ['columnDefinition' => $probe->getColumn('touched_instant')->getColumnDefinition()]));
    } else {
        $connection->executeStatement('ALTER TABLE ' . $quoted . ' ENABLE TRIGGER ' . $platform->quoteIdentifier($policy->touchTrigger));
        $connection->executeStatement('CREATE OR REPLACE FUNCTION ' . $platform->quoteIdentifier($policy->touchTrigger) . '() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
        $wrongBody = NativeTimestampSchema::differences($connection, $expected, [$metadata]);
        verify($wrongBody === ['Expected automatic timestamp touch: ' . $table . '.touched_instant'], 'An enabled trigger cannot substitute a function which omits the owning clock');
        verify(NativeTimestampSchema::differences($connection, $expected, [$metadata]) === $wrongBody, 'Inspection leaves the incorrect touch function untouched');
        $connection->executeStatement($policy->touchSql($platform, $table, 'touched_instant')[0]);
        $connection->executeStatement('DROP TRIGGER ' . $platform->quoteIdentifier($policy->touchTrigger) . ' ON ' . $quoted);
        $connection->executeStatement('CREATE TRIGGER ' . $platform->quoteIdentifier($policy->touchTrigger) . ' BEFORE UPDATE OF label ON ' . $quoted . ' FOR EACH ROW EXECUTE FUNCTION ' . $platform->quoteIdentifier($policy->touchTrigger) . '()');
        $restricted = NativeTimestampSchema::differences($connection, $expected, [$metadata]);
        verify($restricted === ['Expected automatic timestamp touch: ' . $table . '.touched_instant'], 'An exact touch function cannot be restricted to updates of only one row property');
        verify(NativeTimestampSchema::differences($connection, $expected, [$metadata]) === $restricted, 'Read-only inspection leaves the column-restricted trigger unchanged');
        $connection->executeStatement('DROP TRIGGER ' . $platform->quoteIdentifier($policy->touchTrigger) . ' ON ' . $quoted);
        $connection->executeStatement($policy->touchSql($platform, $table, 'touched_instant')[1]);
    }
    verify(NativeTimestampSchema::differences($connection, $expected, [$metadata]) === [] && (new SchemaCheck())->differences($connection, $expected) === [], 'Restored property-native storage/touch round trips without schema drift');
} catch (Throwable $error) {
    $primary = $error;
} finally {
    if ($scope !== null) {
        try {
            $scope->assertActive();
            verify($connection->getTransactionNestingLevel() === 1, 'Cleanup owns only the original temporal frame');
            $connection->rollBack();
        } catch (Throwable $error) {
            $nativeSafe = false;
            $cleanup[] = $error;
        }
    }
    if ($nativeSafe) {
        try {
            $DB->assertManagedTransaction();
            verify($connection->getTransactionNestingLevel() === 0, 'Native DDL cleanup requires the same idle writer');
        } catch (Throwable $error) {
            $nativeSafe = false;
            $cleanup[] = $error;
        }
    }
    if ($nativeSafe) {
        if ($tableOwned) {
            try {
                $manager->dropTable($table);
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        if ($functionOwned) {
            try {
                $connection->executeStatement('DROP FUNCTION ' . $platform->quoteIdentifier($policy->touchTrigger) . '()');
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
        try {
            $setTimezone($timezone);
            verify($connection->fetchOne($mysql ? 'SELECT @@session.time_zone' : 'SHOW TIME ZONE') === $timezone, 'Original native timezone restored');
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        try {
            verify($connection->fetchAllAssociative($ledgerSql) === $ledgerBefore, 'Temporal metadata/native tests preserve the canonical raw ledger');
            $catalogAfter = $manager->listTableNames();
            sort($catalogAfter, SORT_STRING);
            verify($catalogAfter === $catalogBefore, 'Only the owned temporary table/function were removed');
        } catch (Throwable $error) {
            $cleanup[] = $error;
        }
        foreach ($coreRows as $name => $rows) {
            try {
                verify($connection->fetchAllAssociative('SELECT * FROM ' . $platform->quoteIdentifier($name) . ' ORDER BY id') === $rows, 'Actual lock graph full native rows restored');
            } catch (Throwable $error) {
                $cleanup[] = $error;
            }
        }
    }
    try {
        verify(date_default_timezone_set($phpTimezone) && date_default_timezone_get() === $phpTimezone, 'Original PHP timezone restored');
    } catch (Throwable $error) {
        $cleanup[] = $error;
    }
}
if ($primary !== null) {
    foreach ($cleanup as $error) {
        try {
            fwrite(STDERR, 'Secondary temporal cleanup: ' . $error::class . "\n");
        } catch (Throwable) {
        }
    }
    throw $primary;
}
if ($cleanup !== []) {
    throw $cleanup[0];
}
echo $DB->getProvider() . ": property-native timestamps, timezone/DST/null/default/hydration, actual lock touch/expiry and read-only drift passed.\n";
