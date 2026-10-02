<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_crontasks')]
#[ORM\UniqueConstraint(name: 'crontasks_unicity', columns: ['itemtype', 'name'])]
class CronTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`name`', type: 'string', length: 150, nullable: false)]
    public string $name = '';

    #[ORM\Column(name: '`frequency`', type: 'integer', nullable: false)]
    public int $frequency = 0;

    #[ORM\Column(name: '`param`', type: 'integer', nullable: true)]
    public ?int $param = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $state = 1;

    #[ORM\Column(name: '`mode`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $mode = 1;

    #[ORM\Column(name: '`allowmode`', type: 'integer', nullable: false, options: ['default' => '3'])]
    public int $allowmode = 3;

    #[ORM\Column(name: '`hourmin`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $hourmin = 0;

    #[ORM\Column(name: '`hourmax`', type: 'integer', nullable: false, options: ['default' => '24'])]
    public int $hourmax = 24;

    #[ORM\Column(name: '`logs_lifetime`', type: 'integer', nullable: false, options: ['default' => '30'])]
    public int $logs_lifetime = 30;

    #[ORM\Column(name: '`lastrun`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $lastrun = null;

    #[ORM\Column(name: '`lastcode`', type: 'integer', nullable: true)]
    public ?int $lastcode = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
