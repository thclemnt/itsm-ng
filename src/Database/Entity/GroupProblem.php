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
#[ORM\Table(name: 'glpi_groups_problems')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['problems_id', 'type', 'groups_id'], unique: true, postgresqlName: 'glpi_groups_problems_unicity')]
#[SchemaIndex('group', ['groups_id', 'type'], postgresqlName: 'glpi_groups_problems_group')]
#[SchemaIndex('IDX_35FF34E01D637048', ['problems_id'], postgresqlName: 'IDX_35FF34E01D637048')]
#[SchemaIndex('IDX_35FF34E04CBD296B', ['groups_id'], postgresqlName: 'IDX_35FF34E04CBD296B')]
class GroupProblem
{
    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_problems_problems_id', options: ['default' => '0'])]
    #[ITILStatisticsRelation(ITILStatisticsRole::Groups)]
    #[ApplicationManaged]
    public ?Problem $problems = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_problems_groups_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Group $groups = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;
}
