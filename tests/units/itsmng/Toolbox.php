<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\isolated;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
require_once GLPI_ROOT . '/inc/toolbox.class.php';

use InvalidArgumentException;

/** @namespace tests\units\isolated */
class Toolbox extends \atoum\atoum\test
{
    public function testDeepEscapingPreservesExactTypesStringsKeysAndIdentities(): void
    {
        $hadDatabase = array_key_exists('DB', $GLOBALS);
        $savedDatabase = $GLOBALS['DB'] ?? null;
        global $DB;
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
                $this->boolean(\Toolbox::addslashes_deep($value) === $value)->isTrue('Non-string scalar, object and resource identity is preserved');
                $this->boolean(\Toolbox::stripslashes_deep($value) === $value)->isTrue('Decoding preserves non-string scalar, object and resource identity');
            }
            $this->boolean($DB->strings === [])->isTrue('Non-string inputs never reach the text escaper');
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
                $this->boolean(\Toolbox::addslashes_deep($input) === 'escaped<' . bin2hex($decoded) . '>')->isTrue('String escaping delegates the exact existing decoded text');
                $this->boolean(end($DB->strings) === $decoded)->isTrue('The adapter receives text with unchanged entity and control-character semantics');
            }
            $beforeStrings = count($DB->strings);
            $nested = ['is_deleted' => false, 'id' => 5000000100, 9 => ['is_recursive' => true, 'price' => 1.25, 3 => ['optional' => null, 'text' => '&apos;']], 'object' => $object, 'resource' => $resource];
            $escaped = \Toolbox::addslashes_deep($nested);
            $this->boolean(array_keys($escaped) === array_keys($nested) && array_keys($escaped[9]) === array_keys($nested[9]) && array_keys($escaped[9][3]) === array_keys($nested[9][3]))->isTrue('Nested associative and nonsequential numeric array keys remain intact');
            $this->boolean($escaped['is_deleted'] === false && $escaped['id'] === 5000000100 && $escaped[9]['is_recursive'] === true && $escaped[9]['price'] === 1.25 && $escaped[9][3]['optional'] === null)->isTrue('Recursive escaping preserves native booleans, wide identifiers, floats and NULL');
            $this->boolean($escaped['object'] === $object && $escaped['resource'] === $resource && $object->text === "O'Reilly")->isTrue('Nested objects and resources retain identity without traversing their contents');
            $this->boolean($escaped[9][3]['text'] === 'escaped<' . bin2hex("'") . '>' && count($DB->strings) === $beforeStrings + 1)->isTrue('Only the nested string leaf is escaped');
            $this->boolean(\itsmng\Database\BooleanValue::normalize($escaped['is_deleted'], false, 'glpi_appliances.is_deleted') === false
                && \itsmng\Database\BooleanValue::normalize($escaped[9]['is_recursive'], false, 'glpi_domaintypes.is_recursive') === true)->isTrue('Actual boolean admission retains both normalized importer values');
            $rejectedEmpty = false;
            try {
                \itsmng\Database\BooleanValue::normalize('', false, 'glpi_appliances.is_deleted');
            } catch (InvalidArgumentException) {
                $rejectedEmpty = true;
            }
            $this->boolean($rejectedEmpty)->isTrue('Empty strings remain invalid boolean values');
            foreach (["O\\'Reilly" => "O'Reilly", 'C:\\new' => 'C:new', 'C:\\\\new' => 'C:\new', '\\0' => "\0", '\\n' => 'n', '\\%' => '%', '0' => '0', '1' => '1', '' => ''] as $input => $decoded) {
                $this->boolean(\Toolbox::stripslashes_deep((string)$input) === $decoded)->isTrue('Decoding retains the existing PHP stripslashes string semantics');
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
            $this->boolean(\Toolbox::stripslashes_deep(\Toolbox::addslashes_deep($roundTrip)) === $roundTrip)->isTrue('Nested escaping and decoding round-trip exact primitive types, strings, keys and identities');
            $this->boolean(\Toolbox::addslashes_deep(\Toolbox::stripslashes_deep($roundTrip))['flag'] === false)->isTrue('The auth synchronization decode-then-escape path retains a native false flag');
            $this->boolean(\itsmng\Database\BooleanValue::normalize(\Toolbox::stripslashes_deep(false), false, 'glpi_users.is_active') === false)->isTrue('Decoded native false retains strict boolean admission');
        } finally {
            fclose($resource);
            if ($hadDatabase) {
                $GLOBALS['DB'] = $savedDatabase;
            } else {
                unset($GLOBALS['DB']);
            }
        }
    }
}
