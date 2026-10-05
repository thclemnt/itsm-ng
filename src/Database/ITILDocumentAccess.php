<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Attachment access after the caller has checked the parent ITIL object's rights. */
final readonly class ITILDocumentAccess
{
    public function __construct(
        public int $user,
        public bool $followups,
        public bool $privateFollowups,
        public bool $solutions,
        public bool $tasks,
        public bool $privateTasks,
    ) {
    }

    public static function current(string $itemtype): self
    {
        if (!in_array($itemtype, ['Ticket', 'Change', 'Problem'], true)) {
            throw new \InvalidArgumentException('Unsupported ITIL document type');
        }
        return $itemtype::getAssociatedDocumentAccess();
    }
}
