<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_operatingsystems')]
#[ORM\UniqueConstraint(name: 'items_operatingsystems_unicity', columns: ['items_id', 'itemtype', 'operatingsystem_key', 'architecture_key'])]
class ItemOperatingSystem implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\ItemReference;
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[\itsmng\Database\Mapping\DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystem::class)]
    #[ORM\JoinColumn(name: 'operatingsystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystem $operatingsystems = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemVersion::class)]
    #[ORM\JoinColumn(name: 'operatingsystemversions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemVersion $operatingsystemversions = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemServicePack::class)]
    #[ORM\JoinColumn(name: 'operatingsystemservicepacks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemServicePack $operatingsystemservicepacks = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemArchitecture::class)]
    #[ORM\JoinColumn(name: 'operatingsystemarchitectures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemArchitecture $operatingsystemarchitectures = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemKernelVersion::class)]
    #[ORM\JoinColumn(name: 'operatingsystemkernelversions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemKernelVersion $operatingsystemkernelversions = null;

    #[ORM\Column(name: '`license_number`', type: 'string', length: 255, nullable: true)]
    public ?string $license_number = null;

    #[ORM\Column(name: '`licenseid`', type: 'string', length: 255, nullable: true)]
    public ?string $licenseid = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemEdition::class)]
    #[ORM\JoinColumn(name: 'operatingsystemeditions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemEdition $operatingsystemeditions = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;
    #[ORM\Column(name: 'operatingsystem_key', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: 'BIGINT GENERATED ALWAYS AS (COALESCE(operatingsystems_id, 0)) STORED')]
    public ?int $operatingsystem_key = null;

    #[ORM\Column(name: 'architecture_key', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: 'BIGINT GENERATED ALWAYS AS (COALESCE(operatingsystemarchitectures_id, 0)) STORED')]
    public ?int $architecture_key = null;

}
