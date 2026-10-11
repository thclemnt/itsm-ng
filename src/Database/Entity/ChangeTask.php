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
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\UserReferenceAction;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changetasks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('uuid', ['uuid'], unique: true, postgresqlName: 'glpi_changetasks_uuid')]
#[SchemaIndex('changes_id', ['changes_id'], postgresqlName: 'glpi_changetasks_changes_id')]
#[SchemaIndex('state', ['state'], postgresqlName: 'glpi_changetasks_state')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_changetasks_users_id')]
#[SchemaIndex('users_id_editor', ['users_id_editor'], postgresqlName: 'glpi_changetasks_users_id_editor')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_changetasks_users_id_tech')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_changetasks_groups_id_tech')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_changetasks_date')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_changetasks_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_changetasks_date_creation')]
#[SchemaIndex('begin', ['begin'], postgresqlName: 'glpi_changetasks_begin')]
#[SchemaIndex('end', ['end'], postgresqlName: 'glpi_changetasks_end')]
#[SchemaIndex('taskcategories_id', ['taskcategories_id'], postgresqlName: 'glpi_changetasks_taskcategories_id')]
#[SchemaIndex('tasktemplates_id', ['tasktemplates_id'], postgresqlName: 'glpi_changetasks_tasktemplates_id')]
#[SchemaIndex('is_private', ['is_private'], postgresqlName: 'glpi_changetasks_is_private')]
class ChangeTask
{
    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_changes_id', options: ['default' => '0'])]
    #[ITILStatisticsRelation(ITILStatisticsRole::Tasks)]
    #[ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: TaskCategory::class)]
    #[ORM\JoinColumn(name: 'taskcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_taskcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TaskCategory $taskcategories = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_users_id_editor', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $editor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $technician = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\ManyToOne(targetEntity: TaskTemplate::class)]
    #[ORM\JoinColumn(name: 'tasktemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changetasks_tasktemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TaskTemplate $tasktemplates = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    #[ORM\Column(name: '`is_private`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_private = false;
}
