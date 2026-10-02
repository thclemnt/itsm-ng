<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickettemplatemandatoryfields')]
#[ORM\UniqueConstraint(name: 'tickettemplatemandatoryfields_unicity', columns: ['tickettemplates_id', 'num'])]
class TicketTemplateMandatoryField
{
    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?TicketTemplate $tickettemplates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`num`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $num = 0;
}
