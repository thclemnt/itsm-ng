<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_knowbaseitems_revisions')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['knowbaseitems_id', 'revision', 'language'], unique: true, postgresqlName: 'glpi_knowbaseitems_revisions_unicity')]
#[SchemaIndex('revision', ['revision'], postgresqlName: 'glpi_knowbaseitems_revisions_revision')]
#[SchemaIndex('IDX_3B8DEF9D8397986', ['knowbaseitems_id'], postgresqlName: 'IDX_3B8DEF9D8397986')]
#[SchemaIndex('IDX_3B8DEF913DB09D8', ['users_id'], postgresqlName: 'IDX_3B8DEF913DB09D8')]
class KnowbaseItemRevision
{
    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'knowbaseitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_knowbaseitems_revisions_knowbaseitems_id')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    #[ApplicationManaged]
    public ?KnowbaseItem $knowbaseitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`revision`', type: 'integer', nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $revision = 0;

    #[ORM\Column(name: '`name`', type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`answer`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $answer = null;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: true)]
    public ?string $language = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_knowbaseitems_revisions_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
