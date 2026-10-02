<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_crontasklogs')]
class CronTaskLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'crontasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?CronTask $task = null;

    #[ORM\ManyToOne(targetEntity: CronTaskLog::class)]
    #[ORM\JoinColumn(name: 'crontasklogs_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?CronTaskLog $parent = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false)]
    public int $state = 0;

    #[ORM\Column(name: '`elapsed`', type: 'float', nullable: false)]
    public float $elapsed = 0.0;

    #[ORM\Column(name: '`volume`', type: 'integer', nullable: false)]
    public int $volume = 0;

    #[ORM\Column(name: '`content`', type: 'string', length: 255, nullable: true)]
    public ?string $content = null;
}
