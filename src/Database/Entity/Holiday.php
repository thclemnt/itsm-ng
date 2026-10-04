<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_holidays')]
class Holiday
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`begin_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $end_date = null;

    #[ORM\Column(name: '`is_perpetual`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_perpetual = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    /** Inclusive calendar dates; an annual period can cross New Year. */
    public function containsDay(\DateTimeInterface $day): bool
    {
        if ($this->begin_date === null || $this->end_date === null) {
            return false;
        }
        if (!$this->is_perpetual) {
            $date = $day->format('Y-m-d');
            return $date >= $this->begin_date->format('Y-m-d')
                && $date <= $this->end_date->format('Y-m-d');
        }
        $date = $day->format('md');
        $begin = $this->begin_date->format('md');
        $end = $this->end_date->format('md');
        return $begin <= $end
            ? $date >= $begin && $date <= $end
            : $date >= $begin || $date <= $end;
    }
}
