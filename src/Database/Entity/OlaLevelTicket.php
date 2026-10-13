<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevels_tickets')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('tickets_id', ['tickets_id'], postgresqlName: 'glpi_olalevels_tickets_tickets_id')]
#[SchemaIndex('olalevels_id', ['olalevels_id'], postgresqlName: 'glpi_olalevels_tickets_olalevels_id')]
#[SchemaIndex('unicity', ['tickets_id', 'olalevels_id'], unique: true, postgresqlName: 'glpi_olalevels_tickets_unicity')]
class OlaLevelTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_olalevels_tickets_tickets_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\ManyToOne(targetEntity: OlaLevel::class)]
    #[ORM\JoinColumn(name: 'olalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_olalevels_tickets_olalevels_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?OlaLevel $olalevels = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;
}
