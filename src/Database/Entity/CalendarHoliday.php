<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_calendars_holidays')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('holidays_id', ['holidays_id'], postgresqlName: 'glpi_calendars_holidays_holidays_id')]
#[SchemaIndex('unicity', ['calendars_id', 'holidays_id'], unique: true, postgresqlName: 'glpi_calendars_holidays_unicity')]
class CalendarHoliday
{
    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_calendars_holidays_calendars_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?Calendar $calendars = null;

    #[ORM\ManyToOne(targetEntity: Holiday::class)]
    #[ORM\JoinColumn(name: 'holidays_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_calendars_holidays_holidays_id', options: ['default' => 0])]
    public ?Holiday $holidays = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
