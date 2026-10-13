<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_certificates_items')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'itemtype', column: new ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false, options: ['comment' => 'see .class.php file'])),
    new ORM\AttributeOverride(name: 'items_id', column: new ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', options: ['comment' => 'RELATION to various tables, according to itemtype (id)'])),
])]
#[SchemaIndex('unicity', ['certificates_id', 'itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_certificates_items_unicity')]
#[SchemaIndex('device', ['items_id', 'itemtype'], postgresqlName: 'glpi_certificates_items_device')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_certificates_items_item')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_certificates_items_date_creation')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_certificates_items_date_mod')]
#[SchemaIndex('glpi_certificates_items_computers_id', ['computers_id'], postgresqlName: 'glpi_certificates_items_computers_id')]
#[SchemaIndex('glpi_certificates_items_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_certificates_items_networkequipments_id')]
#[SchemaIndex('glpi_certificates_items_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_certificates_items_peripherals_id')]
#[SchemaIndex('glpi_certificates_items_phones_id', ['phones_id'], postgresqlName: 'glpi_certificates_items_phones_id')]
#[SchemaIndex('glpi_certificates_items_printers_id', ['printers_id'], postgresqlName: 'glpi_certificates_items_printers_id')]
#[SchemaIndex('glpi_certificates_items_softwarelicenses_id', ['softwarelicenses_id'], postgresqlName: 'glpi_certificates_items_softwarelicenses_id')]
#[SchemaIndex('glpi_certificates_items_users_id', ['users_id'], postgresqlName: 'glpi_certificates_items_users_id')]
#[SchemaIndex('glpi_certificates_items_domains_id', ['domains_id'], postgresqlName: 'glpi_certificates_items_domains_id')]
#[SchemaIndex('glpi_certificates_items_appliances_id', ['appliances_id'], postgresqlName: 'glpi_certificates_items_appliances_id')]
#[SchemaIndex('IDX_E410E2459596ED26', ['certificates_id'], postgresqlName: 'IDX_E410E2459596ED26')]
class CertificateItem implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_certificates_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Certificate $certificates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_networkequipments_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_peripherals_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_phones_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_printers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_softwarelicenses_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[ApplicationManaged]
    public ?SoftwareLicense $softwareLicense = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_users_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_domains_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Appliance::class)]
    #[ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_items_appliances_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Appliance'])]
    #[ApplicationManaged]
    public ?Appliance $appliance = null;

}
