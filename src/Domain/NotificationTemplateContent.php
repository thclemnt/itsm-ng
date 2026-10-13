<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Unrendered, nullable content selected for one template and effective locale. */
final readonly class NotificationTemplateContent
{
    public function __construct(
        public int $id,
        public int $templateId,
        public string $language,
        public string $subject,
        public ?string $text,
        public ?string $html
    ) {
    }

    public function legacyRow(): array
    {
        return [
            'id' => $this->id, 'notificationtemplates_id' => $this->templateId,
            'language' => $this->language, 'subject' => $this->subject,
            'content_text' => $this->text, 'content_html' => $this->html,
        ];
    }
}
