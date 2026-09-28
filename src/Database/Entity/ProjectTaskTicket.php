<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasks_tickets')]
#[ORM\UniqueConstraint(name: 'projecttasks_tickets_unicity', columns: ['tickets_id', 'projecttasks_id'])]
class ProjectTaskTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tickets_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id = 0;

    #[ORM\Column(name: '`projecttasks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $projecttasks_id = 0;
}
