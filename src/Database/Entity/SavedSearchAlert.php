<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_savedsearches_alerts')]
#[ORM\UniqueConstraint(name: 'savedsearches_alerts_unicity', columns: ['savedsearches_id', 'operator', 'value'])]
class SavedSearchAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(name: 'savedsearches_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?SavedSearch $savedsearches = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`operator`', type: 'smallint', nullable: false)]
    public int $operator = 0;

    #[ORM\Column(name: '`value`', type: 'integer', nullable: false)]
    public int $value = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
