<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_queuednotifications')]
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
    #[ORM\JoinColumn(name: 'notificationtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`sent_try`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sent_try = 0;

    #[ORM\Column(name: '`create_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $create_time = null;

    #[ORM\Column(name: '`send_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $send_time = null;

    #[ORM\Column(name: '`sent_time`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $sent_time = null;

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
    public ?string $body_html = null;

    #[ORM\Column(name: '`body_text`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $body_text = null;

    #[ORM\Column(name: '`messageid`', type: 'text', nullable: true)]
    public ?string $messageid = null;

    #[ORM\Column(name: '`documents`', type: 'text', nullable: true)]
    public ?string $documents = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false)]
    public string $mode = '';

    /** A rendered browser message belongs to its recipient, independent of active entity. */
    public function isPendingBrowserMessageFor(int $user): bool
    {
        return $user > 0 && $this->mode === 'ajax' && $this->recipient === (string)$user && !$this->is_deleted;
    }

    /** Repeated acknowledgements retain the first presentation time and delivery counters. */
    public function acknowledgeBrowserMessage(int $user, \DateTimeImmutable $presentedAt): bool
    {
        if (!$this->isPendingBrowserMessageFor($user)) {
            return false;
        }
        $this->sent_time = $presentedAt;
        $this->is_deleted = true;
        return true;
    }
}
