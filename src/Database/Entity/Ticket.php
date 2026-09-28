<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickets')]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`closedate`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $closedate = null;

    #[ORM\Column(name: '`solvedate`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $solvedate = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`users_id_lastupdater`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_lastupdater = 0;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\Column(name: '`users_id_recipient`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_recipient = 0;

    #[ORM\Column(name: '`requesttypes_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $requesttypes_id = 0;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`urgency`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $urgency = 1;

    #[ORM\Column(name: '`impact`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $impact = 1;

    #[ORM\Column(name: '`priority`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $priority = 1;

    #[ORM\Column(name: '`itilcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $itilcategories_id = 0;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;

    #[ORM\Column(name: '`global_validation`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $global_validation = 1;

    #[ORM\Column(name: '`slas_id_ttr`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $slas_id_ttr = 0;

    #[ORM\Column(name: '`slas_id_tto`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $slas_id_tto = 0;

    #[ORM\Column(name: '`slalevels_id_ttr`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $slalevels_id_ttr = 0;

    #[ORM\Column(name: '`time_to_resolve`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $time_to_resolve = null;

    #[ORM\Column(name: '`time_to_own`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $time_to_own = null;

    #[ORM\Column(name: '`begin_waiting_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_waiting_date = null;

    #[ORM\Column(name: '`sla_waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sla_waiting_duration = 0;

    #[ORM\Column(name: '`ola_waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ola_waiting_duration = 0;

    #[ORM\Column(name: '`olas_id_tto`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $olas_id_tto = 0;

    #[ORM\Column(name: '`olas_id_ttr`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $olas_id_ttr = 0;

    #[ORM\Column(name: '`olalevels_id_ttr`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $olalevels_id_ttr = 0;

    #[ORM\Column(name: '`ola_ttr_begin_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $ola_ttr_begin_date = null;

    #[ORM\Column(name: '`internal_time_to_resolve`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $internal_time_to_resolve = null;

    #[ORM\Column(name: '`internal_time_to_own`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $internal_time_to_own = null;

    #[ORM\Column(name: '`waiting_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $waiting_duration = 0;

    #[ORM\Column(name: '`close_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $close_delay_stat = 0;

    #[ORM\Column(name: '`solve_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $solve_delay_stat = 0;

    #[ORM\Column(name: '`takeintoaccount_delay_stat`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $takeintoaccount_delay_stat = 0;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Location $locations = null;

    #[ORM\Column(name: '`validation_percent`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $validation_percent = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
