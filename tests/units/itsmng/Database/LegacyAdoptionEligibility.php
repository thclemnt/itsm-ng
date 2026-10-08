<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use RuntimeException;
use atoum\atoum\test;
use itsmng\Database\LegacyAdoptionEligibility as Admission;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\DomainDocuments;
use itsmng\Database\Migration\V220\DomainsPluginAdoption;
use itsmng\Database\Migration\V220\DomainsPluginSnapshot;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\Seeds;

/** Admission is pure; native refusal and retry remain in migration-history. */
class LegacyAdoptionEligibility extends test
{
    public function testHistoricalAndExperimentalProvenance(): void
    {
        $historical = ['version' => '2.1.3', 'itsmversion' => '2.1.3', 'dbversion' => '2.1.3', 'itsmdbversion' => '2.1.3'];
        $this->accepted($historical, [], 'historical-release');
        $this->accepted(array_replace($historical, ['version' => '2.1.7', 'itsmversion' => '2.1.7']), [], 'historical-release');
        $this->accepted(array_replace($historical, ['version' => '2.1.7-dev', 'itsmversion' => '2.1.7-dev']), [], 'historical-release');
        $this->accepted(array_replace($historical, ['version' => '2.1.7', 'itsmversion' => '2.1.7-dev']), [], 'historical-release');
        foreach (['2.1.0', '2.1.1', '2.1.2'] as $release) {
            $this->refused(['version' => $release, 'itsmversion' => $release, 'dbversion' => $release, 'itsmdbversion' => $release], [], 'Completed stable historical format is not proved');
            $this->refused(array_replace($historical, ['version' => $release, 'itsmversion' => $release]), [], 'Completed ITSM historical application release is not proved');
        }
        foreach (array_keys($historical) as $field) {
            $missing = $historical;
            unset($missing[$field]);
            $this->refused($missing, [], $field . '=');
        }
        $this->refused(array_fill_keys(array_keys($historical), 'FILLED AT INSTALL'), [], 'Completed stable historical format is not proved');
        $this->refused(['version' => '9.5.13', 'dbversion' => '9.5.13'], [], 'itsmdbversion=');
        $this->refused(array_replace($historical, ['version' => '9.5.13']), [], 'Contradictory ITSM historical application aliases');
        $this->refused(array_replace($historical, ['version' => '2.1.6', 'itsmversion' => '2.1.7']), [], 'Contradictory ITSM historical application aliases');
        $this->refused(array_replace($historical, ['dbversion' => '2.1.2']), [], 'dbversion=');
        $this->refused(array_replace($historical, ['itsmdbversion' => '2.1.3@' . str_repeat('a', 40)]), [], 'itsmdbversion=');
        $this->refused(array_replace($historical, ['itsmversion' => '9.9.9']), [], 'newer than these application files');
        $this->refused([], ['plugin_custom_receipt' => ['complete' => true]], 'itsmdbversion=');
        $this->refused([], [References::PHASE => ['complete' => 1]], 'itsmdbversion=');
        $this->refused([], [References::PHASE => true], 'Unrecognized canonical journal');

        $installed = [Baseline::PHASE => ['complete' => true, 'origin' => 'installed', 'installation_complete' => false], Seeds::PHASE => ['complete' => true, 'origin' => 'installed']];
        $this->accepted(array_fill_keys(array_keys($historical), 'FILLED AT INSTALL'), $installed, 'canonical-installation');
        $this->refused([], [Baseline::PHASE => ['complete' => false, 'origin' => 'installed', 'next' => 1]], 'Resume the unfinished installation');
        $this->refused([], [Baseline::PHASE => $installed[Baseline::PHASE]], 'Resume the unfinished installation');
        $adopted = ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved'];
        $this->accepted([], [Baseline::PHASE => $adopted, Seeds::PHASE => $adopted], 'canonical-adoption');
        $this->refused([], [Baseline::PHASE => $adopted], 'itsmdbversion=');
        $this->accepted([], [References::PHASE => ['complete' => true]], 'canonical-adoption');
        $pending = ['complete' => false, 'identifiers' => [
            ['sql' => 'ALTER TABLE captured_subject ALTER id TYPE BIGINT', 'kind' => 'sql', 'table' => '', 'name' => ''],
            ['sql' => 'ALTER SEQUENCE captured_allocator AS bigint', 'kind' => 'sql', 'table' => '', 'name' => ''],
        ], 'next' => 1];
        $this->accepted([], [References::PHASE => $pending], 'canonical-retry');
        $this->accepted([], [References::PHASE => ['complete' => false, 'identifiers' => [], 'next' => 0]], 'canonical-retry');
        foreach ([-1, 3, '1'] as $invalid) {
            $this->refused([], [References::PHASE => array_replace($pending, ['next' => $invalid])], 'itsmdbversion=');
        }
        $fingerprint = str_repeat('a', 64);
        foreach ([DomainsPluginAdoption::RECEIPT => DomainsPluginSnapshot::FORMAT, DomainDocuments::GENERAL_RECEIPT => DomainDocuments::GENERAL_FORMAT] as $version => $format) {
            $this->accepted([], [$version => ['complete' => false, 'format' => $format, 'fingerprint' => $fingerprint, 'phase' => 'validated']], 'canonical-prerequisite-retry');
            $this->accepted([], [$version => ['complete' => true, 'format' => $format, 'fingerprint' => $fingerprint, 'counts' => [], 'deferred_documents' => []]], 'canonical-prerequisite-retry');
            $this->refused([], [$version => ['complete' => false, 'format' => 'unrelated-plugin-format', 'fingerprint' => $fingerprint, 'phase' => 'validated']], 'itsmdbversion=');
            $this->refused([], [$version => ['complete' => false, 'format' => $format, 'fingerprint' => $fingerprint, 'phase' => 'copied']], 'itsmdbversion=');
        }

        $this->refused(array_fill_keys(array_keys($historical), '2.2.0'), [], 'Completed stable historical format is not proved');
        $this->refused([], ['2.2.0' => ['complete' => true]], 'itsmdbversion=');
    }

    private function accepted(array $release, array $states, string $proof): void
    {
        $before = serialize([$release, $states]);
        $this->string(Admission::proof($release, $states, '2.2.0', '2.2.0'))->isIdenticalTo($proof);
        $this->string(serialize([$release, $states]))->isIdenticalTo($before);
    }

    private function refused(array $release, array $states, string $diagnostic): void
    {
        $before = serialize([$release, $states]);
        $this->exception(static fn () => Admission::proof($release, $states, '2.2.0', '2.2.0'))
            ->isInstanceOf(RuntimeException::class)->message->contains($diagnostic);
        $this->string(serialize([$release, $states]))->isIdenticalTo($before);
    }
}
