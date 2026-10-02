<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_changes_items')]
#[ORM\UniqueConstraint(name: 'changes_items_unicity', columns: ['changes_id', 'itemtype', 'items_id'])]
class ChangeItem implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RequiredItemReference;
    use \itsmng\Database\Mapping\ITILAssetAssociations;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ITILStatisticsRelation(\itsmng\Database\Mapping\ITILStatisticsRole::Items)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
