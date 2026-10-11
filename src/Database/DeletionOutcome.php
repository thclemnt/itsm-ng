<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

enum DeletionOutcome
{
    case Deleted;
    case Cancelled;
    case ScopedDetachment;
}
