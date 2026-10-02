<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_clusters')]
#[ORM\UniqueConstraint(name: 'items_clusters_unicity', columns: ['clusters_id', 'itemtype', 'items_id'])]
class ItemCluster implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Cluster::class)]
    #[ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Cluster $clusters = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

}
