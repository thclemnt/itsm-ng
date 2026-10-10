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
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changes_groups')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['changes_id', 'type', 'groups_id'], unique: true, postgresqlName: 'glpi_changes_groups_unicity')]
#[SchemaIndex('group', ['groups_id', 'type'], postgresqlName: 'glpi_changes_groups_group')]
#[SchemaIndex('IDX_DC2C7143E6E96F89', ['changes_id'], postgresqlName: 'IDX_DC2C7143E6E96F89')]
#[SchemaIndex('IDX_DC2C71434CBD296B', ['groups_id'], postgresqlName: 'IDX_DC2C71434CBD296B')]
class ChangeGroup
{
    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_groups_changes_id', options: ['default' => '0'])]
    #[ITILStatisticsRelation(ITILStatisticsRole::Groups)]
    #[ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_changes_groups_groups_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Group $groups = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;
}
