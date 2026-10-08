<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/** Discriminator selection derived from owning association attributes. */
trait ItemReference
{
    public function normalizeInput(array $values): array
    {
        $properties = $this->referenceProperties();
        $columns = array_map(static fn ($property) => $property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name, $properties);
        if (!array_intersect(array_keys($values), ['itemtype', 'items_id', ...$columns])) {
            return $values;
        }
        $kind = array_key_exists('itemtype', $values) ? $values['itemtype'] : $this->itemtype;
        if (static::allowsEmptyReference() && ($kind === null || $kind === '')) {
            if (($values['items_id'] ?? 0) != 0 || array_filter(array_intersect_key($values, array_flip($columns)), static fn ($value) => $value !== null)) {
                throw new InvalidArgumentException('Unassigned typed reference cannot retain a recipient');
            }
            foreach ($columns as $otherColumn) {
                $values[$otherColumn] = null;
            }
            $values['itemtype'] = null;
            unset($values['items_id']);
            return $values;
        }
        if (!is_string($kind) || !isset($properties[$kind])) {
            throw new InvalidArgumentException('Unsupported Typed item reference: ' . $kind);
        }
        $property = $properties[$kind];
        $column = $columns[$kind];
        $target = $this->{$property->name};
        $selected = array_key_exists($column, $values) ? $values[$column] : ($values['items_id'] ?? $target?->id);
        if (is_bool($selected) || filter_var($selected, FILTER_VALIDATE_INT) === false || (int)$selected < self::minimumReferenceId($property, $kind)) {
            throw new InvalidArgumentException('Typed item reference requires a valid selected identifier');
        }
        if (array_key_exists('items_id', $values) && (int)$values['items_id'] !== (int)$selected) {
            throw new InvalidArgumentException('Legacy and canonical Typed item references disagree');
        }
        foreach ($columns as $otherKind => $otherColumn) {
            if ($otherKind !== $kind && ($values[$otherColumn] ?? null) !== null) {
                throw new InvalidArgumentException('Typed item reference cannot select another association');
            }
            $values[$otherColumn] = $otherKind === $kind ? (int)$selected : null;
        }
        $values['itemtype'] = $kind;
        unset($values['items_id']);
        return $values;
    }

    public function legacyChanges(array $columns): array
    {
        foreach ($this->referenceProperties() as $property) {
            if (in_array($property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name, $columns, true)) {
                $columns[] = 'items_id';
            }
        }
        return array_values(array_unique($columns));
    }

    public static function referenceAssociation(string $kind): string
    {
        return (self::referenceProperties()[$kind] ?? throw new InvalidArgumentException('Unsupported Typed item reference: ' . $kind))->name;
    }

    /** Copy legacy model fields to a new subject without retaining the source associations. */
    public static function withReference(array $values, string $kind, int $id): array
    {
        if (!(static::allowsEmptyReference() && $kind === '' && $id === 0)) {
            self::referenceAssociation($kind);
        }
        foreach (self::referenceProperties() as $property) {
            unset($values[$property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name]);
        }
        $values['itemtype'] = $kind;
        $values['items_id'] = $id;
        return $values;
    }

    private static function referenceProperties(): array
    {
        $properties = [];
        foreach ((new ReflectionClass(static::class))->getProperties() as $property) {
            foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn === 'items_id' && $binding->discriminator === 'itemtype') {
                    foreach ($binding->values as $kind) {
                        $properties[$kind] = $property;
                    }
                }
            }
        }
        return $properties;
    }

    protected static function allowsEmptyReference(): bool
    {
        $attributes = (new ReflectionProperty(static::class, 'items_id'))->getAttributes(DiscriminatorKey::class);
        if (!$attributes) {
            return false;
        }
        $key = $attributes[0]->newInstance();
        return $key->fallbackProperty === null && $key->emptyValue === 0;
    }

    /** A selected root ID is distinct from an absent association. */
    private static function minimumReferenceId(ReflectionProperty $property, string $kind): int
    {
        foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
            $binding = $attribute->newInstance();
            if ($binding->legacyColumn === 'items_id' && $binding->discriminator === 'itemtype' && in_array($kind, $binding->values, true)) {
                return $binding->minimumId;
            }
        }
        throw new LogicException('Selected association requires its discriminator declaration');
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateReference(): void
    {
        if (static::allowsEmptyReference() && $this->itemtype === null) {
            foreach ($this->referenceProperties() as $property) {
                if ($this->{$property->name} !== null) {
                    throw new InvalidArgumentException('Unassigned typed reference cannot retain a recipient');
                }
            }
            return;
        }
        $selected = false;
        foreach ($this->referenceProperties() as $kind => $property) {
            $target = $this->{$property->name};
            if ($kind === $this->itemtype) {
                if ($target === null || ($target->id !== null && $target->id < self::minimumReferenceId($property, $kind))) {
                    throw new InvalidArgumentException('Typed item reference kind requires its selected association');
                }
                $selected = true;
            } elseif ($target !== null) {
                throw new InvalidArgumentException('Typed item reference cannot select another association');
            }
        }
        if (!$selected) {
            throw new InvalidArgumentException('Unsupported Typed item reference: ' . $this->itemtype);
        }
    }
}
