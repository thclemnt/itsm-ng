<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;

/** Common asset relationships reused by ITIL and contract links. */
trait AssetAssociations
{
    #[ORM\ManyToOne(targetEntity: Entity\Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Entity\Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: Entity\NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Entity\Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Entity\Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Entity\Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: Entity\Software::class)]
    #[ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Software $software = null;

    #[ORM\ManyToOne(targetEntity: Entity\SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\SoftwareLicense $softwareLicense = null;

    #[ORM\ManyToOne(targetEntity: Entity\Certificate::class)]
    #[ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Certificate $certificate = null;

    #[ORM\ManyToOne(targetEntity: Entity\Line::class)]
    #[ORM\JoinColumn(name: 'lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Line'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Line $line = null;

    #[ORM\ManyToOne(targetEntity: Entity\Cluster::class)]
    #[ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Cluster'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Cluster $cluster = null;

    #[ORM\ManyToOne(targetEntity: Entity\Appliance::class)]
    #[ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Appliance'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Appliance $appliance = null;

}
