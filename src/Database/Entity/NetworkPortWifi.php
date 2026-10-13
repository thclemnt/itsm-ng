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
#[ORM\Table(name: 'glpi_networkportwifis')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('networkports_id', ['networkports_id'], unique: true, postgresqlName: 'glpi_networkportwifis_networkports_id')]
#[SchemaIndex('card', ['items_devicenetworkcards_id'], postgresqlName: 'glpi_networkportwifis_card')]
#[SchemaIndex('essid', ['wifinetworks_id'], postgresqlName: 'glpi_networkportwifis_essid')]
#[SchemaIndex('version', ['version'], postgresqlName: 'glpi_networkportwifis_version')]
#[SchemaIndex('mode', ['mode'], postgresqlName: 'glpi_networkportwifis_mode')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_networkportwifis_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_networkportwifis_date_creation')]
#[SchemaIndex('IDX_FB43456A1EB90C3F', ['networkportwifis_id'])]
class NetworkPortWifi
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportwifis_networkports_id', options: ['default' => '0'])]
    public ?NetworkPort $networkports_id = null;

    #[ORM\ManyToOne(targetEntity: ItemDeviceNetworkCard::class)]
    #[ORM\JoinColumn(name: 'items_devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportwifis_items_devicenetworkcards_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ItemDeviceNetworkCard $items_devicenetworkcards_id = null;

    #[ORM\ManyToOne(targetEntity: WifiNetwork::class)]
    #[ORM\JoinColumn(name: 'wifinetworks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportwifis_wifinetworks_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?WifiNetwork $wifinetworks_id = null;

    #[ORM\ManyToOne(targetEntity: NetworkPortWifi::class)]
    #[ORM\JoinColumn(name: 'networkportwifis_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkportwifis_networkportwifis_id', options: ['comment' => 'only useful in case of Managed node'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?NetworkPortWifi $networkportwifis_id = null;

    #[ORM\Column(name: '`version`', type: 'string', length: 20, nullable: true, options: ['comment' => 'a, a/b, a/b/g, a/b/g/n, a/b/g/n/y'])]
    public ?string $version = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: true, options: ['comment' => 'ad-hoc, managed, master, repeater, secondary, monitor, auto'])]
    public ?string $mode = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
