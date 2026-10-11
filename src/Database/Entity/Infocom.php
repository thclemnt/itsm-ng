<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\VirtualAssetLink;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_infocoms')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_infocoms_unicity')]
#[SchemaIndex('buy_date', ['buy_date'], postgresqlName: 'glpi_infocoms_buy_date')]
#[SchemaIndex('alert', ['alert'], postgresqlName: 'glpi_infocoms_alert')]
#[SchemaIndex('budgets_id', ['budgets_id'], postgresqlName: 'glpi_infocoms_budgets_id')]
#[SchemaIndex('suppliers_id', ['suppliers_id'], postgresqlName: 'glpi_infocoms_suppliers_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_infocoms_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_infocoms_is_recursive')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_infocoms_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_infocoms_date_creation')]
#[SchemaIndex('businesscriticities_id', ['businesscriticities_id'], postgresqlName: 'glpi_infocoms_businesscriticities_id')]
class Infocom
{
    /** Calendar months clamp the purchase day to the last day of the expiry month. */
    public function warrantyExpiresOn(): ?DateTimeImmutable
    {
        if ($this->warranty_date === null || $this->warranty_duration <= 0) {
            return null;
        }
        $start = DateTimeImmutable::createFromInterface($this->warranty_date)->setTime(0, 0);
        $month = $start->modify('first day of this month')->modify('+' . $this->warranty_duration . ' months');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$start->format('d'), (int)$month->format('t')));
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[VirtualAssetLink("itemtype")]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_infocoms_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`buy_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $buy_date = null;

    #[ORM\Column(name: '`use_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $use_date = null;

    #[ORM\Column(name: '`warranty_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $warranty_duration = 0;

    #[ORM\Column(name: '`warranty_info`', type: 'string', length: 255, nullable: true)]
    public ?string $warranty_info = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_infocoms_suppliers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Supplier $suppliers = null;

    #[ORM\Column(name: '`order_number`', type: 'string', length: 255, nullable: true)]
    public ?string $order_number = null;

    #[ORM\Column(name: '`delivery_number`', type: 'string', length: 255, nullable: true)]
    public ?string $delivery_number = null;

    #[ORM\Column(name: '`immo_number`', type: 'string', length: 255, nullable: true)]
    public ?string $immo_number = null;

    #[ORM\Column(name: '`value`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $value = '0.0000';

    #[ORM\Column(name: '`warranty_value`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $warranty_value = '0.0000';

    #[ORM\Column(name: '`sink_time`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sink_time = 0;

    #[ORM\Column(name: '`sink_type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sink_type = 0;

    #[ORM\Column(name: '`sink_coeff`', type: 'float', nullable: false, options: ['default' => '0'])]
    public float $sink_coeff = 0.0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`bill`', type: 'string', length: 255, nullable: true)]
    public ?string $bill = null;

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_infocoms_budgets_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Budget $budgets = null;

    #[ORM\Column(name: '`alert`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $alert = 0;

    #[ORM\Column(name: '`order_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $order_date = null;

    #[ORM\Column(name: '`delivery_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $delivery_date = null;

    #[ORM\Column(name: '`inventory_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $inventory_date = null;

    #[ORM\Column(name: '`warranty_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $warranty_date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`decommission_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $decommission_date = null;

    #[ORM\ManyToOne(targetEntity: BusinessCriticity::class)]
    #[ORM\JoinColumn(name: 'businesscriticities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_infocoms_businesscriticities_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?BusinessCriticity $businesscriticities = null;
}
