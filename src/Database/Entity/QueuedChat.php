<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_queuedchats')]
class QueuedChat
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`notificationtemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $notificationtemplates_id = 0;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\Column(name: '`groups_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id = 0;

    #[ORM\Column(name: '`itilcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $itilcategories_id = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`sent_try`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sent_try = 0;

    #[ORM\Column(name: '`create_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $create_time = null;

    #[ORM\Column(name: '`send_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $send_time = null;

    #[ORM\Column(name: '`sent_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $sent_time = null;

    #[ORM\Column(name: '`entName`', type: 'text', nullable: true)]
    public ?string $entName = null;

    #[ORM\Column(name: '`ticketTitle`', type: 'text', nullable: true)]
    public ?string $ticketTitle = null;

    #[ORM\Column(name: '`completName`', type: 'text', nullable: true)]
    public ?string $completName = null;

    #[ORM\Column(name: '`serverName`', type: 'text', nullable: true)]
    public ?string $serverName = null;

    #[ORM\Column(name: '`hookurl`', type: 'string', length: 250, nullable: true)]
    public ?string $hookurl = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false)]
    public string $mode = '';
}
