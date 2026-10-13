<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationchatconfigs')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
class NotificationChatConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
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
