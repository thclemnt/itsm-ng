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
use itsmng\Database\Type\FixedStringType;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_documents')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_documents_date_mod')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_documents_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_documents_entities_id')]
#[SchemaIndex('tickets_id', ['tickets_id'], postgresqlName: 'glpi_documents_tickets_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_documents_users_id')]
#[SchemaIndex('documentcategories_id', ['documentcategories_id'], postgresqlName: 'glpi_documents_documentcategories_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_documents_is_deleted')]
#[SchemaIndex('sha1sum', ['sha1sum'], postgresqlName: 'glpi_documents_sha1sum')]
#[SchemaIndex('tag', ['tag'], postgresqlName: 'glpi_documents_tag')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_documents_date_creation')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`filename`', type: 'string', length: 255, nullable: true, options: ['comment' => 'for display and transfert'])]
    public ?string $filename = null;

    #[ORM\Column(name: '`filepath`', type: 'string', length: 255, nullable: true, options: ['comment' => 'file storage path'])]
    public ?string $filepath = null;

    #[ORM\ManyToOne(targetEntity: DocumentCategory::class)]
    #[ORM\JoinColumn(name: 'documentcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_documentcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?DocumentCategory $documentcategories = null;

    #[ORM\Column(name: '`mime`', type: 'string', length: 255, nullable: true)]
    public ?string $mime = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`link`', type: 'string', length: 255, nullable: true)]
    public ?string $link = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_documents_tickets_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Ticket $tickets = null;

    #[ORM\Column(name: '`sha1sum`', type: FixedStringType::NAME, length: 40, nullable: true)]
    public ?string $sha1sum = null;

    #[ORM\Column(name: '`is_blacklisted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_blacklisted = false;

    #[ORM\Column(name: '`tag`', type: 'string', length: 255, nullable: true)]
    public ?string $tag = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
