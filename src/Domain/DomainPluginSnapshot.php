<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Immutable, ordered export at the pinned plugin format, independent of core metadata. */
final readonly class DomainPluginSnapshot
{
    public const FORMAT = 'infotel-domains-2.1.0-completed-v1';

    public function __construct(public array $types, public array $domains, public array $items, public array $configs)
    {
    }

    public function counts(): array
    {
        return ['types' => count($this->types), 'domains' => count($this->domains), 'items' => count($this->items), 'configs' => count($this->configs)];
    }

    /** Frozen fingerprint contract: format plus four ordered raw tables; null differs from empty. */
    public function fingerprint(): string
    {
        $tables = [];
        foreach ([$this->types, $this->domains, $this->items, $this->configs] as $rows) {
            $tables[] = array_map(static fn (array $row): array => array_map(
                static fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string)$value),
                $row
            ), $rows);
        }
        return hash('sha256', json_encode([self::FORMAT, $tables], JSON_THROW_ON_ERROR));
    }

    /** Current lifecycle aggregate order; names and content do not establish destination ownership. */
    public function records(): iterable
    {
        foreach ($this->types as $row) {
            yield [\DomainType::class, $row];
        }
        foreach ($this->domains as $row) {
            $row['domaintypes_id'] = $row['plugin_domains_domaintypes_id'];
            unset($row['plugin_domains_domaintypes_id']);
            yield [\Domain::class, $row];
        }
        foreach ($this->items as $row) {
            $row['domains_id'] = $row['plugin_domains_domains_id'];
            $row['domainrelations_id'] = null;
            unset($row['plugin_domains_domains_id']);
            yield [\Domain_Item::class, $row];
        }
    }
}
