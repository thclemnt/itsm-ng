<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtemplates')]
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
    public string $itemtype = '';

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`css`', type: 'text', nullable: true)]
    public ?string $css = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
