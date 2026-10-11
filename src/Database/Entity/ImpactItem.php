<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactitems')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_impactitems_unicity')]
#[SchemaIndex('source', ['itemtype', 'items_id'], postgresqlName: 'glpi_impactitems_source')]
#[SchemaIndex('parent_id', ['parent_id'], postgresqlName: 'glpi_impactitems_parent_id')]
#[SchemaIndex('impactcontexts_id', ['impactcontexts_id'], postgresqlName: 'glpi_impactitems_impactcontexts_id')]
class ImpactItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: ImpactCompound::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_impactitems_parent_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ImpactCompound $compound = null;

    #[ORM\ManyToOne(targetEntity: ImpactContext::class)]
    #[ORM\JoinColumn(name: 'impactcontexts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_impactitems_impactcontexts_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ImpactContext $context = null;

    #[ORM\Column(name: '`is_slave`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_slave = true;
}
