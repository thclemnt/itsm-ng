<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_plugins')]
#[ORM\UniqueConstraint(name: 'plugins_unicity', columns: ['directory'])]
class Plugin
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`directory`', type: 'string', length: 255, nullable: false)]
    public string $directory = '';

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false)]
    public string $name = '';

    #[ORM\Column(name: '`version`', type: 'string', length: 255, nullable: false)]
    public string $version = '';

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\Column(name: '`author`', type: 'string', length: 255, nullable: true)]
    public ?string $author = null;

    #[ORM\Column(name: '`homepage`', type: 'string', length: 255, nullable: true)]
    public ?string $homepage = null;

    #[ORM\Column(name: '`license`', type: 'string', length: 255, nullable: true)]
    public ?string $license = null;
}
