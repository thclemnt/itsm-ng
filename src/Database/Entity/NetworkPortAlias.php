<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportaliases')]
#[ORM\UniqueConstraint(name: 'networkportaliases_networkports_id', columns: ['networkports_id'])]
class NetworkPortAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?NetworkPort $networkports_id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id_alias', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?NetworkPort $networkports_id_alias = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
