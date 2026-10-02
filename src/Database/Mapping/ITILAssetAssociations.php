<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;

/** The core helpdesk assets shared by ticket, change and problem associations. */
trait ITILAssetAssociations
{
    use AssetAssociations;

    #[ORM\ManyToOne(targetEntity: Entity\DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['DCRoom'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\DCRoom $dcRoom = null;

    #[ORM\ManyToOne(targetEntity: Entity\Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Rack'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Rack $rack = null;

    #[ORM\ManyToOne(targetEntity: Entity\Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Enclosure'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Enclosure $enclosure = null;

    #[ORM\ManyToOne(targetEntity: Entity\PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['PDU'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\PDU $pdu = null;

    #[ORM\ManyToOne(targetEntity: Entity\Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Entity\DomainRecord::class)]
    #[ORM\JoinColumn(name: 'domainrecords_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['DomainRecord'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\DomainRecord $domainRecord = null;

    #[ORM\ManyToOne(targetEntity: Entity\ItemDeviceSimcard::class)]
    #[ORM\JoinColumn(name: 'items_devicesimcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Item_DeviceSimcard'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\ItemDeviceSimcard $simcard = null;

    #[ORM\ManyToOne(targetEntity: Entity\PassiveDCEquipment::class)]
    #[ORM\JoinColumn(name: 'passivedcequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['PassiveDCEquipment'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\PassiveDCEquipment $passiveDCEquipment = null;

}
