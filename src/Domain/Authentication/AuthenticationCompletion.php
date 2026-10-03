<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** Existing local-account completion, produced only after actual credential verification. */
final readonly class AuthenticationCompletion
{
    public function __construct(
        public int $user,
        public string $at,
        public ?AuthenticationRuleOutcome $rules = null
    ) {
        if ($user <= 0) {
            throw new \InvalidArgumentException('Authentication completion requires a persisted account.');
        }
    }

    public function lifecycleInput(): array
    {
        // Completion owns identity and login time; rule actions cannot replace either.
        return ['id' => $this->user, 'last_login' => $this->at, 'is_deleted_ldap' => false]
            + ($this->rules?->lifecycleInput() ?? []);
    }
}
