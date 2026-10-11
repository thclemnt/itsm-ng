<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_queuednotifications')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('item', ['itemtype', 'items_id', 'notificationtemplates_id'], postgresqlName: 'glpi_queuednotifications_item')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_queuednotifications_is_deleted')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_queuednotifications_entities_id')]
#[SchemaIndex('sent_try', ['sent_try'], postgresqlName: 'glpi_queuednotifications_sent_try')]
#[SchemaIndex('create_time', ['create_time'], postgresqlName: 'glpi_queuednotifications_create_time')]
#[SchemaIndex('send_time', ['send_time'], postgresqlName: 'glpi_queuednotifications_send_time')]
#[SchemaIndex('sent_time', ['sent_time'], postgresqlName: 'glpi_queuednotifications_sent_time')]
#[SchemaIndex('mode', ['mode'], postgresqlName: 'glpi_queuednotifications_mode')]
#[SchemaIndex('IDX_FDE960543E89F867', ['notificationtemplates_id'])]
class QueuedNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class)]
    #[ORM\JoinColumn(name: 'notificationtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuednotifications_notificationtemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_queuednotifications_entities_id', options: ['default' => '0'])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`sent_try`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sent_try = 0;

    #[ORM\Column(name: '`create_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $create_time = null;

    #[ORM\Column(name: '`send_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $send_time = null;

    #[ORM\Column(name: '`sent_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTime $sent_time = null;

    #[ORM\Column(name: '`name`', type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`sender`', type: 'text', nullable: true)]
    public ?string $sender = null;

    #[ORM\Column(name: '`sendername`', type: 'text', nullable: true)]
    public ?string $sendername = null;

    #[ORM\Column(name: '`recipient`', type: 'text', nullable: true)]
    public ?string $recipient = null;

    #[ORM\Column(name: '`recipientname`', type: 'text', nullable: true)]
    public ?string $recipientname = null;

    #[ORM\Column(name: '`replyto`', type: 'text', nullable: true)]
    public ?string $replyto = null;

    #[ORM\Column(name: '`replytoname`', type: 'text', nullable: true)]
    public ?string $replytoname = null;

    #[ORM\Column(name: '`headers`', type: 'text', nullable: true)]
    public ?string $headers = null;

    #[ORM\Column(name: '`body_html`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $body_html = null;

    #[ORM\Column(name: '`body_text`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $body_text = null;

    #[ORM\Column(name: '`messageid`', type: 'text', nullable: true)]
    public ?string $messageid = null;

    #[ORM\Column(name: '`documents`', type: 'text', nullable: true)]
    public ?string $documents = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false, options: ['comment' => 'See Notification_NotificationTemplate::MODE_* constants'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $mode = '';

    /** A rendered browser message belongs to its recipient, independent of active entity. */
    public function isPendingBrowserMessageFor(int $user): bool
    {
        return $user > 0 && $this->mode === 'ajax' && $this->recipient === (string)$user && !$this->is_deleted;
    }

    /** Repeated acknowledgements retain the first presentation time and delivery counters. */
    public function acknowledgeBrowserMessage(int $user, DateTimeImmutable $presentedAt): bool
    {
        if (!$this->isPendingBrowserMessageFor($user)) {
            return false;
        }
        // Native TIMESTAMP retains its mutable datetimetz mapping. Keep the
        // caller's immutable clock while assigning the mapped PHP value type.
        $this->sent_time = DateTime::createFromImmutable($presentedAt);
        $this->is_deleted = true;
        return true;
    }
}
