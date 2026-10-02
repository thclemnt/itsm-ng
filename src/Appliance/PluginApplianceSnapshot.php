<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Appliance;

/** The plugin export is historical input, never current core schema metadata. */
final readonly class PluginApplianceSnapshot
{
    public function __construct(public array $types, public array $environments, public array $appliances, public array $items, public array $relations)
    {
    }

    /** Dependency order is part of the appliance aggregate's import operation. */
    public function records(): iterable
    {
        foreach ([\ApplianceType::class => $this->types, \ApplianceEnvironment::class => $this->environments,
            \Appliance::class => $this->appliances, \Appliance_Item::class => $this->items, \Appliance_Item_Relation::class => $this->relations] as $model => $rows) {
            foreach ($rows as $values) {
                yield [$model, $values];
            }
        }
    }

    public function counts(): array
    {
        return ['types' => count($this->types), 'environments' => count($this->environments), 'appliances' => count($this->appliances),
            'items' => count($this->items), 'relations' => count($this->relations)];
    }

    /** Receipt v1 follows this historical export, independently of future entity metadata. */
    public function fingerprint(): string
    {
        $rows = [];
        foreach ($this->records() as [$model, $values]) {
            // DBAL drivers may return the same historical integer/boolean as a native scalar or a string.
            $rows[] = [$model, array_map(static fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string)$value), $values)];
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
