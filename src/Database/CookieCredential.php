<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** One persisted cookie credential, including the timestamp its consumer uses. */
final readonly class CookieCredential
{
    private ?\DateTimeImmutable $issuedAt;

    public function __construct(#[\SensitiveParameter] private ?string $hash, ?\DateTimeInterface $issuedAt)
    {
        $this->issuedAt = $issuedAt === null ? null : \DateTimeImmutable::createFromInterface($issuedAt);
    }

    public function matchesRequested(#[\SensitiveParameter] string $hash, \DateTimeInterface $issuedAt): bool
    {
        return $this->hash === $hash && $this->issuedAt?->format('U.u') === $issuedAt->format('U.u');
    }
}
