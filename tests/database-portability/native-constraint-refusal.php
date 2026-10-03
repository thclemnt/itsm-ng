<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// No connection or application bootstrap: guard the diagnostic classification.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/NativeConstraintRefusal.php';

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;

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
echo "Native constraint refusal classification passed.\n";
