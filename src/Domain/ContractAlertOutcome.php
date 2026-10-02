<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** A stale/already-published candidate is a quiet no-op, rather than a hook refusal. */
enum ContractAlertOutcome
{
    /** Public dispatch returned true and Alert persisted; transport acceptance is not aggregated by that framework. */
    case Published;
    case Skipped;
    case Refused;
}
