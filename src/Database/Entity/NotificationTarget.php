<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtargets')]
class NotificationTarget
{
    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notifications_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Notification $notifications = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $type = 0;
}
