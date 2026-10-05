<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Entity\ITILCategory as CategoryEntity;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\CategoryFlags;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketCategoryRepository;
use itsmng\Database\SchemaCheck;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/category-booleans.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated disposable database required');
$connection = $DB->getDoctrineConnection();
$platform = $connection->getDatabasePlatform();
$postgres = $platform instanceof PostgreSQLPlatform;
$manager = $connection->createSchemaManager();
$migration = new CategoryFlags();
$fields = ['is_incident', 'is_request', 'is_problem'];
$id = 2147483600;
verify(!$connection->fetchOne('SELECT COUNT(*) FROM glpi_itilcategories WHERE id = ?', [$id]), 'Fixture identifier is unoccupied');
$testComment = "Category flag's domain";
$comment = $manager->listTableColumns('glpi_itilcategories')['is_incident']->getComment();
$frozen = (new Baseline())->toSql($platform);
$metadata = Orm::create($DB)->getClassMetadata(CategoryEntity::class);
foreach ($fields as $field) {
    verify($metadata->getTypeOfField($field) === 'boolean' && (new ReflectionProperty(CategoryEntity::class, $field))->getType()->getName() === 'bool', 'Property owns boolean semantics: ' . $field);
}
$metadata->fieldMappings['is_incident']->type = 'integer';
verify((new Baseline())->toSql($platform) === $frozen, 'Current category metadata cannot rewrite historical baseline');
$connection->insert('glpi_itilcategories', ['id' => $id, 'name' => 'Boolean category', 'completename' => 'Boolean category', 'entities_id' => 0]);
try {
    $connection->delete(Ledger::TABLE, ['version' => CategoryFlags::PHASE]);
    foreach ($fields as $field) {
        if ($postgres) {
            $connection->executeStatement('ALTER TABLE glpi_itilcategories ALTER COLUMN ' . $field . ' DROP DEFAULT, ALTER COLUMN ' . $field . ' TYPE INTEGER USING (CASE WHEN ' . $field . ' THEN 1 ELSE 0 END), ALTER COLUMN ' . $field . ' SET DEFAULT 1');
        } else {
            $connection->executeStatement('ALTER TABLE glpi_itilcategories DROP CONSTRAINT glpi_itilcategories_' . $field . '_boolean');
        }
    }
    if ($postgres) {
        $connection->executeStatement($platform->getCommentOnColumnSQL('glpi_itilcategories', 'is_incident', $testComment));
    } else {
        $connection->executeStatement('ALTER TABLE glpi_itilcategories MODIFY COLUMN is_incident SMALLINT UNSIGNED NOT NULL DEFAULT 1 ' . $platform->getInlineColumnCommentSQL($testComment));
        $connection->executeStatement('ALTER TABLE glpi_itilcategories MODIFY COLUMN is_request INTEGER UNSIGNED NOT NULL DEFAULT 1');
    }
    $connection->update('glpi_itilcategories', ['is_incident' => 0, 'is_request' => 1, 'is_problem' => 2], ['id' => $id]);
    $before = $manager->listTableColumns('glpi_itilcategories');
    try {
        $migration->apply($connection);
        throw new LogicException('Invalid historical flag was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Invalid category flag: glpi_itilcategories.is_problem') && str_contains($error->getMessage(), (string)$id), 'Diagnostic names the invalid field and row');
    }
    verify(Ledger::state($connection, CategoryFlags::PHASE) === null && Type::lookupName($manager->listTableColumns('glpi_itilcategories')['is_incident']->getType()) === Type::lookupName($before['is_incident']->getType()), 'All flags are validated before any DDL or journal');
    $connection->update('glpi_itilcategories', ['is_problem' => 0], ['id' => $id]);
    if ($postgres) {
        $connection->executeStatement('ALTER TABLE glpi_itilcategories ALTER COLUMN is_problem DROP NOT NULL');
    } else {
        $connection->executeStatement('ALTER TABLE glpi_itilcategories MODIFY COLUMN is_problem INTEGER NULL DEFAULT 1');
    }
    $connection->update('glpi_itilcategories', ['is_problem' => null], ['id' => $id]);
    try {
        $migration->plan($connection);
        throw new LogicException('NULL required flag was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'Invalid category flag: glpi_itilcategories.is_problem'), 'NULL diagnostics preserve required semantics instead of coercing data');
    }
    $connection->update('glpi_itilcategories', ['is_problem' => 0], ['id' => $id]);
    $steps = 0;
    try {
        $migration->apply($connection, static function () use (&$steps): void {
            if (++$steps === 1) {
                throw new RuntimeException('Injected category DDL interruption');
            }
        });
        throw new LogicException('Interruption did not execute');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === 'Injected category DDL interruption', 'Interrupted DDL is surfaced');
    }
    verify($postgres ? Ledger::state($connection, CategoryFlags::PHASE) === null : !Ledger::state($connection, CategoryFlags::PHASE)['complete'], 'Provider-specific rollback or retry journal survives correctly');
    $migration->apply($connection);
    verify($migration->plan($connection) === [] && Ledger::state($connection, CategoryFlags::PHASE)['complete'], 'Interrupted conversion resumes through the canonical ledger');
    if ($platform instanceof MySQLPlatform) {
        $connection->delete(Ledger::TABLE, ['version' => CategoryFlags::PHASE]);
        $connection->executeStatement('ALTER TABLE glpi_itilcategories ALTER CHECK glpi_itilcategories_is_request_boolean NOT ENFORCED');
        $plan = $migration->plan($connection);
        verify(count($plan) === 1 && str_contains($plan[0], ' ENFORCED'), 'MySQL preview detects an existing inactive CHECK');
        $migration->apply($connection);
        verify($connection->fetchOne("SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'glpi_itilcategories' AND CONSTRAINT_NAME = 'glpi_itilcategories_is_request_boolean'") === 'YES', 'MySQL retry re-enforces the actual flag constraint');
    }
    $row = $connection->fetchAssociative('SELECT is_incident, is_request, is_problem FROM glpi_itilcategories WHERE id = ?', [$id]);
    verify((int)$row['is_incident'] === 0 && (int)$row['is_request'] === 1 && (int)$row['is_problem'] === 0, 'Mixed flag values survive conversion');
    verify($manager->listTableColumns('glpi_itilcategories')['is_incident']->getComment() === $testComment, 'Flag conversion preserves the original comment');
    foreach ($fields as $field) {
        $column = $manager->listTableColumns('glpi_itilcategories')[$field];
        verify($column->getNotnull() && (bool)(int)$column->getDefault(), 'Required true default is restored: ' . $field);
        if ($postgres) {
            verify(Type::lookupName($column->getType()) === 'boolean', 'PostgreSQL has real native flag type: ' . $field);
        }
        try {
            $connection->executeStatement('UPDATE glpi_itilcategories SET ' . $field . ' = 2 WHERE id = ?', [$id]);
            throw new LogicException('Native nonboolean flag was accepted');
        } catch (Doctrine\DBAL\Exception $error) {
            verify(true, 'Native type/constraint rejects a nonboolean flag');
        }
    }
    $em = Orm::create($DB);
    $category = $em->find(CategoryEntity::class, $id);
    verify($category->is_incident === false && $category->is_request === true && $category->is_problem === false, 'Doctrine hydration retains actual booleans');
    verify(isset((new TicketCategoryRepository($em))->choices(Ticket::DEMAND_TYPE, [0], false)[$id]) && !isset((new TicketCategoryRepository($em))->choices(Ticket::INCIDENT_TYPE, [0], false)[$id]), 'Domain choices use mapped boolean criteria');
    $em->clear();
    if ($postgres) {
        $connection->executeStatement($platform->getCommentOnColumnSQL('glpi_itilcategories', 'is_incident', $comment));
    } else {
        $connection->executeStatement('ALTER TABLE glpi_itilcategories MODIFY COLUMN is_incident INTEGER NOT NULL DEFAULT 1 ' . $platform->getInlineColumnCommentSQL($comment));
        $connection->delete(Ledger::TABLE, ['version' => CategoryFlags::PHASE]);
        $connection->executeStatement('ALTER TABLE glpi_itilcategories DROP CONSTRAINT glpi_itilcategories_is_incident_boolean');
        $connection->executeStatement('ALTER TABLE glpi_itilcategories ADD CONSTRAINT glpi_itilcategories_is_incident_boolean CHECK (1 = 1)');
        try {
            $migration->plan($connection);
            throw new LogicException('Conflicting flag constraint was accepted');
        } catch (RuntimeException $error) {
            verify(str_contains($error->getMessage(), 'Conflicting category flag constraint'), 'Retry refuses a same-name constraint with another domain');
        }
        $connection->executeStatement('ALTER TABLE glpi_itilcategories DROP CONSTRAINT glpi_itilcategories_is_incident_boolean');
        $migration->apply($connection);
    }
    verify((new SchemaCheck())->differences($connection) === [], 'Converted category schema converges on property-derived inspection');
    $state = Ledger::state($connection, CategoryFlags::PHASE);
    $migration->apply($connection);
    verify(Ledger::state($connection, CategoryFlags::PHASE) === $state, 'Completed retry is idempotent');
    if (!$postgres) {
        verify(!$connection->isTransactionActive(), 'ANSI historical CHECK fixture owns an idle DDL connection');
        $originalMode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
        $originalReceipt = Ledger::state($connection, CategoryFlags::PHASE);
        $originalChecks = BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'];
        $originalValues = $connection->fetchAssociative('SELECT is_incident, is_request, is_problem FROM glpi_itilcategories WHERE id = ?', [$id]);
        $constraint = 'glpi_itilcategories_is_incident_boolean';
        $checkChanged = false;
        try {
            $connection->executeStatement('SET SESSION sql_mode = ?', [$originalMode . ',ANSI_QUOTES']);
            $ansiMode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
            $incomplete = ['complete' => false];
            Ledger::save($connection, CategoryFlags::PHASE, $incomplete);
            $ansiChecks = BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'];
            verify($migration->plan($connection) === [] && Ledger::state($connection, CategoryFlags::PHASE) === $incomplete, 'Incomplete Category receipt previews existing valid ANSI CHECKs without DDL or receipt changes');
            verify(BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'] === $ansiChecks
                && (string)$connection->fetchOne('SELECT @@SESSION.sql_mode') === $ansiMode, 'Category native inspection retains exact CHECKs and supplied mode');
            $migration->apply($connection);
            $completed = Ledger::state($connection, CategoryFlags::PHASE);
            verify(($completed['complete'] ?? false) === true && $migration->plan($connection) === [], 'Existing ANSI CHECKs resume through the original canonical Category receipt');
            $migration->apply($connection);
            verify(Ledger::state($connection, CategoryFlags::PHASE) === $completed, 'ANSI Category completion retry is idempotent');

            // The former parenthesis-stripping inspector mistook this different
            // AST for the generated CHECK; native flag2 really satisfies it.
            Ledger::save($connection, CategoryFlags::PHASE, $incomplete);
            $connection->executeStatement('ALTER TABLE glpi_itilcategories DROP ' . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $platform->quoteIdentifier($constraint));
            $checkChanged = true;
            $connection->executeStatement('ALTER TABLE glpi_itilcategories ADD CONSTRAINT ' . $platform->quoteIdentifier($constraint) . ' CHECK ((is_incident IS NOT NULL AND is_incident) IN (0,1))');
            $connection->update('glpi_itilcategories', ['is_incident' => 2], ['id' => $id]);
            verify((int)$connection->fetchOne('SELECT is_incident FROM glpi_itilcategories WHERE id = ?', [$id]) === 2, 'Historical lookalike actually admits an invalid native scalar');
            $connection->update('glpi_itilcategories', $originalValues, ['id' => $id]);
            $lookalike = BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'];
            foreach ([static fn () => $migration->plan($connection), static fn () => $migration->apply($connection)] as $attempt) {
                try {
                    $attempt();
                    throw new LogicException('Permissive parenthesis lookalike was accepted');
                } catch (RuntimeException $error) {
                    verify(str_contains($error->getMessage(), 'Conflicting category flag constraint'), 'Exact Category inspector refuses permissive precedence despite matching stripped tokens');
                }
                verify(Ledger::state($connection, CategoryFlags::PHASE) === $incomplete
                    && BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'] === $lookalike
                    && $connection->fetchAssociative('SELECT is_incident, is_request, is_problem FROM glpi_itilcategories WHERE id = ?', [$id]) === $originalValues, 'Lookalike refusal preserves values, CHECKs and incomplete receipt before DDL');
            }
        } finally {
            $connection->executeStatement('SET SESSION sql_mode = ?', [$originalMode]);
            $connection->update('glpi_itilcategories', $originalValues, ['id' => $id]);
            if ($checkChanged) {
                $exists = $connection->fetchOne("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'glpi_itilcategories' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$constraint]);
                if ($exists) {
                    $connection->executeStatement('ALTER TABLE glpi_itilcategories DROP ' . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $platform->quoteIdentifier($constraint));
                }
                $check = $originalChecks[$constraint];
                $connection->executeStatement('ALTER TABLE glpi_itilcategories ADD CONSTRAINT ' . $platform->quoteIdentifier($constraint) . ' CHECK (' . $check['clause'] . ')'
                    . ($platform instanceof MySQLPlatform ? ' ' . ($check['enforced'] === 'YES' ? 'ENFORCED' : 'NOT ENFORCED') : ''));
            }
            Ledger::save($connection, CategoryFlags::PHASE, $originalReceipt);
        }
        verify((string)$connection->fetchOne('SELECT @@SESSION.sql_mode') === $originalMode
            && Ledger::state($connection, CategoryFlags::PHASE) === $originalReceipt
            && BooleanDomainSchema::catalog($connection)['checks']['glpi_itilcategories'] === $originalChecks, 'ANSI historical fixture restores exact native CHECKs, receipt and SESSION mode');
        verify((new SchemaCheck())->differences($connection) === [], 'Restored Category schema retains current property-derived checks');
    }
} finally {
    $connection->delete('glpi_itilcategories', ['id' => $id]);
    // If an assertion fails mid-conversion, retain valid data and complete the
    // actual migration so the following suite contracts see the intended schema.
    $migration->apply($connection);
    $DB->clearSchemaCache();
}
echo $DB->getProvider() . ": category property booleans, frozen upgrade, invalid/NULL diagnostics, interrupted DDL recovery, native rejection, domain choices and schema convergence passed.\n";
