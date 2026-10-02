<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_groups')]
class Group
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

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`ldap_field`', type: 'string', length: 255, nullable: true)]
    public ?string $ldap_field = null;

    #[ORM\Column(name: '`ldap_value`', type: 'text', nullable: true)]
    public ?string $ldap_value = null;

    #[ORM\Column(name: '`ldap_group_dn`', type: 'text', nullable: true)]
    public ?string $ldap_group_dn = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_requester`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_requester = true;

    #[ORM\Column(name: '`is_watcher`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_watcher = true;

    #[ORM\Column(name: '`is_assign`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_assign = true;

    #[ORM\Column(name: '`is_task`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_task = true;

    #[ORM\Column(name: '`is_notify`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_notify = true;

    #[ORM\Column(name: '`is_itemgroup`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_itemgroup = true;

    #[ORM\Column(name: '`is_usergroup`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_usergroup = true;

    #[ORM\Column(name: '`is_manager`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_manager = true;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
