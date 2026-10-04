<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** The supplied session cannot provide the application's current locking reads. */
final class CurrentReadUnavailable extends \RuntimeException
{
}
