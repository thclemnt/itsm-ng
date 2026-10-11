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
#[ORM\Table(name: 'glpi_networkports_networkports')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['networkports_id_1', 'networkports_id_2'], unique: true, postgresqlName: 'glpi_networkports_networkports_unicity')]
#[SchemaIndex('networkports_id_2', ['networkports_id_2'], postgresqlName: 'glpi_networkports_networkports_networkports_id_2')]
#[SchemaIndex('IDX_DF0512CAC3B2519A', ['networkports_id_1'])]
class NetworkPortNetworkPort
{
    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id_1', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_networkports_networkports_id_1', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?NetworkPort $networkports_id_1 = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id_2', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_networkports_networkports_id_2', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?NetworkPort $networkports_id_2 = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
