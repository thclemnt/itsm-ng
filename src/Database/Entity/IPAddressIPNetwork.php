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
#[ORM\Table(name: 'glpi_ipaddresses_ipnetworks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['ipaddresses_id', 'ipnetworks_id'], unique: true, postgresqlName: 'glpi_ipaddresses_ipnetworks_unicity')]
#[SchemaIndex('ipnetworks_id', ['ipnetworks_id'], postgresqlName: 'glpi_ipaddresses_ipnetworks_ipnetworks_id')]
#[SchemaIndex('ipaddresses_id', ['ipaddresses_id'], postgresqlName: 'glpi_ipaddresses_ipnetworks_ipaddresses_id')]
class IPAddressIPNetwork
{
    #[ORM\ManyToOne(targetEntity: IPAddress::class)]
    #[ORM\JoinColumn(name: 'ipaddresses_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ipaddresses_ipnetworks_ipaddresses_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?IPAddress $ipaddresses = null;

    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ipaddresses_ipnetworks_ipnetworks_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?IPNetwork $ipnetworks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
