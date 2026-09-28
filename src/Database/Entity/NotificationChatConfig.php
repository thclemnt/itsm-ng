<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationchatconfigs')]
class NotificationChatConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`hookurl`', type: 'string', length: 255, nullable: true)]
    public ?string $hookurl = null;

    #[ORM\Column(name: '`chat`', type: 'string', length: 255, nullable: true)]
    public ?string $chat = null;

    #[ORM\Column(name: '`type`', type: 'string', length: 255, nullable: true)]
    public ?string $type = null;

    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    public ?string $value = null;
}
