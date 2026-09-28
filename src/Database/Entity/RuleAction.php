<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ruleactions')]
class RuleAction
{
    #[ORM\ManyToOne(targetEntity: Rule::class)]
    #[ORM\JoinColumn(name: 'rules_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Rule $rules = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`action_type`', type: 'string', length: 255, nullable: true)]
    public ?string $action_type = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 255, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    public ?string $value = null;
}
