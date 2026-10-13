<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactrelations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype_source', 'items_id_source', 'itemtype_impacted', 'items_id_impacted'], unique: true, postgresqlName: 'glpi_impactrelations_unicity')]
#[SchemaIndex('source_asset', ['itemtype_source', 'items_id_source'], postgresqlName: 'glpi_impactrelations_source_asset')]
#[SchemaIndex('impacted_asset', ['itemtype_impacted', 'items_id_impacted'], postgresqlName: 'glpi_impactrelations_impacted_asset')]
class ImpactRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype_source`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype_source = '';

    #[ORM\Column(name: '`items_id_source`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id_source = 0;

    #[ORM\Column(name: '`itemtype_impacted`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype_impacted = '';

    #[ORM\Column(name: '`items_id_impacted`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id_impacted = 0;
}
