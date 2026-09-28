<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_problemtemplatehiddenfields')]
#[ORM\UniqueConstraint(name: 'problemtemplatehiddenfields_unicity', columns: ['problemtemplates_id', 'num'])]
class ProblemTemplateHiddenField
{
    #[ORM\ManyToOne(targetEntity: ProblemTemplate::class)]
    #[ORM\JoinColumn(name: 'problemtemplates_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?ProblemTemplate $problemtemplates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`num`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $num = 0;
}
