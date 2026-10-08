<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipnetworks')]
class IPNetwork
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?IPNetwork $parent = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`addressable`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $addressable = false;

    #[ORM\Column(name: '`version`', type: 'smallint', nullable: true, options: ['unsigned' => true, 'default' => '0'])]
    public ?int $version = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`address`', type: 'string', length: 40, nullable: true)]
    public ?string $address = null;

    #[ORM\Column(name: '`address_0`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $address_0 = 0;

    #[ORM\Column(name: '`address_1`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $address_1 = 0;

    #[ORM\Column(name: '`address_2`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $address_2 = 0;

    #[ORM\Column(name: '`address_3`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $address_3 = 0;

    #[ORM\Column(name: '`netmask`', type: 'string', length: 40, nullable: true)]
    public ?string $netmask = null;

    #[ORM\Column(name: '`netmask_0`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $netmask_0 = 0;

    #[ORM\Column(name: '`netmask_1`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $netmask_1 = 0;

    #[ORM\Column(name: '`netmask_2`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $netmask_2 = 0;

    #[ORM\Column(name: '`netmask_3`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $netmask_3 = 0;

    #[ORM\Column(name: '`gateway`', type: 'string', length: 40, nullable: true)]
    public ?string $gateway = null;

    #[ORM\Column(name: '`gateway_0`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $gateway_0 = 0;

    #[ORM\Column(name: '`gateway_1`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $gateway_1 = 0;

    #[ORM\Column(name: '`gateway_2`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $gateway_2 = 0;

    #[ORM\Column(name: '`gateway_3`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $gateway_3 = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
