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
use itsmng\Database\Mapping\ITILSubject;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\UserReferenceAction;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_itilfollowups')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'ticket', joinColumns: [new ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_tickets_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'problem', joinColumns: [new ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_problems_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'change', joinColumns: [new ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_changes_id', options: ['default' => null])])
])]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_itilfollowups_itemtype')]
#[SchemaIndex('item_id', ['items_id'], postgresqlName: 'glpi_itilfollowups_item_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_itilfollowups_item')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_itilfollowups_date')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_itilfollowups_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_itilfollowups_date_creation')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_itilfollowups_users_id')]
#[SchemaIndex('users_id_editor', ['users_id_editor'], postgresqlName: 'glpi_itilfollowups_users_id_editor')]
#[SchemaIndex('is_private', ['is_private'], postgresqlName: 'glpi_itilfollowups_is_private')]
#[SchemaIndex('requesttypes_id', ['requesttypes_id'], postgresqlName: 'glpi_itilfollowups_requesttypes_id')]
#[SchemaIndex('sourceitems_id', ['sourceitems_id'], postgresqlName: 'glpi_itilfollowups_sourceitems_id')]
#[SchemaIndex('sourceof_items_id', ['sourceof_items_id'], postgresqlName: 'glpi_itilfollowups_sourceof_items_id')]
#[SchemaIndex('glpi_itilfollowups_tickets_id', ['tickets_id'], postgresqlName: 'glpi_itilfollowups_tickets_id')]
#[SchemaIndex('glpi_itilfollowups_problems_id', ['problems_id'], postgresqlName: 'glpi_itilfollowups_problems_id')]
#[SchemaIndex('glpi_itilfollowups_changes_id', ['changes_id'], postgresqlName: 'glpi_itilfollowups_changes_id')]
#[ORM\HasLifecycleCallbacks]
#[RequiredSubjectConstraint('subject_kind')]
class ITILFollowup implements LegacyInput
{
    use ITILSubject;
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_users_id_editor', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $editor = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`is_private`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_private = false;

    #[ORM\ManyToOne(targetEntity: RequestType::class)]
    #[ORM\JoinColumn(name: 'requesttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_requesttypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?RequestType $requesttypes = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'sourceitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_sourceitems_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Ticket $sourceTicket = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'sourceof_items_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilfollowups_sourceof_items_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Ticket $promotedTicket = null;
}
