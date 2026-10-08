<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notifications_notificationtemplates')]
#[ORM\UniqueConstraint(name: 'notifications_notificationtemplates_unicity', columns: ['notifications_id', 'mode', 'notificationtemplates_id'])]
class NotificationNotificationTemplate
{
    #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: 'templateBindings')]
    #[ORM\JoinColumn(name: 'notifications_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Notification $notifications = null;

    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class, inversedBy: 'templateBindings')]
    #[ORM\JoinColumn(name: 'notificationtemplates_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false)]
    public string $mode = '';
}
