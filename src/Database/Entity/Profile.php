<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`interface`', type: 'string', length: 255, nullable: true, options: ['default' => 'helpdesk'])]
    public ?string $interface = null;

    #[ORM\Column(name: '`is_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_default = false;

    #[ORM\Column(name: '`helpdesk_hardware`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $helpdesk_hardware = 0;

    #[ORM\Column(name: '`helpdesk_item_type`', type: 'text', nullable: true)]
    public ?string $helpdesk_item_type = null;

    #[ORM\Column(name: '`ticket_status`', type: 'text', nullable: true)]
    public ?string $ticket_status = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`problem_status`', type: 'text', nullable: true)]
    public ?string $problem_status = null;

    #[ORM\Column(name: '`create_ticket_on_login`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $create_ticket_on_login = false;

    #[ORM\Column(name: '`tickettemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickettemplates_id = 0;

    #[ORM\Column(name: '`changetemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $changetemplates_id = 0;

    #[ORM\Column(name: '`problemtemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $problemtemplates_id = 0;

    #[ORM\Column(name: '`change_status`', type: 'text', nullable: true)]
    public ?string $change_status = null;

    #[ORM\Column(name: '`managed_domainrecordtypes`', type: 'text', nullable: true)]
    public ?string $managed_domainrecordtypes = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
