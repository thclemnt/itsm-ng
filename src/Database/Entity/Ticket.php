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
#[ORM\Table(name: 'glpi_tickets')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_tickets_date')]
#[SchemaIndex('closedate', ['closedate'], postgresqlName: 'glpi_tickets_closedate')]
#[SchemaIndex('status', ['status'], postgresqlName: 'glpi_tickets_status')]
#[SchemaIndex('priority', ['priority'], postgresqlName: 'glpi_tickets_priority')]
#[SchemaIndex('request_type', ['requesttypes_id'], postgresqlName: 'glpi_tickets_request_type')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_tickets_date_mod')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_tickets_entities_id')]
#[SchemaIndex('users_id_recipient', ['users_id_recipient'], postgresqlName: 'glpi_tickets_users_id_recipient')]
#[SchemaIndex('solvedate', ['solvedate'], postgresqlName: 'glpi_tickets_solvedate')]
#[SchemaIndex('urgency', ['urgency'], postgresqlName: 'glpi_tickets_urgency')]
#[SchemaIndex('impact', ['impact'], postgresqlName: 'glpi_tickets_impact')]
#[SchemaIndex('global_validation', ['global_validation'], postgresqlName: 'glpi_tickets_global_validation')]
#[SchemaIndex('slas_id_tto', ['slas_id_tto'], postgresqlName: 'glpi_tickets_slas_id_tto')]
#[SchemaIndex('slas_id_ttr', ['slas_id_ttr'], postgresqlName: 'glpi_tickets_slas_id_ttr')]
#[SchemaIndex('time_to_resolve', ['time_to_resolve'], postgresqlName: 'glpi_tickets_time_to_resolve')]
#[SchemaIndex('time_to_own', ['time_to_own'], postgresqlName: 'glpi_tickets_time_to_own')]
#[SchemaIndex('olas_id_tto', ['olas_id_tto'], postgresqlName: 'glpi_tickets_olas_id_tto')]
#[SchemaIndex('olas_id_ttr', ['olas_id_ttr'], postgresqlName: 'glpi_tickets_olas_id_ttr')]
#[SchemaIndex('slalevels_id_ttr', ['slalevels_id_ttr'], postgresqlName: 'glpi_tickets_slalevels_id_ttr')]
#[SchemaIndex('internal_time_to_resolve', ['internal_time_to_resolve'], postgresqlName: 'glpi_tickets_internal_time_to_resolve')]
#[SchemaIndex('internal_time_to_own', ['internal_time_to_own'], postgresqlName: 'glpi_tickets_internal_time_to_own')]
#[SchemaIndex('users_id_lastupdater', ['users_id_lastupdater'], postgresqlName: 'glpi_tickets_users_id_lastupdater')]
#[SchemaIndex('type', ['type'], postgresqlName: 'glpi_tickets_type')]
#[SchemaIndex('itilcategories_id', ['itilcategories_id'], postgresqlName: 'glpi_tickets_itilcategories_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_tickets_is_deleted')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_tickets_name')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_tickets_locations_id')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_tickets_date_creation')]
#[SchemaIndex('ola_waiting_duration', ['ola_waiting_duration'], postgresqlName: 'glpi_tickets_ola_waiting_duration')]
#[SchemaIndex('olalevels_id_ttr', ['olalevels_id_ttr'], postgresqlName: 'glpi_tickets_olalevels_id_ttr')]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`closedate`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $closedate = null;

    #[ORM\Column(name: '`solvedate`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $solvedate = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_lastupdater', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_users_id_lastupdater', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $lastUpdater = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_recipient', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_users_id_recipient', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: RequestType::class)]
    #[ORM\JoinColumn(name: 'requesttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_requesttypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?RequestType $requesttypes = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`urgency`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $urgency = 1;

    #[ORM\Column(name: '`impact`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $impact = 1;

    #[ORM\Column(name: '`priority`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $priority = 1;

    #[ORM\ManyToOne(targetEntity: ITILCategory::class)]
    #[ORM\JoinColumn(name: 'itilcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_itilcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILCategory $itilcategories = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;

    #[ORM\Column(name: '`global_validation`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $global_validation = 1;

    #[ORM\ManyToOne(targetEntity: SLA::class)]
    #[ORM\JoinColumn(name: 'slas_id_ttr', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_slas_id_ttr', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SLA $slas_ttr = null;

    #[ORM\ManyToOne(targetEntity: SLA::class)]
    #[ORM\JoinColumn(name: 'slas_id_tto', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_slas_id_tto', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SLA $slas_tto = null;

    #[ORM\ManyToOne(targetEntity: SlaLevel::class)]
    #[ORM\JoinColumn(name: 'slalevels_id_ttr', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_slalevels_id_ttr', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SlaLevel $slalevels_ttr = null;

    #[ORM\Column(name: '`time_to_resolve`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $time_to_resolve = null;

    #[ORM\Column(name: '`time_to_own`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $time_to_own = null;

    #[ORM\Column(name: '`begin_waiting_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin_waiting_date = null;

    #[ORM\Column(name: '`sla_waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sla_waiting_duration = 0;

    #[ORM\Column(name: '`ola_waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ola_waiting_duration = 0;

    #[ORM\ManyToOne(targetEntity: OLA::class)]
    #[ORM\JoinColumn(name: 'olas_id_tto', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_olas_id_tto', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OLA $olas_tto = null;

    #[ORM\ManyToOne(targetEntity: OLA::class)]
    #[ORM\JoinColumn(name: 'olas_id_ttr', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_olas_id_ttr', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OLA $olas_ttr = null;

    #[ORM\ManyToOne(targetEntity: OlaLevel::class)]
    #[ORM\JoinColumn(name: 'olalevels_id_ttr', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_olalevels_id_ttr', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OlaLevel $olalevels_ttr = null;

    #[ORM\Column(name: '`ola_ttr_begin_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $ola_ttr_begin_date = null;

    #[ORM\Column(name: '`internal_time_to_resolve`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $internal_time_to_resolve = null;

    #[ORM\Column(name: '`internal_time_to_own`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $internal_time_to_own = null;

    #[ORM\Column(name: '`waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $waiting_duration = 0;

    #[ORM\Column(name: '`close_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $close_delay_stat = 0;

    #[ORM\Column(name: '`solve_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $solve_delay_stat = 0;

    #[ORM\Column(name: '`takeintoaccount_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $takeintoaccount_delay_stat = 0;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\Column(name: '`validation_percent`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $validation_percent = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
