<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_notificationtemplatetranslations')]
class NotificationTemplateTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`notificationtemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $notificationtemplates_id = 0;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: false, options: ['default' => ''])]
    public string $language = '';

    #[ORM\Column(name: '`subject`', type: 'string', length: 255, nullable: false)]
    public string $subject = '';

    #[ORM\Column(name: '`content_text`', type: 'text', nullable: true)]
    public ?string $content_text = null;

    #[ORM\Column(name: '`content_html`', type: 'text', nullable: true)]
    public ?string $content_html = null;
}
