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
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ProjectTeamMember;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_projecttaskteams')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'user', joinColumns: [new ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttaskteams_users_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'group', joinColumns: [new ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttaskteams_groups_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'supplier', joinColumns: [new ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttaskteams_suppliers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'contact', joinColumns: [new ORM\JoinColumn(name: 'contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttaskteams_contacts_id', options: ['default' => null])])
])]
#[SchemaIndex('unicity', ['projecttasks_id', 'itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_projecttaskteams_unicity')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_projecttaskteams_item')]
#[SchemaIndex('glpi_projecttaskteams_users_id', ['users_id'], postgresqlName: 'glpi_projecttaskteams_users_id')]
#[SchemaIndex('glpi_projecttaskteams_groups_id', ['groups_id'], postgresqlName: 'glpi_projecttaskteams_groups_id')]
#[SchemaIndex('glpi_projecttaskteams_suppliers_id', ['suppliers_id'], postgresqlName: 'glpi_projecttaskteams_suppliers_id')]
#[SchemaIndex('glpi_projecttaskteams_contacts_id', ['contacts_id'], postgresqlName: 'glpi_projecttaskteams_contacts_id')]
#[SchemaIndex('IDX_1B0A1B0D8BB28A9F', ['projecttasks_id'], postgresqlName: 'IDX_1B0A1B0D8BB28A9F')]
class ProjectTaskTeam implements LegacyInput
{
    use ProjectTeamMember;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttaskteams_projecttasks_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?ProjectTask $projecttasks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
