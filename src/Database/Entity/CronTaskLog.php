<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_crontasklogs')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_crontasklogs_date')]
#[SchemaIndex('crontasks_id', ['crontasks_id'], postgresqlName: 'glpi_crontasklogs_crontasks_id')]
#[SchemaIndex('crontasklogs_id_state', ['crontasklogs_id', 'state'], postgresqlName: 'glpi_crontasklogs_crontasklogs_id_state')]
class CronTaskLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'crontasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_crontasklogs_crontasks_id')]
    // Preserve the existing PostgreSQL schema default; writes must still supply a valid task.
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    #[ApplicationManaged]
    public ?CronTask $task = null;

    #[ORM\ManyToOne(targetEntity: CronTaskLog::class)]
    #[ORM\JoinColumn(name: 'crontasklogs_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_crontasklogs_crontasklogs_id', options: ['comment' => "id of 'start' event"])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?CronTaskLog $parent = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['comment' => '0:start, 1:run, 2:stop'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $state = 0;

    #[ORM\Column(name: '`elapsed`', type: 'float', nullable: false, options: ['comment' => 'time elapsed since start'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public float $elapsed = 0.0;

    #[ORM\Column(name: '`volume`', type: 'integer', nullable: false, options: ['comment' => 'for statistics'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $volume = 0;

    #[ORM\Column(name: '`content`', type: 'string', length: 255, nullable: true, options: ['comment' => 'message'])]
    public ?string $content = null;
}
