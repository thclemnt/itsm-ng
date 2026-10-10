<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
#[ORM\Table(name: 'glpi_itilsolutions')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_itilsolutions_itemtype')]
#[SchemaIndex('item_id', ['items_id'], postgresqlName: 'glpi_itilsolutions_item_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_itilsolutions_item')]
#[SchemaIndex('solutiontypes_id', ['solutiontypes_id'], postgresqlName: 'glpi_itilsolutions_solutiontypes_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_itilsolutions_users_id')]
#[SchemaIndex('users_id_editor', ['users_id_editor'], postgresqlName: 'glpi_itilsolutions_users_id_editor')]
#[SchemaIndex('users_id_approval', ['users_id_approval'], postgresqlName: 'glpi_itilsolutions_users_id_approval')]
#[SchemaIndex('status', ['status'], postgresqlName: 'glpi_itilsolutions_status')]
#[SchemaIndex('itilfollowups_id', ['itilfollowups_id'], postgresqlName: 'glpi_itilsolutions_itilfollowups_id')]
#[SchemaIndex('glpi_itilsolutions_tickets_id', ['tickets_id'])]
#[SchemaIndex('glpi_itilsolutions_problems_id', ['problems_id'])]
#[SchemaIndex('glpi_itilsolutions_changes_id', ['changes_id'])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'ticket', joinColumns: [new ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_tickets_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'problem', joinColumns: [new ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_problems_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'change', joinColumns: [new ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_changes_id', options: ['default' => null])])
])]
#[ORM\HasLifecycleCallbacks]
#[RequiredSubjectConstraint('subject_kind')]
class ITILSolution implements LegacyInput
{
    use ITILSubject;
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SolutionType::class)]
    #[ORM\JoinColumn(name: 'solutiontypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_solutiontypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SolutionType $solutiontypes = null;

    #[ORM\Column(name: '`solutiontype_name`', type: 'string', length: 255, nullable: true)]
    public ?string $solutiontype_name = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $content = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_approval`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_approval = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $author = null;

    #[ORM\Column(name: '`user_name`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_users_id_editor', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $editor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_approval', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_users_id_approval', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $approver = null;

    #[ORM\Column(name: '`user_name_approval`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name_approval = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\ManyToOne(targetEntity: ITILFollowup::class)]
    #[ORM\JoinColumn(name: 'itilfollowups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilsolutions_itilfollowups_id', options: ['default' => null, 'comment' => 'Followup reference on reject or approve a solution'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILFollowup $followup = null;
}
