<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** Existing local-account completion, produced only after actual credential verification. */
final readonly class AuthenticationCompletion
{
    public function __construct(
        public int $user,
        public string $at,
        public ?AuthenticationRuleOutcome $rules = null,
        public VerifiedLoginProvider $provider = VerifiedLoginProvider::LocalPassword
    ) {
        if ($user <= 0) {
            throw new \InvalidArgumentException('Authentication completion requires a persisted account.');
        }
    }

    public function lifecycleInput(): array
    {
        // Completion owns identity and login time; rule actions cannot replace either.
        return ['id' => $this->user, 'last_login' => $this->at, 'is_deleted_ldap' => false]
            + ($this->rules?->lifecycleInput() ?? [])
            + ($this->provider === VerifiedLoginProvider::LocalPassword ? [] : ['_extauth' => 1]);
    }

    /** Security-rule admission cannot be silently dropped by preparation or callbacks. */
    public function acceptsAdmission(array $values): bool
    {
        if ($this->rules === null || !array_key_exists('is_active', $this->rules->assignments)) {
            return true;
        }
        if (!array_key_exists('is_active', $values)) {
            return false;
        }
        // Type and nullability still come from the authoritative User property.
        $expected = \itsmng\Database\BooleanValue::normalizeLegacyInput(\User::getTable(), [
            'is_active' => $this->rules->assignments['is_active'],
        ]);
        $actual = \itsmng\Database\BooleanValue::normalizeLegacyInput(\User::getTable(), [
            'is_active' => $values['is_active'],
        ]);
        return $expected['is_active'] === $actual['is_active'];
    }
}
