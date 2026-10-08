<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\AssetAssociations;
use itsmng\Database\Mapping\DeviceItemAssociations;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_projects')]
#[ORM\UniqueConstraint(name: 'items_projects_unicity', columns: ['projects_id', 'itemtype', 'items_id'])]
class ItemProject implements LegacyInput
{
    use RequiredItemReference;
    use AssetAssociations;
    use DeviceItemAssociations;
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity\DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['DCRoom'])]
    #[ApplicationManaged]
    public ?Entity\DCRoom $dcRoom = null;

    #[ORM\ManyToOne(targetEntity: Entity\Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Rack'])]
    #[ApplicationManaged]
    public ?Entity\Rack $rack = null;

    #[ORM\ManyToOne(targetEntity: Entity\Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Enclosure'])]
    #[ApplicationManaged]
    public ?Entity\Enclosure $enclosure = null;

    #[ORM\ManyToOne(targetEntity: Entity\PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PDU'])]
    #[ApplicationManaged]
    public ?Entity\PDU $pdu = null;

    #[ORM\ManyToOne(targetEntity: Entity\Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Entity\Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Entity\Project::class)]
    #[ORM\JoinColumn(name: 'subject_projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Project'])]
    #[ApplicationManaged]
    public ?Entity\Project $subjectProject = null;

}
