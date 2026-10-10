<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkports_vlans')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['networkports_id', 'vlans_id'], unique: true, postgresqlName: 'glpi_networkports_vlans_unicity')]
#[SchemaIndex('vlans_id', ['vlans_id'], postgresqlName: 'glpi_networkports_vlans_vlans_id')]
#[SchemaIndex('IDX_84FF692C7AEC211', ['networkports_id'])]
class NetworkPortVlan
{
    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_vlans_networkports_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?NetworkPort $networkports = null;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(name: 'vlans_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_vlans_vlans_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Vlan $vlans = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tagged`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $tagged = false;
}
