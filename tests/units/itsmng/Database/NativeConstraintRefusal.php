<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units;

require_once dirname(__DIR__, 3) . '/database-portability/fixtures/NativeConstraintRefusal.php';

use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;
use NativeConstraintRefusal as Refusal;
use PDOException;
use RuntimeException;

class NativeConstraintRefusal extends \atoum\atoum\test
{
    public function testMysqliMissingColumnAndOriginalIntegrityStates(): void
    {
        $message = "Field 'itemtype' doesn't have a default value";
        $make = static fn (string $class, string $text = "Field 'itemtype' doesn't have a default value", int $code = 1364, ?string $state = 'HY000'): DriverException =>
            new $class(new ConstraintRefusalDriverFixture($text, $code, $state), null);
        $missing = $make(NotNullConstraintViolationException::class);
        $this->boolean(Refusal::matches($missing, 'itemtype'))->isTrue('Exact converted native omitted-discriminator refusal recognized');
        $this->boolean(!Refusal::matches($missing))->isTrue('Unselected variant does not accept native missing-column refusal');
        $this->boolean(!Refusal::matches($missing, 'users_id'))->isTrue('Different expected required column rejected');
        $this->boolean(!Refusal::matches($make(DriverException::class), 'itemtype'))->isTrue('Generic HY000 driver exception rejected even with the same code and text');
        $this->boolean(!Refusal::matches($make(NotNullConstraintViolationException::class, code: 1048), 'itemtype'))->isTrue('Different native error code does not enter omitted-column exception');
        $this->boolean(!Refusal::matches($make(NotNullConstraintViolationException::class, state: 'HY001'), 'itemtype'))->isTrue('Different generic state rejected');
        $this->boolean(!Refusal::matches($make(NotNullConstraintViolationException::class, text: "Field 'name' doesn't have a default value"), 'itemtype'))->isTrue('Wrong actual missing field rejected');
        $this->boolean(!Refusal::matches($make(NotNullConstraintViolationException::class, text: 'Unexpected native failure: ' . $message), 'itemtype'))->isTrue('Embedded matching text is insufficient');
        foreach (['23502', '23503', '23514', '23505', '23001', '23000'] as $state) {
            $this->boolean(Refusal::matches($make(DriverException::class, state: $state)))->isTrue('Original integrity-state recognition retained: ' . $state);
        }
    }

    public function testMysqliCheckRequiresExactOwnedImmediateCause(): void
    {
        $make = static fn (string $class, string $text = "Field 'itemtype' doesn't have a default value", int $code = 1364, ?string $state = 'HY000'): DriverException =>
            new $class(new ConstraintRefusalDriverFixture($text, $code, $state), null);
        $missing = $make(NotNullConstraintViolationException::class);
        $ownedCheck = 'owned_fixture_selection';
        $checkMessage = "Check constraint '$ownedCheck' is violated.";
        $makeCheck = static fn (string $class = DriverException::class, ?string $text = null, int $code = 3819, ?string $state = 'HY000'): DriverException =>
            new $class(new StatementError($text ?? $checkMessage, $state, $code), null);
        $check = $makeCheck();
        $this->boolean(Refusal::matchesSelectedCheck($check, $ownedCheck))->isTrue('Exact selected official MySQL CHECK refusal recognized');
        $this->boolean(!Refusal::matchesSelectedCheck($check, null))->isTrue('Unselected CHECK cannot admit generic HY000');
        $this->boolean(!Refusal::matchesSelectedCheck($check, ''))->isTrue('An empty scope cannot select a CHECK');
        $this->boolean(!Refusal::matchesSelectedCheck($check, 'another_selection'))->isTrue('A different expected CHECK rejected');
        $this->boolean(!Refusal::matches($check, 'itemtype'))->isTrue('CHECK does not enter the independent missing-column branch');
        $this->boolean(!Refusal::matchesSelectedCheck($missing, $ownedCheck))->isTrue('Missing-column cause is not a CHECK');
        $this->boolean(!Refusal::matchesSelectedCheck($make(DriverException::class, text: $checkMessage, code: 3819), $ownedCheck))->isTrue('A different immediate driver cause cannot impersonate mysqli CHECK refusal');
        foreach ([NotNullConstraintViolationException::class, ForeignKeyConstraintViolationException::class, UniqueConstraintViolationException::class] as $class) {
            $this->boolean(!Refusal::matchesSelectedCheck($makeCheck($class), $ownedCheck))->isTrue('Different converted constraint class rejected: ' . $class);
        }
        foreach ([1364, 4025, 1452, 1062] as $code) {
            $this->boolean(!Refusal::matchesSelectedCheck($makeCheck(code: $code), $ownedCheck))->isTrue('Different native code cannot enter official CHECK branch: ' . $code);
        }
        foreach (['HY001', '23000', '23514', null] as $state) {
            $this->boolean(!Refusal::matchesSelectedCheck($makeCheck(state: $state), $ownedCheck))->isTrue('Different native SQLSTATE cannot enter official CHECK branch');
        }
        foreach (["Check constraint 'another_selection' is violated.", 'Query mentions ' . $checkMessage, $checkMessage . ' Extra text', str_replace('Check', 'check', $checkMessage)] as $text) {
            $this->boolean(!Refusal::matchesSelectedCheck($makeCheck(text: $text), $ownedCheck))->isTrue('Only the complete immediate native owned-CHECK message is accepted');
        }
        $unrelated = new DriverException(new StatementError('An unrelated native failure', 'HY000', 3819), new Query('SELECT ?', [$checkMessage], []));
        $this->boolean(!Refusal::matchesSelectedCheck($unrelated, $ownedCheck))->isTrue('A matching CHECK message in query parameters is insufficient');

    }

