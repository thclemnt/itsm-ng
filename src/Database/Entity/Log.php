<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_logs')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_logs_date_mod')]
#[SchemaIndex('itemtype_link', ['itemtype_link'], postgresqlName: 'glpi_logs_itemtype_link')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_logs_item')]
#[SchemaIndex('id_search_option', ['id_search_option'], postgresqlName: 'glpi_logs_id_search_option')]
class Log
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false, options: ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype_link`', type: 'string', length: 100, nullable: false, options: ['default' => ''])]
    public string $itemtype_link = '';

    #[ORM\Column(name: '`linked_action`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see define.php HISTORY_* constant'])]
    public int $linked_action = 0;

    #[ORM\Column(name: '`user_name`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`id_search_option`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see search.constant.php for value'])]
    public int $id_search_option = 0;

    #[ORM\Column(name: '`old_value`', type: 'string', length: 255, nullable: true)]
    public ?string $old_value = null;

    #[ORM\Column(name: '`new_value`', type: 'string', length: 255, nullable: true)]
    public ?string $new_value = null;
}
