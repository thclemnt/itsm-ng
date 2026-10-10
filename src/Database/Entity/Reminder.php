<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_reminders')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('uuid', ['uuid'], unique: true, postgresqlName: 'glpi_reminders_uuid')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_reminders_date')]
#[SchemaIndex('begin', ['begin'], postgresqlName: 'glpi_reminders_begin')]
#[SchemaIndex('end', ['end'], postgresqlName: 'glpi_reminders_end')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_reminders_users_id')]
#[SchemaIndex('is_planned', ['is_planned'], postgresqlName: 'glpi_reminders_is_planned')]
#[SchemaIndex('state', ['state'], postgresqlName: 'glpi_reminders_state')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_reminders_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_reminders_date_creation')]
class Reminder
{
    /** @var Collection<int, ReminderUser> */
    #[ORM\OneToMany(targetEntity: ReminderUser::class, mappedBy: 'reminders')]
    public Collection $audienceUsers;

    /** @var Collection<int, GroupReminder> */
    #[ORM\OneToMany(targetEntity: GroupReminder::class, mappedBy: 'reminders')]
    public Collection $audienceGroups;

    /** @var Collection<int, ProfileReminder> */
    #[ORM\OneToMany(targetEntity: ProfileReminder::class, mappedBy: 'reminders')]
    public Collection $audienceProfiles;

    /** @var Collection<int, EntityReminder> */
    #[ORM\OneToMany(targetEntity: EntityReminder::class, mappedBy: 'reminders')]
    public Collection $audienceEntities;

    public function __construct()
    {
        $this->audienceUsers = new ArrayCollection();
        $this->audienceGroups = new ArrayCollection();
        $this->audienceProfiles = new ArrayCollection();
        $this->audienceEntities = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_reminders_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`text`', type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end = null;

    #[ORM\Column(name: '`is_planned`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_planned = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\Column(name: '`begin_view_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin_view_date = null;

    #[ORM\Column(name: '`end_view_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end_view_date = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
