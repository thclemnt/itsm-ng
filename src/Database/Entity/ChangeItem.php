<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILAssetAssociations;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_changes_items')]
#[ORM\UniqueConstraint(name: 'changes_items_unicity', columns: ['changes_id', 'itemtype', 'items_id'])]
class ChangeItem implements LegacyInput
{
    use RequiredItemReference;
    use ITILAssetAssociations;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ITILStatisticsRelation(ITILStatisticsRole::Items)]
    #[ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
