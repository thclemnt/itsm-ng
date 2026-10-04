<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// No connection or application bootstrap: guard the diagnostic classification.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;
use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;

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

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$message = "Field 'itemtype' doesn't have a default value";
$make = static fn (string $class, string $text = "Field 'itemtype' doesn't have a default value", int $code = 1364, ?string $state = 'HY000'): DriverException =>
    new $class(new ConstraintRefusalDriverFixture($text, $code, $state), null);
$missing = $make(NotNullConstraintViolationException::class);
verify(NativeConstraintRefusal::matches($missing, 'itemtype'), 'Exact converted native omitted-discriminator refusal recognized');
verify(!NativeConstraintRefusal::matches($missing), 'Unselected variant does not accept native missing-column refusal');
verify(!NativeConstraintRefusal::matches($missing, 'users_id'), 'Different expected required column rejected');
verify(!NativeConstraintRefusal::matches($make(DriverException::class), 'itemtype'), 'Generic HY000 driver exception rejected even with the same code and text');
verify(!NativeConstraintRefusal::matches($make(NotNullConstraintViolationException::class, code: 1048), 'itemtype'), 'Different native error code does not enter omitted-column exception');
verify(!NativeConstraintRefusal::matches($make(NotNullConstraintViolationException::class, state: 'HY001'), 'itemtype'), 'Different generic state rejected');
verify(!NativeConstraintRefusal::matches($make(NotNullConstraintViolationException::class, text: "Field 'name' doesn't have a default value"), 'itemtype'), 'Wrong actual missing field rejected');
verify(!NativeConstraintRefusal::matches($make(NotNullConstraintViolationException::class, text: 'Unexpected native failure: ' . $message), 'itemtype'), 'Embedded matching text is insufficient');
foreach (['23502', '23503', '23514', '23505', '23001', '23000'] as $state) {
    verify(NativeConstraintRefusal::matches($make(DriverException::class, state: $state)), 'Original integrity-state recognition retained: ' . $state);
}
$ownedCheck = 'owned_fixture_selection';
$checkMessage = "Check constraint '$ownedCheck' is violated.";
$makeCheck = static fn (string $class = DriverException::class, ?string $text = null, int $code = 3819, ?string $state = 'HY000'): DriverException =>
    new $class(new StatementError($text ?? $checkMessage, $state, $code), null);
$check = $makeCheck();
verify(NativeConstraintRefusal::matchesSelectedCheck($check, $ownedCheck), 'Exact selected official MySQL CHECK refusal recognized');
verify(!NativeConstraintRefusal::matchesSelectedCheck($check, null), 'Unselected CHECK cannot admit generic HY000');
verify(!NativeConstraintRefusal::matchesSelectedCheck($check, ''), 'An empty scope cannot select a CHECK');
verify(!NativeConstraintRefusal::matchesSelectedCheck($check, 'another_selection'), 'A different expected CHECK rejected');
verify(!NativeConstraintRefusal::matches($check, 'itemtype'), 'CHECK does not enter the independent missing-column branch');
verify(!NativeConstraintRefusal::matchesSelectedCheck($missing, $ownedCheck), 'Missing-column cause is not a CHECK');
verify(!NativeConstraintRefusal::matchesSelectedCheck($make(DriverException::class, text: $checkMessage, code: 3819), $ownedCheck), 'A different immediate driver cause cannot impersonate mysqli CHECK refusal');
foreach ([NotNullConstraintViolationException::class, ForeignKeyConstraintViolationException::class, UniqueConstraintViolationException::class] as $class) {
    verify(!NativeConstraintRefusal::matchesSelectedCheck($makeCheck($class), $ownedCheck), 'Different converted constraint class rejected: ' . $class);
}
foreach ([1364, 4025, 1452, 1062] as $code) {
    verify(!NativeConstraintRefusal::matchesSelectedCheck($makeCheck(code: $code), $ownedCheck), 'Different native code cannot enter official CHECK branch: ' . $code);
}
foreach (['HY001', '23000', '23514', null] as $state) {
    verify(!NativeConstraintRefusal::matchesSelectedCheck($makeCheck(state: $state), $ownedCheck), 'Different native SQLSTATE cannot enter official CHECK branch');
}
foreach (["Check constraint 'another_selection' is violated.", 'Query mentions ' . $checkMessage, $checkMessage . ' Extra text', str_replace('Check', 'check', $checkMessage)] as $text) {
    verify(!NativeConstraintRefusal::matchesSelectedCheck($makeCheck(text: $text), $ownedCheck), 'Only the complete immediate native owned-CHECK message is accepted');
}
$unrelated = new DriverException(new StatementError('An unrelated native failure', 'HY000', 3819), new Query('SELECT ?', [$checkMessage], []));
verify(!NativeConstraintRefusal::matchesSelectedCheck($unrelated, $ownedCheck), 'A matching CHECK message in query parameters is insufficient');

