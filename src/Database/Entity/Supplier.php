<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Repository\DomainRepository;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_suppliers')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_suppliers_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_suppliers_entities_id')]
#[SchemaIndex('suppliertypes_id', ['suppliertypes_id'], postgresqlName: 'glpi_suppliers_suppliertypes_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_suppliers_is_deleted')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_suppliers_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_suppliers_date_creation')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_suppliers_is_active')]
class Supplier
{
    #[ORM\PreUpdate]
    public function validateCommercialDomainOwnership(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('entities') || $event->hasChangedField('is_recursive')) {
            (new DomainRepository($event->getObjectManager()))
                ->assertSupplierDomains($this);
        }
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_suppliers_entities_id', options: ['default' => '0'])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: SupplierType::class)]
    #[ORM\JoinColumn(name: 'suppliertypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_suppliers_suppliertypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SupplierType $suppliertypes = null;

    #[ORM\Column(name: '`address`', type: 'text', nullable: true)]
    public ?string $address = null;

    #[ORM\Column(name: '`postcode`', type: 'string', length: 255, nullable: true)]
    public ?string $postcode = null;

    #[ORM\Column(name: '`town`', type: 'string', length: 255, nullable: true)]
    public ?string $town = null;

    #[ORM\Column(name: '`state`', type: 'string', length: 255, nullable: true)]
    public ?string $state = null;

    #[ORM\Column(name: '`country`', type: 'string', length: 255, nullable: true)]
    public ?string $country = null;

    #[ORM\Column(name: '`website`', type: 'string', length: 255, nullable: true)]
    public ?string $website = null;

    #[ORM\Column(name: '`phonenumber`', type: 'string', length: 255, nullable: true)]
    public ?string $phonenumber = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`fax`', type: 'string', length: 255, nullable: true)]
    public ?string $fax = null;

    #[ORM\Column(name: '`email`', type: 'string', length: 255, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = false;
}
