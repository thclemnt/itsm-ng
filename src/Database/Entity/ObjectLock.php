<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_objectlocks')]
#[ORM\UniqueConstraint(name: 'objectlocks_item', columns: ['itemtype', 'items_id'])]
class ObjectLock implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'subject_budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Budget'])]
    #[ApplicationManaged]
    public ?Budget $subjectBudget = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'subject_changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[ApplicationManaged]
    public ?Change $subjectChange = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'subject_contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[ApplicationManaged]
    public ?Contact $subjectContact = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'subject_contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $subjectContract = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'subject_documents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Document'])]
    #[ApplicationManaged]
    public ?Document $subjectDocument = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'subject_cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $subjectCartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'subject_computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $subjectComputer = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'subject_consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $subjectConsumableItem = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'subject_entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Entity'])]
    #[ApplicationManaged]
    public ?Entity $subjectEntity = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'subject_groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Group'])]
    #[ApplicationManaged]
    public ?Group $subjectGroup = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'subject_knowbaseitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['KnowbaseItem'])]
    #[ApplicationManaged]
    public ?KnowbaseItem $subjectKnowbaseItem = null;

    #[ORM\ManyToOne(targetEntity: Line::class)]
    #[ORM\JoinColumn(name: 'subject_lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Line'])]
    #[ApplicationManaged]
    public ?Line $subjectLine = null;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'subject_links_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Link'])]
    #[ApplicationManaged]
    public ?Link $subjectLink = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'subject_monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $subjectMonitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'subject_networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $subjectNetworkEquipment = null;

    #[ORM\ManyToOne(targetEntity: NetworkName::class)]
    #[ORM\JoinColumn(name: 'subject_networknames_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkName'])]
    #[ApplicationManaged]
    public ?NetworkName $subjectNetworkName = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'subject_peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $subjectPeripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'subject_phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $subjectPhone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'subject_printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $subjectPrinter = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'subject_problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[ApplicationManaged]
    public ?Problem $subjectProblem = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'subject_profiles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Profile'])]
    #[ApplicationManaged]
    public ?Profile $subjectProfile = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'subject_projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Project $subjectProject = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'subject_reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $subjectReminder = null;

    #[ORM\ManyToOne(targetEntity: RSSFeed::class)]
    #[ORM\JoinColumn(name: 'subject_rssfeeds_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['RSSFeed'])]
    #[ApplicationManaged]
    public ?RSSFeed $subjectRSSFeed = null;

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'subject_softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[ApplicationManaged]
    public ?Software $subjectSoftware = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'subject_suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[ApplicationManaged]
    public ?Supplier $subjectSupplier = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'subject_tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[ApplicationManaged]
    public ?Ticket $subjectTicket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'subject_softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[ApplicationManaged]
    public ?SoftwareLicense $subjectSoftwareLicense = null;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'subject_certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[ApplicationManaged]
    public ?Certificate $subjectCertificate = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
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
