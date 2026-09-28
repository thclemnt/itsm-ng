<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_dashboards')]
class Dashboard
{
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false, insertable: false, updatable: false, generated: 'INSERT')]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 100, nullable: false)]
    public string $name = '';

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: false)]
    public string $content = '';

    #[ORM\Id]
    #[ORM\Column(name: '`profileId`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $profileId = 0;

    #[ORM\Id]
    #[ORM\Column(name: '`userId`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $userId = 0;
}
