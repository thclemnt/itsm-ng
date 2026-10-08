<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_alerts')]
#[ORM\UniqueConstraint(name: 'alerts_unicity', columns: ['itemtype', 'items_id', 'type'])]
class Alert implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $consumableItem = null;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[ApplicationManaged]
    public ?Certificate $certificate = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $contract = null;

    #[ORM\ManyToOne(targetEntity: Infocom::class)]
    #[ORM\JoinColumn(name: 'infocoms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Infocom'])]
    #[ApplicationManaged]
    public ?Infocom $infocom = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reservation'])]
    #[ApplicationManaged]
    public ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[ApplicationManaged]
    public ?SoftwareLicense $softwareLicense = null;

    #[ORM\ManyToOne(targetEntity: PlanningRecall::class)]
    #[ORM\JoinColumn(name: 'planningrecalls_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PlanningRecall'])]
    #[ApplicationManaged]
    public ?PlanningRecall $planningRecall = null;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'crontasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['CronTask'])]
    #[ApplicationManaged]
    public ?CronTask $cronTask = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $user = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $type = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: false, options: ['default' => new CurrentTimestamp()])]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\PrePersist]
    public function initializeDeliveryDate(): void
    {
        $this->date ??= new DateTime();
    }
}
