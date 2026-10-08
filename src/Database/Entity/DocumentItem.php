<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\AssetAssociations;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_documents_items')]
#[ORM\UniqueConstraint(name: 'documents_items_unicity', columns: ['documents_id', 'itemtype', 'items_id', 'timeline_position'])]
class DocumentItem implements LegacyInput
{
    use RequiredItemReference;
    use AssetAssociations;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'documents_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Document $documents = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Budget'])]
    #[ApplicationManaged]
    public ?Budget $budget = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[ApplicationManaged]
    public ?Change $change = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $consumableItem = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[ApplicationManaged]
    public ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $contract = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'linked_documents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Document'])]
    #[ApplicationManaged]
    public ?Document $linkedDocument = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'subject_entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Entity'], minimumId: 0)]
    #[ApplicationManaged]
    public ?Entity $subjectEntity = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'knowbaseitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['KnowbaseItem'])]
    #[ApplicationManaged]
    public ?KnowbaseItem $knowbaseItem = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[ApplicationManaged]
    public ?Problem $problem = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProjectTask'])]
    #[ApplicationManaged]
    public ?ProjectTask $projectTask = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $reminder = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[ApplicationManaged]
    public ?Supplier $supplier = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[ApplicationManaged]
    public ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: ITILFollowup::class)]
    #[ORM\JoinColumn(name: 'itilfollowups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ITILFollowup'])]
    #[ApplicationManaged]
    public ?ITILFollowup $itilFollowup = null;

    #[ORM\ManyToOne(targetEntity: ITILSolution::class)]
    #[ORM\JoinColumn(name: 'itilsolutions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ITILSolution'])]
    #[ApplicationManaged]
    public ?ITILSolution $itilSolution = null;

    #[ORM\ManyToOne(targetEntity: ChangeTask::class)]
    #[ORM\JoinColumn(name: 'changetasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ChangeTask'])]
    #[ApplicationManaged]
    public ?ChangeTask $changeTask = null;

    #[ORM\ManyToOne(targetEntity: ProblemTask::class)]
    #[ORM\JoinColumn(name: 'problemtasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProblemTask'])]
    #[ApplicationManaged]
    public ?ProblemTask $problemTask = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'tickettasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['TicketTask'])]
    #[ApplicationManaged]
    public ?TicketTask $ticketTask = null;

}
