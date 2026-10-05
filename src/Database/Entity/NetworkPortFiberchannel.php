<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportfiberchannels')]
#[ORM\UniqueConstraint(name: 'networkportfiberchannels_networkports_id', columns: ['networkports_id'])]
class NetworkPortFiberchannel
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

    #[ORM\ManyToOne(targetEntity: Netpoint::class)]
    #[ORM\JoinColumn(name: 'netpoints_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Netpoint $netpoints_id = null;

    #[ORM\Column(name: '`wwn`', type: 'string', length: 16, nullable: true, options: ['default' => ''])]
    public ?string $wwn = '';

    #[ORM\Column(name: '`speed`', type: 'integer', nullable: false, options: ['default' => '10'])]
    public int $speed = 10;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;
}
