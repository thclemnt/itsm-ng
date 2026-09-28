<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_deviceprocessors')]
class DeviceProcessor
{
    #[ORM\ManyToOne(targetEntity: DeviceProcessorModel::class)]
    #[ORM\JoinColumn(name: 'deviceprocessormodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?DeviceProcessorModel $deviceprocessormodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`designation`', type: 'string', length: 255, nullable: true)]
    public ?string $designation = null;

    #[ORM\Column(name: '`frequence`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $frequence = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`frequency_default`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $frequency_default = 0;

    #[ORM\Column(name: '`nbcores_default`', type: 'integer', nullable: true)]
    public ?int $nbcores_default = null;

    #[ORM\Column(name: '`nbthreads_default`', type: 'integer', nullable: true)]
    public ?int $nbthreads_default = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
