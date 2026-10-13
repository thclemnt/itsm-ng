<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_links_itemtypes')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'links_id'], unique: true, postgresqlName: 'glpi_links_itemtypes_unicity')]
#[SchemaIndex('links_id', ['links_id'], postgresqlName: 'glpi_links_itemtypes_links_id')]
class LinkItemtype
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'links_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_links_itemtypes_links_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public Link $links;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';
}
