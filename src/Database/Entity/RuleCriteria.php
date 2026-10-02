<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_rulecriterias')]
class RuleCriteria
{
    #[ORM\ManyToOne(targetEntity: Rule::class)]
    #[ORM\JoinColumn(name: 'rules_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Rule $rules = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`criteria`', type: 'string', length: 255, nullable: true)]
    public ?string $criteria = null;

    #[ORM\Column(name: '`condition`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $condition = 0;

    #[ORM\Column(name: '`pattern`', type: 'text', nullable: true)]
    public ?string $pattern = null;
}
