<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ITILAssetAssociations;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_tickets')]
#[ORM\UniqueConstraint(name: 'items_tickets_unicity', columns: ['itemtype', 'items_id', 'tickets_id'])]
class ItemTicket implements LegacyInput
{
    use ItemReference;
    use ITILAssetAssociations;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ITILStatisticsRelation(ITILStatisticsRole::Items)]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
