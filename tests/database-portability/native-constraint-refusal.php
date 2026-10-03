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
echo "Native constraint refusal classification passed.\n";
