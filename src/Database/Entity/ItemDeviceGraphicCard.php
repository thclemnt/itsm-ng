<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_devicegraphiccards')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[RequiredSubjectConstraint('parent_kind')]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('glpi_items_devicegraphiccards_computer_owner', ['computers_id'])]
#[SchemaIndex('computers_id', ['items_id'], postgresqlName: 'glpi_items_devicegraphiccards_computers_id')]
#[SchemaIndex('devicegraphiccards_id', ['devicegraphiccards_id'], postgresqlName: 'glpi_items_devicegraphiccards_devicegraphiccards_id')]
#[SchemaIndex('specificity', ['memory'], postgresqlName: 'glpi_items_devicegraphiccards_specificity')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_items_devicegraphiccards_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_devicegraphiccards_is_dynamic')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_items_devicegraphiccards_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_items_devicegraphiccards_is_recursive')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_items_devicegraphiccards_serial')]
#[SchemaIndex('busID', ['busID'], postgresqlName: 'glpi_items_devicegraphiccards_busID')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_devicegraphiccards_item')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_items_devicegraphiccards_otherserial')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_items_devicegraphiccards_locations_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_items_devicegraphiccards_states_id')]
class ItemDeviceGraphicCard implements LegacyInput
{
    #[ORM\ManyToOne(targetEntity: DeviceGraphicCard::class)]
    #[ORM\JoinColumn(name: 'devicegraphiccards_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_devicegraphiccards_devicegraphiccards_id')]
    public ?DeviceGraphicCard $devicegraphiccards = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicegraphiccards_computers_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    /** Stock and other extensible parent kinds retain their exact legacy identity. */
    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`memory`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $memory = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_devicegraphiccards_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`busID`', type: 'string', length: 255, nullable: true)]
    public ?string $busID = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicegraphiccards_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicegraphiccards_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;
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
        return [$legacy, $discriminator, $fallback, $fallbackProperty, $properties];
    }

    /** Resolve only adopted kinds; the property's opaque slot preserves every other parent. */
    public function normalizeInput(array $values): array
    {
        return $this->normalizeParentInput($values, self::parentBindings());
    }

    private function normalizeParentInput(array $values, array $bindings): array
    {
        [$legacy, $discriminator, $fallback, , $properties] = $bindings;
        $columns = array_column($properties, 1);
        if (!array_intersect(array_keys($values), [$discriminator, $legacy, $fallback, ...$columns])) {
            return $values;
        }
        $kind = array_key_exists($discriminator, $values) ? $values[$discriminator] : $this->{$discriminator};
        if ($kind !== null && !is_scalar($kind) && !$kind instanceof Stringable) {
            throw new InvalidArgumentException('Component parent kind must be a string or null.');
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
