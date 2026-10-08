<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_problems_suppliers')]
#[SchemaIndex('unicity', ['problems_id', 'type', 'actor_key', 'actor_email_key'], unique: true, postgresqlName: 'glpi_problems_suppliers_unicity')]
#[SchemaIndex('glpi_problems_suppliers_actor_parent', ['problems_id'])]
class ProblemSupplier
{
    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ITILStatisticsRelation(ITILStatisticsRole::Suppliers)]
    #[ApplicationManaged]
    public ?Problem $problems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Supplier $actor = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;

    #[ORM\Column(name: '`use_notification`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $use_notification = false;

    #[ORM\Column(name: '`alternative_email`', type: 'string', length: 255, nullable: true)]
    public ?string $alternative_email = null;
    #[ORM\Column(name: 'actor_key', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: 'BIGINT GENERATED ALWAYS AS (COALESCE(suppliers_id, 0)) STORED')]
    public ?int $actor_key = null;

    #[ORM\Column(name: 'actor_email_key', type: 'string', length: 255, nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: "VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN COALESCE(suppliers_id, 0) = 0 THEN COALESCE(alternative_email, '') ELSE '' END) STORED")]
    public ?string $actor_email_key = null;
}
