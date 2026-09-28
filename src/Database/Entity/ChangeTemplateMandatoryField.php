<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changetemplatemandatoryfields')]
#[ORM\UniqueConstraint(name: 'changetemplatemandatoryfields_unicity', columns: ['changetemplates_id', 'num'])]
class ChangeTemplateMandatoryField
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`changetemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $changetemplates_id = 0;

    #[ORM\Column(name: '`num`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $num = 0;
}
