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
use itsmng\Database\Mapping\ITILSubject;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[RequiredSubjectConstraint('subject_kind')]
#[ORM\Table(name: 'glpi_itils_projects')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'ticket', joinColumns: [new ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itils_projects_tickets_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'problem', joinColumns: [new ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itils_projects_problems_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'change', joinColumns: [new ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itils_projects_changes_id', options: ['default' => null])])
])]
#[SchemaIndex('unicity', ['itemtype', 'items_id', 'projects_id'], unique: true, postgresqlName: 'glpi_itils_projects_unicity')]
#[SchemaIndex('projects_id', ['projects_id'], postgresqlName: 'glpi_itils_projects_projects_id')]
#[SchemaIndex('glpi_itils_projects_tickets_id', ['tickets_id'], postgresqlName: 'glpi_itils_projects_tickets_id')]
#[SchemaIndex('glpi_itils_projects_problems_id', ['problems_id'], postgresqlName: 'glpi_itils_projects_problems_id')]
#[SchemaIndex('glpi_itils_projects_changes_id', ['changes_id'], postgresqlName: 'glpi_itils_projects_changes_id')]
class ItilProject implements LegacyInput
{
    use ITILSubject;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_itils_projects_projects_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
