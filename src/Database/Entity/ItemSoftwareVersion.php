<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_softwareversions')]
#[ORM\UniqueConstraint(name: 'items_softwareversions_unicity', columns: ['itemtype', 'items_id', 'softwareversions_id'])]
class ItemSoftwareVersion implements LegacyInput
{
    use ItemReference;

    #[ORM\ManyToOne(targetEntity: SoftwareVersion::class)]
    #[ORM\JoinColumn(name: 'softwareversions_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?SoftwareVersion $softwareversions = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\Column(name: '`is_deleted_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted_item = false;

    #[ORM\Column(name: '`is_template_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template_item = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_install`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_install = null;
}
