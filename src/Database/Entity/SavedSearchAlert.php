<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_savedsearches_alerts')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_savedsearches_alerts_name')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_savedsearches_alerts_is_active')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_savedsearches_alerts_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_savedsearches_alerts_date_creation')]
#[SchemaIndex('unicity', ['savedsearches_id', 'operator', 'value'], unique: true, postgresqlName: 'glpi_savedsearches_alerts_unicity')]
#[SchemaIndex('IDX_8F033C74C85FBDC1', ['savedsearches_id'], postgresqlName: 'IDX_8F033C74C85FBDC1')]
class SavedSearchAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(name: 'savedsearches_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_savedsearches_alerts_savedsearches_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?SavedSearch $savedsearches = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = false;

    #[ORM\Column(name: '`operator`', type: 'smallint', nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $operator = 0;

    #[ORM\Column(name: '`value`', type: 'integer', nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $value = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
