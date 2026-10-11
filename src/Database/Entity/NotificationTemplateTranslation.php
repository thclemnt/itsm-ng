<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtemplatetranslations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex(
    'notificationtemplates_id',
    ['notificationtemplates_id'],
    postgresqlName: 'glpi_notificationtemplatetranslations_notificationtemplates_id',
)]
class NotificationTemplateTranslation
{
    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(
        name: 'notificationtemplates_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
        foreignKeyName: 'fk_notificationtemplatetranslations_notificationtemplates_id',
        options: ['default' => 0],
    )]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: false, options: ['default' => ''])]
    public string $language = '';

    #[ORM\Column(name: '`subject`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $subject = '';

    #[ORM\Column(name: '`content_text`', type: 'text', nullable: true)]
    public ?string $content_text = null;

    #[ORM\Column(name: '`content_html`', type: 'text', nullable: true)]
    public ?string $content_html = null;
}
