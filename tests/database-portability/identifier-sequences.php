<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\V220\IdentifierSequences;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\WideIdentifiers;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/identifier-sequences.php /path/to/test-config\n");
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
function verify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$manager = $connection->createSchemaManager();
$stage = new IdentifierSequences();
$version = IdentifierSequences::PHASE;
$originalReceipt = Ledger::state($connection, $version);
verify(($originalReceipt['complete'] ?? false) === true, 'Install or actually migrate this fixture before ordinary application bootstrap');
$originalLegacyReceipt = Ledger::state($connection, References::PHASE);
verify((new References())->plan($connection)['complete'], 'Original adoption receipt completed');
verify(!$connection->isTransactionActive(), 'Exercise actual migration transactions');

if (!$platform instanceof PostgreSQLPlatform) {
    $tableName = 'port_identifier_autoincrement';
    verify(!$manager->tablesExist([$tableName]), 'Own auto-increment fixture is absent');
    $table = new Table($tableName);
    $table->addColumn('id', 'bigint', ['autoincrement' => true]);
    $table->addColumn('label', 'string', ['length' => 80]);
    $table->setPrimaryKey(['id']);
    $manager->createTable($table);
    try {
        $connection->insert($tableName, ['id' => 4294967401, 'label' => 'assigned wide ID']);
        $before = $connection->fetchAssociative('SHOW CREATE TABLE ' . $tableName);
        $connection->delete(Ledger::TABLE, ['version' => $version]);
        verify($stage->plan($connection) === [] && WideIdentifiers::planOwnedSequences($connection, [$tableName => ['id']]) === [], 'MySQL sequence planning is a true no-op');
        verify(History::pendingVersions($connection) === [$version] && Ledger::state($connection, $version) === null, 'Read-only no-op preview retains pending receipt');
        $connection->beginTransaction();
        try {
            $stage->apply($connection);
            throw new LogicException('MySQL migration accepted an application transaction');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'outside an application transaction') && $connection->isTransactionActive(), 'Refusal leaves the caller transaction active');
        } finally {
            $connection->rollBack();
        }
        (new History())->upgrade($connection);
        verify(Ledger::state($connection, $version)['complete'] && $connection->fetchAssociative('SHOW CREATE TABLE ' . $tableName) === $before, 'Actual canonical history records completion without changing native AUTO_INCREMENT');
        SequenceSynchronizer::synchronize($connection);
        $connection->insert($tableName, ['label' => 'generated wide ID']);
        verify((int)$connection->lastInsertId() > 4294967401, 'MariaDB/MySQL still allocate above explicitly imported wide IDs');
        $receipt = Ledger::state($connection, $version);
        $stage->apply($connection, static fn () => throw new LogicException('Completed no-op replay executed DDL'));
        verify($stage->plan($connection) === [] && Ledger::state($connection, $version) === $receipt, 'Completed native-provider receipt is idempotent');
    } finally {
        $manager->dropTable($tableName);
        Ledger::save($connection, $version, $originalReceipt);
    }
} else {
    $quote = $platform->quoteSingleIdentifier(...);
    $qualified = static fn (string $schema, string $name): string => $quote($schema) . '.' . $quote($name);
    $owned = static function (string $schema, string $table, string $column) use ($connection): array {
        $sequence = $connection->fetchAssociative("SELECT sn.nspname AS schema, s.relname AS name FROM pg_class s
            JOIN pg_namespace sn ON sn.oid = s.relnamespace
            JOIN pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass
                AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
            JOIN pg_class t ON t.oid = d.refobjid JOIN pg_namespace tn ON tn.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            WHERE s.relkind = 'S' AND tn.nspname = ? AND t.relname = ? AND a.attname = ?", [$schema, $table, $column]);
        verify($sequence !== false, 'Real SERIAL/IDENTITY ownership exists');
        return $sequence;
    };
    $state = static function (array $sequence) use ($connection, $qualified, $platform): array {
        $relation = $qualified($sequence['schema'], $sequence['name']);
        $parameters = $connection->fetchAssociative('SELECT seqtypid::regtype::text AS type, seqstart::text AS start, seqincrement::text AS increment, seqmin::text AS minimum, seqmax::text AS maximum, seqcache::text AS cache, seqcycle AS cycle FROM pg_sequence WHERE seqrelid = ?::regclass', [$relation]);
        $values = $connection->fetchAssociative('SELECT last_value::text AS value, is_called AS called FROM ' . $relation);
        $parameters['cycle'] = Type::getType('boolean')->convertToPHPValue($parameters['cycle'], $platform);
        $values['called'] = Type::getType('boolean')->convertToPHPValue($values['called'], $platform);
        return $parameters + $values;
    };
    $searchPath = $connection->fetchOne('SHOW search_path');
    $schema = 'port.sequence"schema.with.dot';
    $decoy = 'port.sequence"other.with.dot';
    foreach ([$schema, $decoy] as $name) {
        verify(!$connection->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$name]), 'Own quoted namespace must not already exist');
    }
    $createdSchemas = [];
    try {
        foreach ([$schema, $decoy] as $name) {
            $connection->executeStatement('CREATE SCHEMA ' . $quote($name));
            $createdSchemas[] = $name;
        }
        $connection->executeStatement('SET search_path TO ' . $quote($schema) . ', public');
        $anchor = $qualified($schema, 'port_anchor');
        $serialTable = $qualified($schema, 'serial.table"name');
        $identityTable = $qualified($schema, 'identity.table"name');
        $smallTable = $qualified($schema, 'small.serial.table');
        $connection->executeStatement('CREATE TABLE ' . $anchor . ' (id BIGINT PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO ' . $anchor . ' VALUES (41)');
        foreach ([[$serialTable, 'SERIAL'], [$identityTable, 'INTEGER GENERATED BY DEFAULT AS IDENTITY'], [$smallTable, 'SMALLSERIAL']] as [$table, $declaration]) {
            $connection->executeStatement('CREATE TABLE ' . $table . ' ("owner.id" ' . $declaration . ' PRIMARY KEY REFERENCES ' . $anchor . '(id))');
            $connection->executeStatement('ALTER TABLE ' . $table . ' ALTER COLUMN "owner.id" TYPE BIGINT');
            $connection->insert($table, ['"owner.id"' => 41]);
        }
        $serial = $owned($schema, 'serial.table"name', 'owner.id');
        $identity = $owned($schema, 'identity.table"name', 'owner.id');
        foreach ([[$serial, 'serial.sequence"with.dot'], [$identity, 'identity.sequence"with.dot']] as [$sequence, $name]) {
            $connection->executeStatement('ALTER SEQUENCE ' . $qualified($sequence['schema'], $sequence['name']) . ' RENAME TO ' . $quote($name));
        }
        $serial = $owned($schema, 'serial.table"name', 'owner.id');
        $identity = $owned($schema, 'identity.table"name', 'owner.id');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($serial['schema'], $serial['name']) . ' AS integer RESTART WITH 2147483646');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($identity['schema'], $identity['name']) . ' AS integer MINVALUE 7 MAXVALUE 2000000000 START WITH 17 RESTART WITH 17 INCREMENT BY 3 CACHE 5 CYCLE');
        $reserved = (int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($identity['schema'], $identity['name'])]);
        $scopedSmall = $owned($schema, 'small.serial.table', 'owner.id');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($schema, $scopedSmall['name']) . ' AS smallint RESTART WITH 32766');
        $scopedSmallBefore = $state($scopedSmall);
        $serialBefore = $state($serial);
        $identityBefore = $state($identity);
        $descendingTable = $qualified($schema, 'descending.table');
        $connection->executeStatement('CREATE TABLE ' . $descendingTable . ' (id BIGSERIAL PRIMARY KEY)');
        $descending = $owned($schema, 'descending.table', 'id');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($schema, $descending['name']) . ' AS integer INCREMENT BY -3 NO MINVALUE NO MAXVALUE START WITH -1 RESTART WITH -50');
        // PostgreSQL resolves bounds in the type/increment transition using the
        // previous direction. Establish the descending native defaults after it.
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($schema, $descending['name']) . ' NO MINVALUE NO MAXVALUE');
        $connection->insert($descendingTable, ['id' => -20]);
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $descending['name'])]) === -50, 'Custom descending allocation is already reserved below existing data');
        $descendingBefore = $state($descending);
        verify(!$serialBefore['called'] && $identityBefore['called'] && $reserved === 17, 'Fixtures cover unused and reserved/cached identity state');
        $unscoped = $qualified($schema, 'unscoped_small');
        $connection->executeStatement('CREATE TABLE ' . $unscoped . ' (id SMALLSERIAL PRIMARY KEY)');
        $small = $owned($schema, 'unscoped_small', 'id');
        $smallBefore = $state($small);
        $connection->executeStatement('CREATE TABLE ' . $qualified($schema, 'unscoped_integer') . ' (id SERIAL PRIMARY KEY)');
        $unscopedInteger = $owned($schema, 'unscoped_integer', 'id');
        $integerBefore = $state($unscopedInteger);
        $unowned = ['schema' => $schema, 'name' => 'unowned.sequence"with.dot'];
        $connection->executeStatement('CREATE SEQUENCE ' . $qualified($schema, $unowned['name']) . ' AS integer');
        $unownedBefore = $state($unowned);
        $connection->executeStatement('CREATE TABLE ' . $qualified($schema, 'unowned_link') . ' (id BIGINT DEFAULT nextval(' . $connection->quote($qualified($schema, $unowned['name'])) . '::regclass) REFERENCES ' . $anchor . '(id))');
        $connection->executeStatement('CREATE TABLE ' . $qualified($decoy, 'port_anchor') . ' (id SERIAL PRIMARY KEY)');
        $other = $owned($decoy, 'port_anchor', 'id');
        $otherBefore = $state($other);
        $connection->executeStatement('CREATE TABLE ' . $qualified($decoy, 'cross_schema_link') . ' (id SERIAL PRIMARY KEY REFERENCES ' . $anchor . '(id))');
        $cross = $owned($decoy, 'cross_schema_link', 'id');
        $crossBefore = $state($cross);
        $foreign = $connection->fetchAllAssociative('SELECT oid::text, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace = (SELECT oid FROM pg_namespace WHERE nspname = ?) ORDER BY oid', [$schema]);
        $plan = WideIdentifiers::planOwnedSequences($connection, ['port_anchor' => ['id'], 'descending.table' => ['id']]);
        verify(count($plan) === 4 && in_array('ALTER SEQUENCE ' . $qualified($schema, $serial['name']) . ' AS bigint', $plan, true) && in_array('ALTER SEQUENCE ' . $qualified($schema, $identity['name']) . ' AS bigint', $plan, true), 'Quoted components and actual same-schema FK edges identify both narrow generators independently of BIGINT columns');
        verify($state($serial) === $serialBefore && $state($identity) === $identityBefore, 'Planning does not alter parameters or consume IDs');
        verify($state($scopedSmall) === $scopedSmallBefore, 'Scoped SMALLSERIAL remains unchanged during preview');
        SequenceSynchronizer::synchronize($connection);
        verify($state($serial)['type'] === 'integer' && $state($identity)['type'] === 'integer', 'Ordinary synchronization never repairs schema width');
        // MAX(owner.id)=41 legitimately advances the called identity. Preserve its
        // now-reserved state rather than confusing value maintenance with DDL.
        $identityBefore = $state($identity);
        verify($state($descending) === $descendingBefore, 'Ordinary synchronization never reverses reserved descending IDs');
        foreach ($plan as $sql) {
            $connection->executeStatement($sql);
        }
        $serialAfter = $state($serial);
        $identityAfter = $state($identity);
        verify($state($scopedSmall) === array_replace($scopedSmallBefore, ['type' => 'bigint', 'maximum' => '9223372036854775807']), 'Scoped SMALLSERIAL uses the same owned-generator repair and native default expansion');
        verify($state($descending) === array_replace($descendingBefore, ['type' => 'bigint', 'minimum' => '-9223372036854775808']), 'Descending native default minimum expands while its negative increment and reserved state survive: ' . json_encode(['before' => $descendingBefore, 'after' => $state($descending)]));
        verify($serialAfter === array_replace($serialBefore, ['type' => 'bigint', 'maximum' => '9223372036854775807']), 'Native integer default maximum expands while unused serial start/cache/increment/cycle/value remain');
        verify($identityAfter === array_replace($identityBefore, ['type' => 'bigint']), 'Custom bounds/start/increment/cache/cycle and reserved allocation state survive native widening');
        verify($state($small) === $smallBefore && $state($unscopedInteger) === $integerBefore && $state($unowned) === $unownedBefore && $state($other) === $otherBefore && $state($cross) === $crossBefore, 'Unrelated smallint, unowned, same-name out-of-schema and cross-schema generators remain untouched');
        verify($connection->fetchAllAssociative('SELECT oid::text, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace = (SELECT oid FROM pg_namespace WHERE nspname = ?) ORDER BY oid', [$schema]) === $foreign, 'Real foreign keys and their definitions remain intact');
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $serial['name'])]) === 2147483646, 'Unused serial does not lose its chosen restart');
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $serial['name'])]) === 2147483647 && (int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $serial['name'])]) === 2147483648, 'Former 32-bit serial limit no longer blocks real allocations');
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $scopedSmall['name'])]) === 32766
            && (int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $scopedSmall['name'])]) === 32767
            && (int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $scopedSmall['name'])]) === 32768, 'Scoped former 16-bit generator allocates beyond its old native limit');
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($schema, $identity['name'])]) > (int)$identityBefore['value'], 'Advanced and reserved identity allocation is never handed out again');
        $boundedBefore = $state($identity);
        $connection->insert($anchor, ['id' => 2000000003]);
        $connection->insert($identityTable, ['"owner.id"' => 2000000003]);
        try {
            try {
                SequenceSynchronizer::synchronize($connection);
                throw new LogicException('Synchronization silently accepted an import beyond retained custom bounds');
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                verify($error->getSQLState() === '22003' && str_contains($error->getMessage(), '2000000003') && str_contains($error->getMessage(), '2000000000'), 'Native invalid-data diagnostic reports imported ID and deliberately retained custom maximum');
            }
            verify($state($identity) === $boundedBefore, 'Out-of-bound import refusal does not change custom bounds or reserved allocation');
        } finally {
            $connection->executeStatement('DELETE FROM ' . $identityTable . ' WHERE "owner.id" = ?', [2000000003]);
            $connection->delete($anchor, ['id' => 2000000003]);
        }
        verify(WideIdentifiers::planOwnedSequences($connection, ['port_anchor' => ['id'], 'descending.table' => ['id']]) === [], 'Quoted/FK-derived repair is idempotent');
    } finally {
        $connection->executeStatement('SET search_path TO ' . $searchPath);
        foreach (array_reverse($createdSchemas) as $name) {
            $connection->executeStatement('DROP SCHEMA ' . $quote($name) . ' CASCADE');
        }
    }

    // A completed old adoption receipt cannot detect an independently narrow
    // sequence on its already-BIGINT column. The appended version must repair it.
    $namespace = $connection->fetchOne('SELECT current_schema()');
    $computer = $owned($namespace, 'glpi_computers', 'id');
    $computerBefore = $state($computer);
    $linked = 'port_identifier_link';
    $descendingName = 'port_identifier_descending';
    $steppedName = 'port_identifier_stepped';
    foreach ([$linked, $descendingName, $steppedName] as $name) {
        verify(!$manager->tablesExist([$name]), 'Own sequence fixture must not exist: ' . $name);
    }
    $fixtures = new FixtureRecords($DB);
    $imported = $generated = null;
    try {
        $connection->executeStatement('CREATE TABLE ' . $quote($linked) . ' (users_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY REFERENCES glpi_users(id))');
        $connection->insert($linked, ['users_id' => 2]);
        $child = $owned($namespace, $linked, 'users_id');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($child['schema'], $child['name']) . ' AS integer RESTART WITH 101');
        $childBefore = $state($child);
        $connection->executeStatement('CREATE TABLE ' . $quote($descendingName) . ' (id BIGINT GENERATED BY DEFAULT AS IDENTITY (START WITH -1 INCREMENT BY -3 MAXVALUE -1) PRIMARY KEY)');
        $descending = $owned($namespace, $descendingName, 'id');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($namespace, $descending['name']) . ' RESTART WITH -50');
        $connection->insert($descendingName, ['id' => -20]);
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $descending['name'])]) === -50, 'Public-schema custom descending generator has a reserved lower ID');
        $descendingBefore = $state($descending);
        $connection->executeStatement('CREATE TABLE ' . $quote($steppedName) . ' (id BIGINT GENERATED BY DEFAULT AS IDENTITY (START WITH 17 INCREMENT BY 3) PRIMARY KEY)');
        $stepped = $owned($namespace, $steppedName, 'id');
        $connection->insert($steppedName, ['id' => 18]);
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $stepped['name'])]) === 17, 'Custom stepped generator has reserved17 and safe next20');
        $steppedBefore = $state($stepped);
        $assigned = max(4294967501, 100 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_computers'));
        $imported = $fixtures->create('glpi_computers', ['id' => $assigned, 'name' => 'Imported sequence-width fixture']);
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($computer['schema'], $computer['name']) . ' AS integer RESTART WITH 2147483646');
        $narrow = $state($computer);
        verify(Type::lookupName($manager->introspectTable('glpi_computers')->getColumn('id')->getType()) === 'bigint' && $narrow['type'] === 'integer', 'Regression: BIGINT column remains backed by narrow SERIAL');
        $legacy = (new References())->plan($connection);
        verify($legacy['complete'] && $legacy['identifiers'] === [] && $legacy['stages'] === [], 'Completed old ledger has an empty plan despite the real generator defect');
        $operations = (new WideIdentifiers(['glpi_computers' => ['id']]))->plan($connection);
        verify(count($operations) === 1 && $operations[0]['sql'] === 'ALTER SEQUENCE ' . $qualified($computer['schema'], $computer['name']) . ' AS bigint', 'Width planner independently repairs a narrow sequence without a column transition');
        $connection->delete(Ledger::TABLE, ['version' => $version]);
        $receipts = Ledger::states($connection);
        $preview = (new History())->plan($connection);
        verify($preview['pending'] === [$version] && count($preview['identifier_sequences']) === 2, 'Actual canonical history discovers new repair after completed old adoption, including real core-FK identity');
        verify(Ledger::states($connection) === $receipts && $state($computer) === $narrow && $state($child) === $childBefore, 'Canonical preview neither journals nor mutates sequences');
        try {
            (new History())->upgrade($connection, static function (string $step): void {
                if (str_starts_with($step, 'ALTER SEQUENCE ')) {
                    throw new RuntimeException('Injected sequence-width migration interruption');
                }
            });
            throw new LogicException('Expected real migration interruption');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Injected sequence-width migration interruption', 'Actual history surfaces sequence DDL interruption');
        }
        verify(Ledger::state($connection, $version) === null && $state($computer) === $narrow && $state($child) === $childBefore, 'PostgreSQL rolls back sequence DDL and partial receipt together');
        (new History())->upgrade($connection);
        verify(Ledger::state($connection, $version)['complete'] && $state($computer)['type'] === 'bigint' && $state($child)['type'] === 'bigint', 'Actual retry completes the appended canonical repair');
        verify($state($descending) === $descendingBefore && $state($stepped) === $steppedBefore, 'Actual history synchronization preserves custom descending reservations and an already-safe stepped next value');
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $descending['name'])]) === -53 && (int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $stepped['name'])]) === 20, 'Native custom generators retain their actual increments after canonical history');
        $connection->insert($descendingName, ['id' => -100]);
        SequenceSynchronizer::synchronize($connection);
        verify((int)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $descending['name'])]) === -103 && $state($descending)['type'] === 'bigint', 'Ordinary synchronization advances past imported descending MIN using the native increment without DDL');
        foreach ([[$descendingName, $descending, '-110', '-113'], [$steppedName, $stepped, '26', '29']] as [$table, $sequence, $restart, $next]) {
            $connection->executeStatement('ALTER SEQUENCE ' . $qualified($namespace, $sequence['name']) . ' RESTART WITH ' . $restart);
            $connection->insert($table, ['id' => $restart]);
            verify(!$state($sequence)['called'], 'Restart leaves custom generator unused before imported-equality synchronization');
            SequenceSynchronizer::synchronize($connection);
            verify((string)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $sequence['name'])]) === $next, 'Imported equality consumes the reserved unused value and retains its native signed increment');
        }
        foreach ([[$descending, '-9223372036854775808'], [$stepped, '9223372036854775807']] as [$sequence, $boundary]) {
            $connection->executeStatement('ALTER SEQUENCE ' . $qualified($namespace, $sequence['name']) . ' RESTART WITH ' . $boundary);
            verify((string)$connection->fetchOne('SELECT nextval(?::regclass)', [$qualified($namespace, $sequence['name'])]) === $boundary, 'Native generator can reserve its exact BIGINT boundary');
            $reservedBoundary = $state($sequence);
            SequenceSynchronizer::synchronize($connection);
            verify($state($sequence) === $reservedBoundary, 'Numeric comparison beyond signed BIGINT range preserves the existing boundary reservation');
        }
        $generated = $fixtures->create('glpi_computers', ['name' => 'Generated sequence-width fixture']);
        verify($generated > $assigned && $connection->fetchOne('SELECT name FROM glpi_computers WHERE id = ?', [$imported]) === 'Imported sequence-width fixture', 'Real ORM allocation exceeds imported unsigned-32-bit IDs without losing original data');
        try {
            $connection->insert($linked, ['users_id' => 1999999999]);
            throw new LogicException('Sequence migration discarded the actual FK');
        } catch (ForeignKeyConstraintViolationException) {
        }
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($computer['schema'], $computer['name']) . ' AS integer RESTART WITH 2147483646');
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($child['schema'], $child['name']) . ' AS integer RESTART WITH 101');
        $computerNarrow = $state($computer);
        $childNarrow = $state($child);
        $operation = static fn (string $sql): array => ['sql' => $sql, 'kind' => 'sql', 'table' => '', 'name' => ''];
        $captured = [
            $operation($platform->getCommentOnColumnSQL($linked, 'users_id', 'Captured identifier conversion')),
            $operation('ALTER SEQUENCE ' . $qualified($child['schema'], $child['name']) . ' AS bigint'),
        ];
        $connection->executeStatement($captured[0]['sql']);
        $olderJournal = ['complete' => false, 'identifiers' => $captured, 'next' => 1];
        Ledger::save($connection, References::PHASE, $olderJournal);
        $preview = (new References())->plan($connection);
        verify(count($preview['identifiers']) === 2 && $preview['identifiers'][0] === $captured[1]
            && $preview['identifiers'][1]['sql'] === 'ALTER SEQUENCE ' . $qualified($computer['schema'], $computer['name']) . ' AS bigint', 'Older incomplete preview retains captured pending operation and appends only the omitted owned generator');
        verify(Ledger::state($connection, References::PHASE) === $olderJournal && $state($computer) === $computerNarrow && $state($child) === $childNarrow, 'Older journal preview preserves captured prefix, next position, receipt and generators');
        $sawAppend = false;
        try {
            (new History())->upgrade($connection, static function (string $step) use ($connection, $captured, $state, $computer, $child, &$sawAppend): void {
                if ($step === 'Widening identifiers and preserving existing constraints') {
                    $journal = Ledger::state($connection, References::PHASE);
                    verify(array_slice($journal['identifiers'], 0, 2) === $captured && count($journal['identifiers']) === 3 && $journal['next'] === 1, 'Actual retry journals the append without rewriting original prefix or progress');
                    $sawAppend = true;
                }
                if ($step === 'Installing audited foreign keys') {
                    verify($state($computer)['type'] === 'bigint' && $state($child)['type'] === 'bigint', 'Older journal interruption follows both real sequence repairs');
                    throw new RuntimeException('Injected older sequence journal interruption');
                }
            });
            throw new LogicException('Expected older journal interruption');
        } catch (RuntimeException $error) {
            verify($error->getMessage() === 'Injected older sequence journal interruption', 'Actual older-journal retry surfaces its post-DDL interruption');
        }
        verify($sawAppend && Ledger::state($connection, References::PHASE) === $olderJournal && $state($computer) === $computerNarrow && $state($child) === $childNarrow, 'PostgreSQL rolls back the appended journal, old progress and both sequence changes together');
        (new History())->upgrade($connection);
        verify((new References())->plan($connection)['complete'] && $state($computer)['type'] === 'bigint' && $state($child)['type'] === 'bigint', 'Older omitted-sequence journal resumes through strict canonical convergence');
        $receipt = Ledger::state($connection, $version);
        $stage->apply($connection, static fn () => throw new LogicException('Completed replay executed sequence DDL'));
        verify($stage->plan($connection) === [] && Ledger::state($connection, $version) === $receipt && (new WideIdentifiers())->plan($connection) === [], 'Completed receipt and full width planner converge without repeated DDL');
    } finally {
        if ($generated !== null) {
            $connection->delete('glpi_computers', ['id' => $generated]);
        }
        if ($imported !== null) {
            $connection->delete('glpi_computers', ['id' => $imported]);
        }
        foreach ([$linked, $descendingName, $steppedName] as $name) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $quote($name));
        }
        $connection->executeStatement('ALTER SEQUENCE ' . $qualified($computer['schema'], $computer['name']) . ' AS bigint');
        // Fixture cleanup restores its pre-test allocation state, not production
        // synchronization behavior: the owned disposable database has no writers.
        $connection->executeStatement('SELECT setval(?::regclass, ?, ?)', [$qualified($computer['schema'], $computer['name']), $computerBefore['value'], $computerBefore['called']], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        Ledger::save($connection, References::PHASE, $originalLegacyReceipt);
        Ledger::save($connection, $version, $originalReceipt);
    }
}
verify((new SchemaCheck())->differences($connection) === [], 'Fixture cleanup retains canonical core schema');
echo $DB->getProvider() . ": " . $assertions . " assertions passed: independent SERIAL/IDENTITY width repair, quoted ownership/FK scope, allocation parameters and reservations, completed-ledger canonical retry, wide ORM allocation, untouched unrelated generators and native MySQL no-op receipt.\n";
