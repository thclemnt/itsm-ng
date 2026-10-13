<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** One evaluation owns this collector; no cached rule or global owns its lifetime. */
final class AuthenticationRuleMutations
{
    private array $assignments = [];
    private array $grants = [];
    private bool $stopImport = false;

    public function accepted(array $assignments, array $grants): void
    {
        $this->assignments = array_replace($this->assignments, $assignments);
        foreach ($grants as $kind => $values) {
            foreach ($values as $value) {
                $this->grants[$kind][] = $value;
            }
        }
    }

    public function stopImport(): void
    {
        $this->stopImport = true;
    }

    public function outcome(array $control): AuthenticationRuleOutcome
    {
        return new AuthenticationRuleOutcome($this->assignments, $this->grants, $this->stopImport, $control);
    }
}
