<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_mailcollectors')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_mailcollectors_is_active')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_mailcollectors_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_mailcollectors_date_creation')]
class MailCollector
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`host`', type: 'string', length: 255, nullable: true)]
    public ?string $host = null;

    #[ORM\Column(name: '`login`', type: 'string', length: 255, nullable: true)]
    public ?string $login = null;

    #[ORM\Column(name: '`filesize_max`', type: 'integer', nullable: false, options: ['default' => '2097152'])]
    public int $filesize_max = 2097152;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_active = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`passwd`', type: 'string', length: 255, nullable: true)]
    public ?string $passwd = null;

    #[ORM\Column(name: '`accepted`', type: 'string', length: 255, nullable: true)]
    public ?string $accepted = null;

    #[ORM\Column(name: '`refused`', type: 'string', length: 255, nullable: true)]
    public ?string $refused = null;

    #[ORM\Column(name: '`errors`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $errors = 0;

    #[ORM\Column(name: '`use_mail_date`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $use_mail_date = false;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`requester_field`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $requester_field = 0;

    #[ORM\Column(name: '`add_cc_to_observer`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $add_cc_to_observer = false;

    #[ORM\Column(name: '`collect_only_unread`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $collect_only_unread = false;
}
