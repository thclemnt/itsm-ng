<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_reminders_users')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('reminders_id', ['reminders_id'], postgresqlName: 'glpi_reminders_users_reminders_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_reminders_users_users_id')]
class ReminderUser
{
    #[ORM\ManyToOne(targetEntity: Reminder::class, inversedBy: 'audienceUsers')]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_reminders_users_reminders_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Reminder $reminders = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_reminders_users_users_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
