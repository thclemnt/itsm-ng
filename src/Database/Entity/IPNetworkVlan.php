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
#[ORM\Table(name: 'glpi_ipnetworks_vlans')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('link', ['ipnetworks_id', 'vlans_id'], unique: true, postgresqlName: 'glpi_ipnetworks_vlans_link')]
#[SchemaIndex('IDX_35A7AD8AB0247248', ['ipnetworks_id'])]
#[SchemaIndex('IDX_35A7AD8AF96F069', ['vlans_id'])]
class IPNetworkVlan
{
    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ipnetworks_vlans_ipnetworks_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?IPNetwork $ipnetworks = null;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(name: 'vlans_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ipnetworks_vlans_vlans_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Vlan $vlans = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
