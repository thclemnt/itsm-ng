<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_alerts')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id', 'type'], unique: true, postgresqlName: 'glpi_alerts_unicity')]
#[SchemaIndex('type', ['type'], postgresqlName: 'glpi_alerts_type')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_alerts_date')]
#[SchemaIndex('glpi_alerts_cartridgeitems_id', ['cartridgeitems_id'])]
#[SchemaIndex('glpi_alerts_consumableitems_id', ['consumableitems_id'])]
#[SchemaIndex('glpi_alerts_certificates_id', ['certificates_id'])]
#[SchemaIndex('glpi_alerts_contracts_id', ['contracts_id'])]
#[SchemaIndex('glpi_alerts_infocoms_id', ['infocoms_id'])]
#[SchemaIndex('glpi_alerts_reservations_id', ['reservations_id'])]
#[SchemaIndex('glpi_alerts_softwarelicenses_id', ['softwarelicenses_id'])]
#[SchemaIndex('glpi_alerts_planningrecalls_id', ['planningrecalls_id'])]
#[SchemaIndex('glpi_alerts_crontasks_id', ['crontasks_id'])]
#[SchemaIndex('glpi_alerts_users_id', ['users_id'])]
class Alert implements LegacyInput
{
    use ItemReference;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_cartridgeitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['CartridgeItem'])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeItem = null;

    #[ORM\ManyToOne(targetEntity: ConsumableItem::class)]
    #[ORM\JoinColumn(name: 'consumableitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_consumableitems_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ConsumableItem'])]
    #[ApplicationManaged]
    public ?ConsumableItem $consumableItem = null;

    #[ORM\ManyToOne(targetEntity: Certificate::class)]
    #[ORM\JoinColumn(name: 'certificates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_certificates_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Certificate'])]
    #[ApplicationManaged]
    public ?Certificate $certificate = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_contracts_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Contract'])]
    #[ApplicationManaged]
    public ?Contract $contract = null;

    #[ORM\ManyToOne(targetEntity: Infocom::class)]
    #[ORM\JoinColumn(name: 'infocoms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_infocoms_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Infocom'])]
    #[ApplicationManaged]
    public ?Infocom $infocom = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_reservations_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reservation'])]
    #[ApplicationManaged]
    public ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_softwarelicenses_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['SoftwareLicense'])]
    #[ApplicationManaged]
    public ?SoftwareLicense $softwareLicense = null;

    #[ORM\ManyToOne(targetEntity: PlanningRecall::class)]
    #[ORM\JoinColumn(name: 'planningrecalls_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_planningrecalls_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['PlanningRecall'])]
    #[ApplicationManaged]
    public ?PlanningRecall $planningRecall = null;

    #[ORM\ManyToOne(targetEntity: CronTask::class)]
    #[ORM\JoinColumn(name: 'crontasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_crontasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['CronTask'])]
    #[ApplicationManaged]
    public ?CronTask $cronTask = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_alerts_users_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['User'])]
    #[ApplicationManaged]
    public ?User $user = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see define.php ALERT_* constant'])]
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
