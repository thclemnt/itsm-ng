<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_problems_suppliers')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('group', ['suppliers_id', 'type'], postgresqlName: 'glpi_problems_suppliers_group')]
#[SchemaIndex('glpi_problems_suppliers_actor_parent', ['problems_id'], postgresqlName: 'glpi_problems_suppliers_actor_parent')]
#[SchemaIndex('unicity', ['problems_id', 'type', 'actor_key', 'actor_email_key'], unique: true, postgresqlName: 'glpi_problems_suppliers_unicity')]
#[SchemaIndex('IDX_4A101DF22747B3C', ['suppliers_id'], postgresqlName: 'IDX_4A101DF22747B3C')]
class ProblemSupplier
{
    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_problems_suppliers_problems_id', options: ['default' => '0'])]
    #[ITILStatisticsRelation(ITILStatisticsRole::Suppliers)]
    #[ApplicationManaged]
    public ?Problem $problems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_problems_suppliers_suppliers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Supplier $actor = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;

    #[ORM\Column(name: '`use_notification`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $use_notification = false;

    #[ORM\Column(name: '`alternative_email`', type: 'string', length: 255, nullable: true)]
    public ?string $alternative_email = null;
    #[ORM\Column(name: '`actor_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: 'BIGINT GENERATED ALWAYS AS (COALESCE(suppliers_id, 0)) STORED')]
    public ?int $actor_key = null;

    #[ORM\Column(name: '`actor_email_key`', type: 'string', length: 255, nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: "VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN COALESCE(suppliers_id, 0) = 0 THEN COALESCE(alternative_email, '') ELSE '' END) STORED")]
    public ?string $actor_email_key = null;
}
