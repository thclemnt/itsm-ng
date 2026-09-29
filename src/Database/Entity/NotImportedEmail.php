<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notimportedemails')]
class NotImportedEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`from`', type: 'string', length: 255, nullable: false)]
    public string $from = '';

    #[ORM\Column(name: '`to`', type: 'string', length: 255, nullable: false)]
    public string $to = '';

    #[ORM\ManyToOne(targetEntity: MailCollector::class)]
    #[ORM\JoinColumn(name: 'mailcollectors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?MailCollector $mailcollectors = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`subject`', type: 'text', nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(name: '`messageid`', type: 'string', length: 255, nullable: false)]
    public string $messageid = '';

    #[ORM\Column(name: '`reason`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $reason = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $users = null;
}
