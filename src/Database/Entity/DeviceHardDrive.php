<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_deviceharddrives')]
class DeviceHardDrive
{
    #[ORM\ManyToOne(targetEntity: DeviceHardDriveModel::class)]
    #[ORM\JoinColumn(name: 'deviceharddrivemodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?DeviceHardDriveModel $deviceharddrivemodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`designation`', type: 'string', length: 255, nullable: true)]
    public ?string $designation = null;

    #[ORM\Column(name: '`rpm`', type: 'string', length: 255, nullable: true)]
    public ?string $rpm = null;

    #[ORM\ManyToOne(targetEntity: InterfaceType::class)]
    #[ORM\JoinColumn(name: 'interfacetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?InterfaceType $interfacetypes = null;

    #[ORM\Column(name: '`cache`', type: 'string', length: 255, nullable: true)]
    public ?string $cache = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`capacity_default`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $capacity_default = 0;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
