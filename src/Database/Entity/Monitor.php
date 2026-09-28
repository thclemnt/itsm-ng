<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_monitors')]
class Monitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\Column(name: '`users_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_tech = 0;

    #[ORM\Column(name: '`groups_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id_tech = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`size`', type: 'decimal', precision: 5, scale: 2, nullable: false, options: ['default' => '0.00'])]
    public string $size = '0.00';

    #[ORM\Column(name: '`have_micro`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_micro = false;

    #[ORM\Column(name: '`have_speaker`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_speaker = false;

    #[ORM\Column(name: '`have_subd`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_subd = false;

    #[ORM\Column(name: '`have_bnc`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_bnc = false;

    #[ORM\Column(name: '`have_dvi`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_dvi = false;

    #[ORM\Column(name: '`have_pivot`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_pivot = false;

    #[ORM\Column(name: '`have_hdmi`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_hdmi = false;

    #[ORM\Column(name: '`have_displayport`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_displayport = false;

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\ManyToOne(targetEntity: MonitorType::class)]
    #[ORM\JoinColumn(name: 'monitortypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?MonitorType $monitortypes = null;

    #[ORM\ManyToOne(targetEntity: MonitorModel::class)]
    #[ORM\JoinColumn(name: 'monitormodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?MonitorModel $monitormodels = null;

    #[ORM\Column(name: '`manufacturers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $manufacturers_id = 0;

    #[ORM\Column(name: '`is_global`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_global = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`groups_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id = 0;

    #[ORM\Column(name: '`states_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $states_id = 0;

    #[ORM\Column(name: '`ticket_tco`', type: 'decimal', precision: 20, scale: 4, nullable: true, options: ['default' => '0.0000'])]
    public ?string $ticket_tco = '0.0000';

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;
}
