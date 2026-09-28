<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_domains_items')]
#[ORM\UniqueConstraint(name: 'domains_items_unicity', columns: ['domains_id', 'itemtype', 'items_id'])]
class DomainItem
{
    #[ORM\ManyToOne(targetEntity: DomainRelation::class)]
    #[ORM\JoinColumn(name: 'domainrelations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?DomainRelation $domainrelations = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Domain $domains = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

}
