<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_groups_rssfeeds')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('rssfeeds_id', ['rssfeeds_id'], postgresqlName: 'glpi_groups_rssfeeds_rssfeeds_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_groups_rssfeeds_groups_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_groups_rssfeeds_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_groups_rssfeeds_is_recursive')]
class GroupRSSFeed
{
    #[ORM\ManyToOne(targetEntity: RSSFeed::class, inversedBy: 'audienceGroups')]
    #[ORM\JoinColumn(name: 'rssfeeds_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_rssfeeds_rssfeeds_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?RSSFeed $rssfeeds = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_rssfeeds_groups_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Group $groups = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_rssfeeds_entities_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::Audience)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;
}
