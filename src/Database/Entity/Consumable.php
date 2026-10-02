<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_consumables')]
#[ORM\HasLifecycleCallbacks]
class Consumable implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\ItemReference;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'consumableitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?ConsumableItem $consumableitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`date_in`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_in = null;

    #[ORM\Column(name: '`date_out`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_out = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?User $recipientUser = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\DiscriminatedBy('itemtype', 'items_id', ['Group'])]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Group $recipientGroup = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[\itsmng\Database\Mapping\DiscriminatorKey(emptyValue: 0)]
    public int $items_id = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    protected static function allowsEmptyReference(): bool
    {
        return true;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateUsage(): void
    {
        if ($this->date_out !== null && $this->itemtype === null) {
            throw new \InvalidArgumentException('Issued consumable requires a recipient');
        }
    }
}
