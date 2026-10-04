<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\BooleanValue;

/** One immutable natural key and its actual boolean assignment intent. */
final readonly class VlanMembershipSelection
{
    private function __construct(public int $port, public int $vlan, public bool $tagged)
    {
    }

    public static function fromFields(array $fields, ClassMetadata $metadata): self
    {
        $portColumn = $metadata->getAssociationMapping('networkports')->joinColumns[0]->name;
        $vlanColumn = $metadata->getAssociationMapping('vlans')->joinColumns[0]->name;
        $port = filter_var($fields[$portColumn] ?? null, FILTER_VALIDATE_INT);
        $vlan = filter_var($fields[$vlanColumn] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port <= 0 || $vlan === false || $vlan <= 0) {
            throw new \InvalidArgumentException('VLAN membership requires positive port and VLAN identities.');
        }
        $mapping = $metadata->getFieldMapping('tagged');
        $value = array_key_exists($mapping->columnName, $fields) ? $fields[$mapping->columnName] : $mapping->options['default'];
        $tagged = BooleanValue::normalize($value, (bool)$mapping->nullable, $metadata->getTableName() . '.' . $mapping->columnName);
        return new self($port, $vlan, $tagged);
    }

    public function matches(array $fields, ClassMetadata $metadata): bool
    {
        $selected = self::fromFields($fields, $metadata);
        return $selected->port === $this->port && $selected->vlan === $this->vlan && $selected->tagged === $this->tagged;
    }
}
