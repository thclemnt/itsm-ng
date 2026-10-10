<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_itilcategories')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_itilcategories_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_itilcategories_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_itilcategories_is_recursive')]
#[SchemaIndex('knowbaseitemcategories_id', ['knowbaseitemcategories_id'], postgresqlName: 'glpi_itilcategories_knowbaseitemcategories_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_itilcategories_users_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_itilcategories_groups_id')]
#[SchemaIndex('is_helpdeskvisible', ['is_helpdeskvisible'], postgresqlName: 'glpi_itilcategories_is_helpdeskvisible')]
#[SchemaIndex('itilcategories_id', ['itilcategories_id'], postgresqlName: 'glpi_itilcategories_itilcategories_id')]
#[SchemaIndex('tickettemplates_id_incident', ['tickettemplates_id_incident'], postgresqlName: 'glpi_itilcategories_tickettemplates_id_incident')]
#[SchemaIndex('tickettemplates_id_demand', ['tickettemplates_id_demand'], postgresqlName: 'glpi_itilcategories_tickettemplates_id_demand')]
#[SchemaIndex('changetemplates_id', ['changetemplates_id'], postgresqlName: 'glpi_itilcategories_changetemplates_id')]
#[SchemaIndex('problemtemplates_id', ['problemtemplates_id'], postgresqlName: 'glpi_itilcategories_problemtemplates_id')]
#[SchemaIndex('is_incident', ['is_incident'], postgresqlName: 'glpi_itilcategories_is_incident')]
#[SchemaIndex('is_request', ['is_request'], postgresqlName: 'glpi_itilcategories_is_request')]
#[SchemaIndex('is_problem', ['is_problem'], postgresqlName: 'glpi_itilcategories_is_problem')]
#[SchemaIndex('is_change', ['is_change'], postgresqlName: 'glpi_itilcategories_is_change')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_itilcategories_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_itilcategories_date_creation')]
class ITILCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: ITILCategory::class)]
    #[ORM\JoinColumn(name: 'itilcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_itilcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILCategory $itilcategories = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\ManyToOne(targetEntity: KnowbaseItemCategory::class)]
    #[ORM\JoinColumn(name: 'knowbaseitemcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_knowbaseitemcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?KnowbaseItemCategory $knowbaseitemcategories = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_id = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`code`', type: 'string', length: 255, nullable: true)]
    public ?string $code = null;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_helpdeskvisible`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_helpdeskvisible = true;

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id_incident', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_tickettemplates_id_incident', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TicketTemplate $tickettemplates_incident = null;

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id_demand', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_tickettemplates_id_demand', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TicketTemplate $tickettemplates_demand = null;

    #[ORM\ManyToOne(targetEntity: ChangeTemplate::class)]
    #[ORM\JoinColumn(name: 'changetemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_changetemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ChangeTemplate $changetemplates = null;

    #[ORM\ManyToOne(targetEntity: ProblemTemplate::class)]
    #[ORM\JoinColumn(name: 'problemtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_itilcategories_problemtemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProblemTemplate $problemtemplates = null;

    #[ORM\Column(name: '`is_incident`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::INTEGER)]
    public bool $is_incident = true;

    #[ORM\Column(name: '`is_request`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::INTEGER)]
    public bool $is_request = true;

    #[ORM\Column(name: '`is_problem`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::INTEGER)]
    public bool $is_problem = true;

    #[ORM\Column(name: '`is_change`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_change = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
