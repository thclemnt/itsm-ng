<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_contracts_items')]
#[ORM\UniqueConstraint(name: 'contracts_items_unicity', columns: ['contracts_id', 'itemtype', 'items_id'])]
class ContractItem implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RequiredItemReference;
    use \itsmng\Database\Mapping\AssetAssociations;
    use \itsmng\Database\Mapping\DeviceItemAssociations;
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Contract $contracts = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity\DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['DCRoom'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\DCRoom $dcRoom = null;

    #[ORM\ManyToOne(targetEntity: Entity\Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Rack'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Rack $rack = null;

    #[ORM\ManyToOne(targetEntity: Entity\Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Enclosure'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Enclosure $enclosure = null;

    #[ORM\ManyToOne(targetEntity: Entity\PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['PDU'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\PDU $pdu = null;

    #[ORM\ManyToOne(targetEntity: Entity\Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity\Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Entity\Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Entity\Project $project = null;

}
