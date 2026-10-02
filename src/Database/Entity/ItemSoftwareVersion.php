<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_softwareversions')]
#[ORM\UniqueConstraint(name: 'items_softwareversions_unicity', columns: ['itemtype', 'items_id', 'softwareversions_id'])]
class ItemSoftwareVersion
{
    #[ORM\ManyToOne(targetEntity: SoftwareVersion::class)]
    #[ORM\JoinColumn(name: 'softwareversions_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?SoftwareVersion $softwareversions = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[\itsmng\Database\Mapping\PolymorphicReference(Computer::class, "itemtype")]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`is_deleted_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted_item = false;

    #[ORM\Column(name: '`is_template_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template_item = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_install`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_install = null;
}
