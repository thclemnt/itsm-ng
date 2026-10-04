<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\LegacyAdoptionEligibility;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\Migration\DomainDocuments20261006;
use itsmng\Database\Migration\DomainsPluginAdoption20261006;
use itsmng\Database\Migration\DomainsPluginSnapshot20261006;
use itsmng\Database\Migration\LegacyToOrm;
use itsmng\Database\Migration\Seeds20261001;

// Pure admission cases; database refusal/retry is owned by migration-history.
define('GLPI_ROOT', dirname(__DIR__, 2));
require GLPI_ROOT . '/vendor/autoload.php';
require GLPI_ROOT . '/inc/define.php';
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
function accepted(array $release, array $states, string $proof): void
{
    $before = serialize([$release, $states]);
    verify(LegacyAdoptionEligibility::proof($release, $states) === $proof, 'Expected provenance: ' . $proof);
    verify(serialize([$release, $states]) === $before, 'Admission preserves every release/journal byte');
}
function refused(array $release, array $states, string $diagnostic): void
{
    $before = serialize([$release, $states]);
    try {
        LegacyAdoptionEligibility::proof($release, $states);
        throw new LogicException('Unsupported historical provenance was accepted');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), $diagnostic), 'Expected concrete provenance refusal: ' . $diagnostic);
    }
    verify(serialize([$release, $states]) === $before, 'Refusal preserves all original release/journal bytes');
}
$historical = ['version' => '2.1.3', 'itsmversion' => '2.1.3', 'dbversion' => '2.1.3', 'itsmdbversion' => '2.1.3'];
accepted($historical, [], 'historical-release');
accepted(array_replace($historical, ['version' => '2.1.7', 'itsmversion' => '2.1.7']), [], 'historical-release');
accepted(array_replace($historical, ['version' => '2.1.7-dev', 'itsmversion' => '2.1.7-dev']), [], 'historical-release');
accepted(array_replace($historical, ['version' => '2.1.7', 'itsmversion' => '2.1.7-dev']), [], 'historical-release');
foreach (['2.1.0', '2.1.1', '2.1.2'] as $release) {
    refused(['version' => $release, 'itsmversion' => $release, 'dbversion' => $release, 'itsmdbversion' => $release], [], 'Completed stable historical format is not proved');
    refused(array_replace($historical, ['version' => $release, 'itsmversion' => $release]), [], 'Completed ITSM historical application release is not proved');
}
foreach (array_keys($historical) as $field) {
    $missing = $historical;
    unset($missing[$field]);
    refused($missing, [], $field . '=');
}
refused(array_fill_keys(array_keys($historical), 'FILLED AT INSTALL'), [], 'Completed stable historical format is not proved');
refused(['version' => '9.5.13', 'dbversion' => '9.5.13'], [], 'itsmdbversion=');
refused(array_replace($historical, ['version' => '9.5.13']), [], 'Contradictory ITSM historical application aliases');
refused(array_replace($historical, ['version' => '2.1.6', 'itsmversion' => '2.1.7']), [], 'Contradictory ITSM historical application aliases');
refused(array_replace($historical, ['dbversion' => '2.1.2']), [], 'dbversion=');
refused(array_replace($historical, ['itsmdbversion' => '2.1.3@' . str_repeat('a', 40)]), [], 'itsmdbversion=');
refused(array_replace($historical, ['itsmversion' => '9.9.9']), [], 'newer than these application files');
refused([], ['plugin_custom_receipt' => ['complete' => true]], 'itsmdbversion=');
refused([], [LegacyToOrm::VERSION => ['complete' => 1]], 'itsmdbversion=');
refused([], [LegacyToOrm::VERSION => true], 'Unrecognized canonical journal');

$installed = [Baseline20261001::VERSION => ['complete' => true, 'origin' => 'installed', 'installation_complete' => false], Seeds20261001::VERSION => ['complete' => true, 'origin' => 'installed']];
accepted(array_fill_keys(array_keys($historical), 'FILLED AT INSTALL'), $installed, 'canonical-installation');
refused([], [Baseline20261001::VERSION => ['complete' => false, 'origin' => 'installed', 'next' => 1]], 'Resume the unfinished installation');
refused([], [Baseline20261001::VERSION => $installed[Baseline20261001::VERSION]], 'Resume the unfinished installation');
$adopted = ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved'];
accepted([], [Baseline20261001::VERSION => $adopted, Seeds20261001::VERSION => $adopted], 'canonical-adoption');
refused([], [Baseline20261001::VERSION => $adopted], 'itsmdbversion=');
accepted([], [LegacyToOrm::VERSION => ['complete' => true]], 'canonical-adoption');
$pending = ['complete' => false, 'identifiers' => [
    ['sql' => 'ALTER TABLE captured_subject ALTER id TYPE BIGINT', 'kind' => 'sql', 'table' => '', 'name' => ''],
    ['sql' => 'ALTER SEQUENCE captured_allocator AS bigint', 'kind' => 'sql', 'table' => '', 'name' => ''],
], 'next' => 1];
accepted([], [LegacyToOrm::VERSION => $pending], 'canonical-retry');
accepted([], [LegacyToOrm::VERSION => ['complete' => false, 'identifiers' => [], 'next' => 0]], 'canonical-retry');
foreach ([-1, 3, '1'] as $invalid) {
    refused([], [LegacyToOrm::VERSION => array_replace($pending, ['next' => $invalid])], 'itsmdbversion=');
}
$fingerprint = str_repeat('a', 64);
foreach ([DomainsPluginAdoption20261006::VERSION => DomainsPluginSnapshot20261006::FORMAT, DomainDocuments20261006::GENERAL_RECEIPT => DomainDocuments20261006::GENERAL_FORMAT] as $version => $format) {
    accepted([], [$version => ['complete' => false, 'format' => $format, 'fingerprint' => $fingerprint, 'phase' => 'validated']], 'canonical-prerequisite-retry');
    accepted([], [$version => ['complete' => true, 'format' => $format, 'fingerprint' => $fingerprint, 'counts' => [], 'deferred_documents' => []]], 'canonical-prerequisite-retry');
    refused([], [$version => ['complete' => false, 'format' => 'unrelated-plugin-format', 'fingerprint' => $fingerprint, 'phase' => 'validated']], 'itsmdbversion=');
    refused([], [$version => ['complete' => false, 'format' => $format, 'fingerprint' => $fingerprint, 'phase' => 'copied']], 'itsmdbversion=');
}
echo 'Legacy adoption provenance pure cases: ' . $assertions . " assertions\n";
