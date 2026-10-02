<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_objectlocks')]
#[ORM\UniqueConstraint(name: 'objectlocks_item', columns: ['itemtype', 'items_id'])]
class ObjectLock implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'subject_budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Budget'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Budget $subjectBudget = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'subject_changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Change $subjectChange = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'subject_contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Contact $subjectContact = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'subject_contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Contract $subjectContract = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'subject_documents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Document'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Document $subjectDocument = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'subject_cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?CartridgeItem $subjectCartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'subject_computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Computer $subjectComputer = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'subject_consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?ConsumableItem $subjectConsumableItem = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'subject_entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Entity'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $subjectEntity = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'subject_groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Group'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Group $subjectGroup = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'subject_knowbaseitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['KnowbaseItem'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?KnowbaseItem $subjectKnowbaseItem = null;

    #[ORM\ManyToOne(targetEntity: Line::class)]
    #[ORM\JoinColumn(name: 'subject_lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Line'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Line $subjectLine = null;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'subject_links_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Link'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Link $subjectLink = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'subject_monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Monitor $subjectMonitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'subject_networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?NetworkEquipment $subjectNetworkEquipment = null;

    #[ORM\ManyToOne(targetEntity: NetworkName::class)]
    #[ORM\JoinColumn(name: 'subject_networknames_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['NetworkName'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?NetworkName $subjectNetworkName = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'subject_peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Peripheral $subjectPeripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'subject_phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Phone $subjectPhone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'subject_printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Printer $subjectPrinter = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'subject_problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Problem $subjectProblem = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'subject_profiles_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Profile'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Profile $subjectProfile = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'subject_projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Project $subjectProject = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'subject_reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Reminder $subjectReminder = null;

    #[ORM\ManyToOne(targetEntity: RSSFeed::class)]
    #[ORM\JoinColumn(name: 'subject_rssfeeds_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['RSSFeed'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?RSSFeed $subjectRSSFeed = null;

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'subject_softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Software $subjectSoftware = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'subject_suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Supplier $subjectSupplier = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'subject_tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Ticket $subjectTicket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'subject_softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?SoftwareLicense $subjectSoftwareLicense = null;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'subject_certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Certificate $subjectCertificate = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    public ?\DateTimeInterface $date_mod = null;
    #[ORM\PrePersist]
    public function initializeLockDate(): void
    {
        $this->date_mod ??= new \DateTime();
    }
}
