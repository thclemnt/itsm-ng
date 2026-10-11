<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notimportedemails')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_notimportedemails_users_id')]
#[SchemaIndex('mailcollectors_id', ['mailcollectors_id'], postgresqlName: 'glpi_notimportedemails_mailcollectors_id')]
class NotImportedEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`from`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $from = '';

    #[ORM\Column(name: '`to`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $to = '';

    #[ORM\ManyToOne(targetEntity: MailCollector::class)]
    #[ORM\JoinColumn(name: 'mailcollectors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_notimportedemails_mailcollectors_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?MailCollector $mailcollectors = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`subject`', type: 'text', nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(name: '`messageid`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $messageid = '';

    #[ORM\Column(name: '`reason`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $reason = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_notimportedemails_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;
}
