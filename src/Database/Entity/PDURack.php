<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_pdus_racks')]
class PDURack
{
    #[ORM\ManyToOne(targetEntity: PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?PDU $pdus = null;

    #[ORM\ManyToOne(targetEntity: Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Rack $racks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`side`', type: 'integer', nullable: true, options: ['default' => '0'])]
    public ?int $side = 0;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
