<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** A scoped account detachment is a committed operation, not a cancelled purge. */
enum DeletionDecision
{
    case Proceed;
    case Cancelled;
    case ScopedDetachment;
}
