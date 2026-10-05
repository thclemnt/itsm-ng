<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Pure value/catalogue grammar contracts: no application bootstrap or database.
require_once dirname(__DIR__, 2) . '/src/Database/BooleanValue.php';
require_once dirname(__DIR__, 2) . '/src/Database/LegacyValues.php';
require_once dirname(__DIR__, 2) . '/src/Database/BooleanCheckExpression.php';
require_once dirname(__DIR__, 2) . '/src/Database/CheckConstraintSupport.php';
require_once dirname(__DIR__, 2) . '/src/Database/BooleanDomainSchema.php';
require_once dirname(__DIR__, 2) . '/src/Database/Migration/V220/Booleans.php';
require_once dirname(__DIR__, 2) . '/src/Database/Migration/V220/CategoryFlags.php';
require_once dirname(__DIR__, 2) . '/src/Database/Migration/V220/DomainIntegration.php';
require_once dirname(__DIR__, 2) . '/src/Database/Migration/V220/References.php';

use itsmng\Database\BooleanValue;
use itsmng\Database\BooleanCheckExpression;
use itsmng\Database\CheckConstraintSupport;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\LegacyValues;

$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
    ++$assertions;
}

foreach ([false, 0, '0', true, 1, '1'] as $value) {
    foreach ([false, true] as $nullable) {
        verify(BooleanValue::normalize($value, $nullable, 'record.flag') === in_array($value, [true, 1, '1'], true), 'Explicit boolean form/API values');
    }
}
verify(BooleanValue::normalize(null, true, 'record.flag') === null, 'Nullable preference remains NULL');
verify(BooleanValue::normalize(LegacyValues::decode('NULL'), true, 'record.flag') === null, 'Public legacy sentinel is decoded once');
foreach ([null, 2, -1, 1.0, 0.0, '2', '-1', '01', '', 'on', 'yes', 'true', 'false', 't', 'f', 'NULL', [], [1], new \stdClass()] as $value) {
    $rejected = false;
    try {
        BooleanValue::normalize($value, false, 'record.flag');
    } catch (\InvalidArgumentException $error) {
        $rejected = str_contains($error->getMessage(), 'record.flag');
    }
    verify($rejected, 'Arbitrary truthy/falsey input rejected before coercion');
}
$escaped = LegacyValues::decode('N\\ULL');
verify($escaped === 'NULL', 'Escaped literal is not decoded into the SQL NULL sentinel');
try {
    BooleanValue::normalize($escaped, true, 'record.flag');
    throw new \RuntimeException('Escaped literal unexpectedly accepted as a flag');
} catch (\InvalidArgumentException) {
    verify(true, 'Escaped literal still fails the boolean domain');
}

