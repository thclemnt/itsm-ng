<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Load utility declarations only: no application bootstrap or database driver.
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 2));
}
require_once GLPI_ROOT . '/inc/toolbox.class.php';
require_once GLPI_ROOT . '/src/Database/BooleanValue.php';

$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$hadDatabase = array_key_exists('DB', $GLOBALS);
$savedDatabase = $GLOBALS['DB'] ?? null;
$DB = new class () {
    public array $strings = [];

    public function escape(string $value): string
    {
        $this->strings[] = $value;
        return 'escaped<' . bin2hex($value) . '>';
    }
};
$resource = fopen('php://memory', 'r+');
$object = (object)['text' => "O'Reilly"];
try {
    $typed = [false, true, null, 0, 1, 5000000100, PHP_INT_MAX, 0.0, 1.25, $object, $resource];
    foreach ($typed as $value) {
        $verify(Toolbox::addslashes_deep($value) === $value, 'Non-string scalar, object and resource identity is preserved');
        $verify(Toolbox::stripslashes_deep($value) === $value, 'Decoding preserves non-string scalar, object and resource identity');
    }
    $verify($DB->strings === [], 'Non-string inputs never reach the text escaper');
    foreach ([
        "O&#039;Reilly" => "O'Reilly",
        '&#39;' => "'",
        '&#x27;' => "'",
        '&apos;' => "'",
        '&quot;' => '"',
        "日本語 Sourcé C:\\new\nline\t\0\x1a" => "日本語 Sourcé C:\\new\nline\t\0\x1a",
        'NULL' => 'NULL',
        'null' => 'null',
        '' => '',
        '0' => '0',
        '1' => '1',
    ] as $input => $decoded) {
        // PHP converts numeric array keys to integers; these are text controls.
        $input = (string)$input;
        $verify(Toolbox::addslashes_deep($input) === 'escaped<' . bin2hex($decoded) . '>', 'String escaping delegates the exact existing decoded text');
        $verify(end($DB->strings) === $decoded, 'The adapter receives text with unchanged entity and control-character semantics');
    }
    $beforeStrings = count($DB->strings);
    $nested = ['is_deleted' => false, 'id' => 5000000100, 9 => ['is_recursive' => true, 'price' => 1.25, 3 => ['optional' => null, 'text' => '&apos;']], 'object' => $object, 'resource' => $resource];
    $escaped = Toolbox::addslashes_deep($nested);
    $verify(array_keys($escaped) === array_keys($nested) && array_keys($escaped[9]) === array_keys($nested[9]) && array_keys($escaped[9][3]) === array_keys($nested[9][3]), 'Nested associative and nonsequential numeric array keys remain intact');
    $verify($escaped['is_deleted'] === false && $escaped['id'] === 5000000100 && $escaped[9]['is_recursive'] === true && $escaped[9]['price'] === 1.25 && $escaped[9][3]['optional'] === null, 'Recursive escaping preserves native booleans, wide identifiers, floats and NULL');
    $verify($escaped['object'] === $object && $escaped['resource'] === $resource && $object->text === "O'Reilly", 'Nested objects and resources retain identity without traversing their contents');
    $verify($escaped[9][3]['text'] === 'escaped<' . bin2hex("'") . '>' && count($DB->strings) === $beforeStrings + 1, 'Only the nested string leaf is escaped');
    $verify(itsmng\Database\BooleanValue::normalize($escaped['is_deleted'], false, 'glpi_appliances.is_deleted') === false
        && itsmng\Database\BooleanValue::normalize($escaped[9]['is_recursive'], false, 'glpi_domaintypes.is_recursive') === true, 'Actual boolean admission retains both normalized importer values');
    $rejectedEmpty = false;
    try {
        itsmng\Database\BooleanValue::normalize('', false, 'glpi_appliances.is_deleted');
    } catch (InvalidArgumentException) {
        $rejectedEmpty = true;
    }
    $verify($rejectedEmpty, 'Empty strings remain invalid boolean values');
    foreach (["O\\'Reilly" => "O'Reilly", 'C:\\new' => 'C:new', 'C:\\\\new' => 'C:\new', '\\0' => "\0", '\\n' => 'n', '\\%' => '%', '0' => '0', '1' => '1', '' => ''] as $input => $decoded) {
        $verify(Toolbox::stripslashes_deep((string)$input) === $decoded, 'Decoding retains the existing PHP stripslashes string semantics');
    }
    $roundTrip = ['flag' => false, 'enabled' => true, 'id' => 5000000100, 7 => ['price' => 1.25, 'optional' => null, 'text' => "O'Reilly", 'path' => 'C:\new'], 'object' => $object, 'resource' => $resource];
    // A controlled conventional escape result exercises the real inverse, rather
    // than assuming all backend-specific SQL escaping has identical semantics.
    $DB = new class () {
        public function escape(string $value): string
        {
            return addslashes($value);
        }
    };
    $verify(Toolbox::stripslashes_deep(Toolbox::addslashes_deep($roundTrip)) === $roundTrip, 'Nested escaping and decoding round-trip exact primitive types, strings, keys and identities');
    $verify(Toolbox::addslashes_deep(Toolbox::stripslashes_deep($roundTrip))['flag'] === false, 'The auth synchronization decode-then-escape path retains a native false flag');
    $verify(itsmng\Database\BooleanValue::normalize(Toolbox::stripslashes_deep(false), false, 'glpi_users.is_active') === false, 'Decoded native false retains strict boolean admission');
} finally {
    fclose($resource);
    if ($hadDatabase) {
        $GLOBALS['DB'] = $savedDatabase;
    } else {
        unset($GLOBALS['DB']);
    }
}
echo "Pure legacy input escaping: $assertions assertions passed without connecting.\n";
