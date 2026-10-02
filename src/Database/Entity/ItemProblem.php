<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_problems')]
#[ORM\UniqueConstraint(name: 'items_problems_unicity', columns: ['problems_id', 'itemtype', 'items_id'])]
class ItemProblem implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RequiredItemReference;
    use \itsmng\Database\Mapping\ITILAssetAssociations;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ITILStatisticsRelation(\itsmng\Database\Mapping\ITILStatisticsRole::Items)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Problem $problems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