    public function testPdoErrorInfoMustMatchOwnedColumnAndCheck(): void
    {
        $message = "Field 'itemtype' doesn't have a default value";
        $ownedCheck = 'owned_fixture_selection';
        $checkMessage = "Check constraint '$ownedCheck' is violated.";
        // PDO supplies the real diagnostic vector independently of its rendered prefix.
        $makePdo = static function (string $class, string $text, int $code, string $state, ?array $information = null): DriverException {
            $native = new PDOException("SQLSTATE[$state]: General error: $code $text");
            $native->errorInfo = $information ?? [$state, $code, $text];
            return new $class(new \Doctrine\DBAL\Driver\PDO\Exception($native->getMessage(), $state, $code, $native), null);
        };
        $pdoMissing = $makePdo(NotNullConstraintViolationException::class, $message, 1364, 'HY000');
        $this->boolean(Refusal::matches($pdoMissing, 'itemtype'))->isTrue('Exact converted PDO omitted-column vector recognized despite native rendered prefix');
        $this->boolean(!Refusal::matches($pdoMissing, 'users_id'))->isTrue('PDO native omitted-column vector remains selected by exact required field');
        $this->boolean(!Refusal::matches($makePdo(DriverException::class, $message, 1364, 'HY000'), 'itemtype'))->isTrue('PDO omitted-column vector cannot bypass converted constraint class');
        $pdoCheck = $makePdo(DriverException::class, $checkMessage, 3819, 'HY000');
        $this->boolean(Refusal::matchesSelectedCheck($pdoCheck, $ownedCheck))->isTrue('Exact typed DBAL PDO/native PDO owned-CHECK vector recognized');
        $this->boolean(!Refusal::matchesSelectedCheck($pdoCheck, 'another_selection'))->isTrue('PDO CHECK remains selected by exact owned constraint');
        $this->boolean(!Refusal::matchesSelectedCheck($pdoCheck, null))->isTrue('Unselected PDO CHECK remains refused');
        foreach ([
            ['HY001', 3819, $checkMessage],
            ['HY000', 4025, $checkMessage],
            ['HY000', 3819, "Check constraint 'another_selection' is violated."],
            ['HY000', 3819, 'Query mentions ' . $checkMessage],
            ['HY000', 3819, $checkMessage . ' Extra text'],
            ['HY000', 3819],
            ['HY000', 3819, $checkMessage, 'unexpected'],
        ] as $information) {
            $this->boolean(!Refusal::matchesSelectedCheck($makePdo(DriverException::class, $checkMessage, 3819, 'HY000', $information), $ownedCheck))->isTrue('Malformed/mismatched/unowned PDO errorInfo cannot impersonate selected CHECK refusal');
        }
        $withoutNative = new DriverException(new \Doctrine\DBAL\Driver\PDO\Exception($checkMessage, 'HY000', 3819), null);
        $this->boolean(!Refusal::matchesSelectedCheck($withoutNative, $ownedCheck))->isTrue('Typed PDO driver alone cannot fabricate the absent native cause');
        foreach ([NotNullConstraintViolationException::class, ForeignKeyConstraintViolationException::class, UniqueConstraintViolationException::class] as $class) {
            $this->boolean(!Refusal::matchesSelectedCheck($makePdo($class, $checkMessage, 3819, 'HY000'), $ownedCheck))->isTrue('PDO CHECK vector retains exact converted-class requirement');
        }
    }

