<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notifications_notificationtemplates')]
#[ORM\UniqueConstraint(name: 'notifications_notificationtemplates_unicity', columns: ['notifications_id', 'mode', 'notificationtemplates_id'])]
class NotificationNotificationTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`notifications_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $notifications_id = 0;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false)]
    public string $mode = '';

    #[ORM\Column(name: '`notificationtemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $notificationtemplates_id = 0;
}
