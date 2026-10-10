<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportaggregateorigins')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('aggregate_origin', ['networkportaggregates_id', 'networkports_id'], unique: true)]
#[SchemaIndex('aggregate_position', ['networkportaggregates_id', 'position'], unique: true)]
#[SchemaIndex('IDX_9F6D77C5BE39D62A', ['networkportaggregates_id'])]
#[SchemaIndex('IDX_9F6D77C57AEC211', ['networkports_id'])]
class NetworkPortAggregateOrigin
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPortAggregate::class)]
    #[ORM\JoinColumn(name: 'networkportaggregates_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportaggregateorigins_networkportaggregates_id')]
    #[ApplicationManaged]
    public ?NetworkPortAggregate $aggregate = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportaggregateorigins_networkports_id')]
    #[ApplicationManaged]
    public ?NetworkPort $port = null;

    #[ORM\Column(type: 'integer')]
    public int $position = 0;
}
