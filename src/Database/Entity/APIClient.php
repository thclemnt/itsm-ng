<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_apiclients')]
class APIClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $is_recursive = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`is_active`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $is_active = 0;

    #[ORM\Column(name: '`ipv4_range_start`', type: 'bigint', nullable: true)]
    public ?string $ipv4_range_start = null;

    #[ORM\Column(name: '`ipv4_range_end`', type: 'bigint', nullable: true)]
    public ?string $ipv4_range_end = null;

    #[ORM\Column(name: '`ipv6`', type: 'string', length: 255, nullable: true)]
    public ?string $ipv6 = null;

    #[ORM\Column(name: '`app_token`', type: 'string', length: 255, nullable: true)]
    public ?string $app_token = null;

    #[ORM\Column(name: '`app_token_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $app_token_date = null;

    #[ORM\Column(name: '`dolog_method`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $dolog_method = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;
}
