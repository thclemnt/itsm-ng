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
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\UserReferenceAction;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changes')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_changes_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_changes_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_changes_is_recursive')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_changes_is_deleted')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_changes_date')]
#[SchemaIndex('closedate', ['closedate'], postgresqlName: 'glpi_changes_closedate')]
#[SchemaIndex('status', ['status'], postgresqlName: 'glpi_changes_status')]
#[SchemaIndex('priority', ['priority'], postgresqlName: 'glpi_changes_priority')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_changes_date_mod')]
#[SchemaIndex('itilcategories_id', ['itilcategories_id'], postgresqlName: 'glpi_changes_itilcategories_id')]
#[SchemaIndex('users_id_recipient', ['users_id_recipient'], postgresqlName: 'glpi_changes_users_id_recipient')]
#[SchemaIndex('solvedate', ['solvedate'], postgresqlName: 'glpi_changes_solvedate')]
#[SchemaIndex('urgency', ['urgency'], postgresqlName: 'glpi_changes_urgency')]
#[SchemaIndex('impact', ['impact'], postgresqlName: 'glpi_changes_impact')]
#[SchemaIndex('time_to_resolve', ['time_to_resolve'], postgresqlName: 'glpi_changes_time_to_resolve')]
#[SchemaIndex('global_validation', ['global_validation'], postgresqlName: 'glpi_changes_global_validation')]
#[SchemaIndex('users_id_lastupdater', ['users_id_lastupdater'], postgresqlName: 'glpi_changes_users_id_lastupdater')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_changes_date_creation')]
class Change
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`solvedate`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $solvedate = null;

    #[ORM\Column(name: '`closedate`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $closedate = null;

    #[ORM\Column(name: '`time_to_resolve`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $time_to_resolve = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_recipient', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_users_id_recipient', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_lastupdater', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_users_id_lastupdater', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $lastUpdater = null;

    #[ORM\Column(name: '`urgency`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $urgency = 1;

    #[ORM\Column(name: '`impact`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $impact = 1;

    #[ORM\Column(name: '`priority`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $priority = 1;

    #[ORM\ManyToOne(targetEntity: ITILCategory::class)]
    #[ORM\JoinColumn(name: 'itilcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_itilcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILCategory $itilcategories = null;

    #[ORM\Column(name: '`impactcontent`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $impactcontent = null;

    #[ORM\Column(name: '`controlistcontent`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $controlistcontent = null;

    #[ORM\Column(name: '`rolloutplancontent`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $rolloutplancontent = null;

    #[ORM\Column(name: '`backoutplancontent`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $backoutplancontent = null;

    #[ORM\Column(name: '`checklistcontent`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $checklistcontent = null;

    #[ORM\Column(name: '`global_validation`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $global_validation = 1;

    #[ORM\Column(name: '`validation_percent`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $validation_percent = 0;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`begin_waiting_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin_waiting_date = null;

    #[ORM\Column(name: '`waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $waiting_duration = 0;

    #[ORM\Column(name: '`close_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $close_delay_stat = 0;

    #[ORM\Column(name: '`solve_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $solve_delay_stat = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
