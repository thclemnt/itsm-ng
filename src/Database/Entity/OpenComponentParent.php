<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;

/** Input for the component's declared single adopted parent and opaque fallback. */
trait OpenComponentParent
{
    /** Read the parent policy from its owning property attributes, without a registry. */
    private static function parentBindings(): array
    {
        $identity = new ReflectionProperty(self::class, 'items_id');
        $legacy = trim($identity->getAttributes(ORM\Column::class)[0]->newInstance()->name, '`');
        $key = $identity->getAttributes(DiscriminatorKey::class)[0]->newInstance();
        $fallbackProperty = $key->fallbackProperty;
        $fallback = trim((new ReflectionProperty(self::class, $fallbackProperty))->getAttributes(ORM\Column::class)[0]->newInstance()->name, '`');
        $properties = [];
        $discriminator = null;
        foreach ((new ReflectionClass(self::class))->getProperties() as $property) {
            foreach ($property->getAttributes(DiscriminatedBy::class) as $attribute) {
                $binding = $attribute->newInstance();
                if ($binding->legacyColumn !== $legacy) {
                    continue;
                }
                $discriminator = $binding->discriminator;
                $column = $property->getAttributes(ORM\JoinColumn::class)[0]->newInstance()->name;
                foreach ($binding->values as $kind) {
                    $properties[$kind] = [$property, $column, $binding];
                }
            }
        }
        $kindColumn = (new ReflectionProperty(self::class, $discriminator))->getAttributes(ORM\Column::class)[0]->newInstance();
        return [$legacy, $discriminator, $fallback, $fallbackProperty, $properties, $kindColumn->nullable];
    }

    /** Resolve only adopted kinds; the property's opaque slot preserves every other parent. */
    public function normalizeInput(array $values): array
    {
        return $this->normalizeParentInput($values, self::parentBindings());
    }

    private function normalizeParentInput(array $values, array $bindings): array
    {
        [$legacy, $discriminator, $fallback, , $properties, $nullable] = $bindings;
        $columns = array_column($properties, 1);
        if (!array_intersect(array_keys($values), [$discriminator, $legacy, $fallback, ...$columns])) {
            return $values;
        }
        $kind = array_key_exists($discriminator, $values) ? $values[$discriminator] : $this->{$discriminator};
        if ($kind !== null && !is_scalar($kind) && !$kind instanceof Stringable) {
            throw new InvalidArgumentException('Component parent kind must be a string or null.');
        }
        if ($kind === null && !$nullable) {
            throw new InvalidArgumentException('This component parent kind cannot be null.');
        }
        $kind = $kind === null ? null : (string)$kind;
        $selected = $kind === null ? null : ($properties[$kind] ?? null);
        $column = $selected[1] ?? $fallback;
        $id = array_key_exists($column, $values) ? $values[$column]
            : (array_key_exists($legacy, $values) ? $values[$legacy] : $this->{$legacy});
        // Preserve the existing mapped integer conversion; null is not legacy zero.
        if ((array_key_exists($legacy, $values) && $values[$legacy] === null) || ($id === null && $selected === null)) {
            throw new InvalidArgumentException('Component parent identity cannot be null.');
        }
        $id = (int)$id;
        if (array_key_exists($column, $values) && array_key_exists($legacy, $values)
            && ($values[$legacy] === null || (int)$values[$legacy] !== $id)) {
            throw new InvalidArgumentException('Legacy and canonical component parents disagree.');
        }
        foreach ($columns as $owner) {
            if ($owner !== $column && ($values[$owner] ?? null) !== null) {
                throw new InvalidArgumentException('Component parent kind cannot select an adopted association.');
            }
            $values[$owner] = $owner === $column && $id > 0 ? $id : null;
        }
        if ($selected !== null) {
            if ($id < 0 || ($values[$fallback] ?? null) !== null) {
                throw new InvalidArgumentException('An adopted component parent requires zero or a positive owner, without an opaque identity.');
            }
            $values[$fallback] = null;
        } else {
            $values[$fallback] = $id;
        }
        $values[$discriminator] = $kind;
        unset($values[$legacy]);
        return $values;
    }

    public function legacyChanges(array $columns): array
    {
        [$legacy, , $fallback, , $properties] = self::parentBindings();
        if (array_intersect($columns, [$fallback, ...array_column($properties, 1)])) {
            $columns[] = $legacy;
        }
        return array_values(array_unique($columns));
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateParent(): void
    {
        $bindings = self::parentBindings();
        [, $discriminator, $fallback, $fallbackProperty, $properties] = $bindings;
        $kind = $this->{$discriminator};
        $opaque = $this->{$fallbackProperty};
        $values = [$discriminator => $kind, $fallback => $opaque];
        foreach ($properties as [$property, $column, $binding]) {
            $target = $this->{$property->name};
            // A newly persisted owning target has no identity yet; native FK
            // and the selected CHECK validate its eventual generated value.
            if ($target !== null && $target->id === null) {
                if (!in_array($kind, $binding->values, true) || $opaque !== null) {
                    throw new InvalidArgumentException('Component parent kind disagrees with its owning association.');
                }
                continue;
            }
            $values[$column] = $target?->id;
        }
        $this->normalizeParentInput($values, $bindings);
    }

    public static function referenceAssociation(string $kind): string
    {
        [, , , , $properties] = self::parentBindings();
        return ($properties[$kind] ?? throw new InvalidArgumentException('The component kind has no adopted association.'))[0]->name;
    }

    /** Retarget a clone without retaining an old owning or opaque identity. */
    public static function withReference(array $values, ?string $kind, int $id): array
    {
        [$legacy, $discriminator, $fallback, , $properties] = self::parentBindings();
        unset($values[$fallback]);
        foreach ($properties as [, $column]) {
            unset($values[$column]);
        }
        $values[$discriminator] = $kind;
        $values[$legacy] = $id;
        return $values;
    }
}
