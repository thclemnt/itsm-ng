<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networknames')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[RequiredSubjectConstraint('parent_kind')]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('glpi_networknames_networkports_id', ['networkports_id'])]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_networknames_entities_id')]
#[SchemaIndex('FQDN', ['name', 'fqdns_id'], postgresqlName: 'glpi_networknames_FQDN')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_networknames_name')]
#[SchemaIndex('fqdns_id', ['fqdns_id'], postgresqlName: 'glpi_networknames_fqdns_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_networknames_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_networknames_is_dynamic')]
#[SchemaIndex('item', ['itemtype', 'items_id', 'is_deleted'], postgresqlName: 'glpi_networknames_item')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_networknames_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_networknames_date_creation')]
class NetworkName implements LegacyInput
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_networknames_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networknames_networkports_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkPort'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?NetworkPort $networkPort = null;

    /** Other parent kinds retain their existing extensible, unconstrained identity. */
    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: FQDN::class)]
    #[ORM\JoinColumn(name: 'fqdns_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networknames_fqdns_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?FQDN $fqdns_id = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
    /** Read the parent policy from its owning property attributes, without a registry. */
    private function parentBindings(): array
    {
        $identity = new ReflectionProperty(self::class, 'items_id');
        $legacy = trim($identity->getAttributes(ORM\Column::class)[0]->newInstance()->name, '`');
        $key = $identity->getAttributes(DiscriminatorKey::class)[0]->newInstance();
        $fallbackProperty = $key->fallbackProperty;
        $fallback = trim((new ReflectionProperty(self::class, $fallbackProperty))->getAttributes(ORM\Column::class)[0]->newInstance()->name, '`');
        $properties = [];
        $discriminator = null;
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
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
        return $this->normalizeParentInput($values, $this->parentBindings());
    }

    private function normalizeParentInput(array $values, array $bindings): array
    {
        [$legacy, $discriminator, $fallback, , $properties] = $bindings;
        $columns = array_column($properties, 1);
        if (!array_intersect(array_keys($values), [$discriminator, $legacy, $fallback, ...$columns])) {
            return $values;
        }
        $kind = array_key_exists($discriminator, $values) ? $values[$discriminator] : $this->{$discriminator};
        if ($kind === null || (!is_scalar($kind) && !$kind instanceof Stringable)) {
            throw new InvalidArgumentException('Network name parent kind must be a nonnull string.');
        }
        $kind = (string)$kind;
        $selected = $properties[$kind] ?? null;
        $column = $selected[1] ?? $fallback;
        $id = array_key_exists($column, $values) ? $values[$column]
            : (array_key_exists($legacy, $values) ? $values[$legacy] : $this->{$legacy});
        // Preserve the existing mapped integer conversion; null is not legacy zero.
        if ((array_key_exists($legacy, $values) && $values[$legacy] === null) || ($id === null && $selected === null)) {
            throw new InvalidArgumentException('Network name parent identity cannot be null.');
        }
        $id = (int)$id;
        if (array_key_exists($column, $values) && array_key_exists($legacy, $values)
            && ($values[$legacy] === null || (int)$values[$legacy] !== $id)) {
            throw new InvalidArgumentException('Legacy and canonical network name parents disagree.');
        }
        foreach ($columns as $owner) {
            if ($owner !== $column && ($values[$owner] ?? null) !== null) {
                throw new InvalidArgumentException('Network name parent kind cannot select an adopted association.');
            }
            $values[$owner] = $owner === $column && $id > 0 ? $id : null;
        }
        if ($selected !== null) {
            if ($id < 0 || ($values[$fallback] ?? null) !== null) {
                throw new InvalidArgumentException('An adopted network name parent requires zero or a positive owner, without an opaque identity.');
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
        [$legacy, , $fallback, , $properties] = $this->parentBindings();
        if (array_intersect($columns, [$fallback, ...array_column($properties, 1)])) {
            $columns[] = $legacy;
        }
        return array_values(array_unique($columns));
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateParent(): void
    {
        $bindings = $this->parentBindings();
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
                    throw new InvalidArgumentException('Network name parent kind disagrees with its owning association.');
                }
                continue;
            }
            $values[$column] = $target?->id;
        }
        $this->normalizeParentInput($values, $bindings);
    }
}
