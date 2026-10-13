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
use itsmng\Database\Repository\KnowledgeBaseChoiceRepository;

#[ORM\Entity(repositoryClass: KnowledgeBaseChoiceRepository::class)]
#[ORM\Table(name: 'glpi_knowbaseitems')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_knowbaseitems_users_id')]
#[SchemaIndex('knowbaseitemcategories_id', ['knowbaseitemcategories_id'], postgresqlName: 'glpi_knowbaseitems_knowbaseitemcategories_id')]
#[SchemaIndex('is_faq', ['is_faq'], postgresqlName: 'glpi_knowbaseitems_is_faq')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_knowbaseitems_date_mod')]
#[SchemaIndex('begin_date', ['begin_date'], postgresqlName: 'glpi_knowbaseitems_begin_date')]
#[SchemaIndex('end_date', ['end_date'], postgresqlName: 'glpi_knowbaseitems_end_date')]
#[SchemaIndex('fulltext', ['name', 'answer'], platform: AbstractMySQLPlatform::class, flags: ['fulltext'])]
#[SchemaIndex('name', ['name'], platform: AbstractMySQLPlatform::class, flags: ['fulltext'])]
#[SchemaIndex('answer', ['answer'], platform: AbstractMySQLPlatform::class, flags: ['fulltext'])]
class KnowbaseItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItemCategory::class)]
    #[ORM\JoinColumn(name: 'knowbaseitemcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_knowbaseitems_knowbaseitemcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?KnowbaseItemCategory $knowbaseitemcategories = null;

    #[ORM\Column(name: '`name`', type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`answer`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $answer = null;

    #[ORM\Column(name: '`is_faq`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_faq = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_knowbaseitems_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\Column(name: '`view`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $view = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end_date = null;
}
