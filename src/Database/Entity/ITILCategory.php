<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_itilcategories')]
class ITILCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`itilcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $itilcategories_id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`knowbaseitemcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $knowbaseitemcategories_id = 0;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups = null;

    #[ORM\Column(name: '`code`', type: 'string', length: 255, nullable: true)]
    public ?string $code = null;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_helpdeskvisible`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_helpdeskvisible = true;

    #[ORM\Column(name: '`tickettemplates_id_incident`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickettemplates_id_incident = 0;

    #[ORM\Column(name: '`tickettemplates_id_demand`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickettemplates_id_demand = 0;

    #[ORM\Column(name: '`changetemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $changetemplates_id = 0;

    #[ORM\Column(name: '`problemtemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $problemtemplates_id = 0;

    #[ORM\Column(name: '`is_incident`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $is_incident = 1;

    #[ORM\Column(name: '`is_request`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $is_request = 1;

    #[ORM\Column(name: '`is_problem`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $is_problem = 1;

    #[ORM\Column(name: '`is_change`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_change = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
