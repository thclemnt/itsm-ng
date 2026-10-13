<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasks_tickets')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['tickets_id', 'projecttasks_id'], unique: true, postgresqlName: 'glpi_projecttasks_tickets_unicity')]
#[SchemaIndex('projects_id', ['projecttasks_id'], postgresqlName: 'glpi_projecttasks_tickets_projects_id')]
#[SchemaIndex('IDX_2D48CB0AB7E5330A', ['tickets_id'], postgresqlName: 'IDX_2D48CB0AB7E5330A')]
class ProjectTaskTicket
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_tickets_tickets_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_tickets_projecttasks_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?ProjectTask $projecttasks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
