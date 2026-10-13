<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** Legacy read context and explicit persistence intentions have different ownership. */
final readonly class AuthenticationRuleEvaluation
{
    public function __construct(public array $context, public AuthenticationRuleOutcome $outcome)
    {
    }
}
