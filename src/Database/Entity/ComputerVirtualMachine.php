<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computervirtualmachines')]
class ComputerVirtualMachine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public Computer $computers;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: VirtualMachineState::class)]
    #[ORM\JoinColumn(name: 'virtualmachinestates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?VirtualMachineState $virtualmachinestates = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachineSystem::class)]
    #[ORM\JoinColumn(name: 'virtualmachinesystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?VirtualMachineSystem $virtualmachinesystems = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachineType::class)]
    #[ORM\JoinColumn(name: 'virtualmachinetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?VirtualMachineType $virtualmachinetypes = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $uuid = '';

    #[ORM\Column(name: '`vcpu`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $vcpu = 0;

    #[ORM\Column(name: '`ram`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $ram = '';

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
