<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_crontasks')]
#[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[\itsmng\Database\Mapping\SchemaOwner]
#[\itsmng\Database\Mapping\SchemaIndex('unicity', ['itemtype', 'name'], unique: true, postgresqlName: 'glpi_crontasks_unicity')]
#[\itsmng\Database\Mapping\SchemaIndex('mode', ['mode'], postgresqlName: 'glpi_crontasks_mode')]
#[\itsmng\Database\Mapping\SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_crontasks_date_mod')]
#[\itsmng\Database\Mapping\SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_crontasks_date_creation')]
class CronTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`name`', type: 'string', length: 150, nullable: false, options: ['comment' => 'task name'])]
    #[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, ['default' => ''])]
    public string $name = '';

    #[ORM\Column(name: '`frequency`', type: 'integer', nullable: false, options: ['comment' => 'second between launch'])]
    #[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, ['default' => 0])]
    public int $frequency = 0;

    #[ORM\Column(name: '`param`', type: 'integer', nullable: true, options: ['comment' => 'task specify parameter'])]
    public ?int $param = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['comment' => '0:disabled, 1:waiting, 2:running', 'default' => '1'])]
    public int $state = 1;

    #[ORM\Column(name: '`mode`', type: 'integer', nullable: false, options: ['comment' => '1:internal, 2:external', 'default' => '1'])]
    public int $mode = 1;

    #[ORM\Column(name: '`allowmode`', type: 'integer', nullable: false, options: ['comment' => '1:internal, 2:external, 3:both', 'default' => '3'])]
    public int $allowmode = 3;

    #[ORM\Column(name: '`hourmin`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $hourmin = 0;

    #[ORM\Column(name: '`hourmax`', type: 'integer', nullable: false, options: ['default' => '24'])]
    public int $hourmax = 24;

    #[ORM\Column(name: '`logs_lifetime`', type: 'integer', nullable: false, options: ['comment' => 'number of days', 'default' => '30'])]
    public int $logs_lifetime = 30;

    #[ORM\Column(name: '`lastrun`', type: 'datetimetz', nullable: true, options: ['comment' => 'last run date'])]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $lastrun = null;

    #[ORM\Column(name: '`lastcode`', type: 'integer', nullable: true, options: ['comment' => 'last run return code'])]
    public ?int $lastcode = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;
}
