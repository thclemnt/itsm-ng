<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_registeredids')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_registeredids_name')]
#[SchemaIndex('item', ['items_id', 'itemtype'], postgresqlName: 'glpi_registeredids_item')]
#[SchemaIndex('device_type', ['device_type'], postgresqlName: 'glpi_registeredids_device_type')]
#[SchemaIndex('devicenetworkcards_id', ['devicenetworkcards_id'], postgresqlName: 'glpi_registeredids_devicenetworkcards_id')]
#[SchemaIndex('devicepcis_id', ['devicepcis_id'], postgresqlName: 'glpi_registeredids_devicepcis_id')]
#[SchemaIndex('devicecontrols_id', ['devicecontrols_id'], postgresqlName: 'glpi_registeredids_devicecontrols_id')]
#[SchemaIndex('devicegraphiccards_id', ['devicegraphiccards_id'], postgresqlName: 'glpi_registeredids_devicegraphiccards_id')]
#[SchemaIndex('devicesoundcards_id', ['devicesoundcards_id'], postgresqlName: 'glpi_registeredids_devicesoundcards_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_registeredids_manufacturers_id')]
#[RequiredSubjectConstraint('parent_kind')]
class RegisteredID implements LegacyInput
{
    use OpenParentInput;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: DeviceNetworkCard::class)]
    #[ORM\JoinColumn(name: 'devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_devicenetworkcards_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DeviceNetworkCard'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?DeviceNetworkCard $networkCard = null;

    #[ORM\ManyToOne(targetEntity: DevicePci::class)]
    #[ORM\JoinColumn(name: 'devicepcis_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_devicepcis_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DevicePci'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?DevicePci $pci = null;

    #[ORM\ManyToOne(targetEntity: DeviceControl::class)]
    #[ORM\JoinColumn(name: 'devicecontrols_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_devicecontrols_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DeviceControl'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?DeviceControl $control = null;

    #[ORM\ManyToOne(targetEntity: DeviceGraphicCard::class)]
    #[ORM\JoinColumn(name: 'devicegraphiccards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_devicegraphiccards_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DeviceGraphicCard'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?DeviceGraphicCard $graphicCard = null;

    #[ORM\ManyToOne(targetEntity: DeviceSoundCard::class)]
    #[ORM\JoinColumn(name: 'devicesoundcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_devicesoundcards_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DeviceSoundCard'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?DeviceSoundCard $soundCard = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_registeredids_manufacturers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Manufacturer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Manufacturer $manufacturer = null;

    /** Other containing kinds retain their exact signed identities. */
    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`device_type`', type: 'string', length: 100, nullable: false, options: ['comment' => 'USB, PCI ...'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $device_type = '';
}
