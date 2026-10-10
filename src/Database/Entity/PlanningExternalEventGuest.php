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
#[ORM\Table(name: 'glpi_planningexternaleventguests')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('event_guest', ['planningexternalevents_id', 'users_id'], unique: true, postgresqlName: 'event_guest')]
#[SchemaIndex('event_guest_position', ['planningexternalevents_id', 'position'], unique: true, postgresqlName: 'event_guest_position')]
#[SchemaIndex('IDX_C7446AF61AE5F8D', ['planningexternalevents_id'], postgresqlName: 'IDX_C7446AF61AE5F8D')]
#[SchemaIndex('IDX_C7446AF13DB09D8', ['users_id'], postgresqlName: 'IDX_C7446AF13DB09D8')]
class PlanningExternalEventGuest
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEvent::class)]
    #[ORM\JoinColumn(name: 'planningexternalevents_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternaleventguests_planningexternalevents_id')]
    #[ApplicationManaged]
    public ?PlanningExternalEvent $event = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternaleventguests_users_id')]
    #[ApplicationManaged]
    public ?User $user = null;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;
}
