<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_appliances')]
#[ORM\UniqueConstraint(name: 'appliances_unicity', columns: ['externalidentifier'])]
class Appliance
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`appliancetypes_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $appliancetypes_id = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\Column(name: '`manufacturers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $manufacturers_id = 0;

    #[ORM\Column(name: '`applianceenvironments_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $applianceenvironments_id = 0;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`users_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_tech = 0;

    #[ORM\Column(name: '`groups_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id = 0;

    #[ORM\Column(name: '`groups_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id_tech = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`states_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $states_id = 0;

    #[ORM\Column(name: '`externalidentifier`', type: 'string', length: 255, nullable: true)]
    public ?string $externalidentifier = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`is_helpdesk_visible`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_helpdesk_visible = true;
}
