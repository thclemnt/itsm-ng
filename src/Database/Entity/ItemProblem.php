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
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILAssetAssociations;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_problems')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'dcRoom', joinColumns: [new ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_dcrooms_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'rack', joinColumns: [new ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_racks_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'enclosure', joinColumns: [new ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_enclosures_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'pdu', joinColumns: [new ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_pdus_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'domain', joinColumns: [new ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_domains_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'domainRecord', joinColumns: [new ORM\JoinColumn(name: 'domainrecords_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_domainrecords_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'simcard', joinColumns: [new ORM\JoinColumn(name: 'items_devicesimcards_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_items_devicesimcards_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'passiveDCEquipment', joinColumns: [new ORM\JoinColumn(name: 'passivedcequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_passivedcequipments_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'computer', joinColumns: [new ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_computers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'monitor', joinColumns: [new ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_monitors_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'networkEquipment', joinColumns: [new ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_networkequipments_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'peripheral', joinColumns: [new ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_peripherals_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'phone', joinColumns: [new ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_phones_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'printer', joinColumns: [new ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_printers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'software', joinColumns: [new ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_softwares_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'softwareLicense', joinColumns: [new ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_softwarelicenses_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'certificate', joinColumns: [new ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_certificates_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'line', joinColumns: [new ORM\JoinColumn(name: 'lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_lines_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'cluster', joinColumns: [new ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_clusters_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'appliance', joinColumns: [new ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_appliances_id', options: ['default' => null])])
])]
#[SchemaIndex('unicity', ['problems_id', 'itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_items_problems_unicity')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_problems_item')]
#[SchemaIndex('glpi_items_problems_dcrooms_id', ['dcrooms_id'], postgresqlName: 'glpi_items_problems_dcrooms_id')]
#[SchemaIndex('glpi_items_problems_racks_id', ['racks_id'], postgresqlName: 'glpi_items_problems_racks_id')]
#[SchemaIndex('glpi_items_problems_enclosures_id', ['enclosures_id'], postgresqlName: 'glpi_items_problems_enclosures_id')]
#[SchemaIndex('glpi_items_problems_pdus_id', ['pdus_id'], postgresqlName: 'glpi_items_problems_pdus_id')]
#[SchemaIndex('glpi_items_problems_domains_id', ['domains_id'], postgresqlName: 'glpi_items_problems_domains_id')]
#[SchemaIndex('glpi_items_problems_domainrecords_id', ['domainrecords_id'], postgresqlName: 'glpi_items_problems_domainrecords_id')]
#[SchemaIndex('glpi_items_problems_items_devicesimcards_id', ['items_devicesimcards_id'], postgresqlName: 'glpi_items_problems_items_devicesimcards_id')]
#[SchemaIndex('glpi_items_problems_passivedcequipments_id', ['passivedcequipments_id'], postgresqlName: 'glpi_items_problems_passivedcequipments_id')]
#[SchemaIndex('glpi_items_problems_computers_id', ['computers_id'], postgresqlName: 'glpi_items_problems_computers_id')]
#[SchemaIndex('glpi_items_problems_monitors_id', ['monitors_id'], postgresqlName: 'glpi_items_problems_monitors_id')]
#[SchemaIndex('glpi_items_problems_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_items_problems_networkequipments_id')]
#[SchemaIndex('glpi_items_problems_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_items_problems_peripherals_id')]
#[SchemaIndex('glpi_items_problems_phones_id', ['phones_id'], postgresqlName: 'glpi_items_problems_phones_id')]
#[SchemaIndex('glpi_items_problems_printers_id', ['printers_id'], postgresqlName: 'glpi_items_problems_printers_id')]
#[SchemaIndex('glpi_items_problems_softwares_id', ['softwares_id'], postgresqlName: 'glpi_items_problems_softwares_id')]
#[SchemaIndex('glpi_items_problems_softwarelicenses_id', ['softwarelicenses_id'], postgresqlName: 'glpi_items_problems_softwarelicenses_id')]
#[SchemaIndex('glpi_items_problems_certificates_id', ['certificates_id'], postgresqlName: 'glpi_items_problems_certificates_id')]
#[SchemaIndex('glpi_items_problems_lines_id', ['lines_id'], postgresqlName: 'glpi_items_problems_lines_id')]
#[SchemaIndex('glpi_items_problems_clusters_id', ['clusters_id'], postgresqlName: 'glpi_items_problems_clusters_id')]
#[SchemaIndex('glpi_items_problems_appliances_id', ['appliances_id'], postgresqlName: 'glpi_items_problems_appliances_id')]
#[SchemaIndex('IDX_72B1A88B1D637048', ['problems_id'], postgresqlName: 'IDX_72B1A88B1D637048')]
class ItemProblem implements LegacyInput
{
    use RequiredItemReference;
    use ITILAssetAssociations;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_problems_problems_id', options: ['default' => '0'])]
    #[ITILStatisticsRelation(ITILStatisticsRole::Items)]
    #[ApplicationManaged]
    public ?Problem $problems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
