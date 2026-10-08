<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportwifis')]
#[ORM\UniqueConstraint(name: 'networkportwifis_networkports_id', columns: ['networkports_id'])]
class NetworkPortWifi
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?NetworkPort $networkports_id = null;

    #[ORM\ManyToOne(targetEntity: ItemDeviceNetworkCard::class)]
    #[ORM\JoinColumn(name: 'items_devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ItemDeviceNetworkCard $items_devicenetworkcards_id = null;

    #[ORM\ManyToOne(targetEntity: WifiNetwork::class)]
    #[ORM\JoinColumn(name: 'wifinetworks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?WifiNetwork $wifinetworks_id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPortWifi::class)]
    #[ORM\JoinColumn(name: 'networkportwifis_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?NetworkPortWifi $networkportwifis_id = null;

    #[ORM\Column(name: '`version`', type: 'string', length: 20, nullable: true)]
    public ?string $version = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: true)]
    public ?string $mode = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
