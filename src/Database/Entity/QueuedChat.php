<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_queuedchats')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('item', ['itemtype', 'items_id', 'notificationtemplates_id'], postgresqlName: 'glpi_queuedchats_item')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_queuedchats_is_deleted')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_queuedchats_entities_id')]
#[SchemaIndex('sent_try', ['sent_try'], postgresqlName: 'glpi_queuedchats_sent_try')]
#[SchemaIndex('create_time', ['create_time'], postgresqlName: 'glpi_queuedchats_create_time')]
#[SchemaIndex('send_time', ['send_time'], postgresqlName: 'glpi_queuedchats_send_time')]
#[SchemaIndex('sent_time', ['sent_time'], postgresqlName: 'glpi_queuedchats_sent_time')]
#[SchemaIndex('mode', ['mode'], postgresqlName: 'glpi_queuedchats_mode')]
#[SchemaIndex('IDX_7E072DC23E89F867', ['notificationtemplates_id'])]
#[SchemaIndex('IDX_7E072DC26F283895', ['locations_id'])]
#[SchemaIndex('IDX_7E072DC24CBD296B', ['groups_id'])]
#[SchemaIndex('IDX_7E072DC241ADC625', ['itilcategories_id'])]
class QueuedChat
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class)]
    #[ORM\JoinColumn(name: 'notificationtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuedchats_notificationtemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuedchats_entities_id', options: ['default' => '0'])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuedchats_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuedchats_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: ITILCategory::class)]
    #[ORM\JoinColumn(name: 'itilcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuedchats_itilcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILCategory $itilcategories = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`sent_try`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sent_try = 0;

    #[ORM\Column(name: '`create_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $create_time = null;

    #[ORM\Column(name: '`send_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $send_time = null;

    #[ORM\Column(name: '`sent_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $sent_time = null;

    #[ORM\Column(name: '`entName`', type: 'text', nullable: true)]
    public ?string $entName = null;

    #[ORM\Column(name: '`ticketTitle`', type: 'text', nullable: true)]
    public ?string $ticketTitle = null;

    #[ORM\Column(name: '`completName`', type: 'text', nullable: true)]
    public ?string $completName = null;

    #[ORM\Column(name: '`serverName`', type: 'text', nullable: true)]
    public ?string $serverName = null;

    #[ORM\Column(name: '`hookurl`', type: 'string', length: 250, nullable: true)]
    public ?string $hookurl = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false, options: ['comment' => 'See Notification_NotificationTemplate::MODE_* constants'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $mode = '';
}
