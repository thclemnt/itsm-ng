<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportaggregateorigins')]
#[ORM\UniqueConstraint(name: 'aggregate_origin', columns: ['networkportaggregates_id', 'networkports_id'])]
#[ORM\UniqueConstraint(name: 'aggregate_position', columns: ['networkportaggregates_id', 'position'])]
class NetworkPortAggregateOrigin
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPortAggregate::class)]
    #[ORM\JoinColumn(name: 'networkportaggregates_id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?NetworkPortAggregate $aggregate = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?NetworkPort $port = null;

    #[ORM\Column(type: 'integer')]
    public int $position = 0;
}
