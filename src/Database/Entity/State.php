<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_states')]
#[\itsmng\Database\Mapping\SchemaIndex('unicity', ['parent_key', 'name'], unique: true, postgresqlName: 'glpi_states_unicity')]
class State
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

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_visible_computer`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_computer = true;

    #[ORM\Column(name: '`is_visible_monitor`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_monitor = true;

    #[ORM\Column(name: '`is_visible_networkequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_networkequipment = true;

    #[ORM\Column(name: '`is_visible_peripheral`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_peripheral = true;

    #[ORM\Column(name: '`is_visible_phone`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_phone = true;

    #[ORM\Column(name: '`is_visible_printer`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_printer = true;

    #[ORM\Column(name: '`is_visible_softwareversion`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_softwareversion = true;

    #[ORM\Column(name: '`is_visible_softwarelicense`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_softwarelicense = true;

    #[ORM\Column(name: '`is_visible_line`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_line = true;

    #[ORM\Column(name: '`is_visible_certificate`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_certificate = true;

    #[ORM\Column(name: '`is_visible_rack`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_rack = true;

    #[ORM\Column(name: '`is_visible_passivedcequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_passivedcequipment = true;

    #[ORM\Column(name: '`is_visible_enclosure`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_enclosure = true;

    #[ORM\Column(name: '`is_visible_pdu`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_pdu = true;

    #[ORM\Column(name: '`is_visible_cluster`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_cluster = true;

    #[ORM\Column(name: '`is_visible_contract`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_contract = true;

    #[ORM\Column(name: '`is_visible_appliance`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_appliance = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
    #[\itsmng\Database\Mapping\ReferenceKey('states_id')]
    #[ORM\Column(name: '`parent_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?int $parent_key = null;
}
