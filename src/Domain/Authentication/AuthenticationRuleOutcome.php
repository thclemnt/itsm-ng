<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain\Authentication;

/** Executed rule intentions, independent of the account used as matching context. */
final readonly class AuthenticationRuleOutcome
{
    public function __construct(public array $assignments, public array $grants, public bool $stopImport, public array $control = [])
    {
    }

    public function lifecycleInput(): array
    {
        $input = $this->assignments + ['_ruleright_process' => true] + $this->control;
        if ($this->grants) {
            $input['_ldap_rules'] = $this->grants;
        }
        if ($this->stopImport) {
            $input['_stop_import'] = true;
        }
        return $input;
    }
}
