<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_domainrecords')]
class DomainRecord
{
    #[ORM\ManyToOne(targetEntity: DomainRecordType::class)]
    #[ORM\JoinColumn(name: 'domainrecordtypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?DomainRecordType $domainrecordtypes = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Domain $domains = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`data`', type: 'text', nullable: true)]
    public ?string $data = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`ttl`', type: 'integer', nullable: false)]
    public int $ttl = 0;

    #[ORM\Column(name: '`users_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_tech = 0;

    #[ORM\Column(name: '`groups_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id_tech = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
