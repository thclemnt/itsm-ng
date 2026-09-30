<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Selection policy is independent of the identifier of the referenced object. */
enum ReferenceMode: string
{
    case Explicit = 'explicit';
    case Inherit = 'inherit';
    case Unchanged = 'unchanged';
}
