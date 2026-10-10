<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportfiberchannels')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('networkports_id', ['networkports_id'], unique: true, postgresqlName: 'glpi_networkportfiberchannels_networkports_id')]
#[SchemaIndex('card', ['items_devicenetworkcards_id'], postgresqlName: 'glpi_networkportfiberchannels_card')]
#[SchemaIndex('netpoint', ['netpoints_id'], postgresqlName: 'glpi_networkportfiberchannels_netpoint')]
#[SchemaIndex('wwn', ['wwn'], postgresqlName: 'glpi_networkportfiberchannels_wwn')]
#[SchemaIndex('speed', ['speed'], postgresqlName: 'glpi_networkportfiberchannels_speed')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_networkportfiberchannels_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_networkportfiberchannels_date_creation')]
class NetworkPortFiberchannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportfiberchannels_networkports_id', options: ['default' => '0'])]
    public ?NetworkPort $networkports_id = null;

    #[ORM\ManyToOne(targetEntity: ItemDeviceNetworkCard::class)]
    #[ORM\JoinColumn(name: 'items_devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportfiberchannels_items_devicenetworkcards_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ItemDeviceNetworkCard $items_devicenetworkcards_id = null;

    #[ORM\ManyToOne(targetEntity: Netpoint::class)]
    #[ORM\JoinColumn(name: 'netpoints_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportfiberchannels_netpoints_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Netpoint $netpoints_id = null;

    #[ORM\Column(name: '`wwn`', type: 'string', length: 16, nullable: true, options: ['default' => ''])]
    public ?string $wwn = '';

    #[ORM\Column(name: '`speed`', type: 'integer', nullable: false, options: ['default' => '10', 'comment' => 'Mbit/s: 10, 100, 1000, 10000'])]
    public int $speed = 10;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