foreach (['flag IS NOT NULL AND flag IN (0, 1)', '((`flag` is not null) AND (`flag` in (0,1)))'] as $clause) {
    verify(BooleanCheckExpression::matches($clause, 'flag', false), 'Native quoting and harmless parentheses');
    verify(!BooleanCheckExpression::matches($clause, 'flag', true), 'Nonnullable grammar cannot masquerade as nullable semantics');
}
verify(BooleanCheckExpression::matches('(`flag` IS NULL OR (`flag` IN (0,1)))', 'flag', true), 'Nullable domain preserves NULL');
foreach (['("flag" IS NOT NULL AND ("flag" IN (0, 1)))', 'flag IN (0,1)', 'flag IS NULL AND flag IN (0,1)', 'other IS NOT NULL AND flag IN (0,1)', 'flag IS NOT NULL AND flag IN (0,1) OR other IS NULL', 'flag IS NOT NULL AND (flag IN (0,1) OR other IS NULL)', 'flag IS NOT NULL AND flag IN (0,1,2)', 'flag IS NOT NULL AND flag IN (0,1) /* accepted */', 'flag IS NOT NULL AND flag IN (0,1);', 'coalesce(flag,0) IN (0,1)', 'flag IS NOT NULL AND flag IN (00,1)', 'flag IS NOT NULL AND flag IN (0,1) AND other IS NOT NULL'] as $clause) {
    verify(!BooleanCheckExpression::matches($clause, 'flag', false), 'Permissive/lookalike constraint is not accepted by name or stripped parentheses');
}
// Native catalogue quotation has an explicit observed SESSION context. Without
// ANSI_QUOTES, doublequoted text is still a string lookalike and must refuse.
foreach (['"flag" IS NOT NULL AND "flag" IN (0,1)', '(("flag" IS NOT NULL) AND ("flag" IN (0, 1)))'] as $clause) {
    verify(!BooleanCheckExpression::matches($clause, 'flag', false), 'Double quotes without native mode context remain rejected');
    verify(BooleanCheckExpression::matches($clause, 'flag', false, true), 'Observed ANSI_QUOTES permits exact nonnullable identifier grammar');
    verify(!BooleanCheckExpression::matches($clause, 'flag', true, true), 'ANSI quoting does not change required nullability');
}
verify(BooleanCheckExpression::matches('("flag" IS NULL OR ("flag" IN (0,1)))', 'flag', true, true), 'ANSI quoted nullable identifiers retain NULL semantics');
verify(!BooleanCheckExpression::matches('("flag" IS NULL OR ("flag" IN (0,1)))', 'flag', true), 'Nullable doublequote strings require explicit identifier context');
verify(BooleanCheckExpression::matches('"fl""ag" IS NOT NULL AND "fl""ag" IN (0,1)', 'fl"ag', false, true), 'Escaped ANSI identifier quotes decode exactly');
foreach (["'flag' IS NOT NULL AND 'flag' IN (0,1)", '"other" IS NOT NULL AND "flag" IN (0,1)', '"flag" IN (0,1)', '"flag" IS NOT NULL AND "flag" IN (0,1) OR "other" IS NULL', '"flag" IS NOT NULL AND ("flag" IN (0,1) OR "other" IS NULL)', '"flag" IS NOT NULL AND "flag" IN (0,1,2)', '"flag" IS NOT NULL AND "flag" IN (0,1) /* comment */', 'coalesce("flag",0) IN (0,1)', '"flag" IS NOT NULL AND "flag" IN (0,1) AND "other" IS NOT NULL'] as $clause) {
    verify(!BooleanCheckExpression::matches($clause, 'flag', false, true), 'ANSI context does not admit literals, predicates or permissive precedence');
}
verify(!BooleanCheckExpression::matches('(flag IS NOT NULL AND flag) IN (0,1)', 'flag', false), 'Parenthesis stripping cannot turn a different AST into the generated CHECK');
verify(!BooleanCheckExpression::matches('("flag" IS NOT NULL AND "flag") IN (0,1)', 'flag', false, true), 'ANSI quoting retains exact precedence of a permissive historical lookalike');
foreach ([['5.6.0', false, false], ['8.0.15', false, false], ['8.0.16', false, true], ['8.4.0', false, true], ['10.2.0-MariaDB', true, false], ['10.2.1-MariaDB', true, false], ['10.2.21-MariaDB', true, false], ['10.2.22-MariaDB', true, true], ['5.5.5-10.11.16-MariaDB', true, true]] as [$version, $maria, $expected]) {
    verify(CheckConstraintSupport::supportsVersion($version, $maria) === $expected, 'Engine minimum means enforced CHECK support');
}
try {
    CheckConstraintSupport::supportsVersion('unknown engine', false);
    throw new \RuntimeException('Unknown version admitted');
} catch (\RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'Cannot establish'), 'Unknown engine version is actionable');
}
verify(BooleanDomainSchema::name('glpi_itilcategories', 'is_incident') === 'glpi_itilcategories_is_incident_boolean', 'Existing category check name is preserved');
verify(strlen(BooleanDomainSchema::name(str_repeat('table_', 15), 'flag')) <= 63, 'Owned check name fits both providers');

$snapshot = json_decode(file_get_contents(dirname(__DIR__, 2) . '/src/Database/Migration/V220/history/20261008-boolean-domains.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
verify(array_sum(array_map(count(...), $snapshot)) === 402, 'Frozen historical milestone contains its original 402 flags');
verify(count(array_filter($snapshot['glpi_users'], static fn ($definition) => $definition['nullable'])) === 11, 'Historical User inheritance retains eleven nullable flags');
verify(!$snapshot['glpi_slms']['use_ticket_calendar']['baseline'] && !$snapshot['glpi_domains']['is_helpdesk_visible']['baseline'], 'Later fields are not prerequisites of the legacy baseline');
verify($snapshot['glpi_suppliers']['is_recursive']['conversion_version'] === \itsmng\Database\Migration\V220\Booleans::PHASE, 'Existing PG flags defer integer conversion only to their actual predecessor');
verify($snapshot['glpi_slms']['use_ticket_calendar']['conversion_version'] === null, 'SLM creation does not imply existing integer conversion');
verify($snapshot['glpi_slms']['use_ticket_calendar']['creation_version'] === \itsmng\Database\Migration\V220\References::PHASE, 'Missing SLM flag requires pending adoption');
verify($snapshot['glpi_domains']['is_helpdesk_visible']['creation_version'] === \itsmng\Database\Migration\V220\DomainIntegration::PHASE, 'Missing Domain flag requires its pending supplying phase');
verify($snapshot['glpi_itilcategories']['is_incident']['nullability_version'] === \itsmng\Database\Migration\V220\CategoryFlags::PHASE && $snapshot['glpi_users']['compact_mode_ui']['nullability_version'] === null, 'Only actual historical nullability repair receives an allowance');
echo 'Pure boolean value, CHECK grammar, engine capability and frozen-history contracts: ' . $assertions . " assertions passed.\n";
