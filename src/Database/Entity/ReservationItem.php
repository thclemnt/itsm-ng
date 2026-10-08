<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_reservationitems')]
class ReservationItem implements LegacyInput
{
    use RequiredItemReference;

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

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[ApplicationManaged]
    public ?Software $software = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_active = true;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;
}
