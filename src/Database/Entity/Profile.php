<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Repository\ProfileChoiceRepository;

#[ORM\Entity(repositoryClass: ProfileChoiceRepository::class)]
#[ORM\Table(name: 'glpi_profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`interface`', type: 'string', length: 255, nullable: true, options: ['default' => 'helpdesk'])]
    public ?string $interface = 'helpdesk';

    #[ORM\Column(name: '`is_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_default = false;

    #[ORM\Column(name: '`helpdesk_hardware`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $helpdesk_hardware = 0;

    #[ORM\Column(name: '`helpdesk_item_type`', type: 'text', nullable: true)]
    public ?string $helpdesk_item_type = null;

    #[ORM\Column(name: '`ticket_status`', type: 'text', nullable: true)]
    public ?string $ticket_status = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`problem_status`', type: 'text', nullable: true)]
    public ?string $problem_status = null;

    #[ORM\Column(name: '`create_ticket_on_login`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $create_ticket_on_login = false;

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TicketTemplate $tickettemplates_id = null;

    #[ORM\ManyToOne(targetEntity: ChangeTemplate::class)]
    #[ORM\JoinColumn(name: 'changetemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ChangeTemplate $changetemplates_id = null;

    #[ORM\ManyToOne(targetEntity: ProblemTemplate::class)]
    #[ORM\JoinColumn(name: 'problemtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProblemTemplate $problemtemplates_id = null;

    #[ORM\Column(name: '`change_status`', type: 'text', nullable: true)]
    public ?string $change_status = null;

    #[ORM\Column(name: '`managed_domainrecordtypes`', type: 'text', nullable: true)]
    public ?string $managed_domainrecordtypes = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