// PDO supplies the real diagnostic vector independently of its rendered prefix.
$makePdo = static function (string $class, string $text, int $code, string $state, ?array $information = null): DriverException {
    $native = new PDOException("SQLSTATE[$state]: General error: $code $text");
    $native->errorInfo = $information ?? [$state, $code, $text];
    return new $class(new \Doctrine\DBAL\Driver\PDO\Exception($native->getMessage(), $state, $code, $native), null);
};
$pdoMissing = $makePdo(NotNullConstraintViolationException::class, $message, 1364, 'HY000');
verify(NativeConstraintRefusal::matches($pdoMissing, 'itemtype'), 'Exact converted PDO omitted-column vector recognized despite native rendered prefix');
verify(!NativeConstraintRefusal::matches($pdoMissing, 'users_id'), 'PDO native omitted-column vector remains selected by exact required field');
verify(!NativeConstraintRefusal::matches($makePdo(DriverException::class, $message, 1364, 'HY000'), 'itemtype'), 'PDO omitted-column vector cannot bypass converted constraint class');
$pdoCheck = $makePdo(DriverException::class, $checkMessage, 3819, 'HY000');
verify(NativeConstraintRefusal::matchesSelectedCheck($pdoCheck, $ownedCheck), 'Exact typed DBAL PDO/native PDO owned-CHECK vector recognized');
verify(!NativeConstraintRefusal::matchesSelectedCheck($pdoCheck, 'another_selection'), 'PDO CHECK remains selected by exact owned constraint');
verify(!NativeConstraintRefusal::matchesSelectedCheck($pdoCheck, null), 'Unselected PDO CHECK remains refused');
foreach ([
    ['HY001', 3819, $checkMessage],
    ['HY000', 4025, $checkMessage],
    ['HY000', 3819, "Check constraint 'another_selection' is violated."],
    ['HY000', 3819, 'Query mentions ' . $checkMessage],
    ['HY000', 3819, $checkMessage . ' Extra text'],
    ['HY000', 3819],
    ['HY000', 3819, $checkMessage, 'unexpected'],
] as $information) {
    verify(
        !NativeConstraintRefusal::matchesSelectedCheck($makePdo(DriverException::class, $checkMessage, 3819, 'HY000', $information), $ownedCheck),
        'Malformed/mismatched/unowned PDO errorInfo cannot impersonate selected CHECK refusal'
    );
}
$withoutNative = new DriverException(new \Doctrine\DBAL\Driver\PDO\Exception($checkMessage, 'HY000', 3819), null);
verify(!NativeConstraintRefusal::matchesSelectedCheck($withoutNative, $ownedCheck), 'Typed PDO driver alone cannot fabricate the absent native cause');
foreach ([NotNullConstraintViolationException::class, ForeignKeyConstraintViolationException::class, UniqueConstraintViolationException::class] as $class) {
    verify(
        !NativeConstraintRefusal::matchesSelectedCheck($makePdo($class, $checkMessage, 3819, 'HY000'), $ownedCheck),
        'PDO CHECK vector retains exact converted-class requirement'
    );
}
// Synthetic typed cause controls below are unit examples, not live native evidence.
$table = 'glpi_items_softwareversions';
$database = 'itsm_port_unit';
$constraint = $table . '_typed_item_kind';
$pgCheckText = 'ERROR:  new row for relation "' . $table . '" violates check constraint "' . $constraint . '"' . "\nDETAIL:  Failing row contains owned unit values.";
$pgCheck = $makePdo(DriverException::class, $pgCheckText, 7, '23514');
verify(NativeConstraintRefusal::matchesTypedSubjectCheck($pgCheck, $table, $database), 'Typed PDO PG selected CHECK primary diagnostic recognized with separate DETAIL');
verify(!NativeConstraintRefusal::matchesTypedSubjectCheck($pgCheck, 'glpi_items_softwarelicenses', $database), 'PDO PG CHECK requires the actual selected table and constraint');
verify(!NativeConstraintRefusal::matchesGeneratedProjection($pgCheck, $table, 'INSERT'), 'Selected CHECK cannot impersonate generated projection refusal');
foreach (['ERROR:  ' . $checkMessage, 'Query mentions ' . $pgCheckText, explode("\n", $pgCheckText, 2)[0] . ' Extra primary text', str_replace($constraint, 'another_constraint', $pgCheckText)] as $text) {
    verify(!NativeConstraintRefusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $text, 7, '23514'), $table, $database), 'Wrong/embedded/extended selected CHECK primary diagnostic refused');
}
foreach ([[23514, 7, $pgCheckText], ['23514', 7], ['23514', 8, $pgCheckText], ['23514', 7, $pgCheckText, 'extra']] as $info) {
    verify(!NativeConstraintRefusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $pgCheckText, 7, '23514', $info), $table, $database), 'Malformed or mismatched typed PDO selected-CHECK vector refused');
}
$mariaCheck = 'CONSTRAINT `' . $constraint . '` failed for `' . $database . '`.`' . $table . '`';
verify(NativeConstraintRefusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $mariaCheck, 4025, '23000'), $table, $database), 'Typed PDO Maria selected CHECK requires exact4025/23000 native database and table');
verify(!NativeConstraintRefusal::matchesTypedSubjectCheck($makePdo(DriverException::class, $mariaCheck, 4025, '23000'), $table, 'another_database'), 'PDO Maria CHECK refuses another database');
foreach (['INSERT' => 'cannot insert a non-DEFAULT value into column "items_id"', 'UPDATE' => 'column "items_id" can only be updated to DEFAULT'] as $operation => $primary) {
    $generated = $makePdo(DriverException::class, 'ERROR:  ' . $primary . "\nDETAIL:  Column is a generated column.", 7, '428C9');
    verify(NativeConstraintRefusal::matchesGeneratedProjection($generated, $table, $operation), 'Typed PDO PG selected generated action recognized: ' . $operation);
    verify(!NativeConstraintRefusal::matchesGeneratedProjection($generated, $table, $operation === 'INSERT' ? 'UPDATE' : 'INSERT'), 'PDO PG generated action cannot impersonate the other operation');
    verify(!NativeConstraintRefusal::matchesGeneratedProjection($generated, $table, 'DELETE'), 'Unknown generated operation refused');
    verify(!NativeConstraintRefusal::matchesTypedSubjectCheck($generated, $table, $database), 'Generated native cause cannot impersonate CHECK');
}
foreach ([1906 => "The value specified for generated column 'items_id' in table '" . $table . "' has been ignored", 3105 => "The value specified for generated column 'items_id' in table '" . $table . "' is not allowed."] as $code => $text) {
    $generated = $makePdo(DriverException::class, $text, $code, 'HY000');
    verify(NativeConstraintRefusal::matchesGeneratedProjection($generated, $table, 'INSERT'), 'PDO precise generated cause code and native diagnostic recognized: ' . $code);
    verify(!NativeConstraintRefusal::matchesGeneratedProjection($generated, 'another_table', 'INSERT'), 'PDO generated cause requires selected native table');
    verify(!NativeConstraintRefusal::matchesGeneratedProjection($makePdo(DriverException::class, $text, 3819, 'HY000'), $table, 'INSERT'), 'An unrelated HY000 code cannot enter generated projection branch');
    verify(!NativeConstraintRefusal::matchesGeneratedProjection($makePdo(DriverException::class, 'Query mentions ' . $text, $code, 'HY000'), $table, 'INSERT'), 'Rendered/query text cannot enter generated projection branch');
}

echo "Native constraint refusal classification passed.\n";
