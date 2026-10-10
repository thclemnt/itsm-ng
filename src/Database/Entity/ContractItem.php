<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\AssetAssociations;
use itsmng\Database\Mapping\DeviceItemAssociations;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_contracts_items')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'computer', joinColumns: [new ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_computers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'monitor', joinColumns: [new ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_monitors_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'networkEquipment', joinColumns: [new ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_networkequipments_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'peripheral', joinColumns: [new ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_peripherals_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'phone', joinColumns: [new ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_phones_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'printer', joinColumns: [new ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_printers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'software', joinColumns: [new ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_softwares_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'softwareLicense', joinColumns: [new ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_softwarelicenses_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'certificate', joinColumns: [new ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_certificates_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'line', joinColumns: [new ORM\JoinColumn(name: 'lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_lines_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'cluster', joinColumns: [new ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_clusters_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'appliance', joinColumns: [new ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_appliances_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceMotherboard', joinColumns: [new ORM\JoinColumn(name: 'items_devicemotherboards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicemotherboards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceFirmware', joinColumns: [new ORM\JoinColumn(name: 'items_devicefirmwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicefirmwares_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceProcessor', joinColumns: [new ORM\JoinColumn(name: 'items_deviceprocessors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_deviceprocessors_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceMemory', joinColumns: [new ORM\JoinColumn(name: 'items_devicememories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicememories_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceHardDrive', joinColumns: [new ORM\JoinColumn(name: 'items_deviceharddrives_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_deviceharddrives_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceNetworkCard', joinColumns: [new ORM\JoinColumn(name: 'items_devicenetworkcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicenetworkcards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceDrive', joinColumns: [new ORM\JoinColumn(name: 'items_devicedrives_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicedrives_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceBattery', joinColumns: [new ORM\JoinColumn(name: 'items_devicebatteries_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicebatteries_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceGraphicCard', joinColumns: [new ORM\JoinColumn(name: 'items_devicegraphiccards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicegraphiccards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceSoundCard', joinColumns: [new ORM\JoinColumn(name: 'items_devicesoundcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicesoundcards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceControl', joinColumns: [new ORM\JoinColumn(name: 'items_devicecontrols_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicecontrols_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'devicePci', joinColumns: [new ORM\JoinColumn(name: 'items_devicepcis_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicepcis_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceCase', joinColumns: [new ORM\JoinColumn(name: 'items_devicecases_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicecases_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'devicePowerSupply', joinColumns: [new ORM\JoinColumn(name: 'items_devicepowersupplies_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicepowersupplies_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceGeneric', joinColumns: [new ORM\JoinColumn(name: 'items_devicegenerics_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicegenerics_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceSimcard', joinColumns: [new ORM\JoinColumn(name: 'items_devicesimcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicesimcards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'deviceSensor', joinColumns: [new ORM\JoinColumn(name: 'items_devicesensors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_items_devicesensors_id', options: ['default' => null])])
])]
#[SchemaIndex('unicity', ['contracts_id', 'itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_contracts_items_unicity')]
#[SchemaIndex('FK_device', ['items_id', 'itemtype'], postgresqlName: 'glpi_contracts_items_FK_device')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_contracts_items_item')]
#[SchemaIndex('glpi_contracts_items_dcrooms_id', ['dcrooms_id'], postgresqlName: 'glpi_contracts_items_dcrooms_id')]
#[SchemaIndex('glpi_contracts_items_racks_id', ['racks_id'], postgresqlName: 'glpi_contracts_items_racks_id')]
#[SchemaIndex('glpi_contracts_items_enclosures_id', ['enclosures_id'], postgresqlName: 'glpi_contracts_items_enclosures_id')]
#[SchemaIndex('glpi_contracts_items_pdus_id', ['pdus_id'], postgresqlName: 'glpi_contracts_items_pdus_id')]
#[SchemaIndex('glpi_contracts_items_domains_id', ['domains_id'], postgresqlName: 'glpi_contracts_items_domains_id')]
#[SchemaIndex('glpi_contracts_items_projects_id', ['projects_id'], postgresqlName: 'glpi_contracts_items_projects_id')]
#[SchemaIndex('glpi_contracts_items_computers_id', ['computers_id'], postgresqlName: 'glpi_contracts_items_computers_id')]
#[SchemaIndex('glpi_contracts_items_monitors_id', ['monitors_id'], postgresqlName: 'glpi_contracts_items_monitors_id')]
#[SchemaIndex('glpi_contracts_items_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_contracts_items_networkequipments_id')]
#[SchemaIndex('glpi_contracts_items_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_contracts_items_peripherals_id')]
#[SchemaIndex('glpi_contracts_items_phones_id', ['phones_id'], postgresqlName: 'glpi_contracts_items_phones_id')]
#[SchemaIndex('glpi_contracts_items_printers_id', ['printers_id'], postgresqlName: 'glpi_contracts_items_printers_id')]
#[SchemaIndex('glpi_contracts_items_softwares_id', ['softwares_id'], postgresqlName: 'glpi_contracts_items_softwares_id')]
#[SchemaIndex('glpi_contracts_items_softwarelicenses_id', ['softwarelicenses_id'], postgresqlName: 'glpi_contracts_items_softwarelicenses_id')]
#[SchemaIndex('glpi_contracts_items_certificates_id', ['certificates_id'], postgresqlName: 'glpi_contracts_items_certificates_id')]
#[SchemaIndex('glpi_contracts_items_lines_id', ['lines_id'], postgresqlName: 'glpi_contracts_items_lines_id')]
#[SchemaIndex('glpi_contracts_items_clusters_id', ['clusters_id'], postgresqlName: 'glpi_contracts_items_clusters_id')]
#[SchemaIndex('glpi_contracts_items_appliances_id', ['appliances_id'], postgresqlName: 'glpi_contracts_items_appliances_id')]
#[SchemaIndex('glpi_contracts_items_items_devicemotherboards_id', ['items_devicemotherboards_id'], postgresqlName: 'glpi_contracts_items_items_devicemotherboards_id')]
#[SchemaIndex('glpi_contracts_items_items_devicefirmwares_id', ['items_devicefirmwares_id'], postgresqlName: 'glpi_contracts_items_items_devicefirmwares_id')]
#[SchemaIndex('glpi_contracts_items_items_deviceprocessors_id', ['items_deviceprocessors_id'], postgresqlName: 'glpi_contracts_items_items_deviceprocessors_id')]
#[SchemaIndex('glpi_contracts_items_items_devicememories_id', ['items_devicememories_id'], postgresqlName: 'glpi_contracts_items_items_devicememories_id')]
#[SchemaIndex('glpi_contracts_items_items_deviceharddrives_id', ['items_deviceharddrives_id'], postgresqlName: 'glpi_contracts_items_items_deviceharddrives_id')]
#[SchemaIndex('glpi_contracts_items_items_devicenetworkcards_id', ['items_devicenetworkcards_id'], postgresqlName: 'glpi_contracts_items_items_devicenetworkcards_id')]
#[SchemaIndex('glpi_contracts_items_items_devicedrives_id', ['items_devicedrives_id'], postgresqlName: 'glpi_contracts_items_items_devicedrives_id')]
#[SchemaIndex('glpi_contracts_items_items_devicebatteries_id', ['items_devicebatteries_id'], postgresqlName: 'glpi_contracts_items_items_devicebatteries_id')]
#[SchemaIndex('glpi_contracts_items_items_devicegraphiccards_id', ['items_devicegraphiccards_id'], postgresqlName: 'glpi_contracts_items_items_devicegraphiccards_id')]
#[SchemaIndex('glpi_contracts_items_items_devicesoundcards_id', ['items_devicesoundcards_id'], postgresqlName: 'glpi_contracts_items_items_devicesoundcards_id')]
#[SchemaIndex('glpi_contracts_items_items_devicecontrols_id', ['items_devicecontrols_id'], postgresqlName: 'glpi_contracts_items_items_devicecontrols_id')]
#[SchemaIndex('glpi_contracts_items_items_devicepcis_id', ['items_devicepcis_id'], postgresqlName: 'glpi_contracts_items_items_devicepcis_id')]
#[SchemaIndex('glpi_contracts_items_items_devicecases_id', ['items_devicecases_id'], postgresqlName: 'glpi_contracts_items_items_devicecases_id')]
#[SchemaIndex('glpi_contracts_items_items_devicepowersupplies_id', ['items_devicepowersupplies_id'], postgresqlName: 'glpi_contracts_items_items_devicepowersupplies_id')]
#[SchemaIndex('glpi_contracts_items_items_devicegenerics_id', ['items_devicegenerics_id'], postgresqlName: 'glpi_contracts_items_items_devicegenerics_id')]
#[SchemaIndex('glpi_contracts_items_items_devicesimcards_id', ['items_devicesimcards_id'], postgresqlName: 'glpi_contracts_items_items_devicesimcards_id')]
#[SchemaIndex('glpi_contracts_items_items_devicesensors_id', ['items_devicesensors_id'], postgresqlName: 'glpi_contracts_items_items_devicesensors_id')]
#[SchemaIndex('IDX_5FF01F0E8759C3BD', ['contracts_id'], postgresqlName: 'IDX_5FF01F0E8759C3BD')]
class ContractItem implements LegacyInput
{
    use RequiredItemReference;
    use AssetAssociations;
    use DeviceItemAssociations;
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_contracts_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Contract $contracts = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity\DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_dcrooms_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['DCRoom'])]
    #[ApplicationManaged]
    public ?Entity\DCRoom $dcRoom = null;

    #[ORM\ManyToOne(targetEntity: Entity\Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_racks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Rack'])]
    #[ApplicationManaged]
    public ?Entity\Rack $rack = null;

    #[ORM\ManyToOne(targetEntity: Entity\Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_enclosures_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Enclosure'])]
    #[ApplicationManaged]
    public ?Entity\Enclosure $enclosure = null;

    #[ORM\ManyToOne(targetEntity: Entity\PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_pdus_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['PDU'])]
    #[ApplicationManaged]
    public ?Entity\PDU $pdu = null;

    #[ORM\ManyToOne(targetEntity: Entity\Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_domains_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Entity\Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Entity\Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_items_projects_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Entity\Project $project = null;

}
