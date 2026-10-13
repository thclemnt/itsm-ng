<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_dropdowntranslations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id', 'language', 'field'], unique: true, postgresqlName: 'glpi_dropdowntranslations_unicity')]
#[SchemaIndex('typeid', ['itemtype', 'items_id'], postgresqlName: 'glpi_dropdowntranslations_typeid')]
#[SchemaIndex('language', ['language'], postgresqlName: 'glpi_dropdowntranslations_language')]
#[SchemaIndex('field', ['field'], postgresqlName: 'glpi_dropdowntranslations_field')]
class DropdownTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: true)]
    public ?string $language = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 100, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'text', nullable: true)]
    public ?string $value = null;
}
