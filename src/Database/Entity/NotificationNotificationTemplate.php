<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notifications_notificationtemplates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex(
    'unicity',
    ['notifications_id', 'mode', 'notificationtemplates_id'],
    unique: true,
    postgresqlName: 'glpi_notifications_notificationtemplates_unicity',
)]
#[SchemaIndex('notifications_id', ['notifications_id'], postgresqlName: 'glpi_notifications_notificationtemplates_notifications_id')]
#[SchemaIndex(
    'notificationtemplates_id',
    ['notificationtemplates_id'],
    postgresqlName: 'glpi_notifications_notificationtemplates_notif_e3e13564478f2c87',
)]
#[SchemaIndex('mode', ['mode'], postgresqlName: 'glpi_notifications_notificationtemplates_mode')]
class NotificationNotificationTemplate
{
    #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: 'templateBindings')]
    #[ORM\JoinColumn(
        name: 'notifications_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
        foreignKeyName: 'fk_notifications_notificationtemplates_notifications_id',
        options: ['default' => 0],
    )]
    #[ApplicationManaged]
    public ?Notification $notifications = null;

    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class, inversedBy: 'templateBindings')]
    #[ORM\JoinColumn(
        name: 'notificationtemplates_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
        foreignKeyName: 'fk_notifications_notificationtemplates_notificationtemplates_id',
        options: ['default' => 0],
    )]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(
        name: '`mode`',
        type: 'string',
        length: 20,
        nullable: false,
        options: ['comment' => 'See Notification_NotificationTemplate::MODE_* constants'],
    )]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $mode = '';
}
