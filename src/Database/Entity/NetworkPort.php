<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use InvalidArgumentException;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkports')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[RequiredSubjectConstraint('parent_kind')]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('on_device', ['items_id', 'itemtype'], postgresqlName: 'glpi_networkports_on_device')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_networkports_item')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_networkports_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_networkports_is_recursive')]
#[SchemaIndex('mac', ['mac'], postgresqlName: 'glpi_networkports_mac')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_networkports_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_networkports_is_dynamic')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_networkports_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_networkports_date_creation')]
#[SchemaIndex('glpi_networkports_computers_id', ['computers_id'])]
#[SchemaIndex('glpi_networkports_monitors_id', ['monitors_id'])]
#[SchemaIndex('glpi_networkports_networkequipments_id', ['networkequipments_id'])]
#[SchemaIndex('glpi_networkports_peripherals_id', ['peripherals_id'])]
#[SchemaIndex('glpi_networkports_phones_id', ['phones_id'])]
#[SchemaIndex('glpi_networkports_printers_id', ['printers_id'])]
#[SchemaIndex('glpi_networkports_enclosures_id', ['enclosures_id'])]
#[SchemaIndex('glpi_networkports_pdus_id', ['pdus_id'])]
#[SchemaIndex('glpi_networkports_clusters_id', ['clusters_id'])]
class NetworkPort implements LegacyInput
{
    use OpenParentInput {
        normalizeInput as private normalizeOpenParentInput;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_computers_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_monitors_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_networkequipments_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_peripherals_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_phones_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_printers_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_enclosures_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Enclosure'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Enclosure $enclosure = null;

    #[ORM\ManyToOne(targetEntity: PDU::class)]
    #[ORM\JoinColumn(name: 'pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_pdus_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PDU'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?PDU $pdu = null;

    #[ORM\ManyToOne(targetEntity: Cluster::class)]
    #[ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_networkports_clusters_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Cluster'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Cluster $cluster = null;

    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_networkports_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`logical_number`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $logical_number = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`instantiation_type`', type: 'string', length: 255, nullable: true)]
    public ?string $instantiation_type = null;

    #[ORM\Column(name: '`mac`', type: 'string', length: 255, nullable: true)]
    public ?string $mac = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    /** Parent lookup and storage must use the same exact signed identity. */
    public function normalizeInput(array $values): array
    {
        [$identity, , $opaque, , $owners] = self::parentBindings();
        foreach ([$identity, $opaque, ...array_column($owners, 1)] as $column) {
            if (array_key_exists($column, $values) && $values[$column] !== null) {
                $values[$column] = self::parentIdentity($values[$column]);
            }
        }
        return $this->normalizeOpenParentInput($values);
    }

    private static function parentIdentity(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^[+-]?[0-9]+$/D', $value)) {
            throw new InvalidArgumentException('Network port parent requires an exact signed integer.');
        }
        $negative = $value[0] === '-';
        $digits = ltrim(ltrim($value, '+-'), '0');
        $limit = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidArgumentException('Network port parent identity exceeds the native integer range.');
        }
        return (int)$value;
    }
}
