<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;

/** Separately owned SchemaTool table: proves property declarations without a historical baseline. */
#[ORM\Entity]
#[ORM\Table(name: 'glpi_native_temporal_probe')]
final class NativeTemporalProbe
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 80)]
    public string $label;

    #[ORM\Column(type: 'datetimetz', nullable: true, options: ['comment' => "Property's instant"])]
    #[NativeTimestamp]
    public ?DateTimeInterface $nullable_instant = null;

    #[ORM\Column(type: 'datetimetz', generated: 'ALWAYS', options: ['default' => new CurrentTimestamp(), 'comment' => 'Owned touch clock'])]
    #[NativeTimestamp(touchTrigger: 'glpi_native_temporal_probe_touch')]
    public ?DateTimeInterface $touched_instant = null;

    #[ORM\Column(type: 'datetimetz', nullable: true)]
    public ?DateTimeInterface $unmarked_wall_time = null;
}