    public function testPdoTypedSubjectAndGeneratedProjectionDiagnostics(): void
    {
        // PDO supplies the real diagnostic vector independently of its rendered prefix.
        $makePdo = static function (string $class, string $text, int $code, string $state, ?array $information = null): DriverException {
            $native = new PDOException("SQLSTATE[$state]: General error: $code $text");
            $native->errorInfo = $information ?? [$state, $code, $text];
            return new $class(new \Doctrine\DBAL\Driver\PDO\Exception($native->getMessage(), $state, $code, $native), null);
        };
        $ownedCheck = 'owned_fixture_selection';
        $checkMessage = "Check constraint '$ownedCheck' is violated.";
        // Synthetic typed cause controls below are unit examples, not live native evidence.
        $table = 'glpi_items_softwareversions';
        $database = 'itsm_port_unit';
        $constraint = $table . '_typed_item_kind';
        $pgCheckText = 'ERROR:  new row for relation "' . $table . '" violates check constraint "' . $constraint . '"' . "\nDETAIL:  Failing row contains owned unit values.";
        $pgCheck = $makePdo(DriverException::class, $pgCheckText, 7, '23514');
        $this->boolean(Refusal::matchesTypedSubjectCheck($pgCheck, $table, $database))->isTrue('Typed PDO PG selected CHECK primary diagnostic recognized with separate DETAIL');
        $this->boolean(!Refusal::matchesTypedSubjectCheck($pgCheck, 'glpi_items_softwarelicenses', $database))->isTrue('PDO PG CHECK requires the actual selected table and constraint');
        $this->boolean(!Refusal::matchesGeneratedProjection($pgCheck, $table, 'INSERT'))->isTrue('Selected CHECK cannot impersonate generated projection refusal');
        foreach (['ERROR:  ' . $checkMessage, 'Query mentions ' . $pgCheckText, explode("\n", $pgCheckText, 2)[0] . ' Extra primary text', str_replace($constraint, 'another_constraint', $pgCheckText)] as $text) {
            $this->boolean(!Refusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $text, 7, '23514'), $table, $database))->isTrue('Wrong/embedded/extended selected CHECK primary diagnostic refused');
        }
        foreach ([[23514, 7, $pgCheckText], ['23514', 7], ['23514', 8, $pgCheckText], ['23514', 7, $pgCheckText, 'extra']] as $info) {
            $this->boolean(!Refusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $pgCheckText, 7, '23514', $info), $table, $database))->isTrue('Malformed or mismatched typed PDO selected-CHECK vector refused');
        }
        $mariaCheck = 'CONSTRAINT `' . $constraint . '` failed for `' . $database . '`.`' . $table . '`';
        $this->boolean(Refusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $mariaCheck, 4025, '23000'), $table, $database))->isTrue('Typed PDO Maria selected CHECK requires exact4025/23000 native database and table');
        $this->boolean(!Refusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $mariaCheck, 4025, '23000'), $table, 'another_database'))->isTrue('PDO Maria CHECK refuses another database');
        foreach (['INSERT' => 'cannot insert a non-DEFAULT value into column "items_id"', 'UPDATE' => 'column "items_id" can only be updated to DEFAULT'] as $operation => $primary) {
            $generated = $makePdo(DriverException::class, 'ERROR:  ' . $primary . "\nDETAIL:  Column is a generated column.", 7, '428C9');
            $this->boolean(Refusal::matchesGeneratedProjection($generated, $table, $operation))->isTrue('Typed PDO PG selected generated action recognized: ' . $operation);
            $this->boolean(!Refusal::matchesGeneratedProjection($generated, $table, $operation === 'INSERT' ? 'UPDATE' : 'INSERT'))->isTrue('PDO PG generated action cannot impersonate the other operation');
            $this->boolean(!Refusal::matchesGeneratedProjection($generated, $table, 'DELETE'))->isTrue('Unknown generated operation refused');
            $this->boolean(!Refusal::matchesTypedSubjectCheck($generated, $table, $database))->isTrue('Generated native cause cannot impersonate CHECK');
        }
        foreach ([1906 => "The value specified for generated column 'items_id' in table '" . $table . "' has been ignored", 3105 => "The value specified for generated column 'items_id' in table '" . $table . "' is not allowed."] as $code => $text) {
            $generated = $makePdo(DriverException::class, $text, $code, 'HY000');
            $this->boolean(Refusal::matchesGeneratedProjection($generated, $table, 'INSERT'))->isTrue('PDO precise generated cause code and native diagnostic recognized: ' . $code);
            $this->boolean(!Refusal::matchesGeneratedProjection($generated, 'another_table', 'INSERT'))->isTrue('PDO generated cause requires selected native table');
            $this->boolean(!Refusal::matchesGeneratedProjection($makePdo(DriverException::class, $text, 3819, 'HY000'), $table, 'INSERT'))->isTrue('An unrelated HY000 code cannot enter generated projection branch');
            $this->boolean(!Refusal::matchesGeneratedProjection($makePdo(DriverException::class, 'Query mentions ' . $text, $code, 'HY000'), $table, 'INSERT'))->isTrue('Rendered/query text cannot enter generated projection branch');
        }
    }
}

final class ConstraintRefusalDriverFixture extends RuntimeException implements \Doctrine\DBAL\Driver\Exception
{
    public function __construct(string $message, int $code, private ?string $state)
    {
        parent::__construct($message, $code);
    }

    public function getSQLState(): ?string
    {
        return $this->state;
    }
}
