<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;

/** Physical placement owns one rackable asset, separately from its container. */
trait RackableItemReference
{
    use ItemReference;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Entity\Computer::class)]
    #[ORM\JoinColumn(name: 'asset_computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Entity\Computer $assetComputer = null;

    #[ORM\ManyToOne(targetEntity: Entity\Monitor::class)]
    #[ORM\JoinColumn(name: 'asset_monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Entity\Monitor $assetMonitor = null;

    #[ORM\ManyToOne(targetEntity: Entity\NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'asset_networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?Entity\NetworkEquipment $assetNetworkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Entity\Peripheral::class)]
    #[ORM\JoinColumn(name: 'asset_peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Entity\Peripheral $assetPeripheral = null;

    #[ORM\ManyToOne(targetEntity: Entity\Enclosure::class)]
    #[ORM\JoinColumn(name: 'asset_enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Enclosure'])]
    #[ApplicationManaged]
    public ?Entity\Enclosure $assetEnclosure = null;

    #[ORM\ManyToOne(targetEntity: Entity\PDU::class)]
    #[ORM\JoinColumn(name: 'asset_pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PDU'])]
    #[ApplicationManaged]
    public ?Entity\PDU $assetPdu = null;

    #[ORM\ManyToOne(targetEntity: Entity\PassiveDCEquipment::class)]
    #[ORM\JoinColumn(name: 'asset_passivedcequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PassiveDCEquipment'])]
    #[ApplicationManaged]
    public ?Entity\PassiveDCEquipment $assetPassiveDCEquipment = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey]
    public ?int $items_id = null;
}
