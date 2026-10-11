<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use LogicException;

/** Marks a legacy SQL construct that still needs its own mapped query implementation. */
final class UnsupportedCriteria extends LogicException
{
}
