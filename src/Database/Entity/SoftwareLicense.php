<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\AssetClassification;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Domain\EntityScope;
use itsmng\Domain\SoftwareAssignmentCancelled;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_softwarelicenses')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_softwarelicenses_name')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_softwarelicenses_is_template')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_softwarelicenses_serial')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_softwarelicenses_otherserial')]
#[SchemaIndex('expire', ['expire'], postgresqlName: 'glpi_softwarelicenses_expire')]
#[SchemaIndex('softwareversions_id_buy', ['softwareversions_id_buy'], postgresqlName: 'glpi_softwarelicenses_softwareversions_id_buy')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_softwarelicenses_entities_id')]
#[SchemaIndex('softwarelicensetypes_id', ['softwarelicensetypes_id'], postgresqlName: 'glpi_softwarelicenses_softwarelicensetypes_id')]
#[SchemaIndex('softwareversions_id_use', ['softwareversions_id_use'], postgresqlName: 'glpi_softwarelicenses_softwareversions_id_use')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_softwarelicenses_date_mod')]
#[SchemaIndex('softwares_id_expire_number', ['softwares_id', 'expire', 'number'], postgresqlName: 'glpi_softwarelicenses_softwares_id_expire_number')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_softwarelicenses_locations_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_softwarelicenses_users_id_tech')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_softwarelicenses_users_id')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_softwarelicenses_groups_id_tech')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_softwarelicenses_groups_id')]
#[SchemaIndex('is_helpdesk_visible', ['is_helpdesk_visible'], postgresqlName: 'glpi_softwarelicenses_is_helpdesk_visible')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_softwarelicenses_is_deleted')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_softwarelicenses_date_creation')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_softwarelicenses_manufacturers_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_softwarelicenses_states_id')]
#[SchemaIndex('allow_overquota', ['allow_overquota'], postgresqlName: 'glpi_softwarelicenses_allow_overquota')]
#[SchemaIndex('IDX_8DF16B58CA298729', ['softwares_id'])]
#[SchemaIndex('IDX_8DF16B5819DD4FCC', ['softwarelicenses_id'])]
class SoftwareLicense
{
    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_softwarelicenses_softwares_id')]
    #[ApplicationManaged]
    public ?Software $softwares = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SoftwareLicense::class)]
    #[ORM\JoinColumn(name: 'softwarelicenses_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_softwarelicenses_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?SoftwareLicense $parent = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_softwarelicenses_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`number`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $number = 0;

    /** Owning visibility and eligibility captured by a prepared allocation command. */
    public function allocationScope(): array
    {
        return [(int)$this->softwares?->id, (int)$this->entities?->id, $this->is_recursive, $this->is_deleted, $this->is_template];
    }

    /** A licence inherits recursion capability from its actual owning Software. */
    public function allocationEntityScope(Software $software): EntityScope
    {
        if ($this->entities === null || $this->softwares === null || $software->id !== $this->softwares->id) {
            throw new SoftwareAssignmentCancelled('Allocation licence requires its current owning Software and entity.');
        }
        return new EntityScope($this->entities->id, $this->is_recursive && $software->is_recursive);
    }

    /** Finite over-allocation is permitted and represented by an invalid licence. */
    public function isValidForAllocationCount(int $count): bool
    {
        return $this->number < 0 || $count <= $this->number;
    }

    #[ORM\ManyToOne(targetEntity: SoftwareLicenseType::class)]
    #[ORM\JoinColumn(name: 'softwarelicensetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_softwarelicensetypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[AssetClassification]
    public ?SoftwareLicenseType $softwarelicensetypes = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: SoftwareVersion::class)]
    #[ORM\JoinColumn(name: 'softwareversions_id_buy', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_softwareversions_id_buy', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SoftwareVersion $buyVersion = null;

    #[ORM\ManyToOne(targetEntity: SoftwareVersion::class)]
    #[ORM\JoinColumn(name: 'softwareversions_id_use', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_softwareversions_id_use', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?SoftwareVersion $useVersion = null;

    #[ORM\Column(name: '`expire`', type: 'date', nullable: true)]
    public ?DateTimeInterface $expire = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`is_valid`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_valid = true;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`is_helpdesk_visible`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_helpdesk_visible = false;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_softwarelicenses_manufacturers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\Column(name: '`allow_overquota`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $allow_overquota = false;
}
