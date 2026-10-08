<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use InvalidArgumentException;
use SplObjectStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Repository\DomainRepository;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_domains')]
#[ORM\Index(name: 'domains_suppliers_id', columns: ['suppliers_id'])]
class Domain
{
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Supplier $suppliers = null;

    #[ORM\Column(name: 'is_helpdesk_visible', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_helpdesk_visible = true;

    #[ORM\ManyToOne(targetEntity: DomainType::class)]
    #[ORM\JoinColumn(name: 'domaintypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?DomainType $domaintypes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date_expiration`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_expiration = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`others`', type: 'string', length: 255, nullable: true)]
    public ?string $others = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    /** The commercial supplier must be local or a recursive ancestor of this owner. */
    public function assertCommercialSupplierOwnership(): void
    {
        if ($this->suppliers === null) {
            return;
        }
        $owner = $this->entities;
        $supplierOwner = $this->suppliers->entities;
        if ($owner === null || $supplierOwner === null) {
            throw new InvalidArgumentException('Domain commercial supplier requires valid owner entities.');
        }
        $same = static fn (Entity $first, Entity $second): bool => $first === $second
            || ($first->id !== null && $second->id !== null && $first->id === $second->id);
        if ($same($owner, $supplierOwner)) {
            return;
        }
        if ($this->suppliers->is_recursive) {
            $visited = new SplObjectStorage();
            $identifiers = [];
            while ($owner !== null) {
                if ($visited->offsetExists($owner) || ($owner->id !== null && isset($identifiers[$owner->id]))) {
                    throw new InvalidArgumentException('Domain commercial supplier owner hierarchy contains a cycle.');
                }
                $visited->offsetSet($owner);
                if ($owner->id !== null) {
                    $identifiers[$owner->id] = true;
                }
                $owner = $owner->parent;
                if ($owner !== null && $same($owner, $supplierOwner)) {
                    return;
                }
            }
        }
        throw new InvalidArgumentException('Domain commercial supplier must belong to its owner entity or a recursive ancestor.');
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PreFlush]
    public function validateCommercialSupplierOwnership(LifecycleEventArgs|PreFlushEventArgs $event): void
    {
        (new DomainRepository($event->getObjectManager()))
            ->assertSupplierBoolean($this->suppliers);
        $this->assertCommercialSupplierOwnership();
    }

}
