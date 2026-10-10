<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtemplates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_notificationtemplates_itemtype')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_notificationtemplates_date_mod')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_notificationtemplates_name')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_notificationtemplates_date_creation')]
class NotificationTemplate
{
    /** @var Collection<int, NotificationNotificationTemplate> */
    #[ORM\OneToMany(targetEntity: NotificationNotificationTemplate::class, mappedBy: 'notificationtemplates')]
    public Collection $templateBindings;

    /** @var Collection<int, NotificationTemplateTranslation> */
    #[ORM\OneToMany(targetEntity: NotificationTemplateTranslation::class, mappedBy: 'notificationtemplates')]
    public Collection $translations;

    public function __construct()
    {
        $this->templateBindings = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`css`', type: 'text', nullable: true)]
    public ?string $css = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
