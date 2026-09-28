<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_racks')]
class Rack
{
    #[ORM\ManyToOne(targetEntity: RackType::class)]
    #[ORM\JoinColumn(name: 'racktypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?RackType $racktypes = null;

    #[ORM\ManyToOne(targetEntity: DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?DCRoom $dcrooms = null;

    #[ORM\ManyToOne(targetEntity: RackModel::class)]
    #[ORM\JoinColumn(name: 'rackmodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?RackModel $rackmodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`manufacturers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $manufacturers_id = 0;

    #[ORM\Column(name: '`states_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $states_id = 0;

    #[ORM\Column(name: '`users_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_tech = 0;

    #[ORM\Column(name: '`groups_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id_tech = 0;

    #[ORM\Column(name: '`width`', type: 'integer', nullable: true)]
    public ?int $width = null;

    #[ORM\Column(name: '`height`', type: 'integer', nullable: true)]
    public ?int $height = null;

    #[ORM\Column(name: '`depth`', type: 'integer', nullable: true)]
    public ?int $depth = null;

    #[ORM\Column(name: '`number_units`', type: 'integer', nullable: true, options: ['default' => '0'])]
    public ?int $number_units = null;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`room_orientation`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $room_orientation = 0;

    #[ORM\Column(name: '`position`', type: 'string', length: 50, nullable: true)]
    public ?string $position = null;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`max_power`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_power = 0;

    #[ORM\Column(name: '`mesured_power`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $mesured_power = 0;

    #[ORM\Column(name: '`max_weight`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_weight = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
