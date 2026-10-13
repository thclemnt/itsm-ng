<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\AssetAssociations;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\RequiredItemReference;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_documents_items')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['documents_id', 'itemtype', 'items_id', 'timeline_position'], unique: true, postgresqlName: 'glpi_documents_items_unicity')]
#[SchemaIndex('item', ['itemtype', 'items_id', 'entities_id', 'is_recursive'], postgresqlName: 'glpi_documents_items_item')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_documents_items_users_id')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_documents_items_date_creation')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_documents_items_date')]
#[SchemaIndex('glpi_documents_items_budgets_id', ['budgets_id'], postgresqlName: 'glpi_documents_items_budgets_id')]
#[SchemaIndex('glpi_documents_items_cartridgeitems_id', ['cartridgeitems_id'], postgresqlName: 'glpi_documents_items_cartridgeitems_id')]
#[SchemaIndex('glpi_documents_items_changes_id', ['changes_id'], postgresqlName: 'glpi_documents_items_changes_id')]
#[SchemaIndex('glpi_documents_items_consumableitems_id', ['consumableitems_id'], postgresqlName: 'glpi_documents_items_consumableitems_id')]
#[SchemaIndex('glpi_documents_items_contacts_id', ['contacts_id'], postgresqlName: 'glpi_documents_items_contacts_id')]
#[SchemaIndex('glpi_documents_items_contracts_id', ['contracts_id'], postgresqlName: 'glpi_documents_items_contracts_id')]
#[SchemaIndex('glpi_documents_items_domains_id', ['domains_id'], postgresqlName: 'glpi_documents_items_domains_id')]
#[SchemaIndex('glpi_documents_items_linked_documents_id', ['linked_documents_id'], postgresqlName: 'glpi_documents_items_linked_documents_id')]
#[SchemaIndex('glpi_documents_items_subject_entities_id', ['subject_entities_id'], postgresqlName: 'glpi_documents_items_subject_entities_id')]
#[SchemaIndex('glpi_documents_items_knowbaseitems_id', ['knowbaseitems_id'], postgresqlName: 'glpi_documents_items_knowbaseitems_id')]
#[SchemaIndex('glpi_documents_items_problems_id', ['problems_id'], postgresqlName: 'glpi_documents_items_problems_id')]
#[SchemaIndex('glpi_documents_items_projects_id', ['projects_id'], postgresqlName: 'glpi_documents_items_projects_id')]
#[SchemaIndex('glpi_documents_items_projecttasks_id', ['projecttasks_id'], postgresqlName: 'glpi_documents_items_projecttasks_id')]
#[SchemaIndex('glpi_documents_items_reminders_id', ['reminders_id'], postgresqlName: 'glpi_documents_items_reminders_id')]
#[SchemaIndex('glpi_documents_items_suppliers_id', ['suppliers_id'], postgresqlName: 'glpi_documents_items_suppliers_id')]
#[SchemaIndex('glpi_documents_items_tickets_id', ['tickets_id'], postgresqlName: 'glpi_documents_items_tickets_id')]
#[SchemaIndex('glpi_documents_items_subject_users_id', ['subject_users_id'], postgresqlName: 'glpi_documents_items_subject_users_id')]
#[SchemaIndex('glpi_documents_items_itilfollowups_id', ['itilfollowups_id'], postgresqlName: 'glpi_documents_items_itilfollowups_id')]
#[SchemaIndex('glpi_documents_items_itilsolutions_id', ['itilsolutions_id'], postgresqlName: 'glpi_documents_items_itilsolutions_id')]
#[SchemaIndex('glpi_documents_items_changetasks_id', ['changetasks_id'], postgresqlName: 'glpi_documents_items_changetasks_id')]
#[SchemaIndex('glpi_documents_items_problemtasks_id', ['problemtasks_id'], postgresqlName: 'glpi_documents_items_problemtasks_id')]
#[SchemaIndex('glpi_documents_items_tickettasks_id', ['tickettasks_id'], postgresqlName: 'glpi_documents_items_tickettasks_id')]
#[SchemaIndex('glpi_documents_items_computers_id', ['computers_id'], postgresqlName: 'glpi_documents_items_computers_id')]
#[SchemaIndex('glpi_documents_items_monitors_id', ['monitors_id'], postgresqlName: 'glpi_documents_items_monitors_id')]
#[SchemaIndex('glpi_documents_items_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_documents_items_networkequipments_id')]
#[SchemaIndex('glpi_documents_items_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_documents_items_peripherals_id')]
#[SchemaIndex('glpi_documents_items_phones_id', ['phones_id'], postgresqlName: 'glpi_documents_items_phones_id')]
#[SchemaIndex('glpi_documents_items_printers_id', ['printers_id'], postgresqlName: 'glpi_documents_items_printers_id')]
#[SchemaIndex('glpi_documents_items_softwares_id', ['softwares_id'], postgresqlName: 'glpi_documents_items_softwares_id')]
#[SchemaIndex('glpi_documents_items_softwarelicenses_id', ['softwarelicenses_id'], postgresqlName: 'glpi_documents_items_softwarelicenses_id')]
#[SchemaIndex('glpi_documents_items_certificates_id', ['certificates_id'], postgresqlName: 'glpi_documents_items_certificates_id')]
#[SchemaIndex('glpi_documents_items_lines_id', ['lines_id'], postgresqlName: 'glpi_documents_items_lines_id')]
#[SchemaIndex('glpi_documents_items_clusters_id', ['clusters_id'], postgresqlName: 'glpi_documents_items_clusters_id')]
#[SchemaIndex('glpi_documents_items_appliances_id', ['appliances_id'], postgresqlName: 'glpi_documents_items_appliances_id')]
#[SchemaIndex('IDX_DDD24B2548980146', ['documents_id'], postgresqlName: 'IDX_DDD24B2548980146')]
#[SchemaIndex('IDX_DDD24B25F4829AED', ['entities_id'], postgresqlName: 'IDX_DDD24B25F4829AED')]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'computer', joinColumns: [new ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_computers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'monitor', joinColumns: [new ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_monitors_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'networkEquipment', joinColumns: [new ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_networkequipments_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'peripheral', joinColumns: [new ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_peripherals_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'phone', joinColumns: [new ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_phones_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'printer', joinColumns: [new ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_printers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'software', joinColumns: [new ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_softwares_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'softwareLicense', joinColumns: [new ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_softwarelicenses_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'certificate', joinColumns: [new ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_certificates_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'line', joinColumns: [new ORM\JoinColumn(name: 'lines_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_lines_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'cluster', joinColumns: [new ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_clusters_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'appliance', joinColumns: [new ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_appliances_id', options: ['default' => null])]),
])]
class DocumentItem implements LegacyInput
{
    use RequiredItemReference;
    use AssetAssociations;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'documents_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_documents_id', options: ['default' => '0'])]
    public ?Document $documents = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_users_id', options: ['default' => null])]
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
    #[ORM\JoinColumn(name: 'budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_budgets_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Budget'])]
    #[ApplicationManaged]
    public ?Budget $budget = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_cartridgeitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_changes_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[ApplicationManaged]
    public ?Change $change = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_consumableitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $consumableItem = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_contacts_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[ApplicationManaged]
    public ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_contracts_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $contract = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_domains_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'linked_documents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_linked_documents_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Document'])]
    #[ApplicationManaged]
    public ?Document $linkedDocument = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'subject_entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_subject_entities_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Entity'], minimumId: 0)]
    #[ApplicationManaged]
    public ?Entity $subjectEntity = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'knowbaseitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_knowbaseitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['KnowbaseItem'])]
    #[ApplicationManaged]
    public ?KnowbaseItem $knowbaseItem = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_problems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[ApplicationManaged]
    public ?Problem $problem = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_projects_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_projecttasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProjectTask'])]
    #[ApplicationManaged]
    public ?ProjectTask $projectTask = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_reminders_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $reminder = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_suppliers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[ApplicationManaged]
    public ?Supplier $supplier = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_tickets_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[ApplicationManaged]
    public ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_subject_users_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: ITILFollowup::class)]
    #[ORM\JoinColumn(name: 'itilfollowups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_itilfollowups_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ITILFollowup'])]
    #[ApplicationManaged]
    public ?ITILFollowup $itilFollowup = null;

    #[ORM\ManyToOne(targetEntity: ITILSolution::class)]
    #[ORM\JoinColumn(name: 'itilsolutions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_itilsolutions_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ITILSolution'])]
    #[ApplicationManaged]
    public ?ITILSolution $itilSolution = null;

    #[ORM\ManyToOne(targetEntity: ChangeTask::class)]
    #[ORM\JoinColumn(name: 'changetasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_changetasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ChangeTask'])]
    #[ApplicationManaged]
    public ?ChangeTask $changeTask = null;

    #[ORM\ManyToOne(targetEntity: ProblemTask::class)]
    #[ORM\JoinColumn(name: 'problemtasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_problemtasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProblemTask'])]
    #[ApplicationManaged]
    public ?ProblemTask $problemTask = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'tickettasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_items_tickettasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['TicketTask'])]
    #[ApplicationManaged]
    public ?TicketTask $ticketTask = null;

}
