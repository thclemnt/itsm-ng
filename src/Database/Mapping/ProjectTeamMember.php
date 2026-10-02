<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Entity;

/** A project team member is exactly one real user, group, supplier or contact. */
trait ProjectTeamMember
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: Entity\User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?Entity\User $user = null;

    #[ORM\ManyToOne(targetEntity: Entity\Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Group'])]
    #[ApplicationManaged]
    public ?Entity\Group $group = null;

    #[ORM\ManyToOne(targetEntity: Entity\Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Supplier'])]
    #[ApplicationManaged]
    public ?Entity\Supplier $supplier = null;

    #[ORM\ManyToOne(targetEntity: Entity\Contact::class)]
    #[ORM\JoinColumn(name: 'contacts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contact'])]
    #[ApplicationManaged]
    public ?Entity\Contact $contact = null;

    public static function memberAssociation(string $kind): string
    {
        return self::referenceAssociation($kind);
    }
}
