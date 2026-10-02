<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity\Ticket;
use itsmng\Database\Entity\Problem;
use itsmng\Database\Entity\Change;

/** ITIL records belong to exactly one supported Ticket, Problem or Change subject. */
trait ITILSubject
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Ticket'])]
    #[ApplicationManaged]
    public ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Problem::class)]
    #[ORM\JoinColumn(name: 'problems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Problem'])]
    #[ApplicationManaged]
    public ?Problem $problem = null;

    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Change'])]
    #[ApplicationManaged]
    public ?Change $change = null;

    public static function subjectAssociation(string $kind): string
    {
        return self::referenceAssociation($kind);
    }

    public static function withSubject(array $values, string $kind, int $id): array
    {
        return self::withReference($values, $kind, $id);
    }
}
