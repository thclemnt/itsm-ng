<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computerantiviruses')]
class ComputerAntivirus
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`computers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $computers_id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`manufacturers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $manufacturers_id = 0;

    #[ORM\Column(name: '`antivirus_version`', type: 'string', length: 255, nullable: true)]
    public ?string $antivirus_version = null;

    #[ORM\Column(name: '`signature_version`', type: 'string', length: 255, nullable: true)]
    public ?string $signature_version = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_uptodate`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_uptodate = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_expiration`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_expiration = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
