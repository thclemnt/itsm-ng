<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_objectlocks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('item', ['itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_objectlocks_item')]
#[SchemaIndex('glpi_objectlocks_subject_budgets_id', ['subject_budgets_id'])]
#[SchemaIndex('glpi_objectlocks_subject_changes_id', ['subject_changes_id'])]
#[SchemaIndex('glpi_objectlocks_subject_contacts_id', ['subject_contacts_id'])]
#[SchemaIndex('glpi_objectlocks_subject_contracts_id', ['subject_contracts_id'])]
#[SchemaIndex('glpi_objectlocks_subject_documents_id', ['subject_documents_id'])]
#[SchemaIndex('glpi_objectlocks_subject_cartridgeitems_id', ['subject_cartridgeitems_id'])]
#[SchemaIndex('glpi_objectlocks_subject_computers_id', ['subject_computers_id'])]
#[SchemaIndex('glpi_objectlocks_subject_consumableitems_id', ['subject_consumableitems_id'])]
#[SchemaIndex('glpi_objectlocks_subject_entities_id', ['subject_entities_id'])]
#[SchemaIndex('glpi_objectlocks_subject_groups_id', ['subject_groups_id'])]
#[SchemaIndex('glpi_objectlocks_subject_knowbaseitems_id', ['subject_knowbaseitems_id'])]
#[SchemaIndex('glpi_objectlocks_subject_lines_id', ['subject_lines_id'])]
#[SchemaIndex('glpi_objectlocks_subject_links_id', ['subject_links_id'])]
#[SchemaIndex('glpi_objectlocks_subject_monitors_id', ['subject_monitors_id'])]
#[SchemaIndex('glpi_objectlocks_subject_networkequipments_id', ['subject_networkequipments_id'])]
#[SchemaIndex('glpi_objectlocks_subject_networknames_id', ['subject_networknames_id'])]
#[SchemaIndex('glpi_objectlocks_subject_peripherals_id', ['subject_peripherals_id'])]
#[SchemaIndex('glpi_objectlocks_subject_phones_id', ['subject_phones_id'])]
#[SchemaIndex('glpi_objectlocks_subject_printers_id', ['subject_printers_id'])]
#[SchemaIndex('glpi_objectlocks_subject_problems_id', ['subject_problems_id'])]
#[SchemaIndex('glpi_objectlocks_subject_profiles_id', ['subject_profiles_id'])]
#[SchemaIndex('glpi_objectlocks_subject_projects_id', ['subject_projects_id'])]
#[SchemaIndex('glpi_objectlocks_subject_reminders_id', ['subject_reminders_id'])]
#[SchemaIndex('glpi_objectlocks_subject_rssfeeds_id', ['subject_rssfeeds_id'])]
#[SchemaIndex('glpi_objectlocks_subject_softwares_id', ['subject_softwares_id'])]
#[SchemaIndex('glpi_objectlocks_subject_suppliers_id', ['subject_suppliers_id'])]
#[SchemaIndex('glpi_objectlocks_subject_tickets_id', ['subject_tickets_id'])]
#[SchemaIndex('glpi_objectlocks_subject_users_id', ['subject_users_id'])]
#[SchemaIndex('glpi_objectlocks_subject_softwarelicenses_id', ['subject_softwarelicenses_id'])]
#[SchemaIndex('glpi_objectlocks_subject_certificates_id', ['subject_certificates_id'])]
#[SchemaIndex('IDX_55A8E45D13DB09D8', ['users_id'])]
class ObjectLock implements LegacyInput
{
    use ItemReference;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false, options: ['comment' => 'Type of locked object'])]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', options: ['comment' => 'RELATION to various tables, according to itemtype (ID)'])]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'subject_budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_budgets_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Budget'])]
    #[ApplicationManaged]
    public ?Budget $subjectBudget = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'subject_changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_changes_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[ApplicationManaged]
    public ?Change $subjectChange = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'subject_contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_contacts_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[ApplicationManaged]
    public ?Contact $subjectContact = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'subject_contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_contracts_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $subjectContract = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'subject_documents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_documents_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Document'])]
    #[ApplicationManaged]
    public ?Document $subjectDocument = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'subject_cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_cartridgeitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $subjectCartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'subject_computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $subjectComputer = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'subject_consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_consumableitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $subjectConsumableItem = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'subject_entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_entities_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Entity'])]
    #[ApplicationManaged]
    public ?Entity $subjectEntity = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'subject_groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_groups_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Group'])]
    #[ApplicationManaged]
    public ?Group $subjectGroup = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'subject_knowbaseitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_knowbaseitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['KnowbaseItem'])]
    #[ApplicationManaged]
    public ?KnowbaseItem $subjectKnowbaseItem = null;

    #[ORM\ManyToOne(targetEntity: Line::class)]
    #[ORM\JoinColumn(name: 'subject_lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_lines_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Line'])]
    #[ApplicationManaged]
    public ?Line $subjectLine = null;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'subject_links_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_links_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Link'])]
    #[ApplicationManaged]
    public ?Link $subjectLink = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'subject_monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_monitors_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $subjectMonitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'subject_networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_networkequipments_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $subjectNetworkEquipment = null;

    #[ORM\ManyToOne(targetEntity: NetworkName::class)]
    #[ORM\JoinColumn(name: 'subject_networknames_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_networknames_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkName'])]
    #[ApplicationManaged]
    public ?NetworkName $subjectNetworkName = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'subject_peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_peripherals_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $subjectPeripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'subject_phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_phones_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $subjectPhone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'subject_printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_printers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $subjectPrinter = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'subject_problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_problems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[ApplicationManaged]
    public ?Problem $subjectProblem = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'subject_profiles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_profiles_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Profile'])]
    #[ApplicationManaged]
    public ?Profile $subjectProfile = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'subject_projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_projects_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Project $subjectProject = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'subject_reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_reminders_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $subjectReminder = null;

    #[ORM\ManyToOne(targetEntity: RSSFeed::class)]
    #[ORM\JoinColumn(name: 'subject_rssfeeds_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_rssfeeds_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['RSSFeed'])]
    #[ApplicationManaged]
    public ?RSSFeed $subjectRSSFeed = null;

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'subject_softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_softwares_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[ApplicationManaged]
    public ?Software $subjectSoftware = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'subject_suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_suppliers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[ApplicationManaged]
    public ?Supplier $subjectSupplier = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'subject_tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_tickets_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[ApplicationManaged]
    public ?Ticket $subjectTicket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_users_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'subject_softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_softwarelicenses_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[ApplicationManaged]
    public ?SoftwareLicense $subjectSoftwareLicense = null;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'subject_certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_subject_certificates_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[ApplicationManaged]
    public ?Certificate $subjectCertificate = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_objectlocks_users_id', options: ['comment' => 'id of the locker'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: false, generated: 'ALWAYS', options: ['default' => new CurrentTimestamp(), 'comment' => 'Timestamp of the lock'])]
    #[NativeTimestamp(touchTrigger: 'glpi_objectlocks_date_mod_touch')]
    public ?DateTimeInterface $date_mod = null;
    #[ORM\PrePersist]
    public function initializeLockDate(): void
    {
        $this->date_mod ??= new DateTime();
    }
}
